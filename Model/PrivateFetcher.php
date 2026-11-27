<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Translator;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/**
 * The only code that touches a private source's GitHub token. Each operation
 * reads the token from Vault once, sends it on hop 0 of GitHub requests only,
 * and turns every failure into a new ModMenuException with a fixed message
 * and no previous exception, so nothing it caught can carry the token out.
 */
class PrivateFetcher extends Base
{
    private const CONSUMER = 'ModMenu';
    private const TOKEN = '/^[A-Za-z0-9_]{20,255}$/D';
    private const REFUSED_STATUSES = [401, 403, 404];
    private const DIRECTORY_BYTES = 5 * 1024 * 1024;
    private const RELEASE_BYTES = 1024 * 1024;
    private const API_HEADERS = ['X-GitHub-Api-Version: 2022-11-28'];

    private const FALLBACK = "Couldn't load this private source.";
    private const RELEASE_FAILED = "Couldn't download this private plugin's release.";
    private const NOT_A_SOURCE = "This private source's URL is not a GitHub source URL.";
    private const NOT_A_TOKEN = 'The token in Vault is not a GitHub token';
    private const OTHER_ACCOUNT = "This private plugin's download is not in its source's GitHub account";
    private const NO_ASSET = 'The release does not have exactly one asset named %s';
    private const REFUSED = 'GitHub refused the token for this source (check its access to %s)';
    private const VAULT_FALLBACK = 'Vault could not give ModMenu the token for this source';

    /** One message per reason in Vault's ServiceSecretException::REASONS. */
    private const VAULT_MESSAGES = [
        'server_off' => "Vault's Server sharing is off, so ModMenu can't read the token for this source.",
        'server_key_unavailable' => "Vault's server key is unavailable, so ModMenu can't read the token for this source.",
        'not_shared' => 'The token for this source is not shared with Server in Vault.',
        'consumer_not_allowed' => "The token's Server grant in Vault does not allow ModMenu.",
        'field_not_allowed' => "The token's Server grant in Vault does not allow this field.",
        'rate_limited' => "ModMenu reached the token's Vault rate limit. Try again later.",
        'revoked' => "The token's Server grant in Vault was revoked.",
        'not_found' => 'The token for this source no longer exists in Vault.',
        'orphaned' => "The token's Vault entry no longer has an owner.",
    ];

    /**
     * Messages a caught ModMenuException may keep: ArchiveDownloader's fixed
     * transport messages and this class's own. %s and %d stand for the hosts,
     * names and sizes those messages are built with.
     */
    private const ALLOWED_MESSAGES = [
        'The plugin archive is larger than 50 MB.',
        'Unable to download the plugin archive.',
        "Couldn't load this directory listing.",
        'The directory listing is larger than %d MB.',
        'This directory listing is not valid JSON.',
        'Plugin downloads need the PHP curl extension.',
        'Too many redirects while downloading the plugin archive.',
        'The download URL must be a valid https:// URL.',
        'The download was refused: %s is not a public address.',
        self::FALLBACK,
        self::RELEASE_FAILED,
        self::NOT_A_SOURCE,
        self::NOT_A_TOKEN,
        self::OTHER_ACCOUNT,
        self::NO_ASSET,
        self::REFUSED,
        self::VAULT_FALLBACK,
    ];

    /** ArchiveDownloader's generic failures, worded for directory listings; a release reports RELEASE_FAILED instead. */
    private const GENERIC_TRANSPORT_MESSAGES = [
        'Unable to download the plugin archive.',
        "Couldn't load this directory listing.",
        'The directory listing is larger than %d MB.',
        'This directory listing is not valid JSON.',
    ];

