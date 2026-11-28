<?php

namespace Kanboard\Plugin\ModMenu\Model;

/**
 * The one strict parser for GitHub URLs. It decides whether a hop-0 URL may
 * carry the GitHub token and builds every URL actually requested; anything it
 * does not fully understand is refused (null / false).
 *
 * Accepted: https, an authority that is exactly one of three hosts (ASCII
 * case-insensitive) with no userinfo, port, trailing dot, IP or IDN, no query
 * or fragment, and a path whose segments are non-empty, not "." / "..", and
 * match their own grammar after exactly one raw URL decode.
 */
final class GithubUrl
{
    private const RAW_HOST = 'raw.githubusercontent.com';
    private const API_HOST = 'api.github.com';
    private const WEB_HOST = 'github.com';

    private const OWNER = '/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/D';
    private const REPO = '/^[A-Za-z0-9._-]{1,100}$/D';
    private const REF = '/^[A-Za-z0-9._+-]{1,128}$/D';
    private const ASSET = '/^[A-Za-z0-9._+-]{1,255}$/D';
    private const PATH = '/^[A-Za-z0-9._-]{1,100}$/D';
    private const ASSET_ID = '/^[1-9][0-9]{0,18}$/D';

    /**
     * A private source URL https://raw.githubusercontent.com/<owner>/<repo>/<ref>/<path…>.json.
     * Owner and repo are returned ASCII-lowercased, ref and path segments
     * decoded; endpoint is rebuilt from those with every segment rawurlencoded
     * once, and id is the sha256 of the endpoint.
     *
     * @return array{owner: string, repo: string, ref: string, path: string[], endpoint: string, id: string}|null
     */
    public static function source(string $url): ?array
    {
        $segments = self::segments($url, self::RAW_HOST);

        if ($segments === null || count($segments) < 4) {
            return null;
        }

        [$owner, $repo, $ref] = $segments;
        $path = array_slice($segments, 3);

        if (! self::isOwner($owner) || ! self::isRepo($repo) || ! self::isRef($ref)) {
            return null;
        }

        foreach ($path as $segment) {
            if (preg_match(self::PATH, $segment) !== 1) {
                return null;
            }
        }

        if (! str_ends_with(end($path), '.json')) {
            return null;
        }

        $owner = strtolower($owner);
        $repo = strtolower($repo);
        $endpoint = 'https://'.self::RAW_HOST.'/'.implode('/', array_map('rawurlencode', [$owner, $repo, $ref, ...$path]));

        return [
            'owner' => $owner,
            'repo' => $repo,
            'ref' => $ref,
            'path' => $path,
            'endpoint' => $endpoint,
            'id' => SourceUrl::id($endpoint),
        ];
    }

    /**
     * A private entry download https://github.com/<owner>/<repo>/releases/download/<tag>/<asset>.
     * Owner and repo are returned ASCII-lowercased, tag and asset decoded.
     *
     * @return array{owner: string, repo: string, tag: string, asset: string}|null
     */
    public static function download(string $url): ?array
    {
        $segments = self::segments($url, self::WEB_HOST);

        if ($segments === null || count($segments) !== 6) {
            return null;
        }

        [$owner, $repo, $releases, $download, $tag, $asset] = $segments;

        if (! self::isOwner($owner)
            || ! self::isRepo($repo)
            || $releases !== 'releases'
            || $download !== 'download'
            || ! self::isRef($tag)
            || preg_match(self::ASSET, $asset) !== 1) {
            return null;
        }

        return [
            'owner' => strtolower($owner),
            'repo' => strtolower($repo),
            'tag' => $tag,
            'asset' => $asset,
        ];
    }

    public static function releaseByTagUrl(string $owner, string $repo, string $tag): string
    {
        return self::apiUrl([$owner, $repo, 'releases', 'tags', $tag]);
    }

    public static function assetUrl(string $owner, string $repo, int $id): string
    {
        return self::apiUrl([$owner, $repo, 'releases', 'assets', (string) $id]);
    }

    /**
     * True only for a hop-0 URL that may carry the token: a valid private
     * source URL on raw.githubusercontent.com, or an api.github.com URL of
     * the exact shape releaseByTagUrl() or assetUrl() builds.
     */
    public static function tokenHost(string $url): bool
    {
        if (self::source($url) !== null) {
            return true;
        }

        $segments = self::segments($url, self::API_HOST);

        if ($segments === null || count($segments) !== 6) {
            return false;
        }

        [$repos, $owner, $repo, $releases, $kind, $last] = $segments;

        return $repos === 'repos'
            && self::isOwner($owner)
            && self::isRepo($repo)
            && $releases === 'releases'
            && (($kind === 'tags' && self::isRef($last))
                || ($kind === 'assets' && preg_match(self::ASSET_ID, $last) === 1));
    }

    /**
     * @param string[] $segments
     */
    private static function apiUrl(array $segments): string
    {
        return 'https://'.self::API_HOST.'/repos/'.implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * The decoded path segments of $url when it is https on exactly $host and
     * passes every generic rule; null otherwise. Each segment is decoded once
     * and must not decode to "." / ".." or contain "/", "\" or a control byte.
     *
     * @return string[]|null
     */
    private static function segments(string $url, string $host): ?array
    {
        // Whitespace, controls, non-ASCII bytes and "\" are refused anywhere,
        // as are a query and a fragment (even empty ones).
        if (preg_match('/[\x00-\x20\x7F-\xFF\\\\?#]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^https://([^/]*)/(.*)$#iD', $url, $match) !== 1) {
            return null;
        }

        // Byte-equal after ASCII lowercase: no userinfo, port, trailing dot,
        // "%", IP literal or look-alike can survive this.
        if (strtolower($match[1]) !== $host) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $match[2]) as $raw) {
            $decoded = rawurldecode($raw);

            if ($raw === ''
                || $decoded === '.'
                || $decoded === '..'
                || preg_match('/[\/\\\\\x00-\x1F\x7F]/', $decoded) === 1) {
                return null;
            }

            $segments[] = $decoded;
        }

        return $segments;
    }

    private static function isOwner(string $owner): bool
    {
        return preg_match(self::OWNER, $owner) === 1;
    }

    private static function isRepo(string $repo): bool
    {
        return preg_match(self::REPO, $repo) === 1 && $repo !== '.' && $repo !== '..';
    }

    private static function isRef(string $ref): bool
    {
        return preg_match(self::REF, $ref) === 1 && ! str_contains($ref, '..');
    }
}