    /**
     * The decoded private directory listing.
     *
     * @param array{id: string, url: string, token_ref: string} $source
     */
    public function fetchDirectory(array $source): array
    {
        $parsed = $this->parsedSource($source);
        $downloader = $this->container['modMenuDirectoryDownloader'] ?? new ArchiveDownloader();

        $repository = $parsed['owner'] . '/' . $parsed['repo'];

        return $this->boundary(self::FALLBACK, fn (): array => $this->withToken(
            (string) $source['token_ref'],
            self::FALLBACK,
            static fn (#[\SensitiveParameter] array $auth): array => self::github(
                $downloader,
                $repository,
                static fn (): array => $downloader->fetchJsonWith(
                    $parsed['endpoint'],
                    $auth,
                    self::DIRECTORY_BYTES,
                    true,
                    ['Accept: application/json']
                )
            )
        ));
    }

    /**
     * Downloads a private release asset; the caller owns the returned temporary file.
     *
     * @param array{id: string, url: string, token_ref: string} $source
     */
    public function downloadRelease(array $source, string $downloadUrl): string
    {
        $parsed = $this->parsedSource($source);
        $download = GithubUrl::download($downloadUrl);
        if ($download === null || $download['owner'] !== $parsed['owner']) {
            throw new ModMenuException(e(self::OTHER_ACCOUNT));
        }
        $tagsUrl = GithubUrl::releaseByTagUrl($download['owner'], $download['repo'], $download['tag']);
        self::assertTokenHost($tagsUrl);
        $downloader = $this->container['modMenuArchiveDownloader'] ?? new ArchiveDownloader();
        $repository = $download['owner'] . '/' . $download['repo'];

        return $this->boundary(self::RELEASE_FAILED, fn (): string => $this->withToken(
            (string) $source['token_ref'],
            self::RELEASE_FAILED,
            static function (#[\SensitiveParameter] array $auth) use ($downloader, $download, $repository, $tagsUrl): string {
                $release = self::github($downloader, $repository, static fn (): array => $downloader->fetchJsonWith(
                    $tagsUrl,
                    $auth,
                    self::RELEASE_BYTES,
                    false,
                    ['Accept: application/vnd.github+json', ...self::API_HEADERS]
                ), self::RELEASE_FAILED);
                $assetUrl = GithubUrl::assetUrl($download['owner'], $download['repo'], self::assetId($release, $download['asset']));
                self::assertTokenHost($assetUrl);

                return self::github($downloader, $repository, static fn (): string => $downloader->downloadWith(
                    $assetUrl,
                    $auth,
                    ['Accept: application/octet-stream', ...self::API_HEADERS]
                ), self::RELEASE_FAILED);
            }
        ));
    }

    /** Reasons meaning Vault cannot read for Server at all: shown as 'vault_off'. */
    private const VAULT_OFF_REASONS = ['server_off', 'server_key_unavailable'];

    /** Whether Vault shares the token: 'shared', 'not_shared' or 'vault_off'. Never throws. */
    public function describe(string $ref): string
    {
        try {
            $vault = $this->vault();
            if ($vault === null) {
                return 'vault_off';
            }
            $status = $vault->describe($ref);
            if (! empty($status->ok)) {
                return 'shared';
            }
            $reason = $status->reason ?? null;
        } catch (\Throwable $e) {
            $reason = self::reasonOf($e);
        }
        return in_array($reason, self::VAULT_OFF_REASONS, true) ? 'vault_off' : 'not_shared';
    }

    /** @return array{owner: string, repo: string, endpoint: string} */
    private function parsedSource(array $source): array
    {
        $parsed = is_string($source['url'] ?? null) ? GithubUrl::source($source['url']) : null;
        if ($parsed === null || ! is_string($source['token_ref'] ?? null) || ! GithubUrl::tokenHost($parsed['endpoint'])) {
            throw new ModMenuException(e(self::NOT_A_SOURCE));
        }
        return $parsed;
    }

    private static function assertTokenHost(string $url): void
    {
        if (! GithubUrl::tokenHost($url)) {
            throw new ModMenuException(e(self::NOT_A_SOURCE));
        }
    }

    private static function assetId(array $release, string $asset): int
    {
        $matches = array_values(array_filter(
            is_array($release['assets'] ?? null) ? $release['assets'] : [],
            static fn ($entry): bool => is_array($entry) && ($entry['name'] ?? null) === $asset
        ));
        $id = $matches[0]['id'] ?? null;
        if (count($matches) !== 1 || ! is_int($id) || $id <= 0) {
            throw new ModMenuException(e(self::NO_ASSET, $asset));
        }
        return $id;
    }

    /**
     * Reads the token once, passes the operation its hop-0 Authorization
     * header, and drops the revealed string afterwards (best effort). A
     * failure whose message contains the token becomes $fallback.
     */
    private function withToken(string $ref, string $fallback, callable $operation): mixed
    {
        $vault = $this->vault();
        if ($vault === null) {
            throw new ModMenuException(e(self::VAULT_MESSAGES['server_off']));
        }
        try {
            $token = $vault->read(self::CONSUMER, $ref)->reveal();
        } catch (\Throwable $e) {
            $reason = self::reasonOf($e);
            unset($e);
            throw new ModMenuException(e(self::VAULT_MESSAGES[$reason] ?? self::VAULT_FALLBACK));
        }

        try {
            if (! is_string($token) || preg_match(self::TOKEN, $token) !== 1) {
                throw new ModMenuException(e(self::NOT_A_TOKEN));
            }
            try {
                return $operation(['Authorization: Bearer ' . $token]);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), $token)) {
                    unset($e);
                    throw new ModMenuException(e($fallback));
                }
                throw $e;
            }
        } finally {
            $token = null;
        }
    }

    private function vault(): ?object
    {
        $vault = isset($this->container['vaultSecrets']) ? $this->container['vaultSecrets'] : null;
        return is_object($vault) ? $vault : null;
    }

    /**
     * Runs private network work and rethrows any failure as a new
     * ModMenuException with a fixed message (or $fallback) and no previous exception.
     */
    private function boundary(string $fallback, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            $message = $e instanceof ModMenuException ? $e->getMessage() : null;
            $reason = self::reasonOf($e);
            unset($e);
        }

        if ($reason !== null) {
            $message = e(self::VAULT_MESSAGES[$reason] ?? self::VAULT_FALLBACK);
        } elseif ($message === null || ! self::matches($message, [...self::ALLOWED_MESSAGES, ...array_values(self::VAULT_MESSAGES)])) {
            $message = e($fallback);
        }
        throw new ModMenuException($message);
    }

    /**
     * Runs one GitHub request. A 401, 403 or 404 on hop 0, the only hop that
     * carries the token, is GitHub refusing the token. With $failure, a
     * generic transport failure is reported as $failure.
     */
    private static function github(ArchiveDownloader $downloader, string $repository, callable $request, ?string $failure = null): mixed
    {
        try {
            return $request();
        } catch (\Throwable $e) {
            if ($downloader->lastHop() === 0 && in_array($downloader->lastStatus(), self::REFUSED_STATUSES, true)) {
                throw new ModMenuException(e(self::REFUSED, $repository));
            }
            if ($failure !== null
                && (! $e instanceof ModMenuException || self::matches($e->getMessage(), self::GENERIC_TRANSPORT_MESSAGES))) {
                throw new ModMenuException(e($failure));
            }
            throw $e;
        }
    }

    private static function reasonOf(\Throwable $e): ?string
    {
        if (! method_exists($e, 'reason')) {
            return null;
        }
        try {
            $reason = $e->reason();
        } catch (\Throwable) {
            return null;
        }
        return is_string($reason) ? $reason : null;
    }

    /** @param string[] $formats Untranslated messages; %s and %d match the values they are built with. */
    private static function matches(string $message, array $formats): bool
    {
        foreach ($formats as $format) {
            $template = Translator::getInstance()->get($format, $format);
            $pattern = implode('', array_map(
                static fn (string $part): string => match ($part) {
                    '%s' => '[A-Za-z0-9._+\/:\[\]-]+',
                    '%d' => '[0-9]+',
                    default => preg_quote($part, '/'),
                },
                preg_split('/(%[sd])/', $template, -1, PREG_SPLIT_DELIM_CAPTURE)
            ));
            if (preg_match('/^' . $pattern . '$/D', $message) === 1) {
                return true;
            }
        }
        return false;
    }
}
