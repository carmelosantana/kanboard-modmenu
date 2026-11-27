<?php

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\PrivateFetcher;

/** Stands in for Vault's ServiceSecretException: a refusal that carries only a reason. */
class PrivateFetcherVaultRefusal extends \RuntimeException
{
    public function __construct(private string $refusal)
    {
        parent::__construct('Vault refused: ' . $refusal);
    }

    public function reason(): string
    {
        return $this->refusal;
    }
}

class PrivateFetcherTest extends Base
{
    private const TOKEN = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';
    private const REF = 'vault:42/token';
    private const SOURCE_URL = 'https://raw.githubusercontent.com/Acme/Plugins/main/plugins.json';
    private const ENDPOINT = 'https://raw.githubusercontent.com/acme/plugins/main/plugins.json';
    private const DOWNLOAD = 'https://github.com/ACME/widget/releases/download/v1.0.0/Widget-1.0.0.zip';
    private const TAGS_URL = 'https://api.github.com/repos/acme/widget/releases/tags/v1.0.0';
    private const ASSET_URL = 'https://api.github.com/repos/acme/widget/releases/assets/77';
    private const FALLBACK = "Couldn't load this private source.";
    private const RELEASE_FAILED = "Couldn't download this private plugin's release.";
    private const SIGNED = 'https://objects.githubusercontent.com/github-production-release-asset/1?X-Amz-Signature=abc';

    private object $vault;
    private array $requests = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->vault = $this->fakeVault(self::TOKEN);
        $this->container['vaultSecrets'] = $this->vault;
        $this->requests = [];
    }

    private function fakeVault(string|\Throwable $token): object
    {
        return new class ($token) {
            public int $reads = 0;
            public array $refs = [];
            public mixed $description = null;

            public function __construct(public string|\Throwable $token)
            {
            }

            public function read(string $consumer, string $ref): object
            {
                $this->reads++;
                $this->refs[] = [$consumer, $ref];
                if ($this->token instanceof \Throwable) {
                    throw $this->token;
                }
                return new class ($this->token) {
                    public function __construct(private string $value)
                    {
                    }

                    public function reveal(): string
                    {
                        return $this->value;
                    }
                };
            }

            public function describe(string $ref): object
            {
                if ($this->description instanceof \Throwable) {
                    throw $this->description;
                }
                return $this->description;
            }
        };
    }

    private function source(): array
    {
        return ['id' => 'source-id', 'url' => self::SOURCE_URL, 'token_ref' => self::REF];
    }

    /**
     * @param array<string, callable(callable): array> $routes URL → handler receiving the write callback.
     * @param array<string, string> $addresses Host → resolved address; every other host is public.
     */
    private function downloader(array $routes, array $addresses = []): ArchiveDownloader
    {
        return new ArchiveDownloader(
            static fn ($host) => [$addresses[$host] ?? '140.82.112.3'],
            function ($url, $address, $header, $write, $headers) use ($routes) {
                $this->requests[] = ['url' => $url, 'headers' => $headers];
                if (! isset($routes[$url])) {
                    return [404, null];
                }
                return $routes[$url]($write);
            }
        );
    }

    private function useDirectoryRoutes(array $routes, array $addresses = []): void
    {
        $this->container['modMenuDirectoryDownloader'] = $this->downloader($routes, $addresses);
    }

    private function useReleaseRoutes(array $routes, array $addresses = []): void
    {
        $this->container['modMenuArchiveDownloader'] = $this->downloader($routes, $addresses);
    }

    private static function json(mixed $value, int $status = 200): callable
    {
        return static function ($write) use ($value, $status) {
            $write(null, json_encode($value));
            return [$status, null];
        };
    }

    private static function release(array $assets): callable
    {
        return self::json(['tag_name' => 'v1.0.0', 'assets' => $assets]);
    }

    private static function zipAsset(string $body = 'PK-zip-bytes'): callable
    {
        return static function ($write) use ($body) {
            $write(null, $body);
            return [200, null];
        };
    }

    private function fetcher(): PrivateFetcher
    {
        return new PrivateFetcher($this->container);
    }

    private function failure(callable $operation): ModMenuException
    {
        try {
            $operation();
        } catch (ModMenuException $e) {
            return $e;
        }
        $this->fail('Expected a ModMenuException.');
    }

    private function auth(string $token = self::TOKEN): string
    {
        return 'Authorization: Bearer ' . $token;
    }

    public function testDirectoryFetchSendsTokenOnlyToRaw(): void
    {
        $cdn = 'https://cdn.example/plugins.json';
        $this->useDirectoryRoutes([
            self::ENDPOINT => static fn () => [302, $cdn],
            $cdn => self::json([['name' => 'Alpha']]),
        ]);

        $this->assertSame([['name' => 'Alpha']], $this->fetcher()->fetchDirectory($this->source()));

        $this->assertSame([self::ENDPOINT, $cdn], array_column($this->requests, 'url'));
        $this->assertContains($this->auth(), $this->requests[0]['headers']);
        $this->assertContains('Accept: application/json', $this->requests[0]['headers']);
        $this->assertSame(['Accept: application/json'], $this->requests[1]['headers']);
        $this->assertSame([['ModMenu', self::REF]], $this->vault->refs);
    }

    public function testDirectoryFetchRefusesNonGithubSourceWithoutReadingVault(): void
    {
        $this->useDirectoryRoutes([]);
        $source = ['id' => 'x', 'url' => 'https://evil.example/plugins.json', 'token_ref' => self::REF];

        $e = $this->failure(fn () => $this->fetcher()->fetchDirectory($source));

        $this->assertSame("This private source's URL is not a GitHub source URL.", $e->getMessage());
        $this->assertSame(0, $this->vault->reads);
        $this->assertSame([], $this->requests);
    }

    public function testOneVaultReadPerFetchAndPerRelease(): void
    {
        $this->useDirectoryRoutes([self::ENDPOINT => self::json([])]);
        $this->fetcher()->fetchDirectory($this->source());
        $this->assertSame(1, $this->vault->reads);

        $this->vault->reads = 0;
        $this->useReleaseRoutes([
            self::TAGS_URL => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
            self::ASSET_URL => self::zipAsset(),
        ]);
        $path = $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD);
        unlink($path);
        $this->assertSame(1, $this->vault->reads);
        $this->assertContains($this->auth(), $this->requests[1]['headers']);
        $this->assertContains($this->auth(), $this->requests[2]['headers']);
    }

    public function testEachVaultReasonMapsToItsMessage(): void
    {
        $expected = [
            'server_off' => "Vault's Server sharing is off, so ModMenu can't read the token for this source.",
            'server_key_unavailable' => "Vault's server key is unavailable, so ModMenu can't read the token for this source.",
            'not_shared' => 'The token for this source is not shared with Server in Vault.',
            'consumer_not_allowed' => "The token's Server grant in Vault does not allow ModMenu.",
            'field_not_allowed' => "The token's Server grant in Vault does not allow this field.",
            'rate_limited' => "ModMenu reached the token's Vault rate limit. Try again later.",
            'revoked' => "The token's Server grant in Vault was revoked.",
            'not_found' => 'The token for this source no longer exists in Vault.',
            'orphaned' => "The token's Vault entry no longer has an owner.",
            'something_new' => 'Vault could not give ModMenu the token for this source',
        ];
        $this->useDirectoryRoutes([self::ENDPOINT => self::json([])]);

        foreach ($expected as $reason => $message) {
            $this->container['vaultSecrets'] = $this->fakeVault(new PrivateFetcherVaultRefusal($reason));
            $e = $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()));
            $this->assertSame($message, $e->getMessage(), $reason);
            $this->assertNull($e->getPrevious(), $reason);
            $this->assertStringNotContainsString(self::REF, $e->getMessage(), $reason);
        }
        $this->assertSame([], $this->requests);

        $serverOff = $expected['server_off'];
        unset($this->container['vaultSecrets']);
        $this->assertSame($serverOff, $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()))->getMessage());
        $this->container['vaultSecrets'] = 'not an object';
        $this->assertSame($serverOff, $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()))->getMessage());
        $this->assertSame([], $this->requests);
    }

    public function testDescribeReportsSharingWithoutThrowing(): void
    {
        $this->vault->description = (object) ['ok' => true, 'reason' => null];
        $this->assertSame('shared', $this->fetcher()->describe(self::REF));

        $this->vault->description = (object) ['ok' => false, 'reason' => 'server_off'];
        $this->assertSame('vault_off', $this->fetcher()->describe(self::REF));

        $this->vault->description = (object) ['ok' => false, 'reason' => 'revoked'];
        $this->assertSame('not_shared', $this->fetcher()->describe(self::REF));

        $this->vault->description = new PrivateFetcherVaultRefusal('server_off');
        $this->assertSame('vault_off', $this->fetcher()->describe(self::REF));

        $this->vault->description = (object) ['ok' => false, 'reason' => 'server_key_unavailable'];
        $this->assertSame('vault_off', $this->fetcher()->describe(self::REF));

        $this->vault->description = new PrivateFetcherVaultRefusal('server_key_unavailable');
        $this->assertSame('vault_off', $this->fetcher()->describe(self::REF));

        $this->vault->description = new \RuntimeException('boom');
        $this->assertSame('not_shared', $this->fetcher()->describe(self::REF));

        unset($this->container['vaultSecrets']);
        $this->assertSame('vault_off', $this->fetcher()->describe(self::REF));
    }

    public function testTokenGrammarRefusalsNotEchoed(): void
    {
        $this->useDirectoryRoutes([self::ENDPOINT => self::json([])]);

        foreach (["abc\r\nX: y", str_repeat('a', 19), 'ghp_abc def ghi jkl mno pqr', str_repeat('a', 256)] as $token) {
            $this->container['vaultSecrets'] = $this->fakeVault($token);
            $e = $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()));
            $this->assertSame('The token in Vault is not a GitHub token', $e->getMessage());
            $this->assertStringNotContainsString($token, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame([], $this->requests);

        foreach ([str_repeat('a', 20), str_repeat('Z_9', 85)] as $token) {
            $this->container['vaultSecrets'] = $this->fakeVault($token);
            $this->assertSame([], $this->fetcher()->fetchDirectory($this->source()));
        }
    }

    public function testReleaseRefusesZeroOrTwoMatchingAssets(): void
    {
        $other = ['id' => 5, 'name' => 'Widget-1.0.0.zip.sha256'];
        $match = ['id' => 77, 'name' => 'Widget-1.0.0.zip'];
        foreach ([[], [$other], [$match, $match + ['id' => 78]], [['id' => 77, 'name' => 'widget-1.0.0.zip']]] as $assets) {
            $this->requests = [];
            $this->useReleaseRoutes([self::TAGS_URL => self::release($assets), self::ASSET_URL => self::zipAsset()]);
            $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
            $this->assertSame('The release does not have exactly one asset named Widget-1.0.0.zip', $e->getMessage());
            $this->assertSame([self::TAGS_URL], array_column($this->requests, 'url'));
        }

        $this->requests = [];
        $this->useReleaseRoutes([self::TAGS_URL => self::json(['assets' => 'nope'])]);
        $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
        $this->assertSame('The release does not have exactly one asset named Widget-1.0.0.zip', $e->getMessage());
    }

    public function testReleaseRefusesNonPositiveId(): void
    {
        // Raw JSON, so floats such as 77.0 and 1e2 reach the decoder as written.
        foreach (['0', '-1', '"77"', '77.0', '1e2', 'null', '[77]'] as $id) {
            $this->requests = [];
            $this->useReleaseRoutes([
                self::TAGS_URL => static function ($write) use ($id) {
                    $write(null, '{"assets":[{"id":' . $id . ',"name":"Widget-1.0.0.zip"}]}');
                    return [200, null];
                },
                self::ASSET_URL => self::zipAsset(),
            ]);
            $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
            $this->assertSame('The release does not have exactly one asset named Widget-1.0.0.zip', $e->getMessage(), $id);
            $this->assertSame([self::TAGS_URL], array_column($this->requests, 'url'));
        }
    }

    public function testReleaseIgnoresBrowserDownloadUrl(): void
    {
        $evil = 'https://evil.example/Widget-1.0.0.zip';
        $this->useReleaseRoutes([
            self::TAGS_URL => self::release([[
                'id' => 77,
                'name' => 'Widget-1.0.0.zip',
                'url' => $evil,
                'browser_download_url' => $evil,
            ]]),
            self::ASSET_URL => self::zipAsset(),
            $evil => self::zipAsset('evil'),
        ]);

        $path = $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD);

        $this->assertSame('PK-zip-bytes', file_get_contents($path));
        unlink($path);
        $this->assertSame([self::TAGS_URL, self::ASSET_URL], array_column($this->requests, 'url'));
    }

    public function testAsset200Accepted(): void
    {
        $this->useReleaseRoutes([
            self::TAGS_URL => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
            self::ASSET_URL => self::zipAsset(),
        ]);

        $path = $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD);

        $this->assertSame('PK-zip-bytes', file_get_contents($path));
        unlink($path);
        $this->assertSame([
            [
                'url' => self::TAGS_URL,
                'headers' => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', $this->auth()],
            ],
            [
                'url' => self::ASSET_URL,
                'headers' => ['Accept: application/octet-stream', 'X-GitHub-Api-Version: 2022-11-28', $this->auth()],
            ],
        ], $this->requests);
    }

    public function testAsset302FollowedWithoutToken(): void
    {
        $signed = 'https://objects.githubusercontent.com/github-production-release-asset/1?X-Amz-Signature=abc&response-content-type=application%2Foctet-stream';
        $this->useReleaseRoutes([
            self::TAGS_URL => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
            self::ASSET_URL => static fn () => [302, $signed],
            $signed => self::zipAsset(),
        ]);

        $path = $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD);

        $this->assertSame('PK-zip-bytes', file_get_contents($path));
        unlink($path);
        $this->assertSame([self::TAGS_URL, self::ASSET_URL, $signed], array_column($this->requests, 'url'));
        $this->assertSame(['Accept: application/octet-stream', 'X-GitHub-Api-Version: 2022-11-28'], $this->requests[2]['headers']);
    }

    public function testTagsCall3xxRefused(): void
    {
        $elsewhere = 'https://api.github.com/repos/acme/widget/releases/tags/v1.0.0-moved';
        $this->useReleaseRoutes([
            self::TAGS_URL => static fn () => [302, $elsewhere],
            $elsewhere => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
        ]);

        $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));

        $this->assertSame([self::TAGS_URL], array_column($this->requests, 'url'));
        $this->assertSame(self::RELEASE_FAILED, $e->getMessage());
        $this->assertNull($e->getPrevious());
    }

    public function testAllowlistedTransportMessagesPassThrough(): void
    {
        $internal = 'https://internal.example/plugins.json';
        $cases = [
            "Couldn't load this directory listing." => self::json(['error' => 'x'], 500),
            'This directory listing is not valid JSON.' => static function ($write) {
                $write(null, '{');
                return [200, null];
            },
            'The download was refused: internal.example is not a public address.' => static fn () => [302, $internal],
        ];
        foreach ($cases as $message => $route) {
            $this->useDirectoryRoutes([self::ENDPOINT => $route], ['internal.example' => '127.0.0.1']);
            $e = $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()));
            $this->assertSame($message, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->useReleaseRoutes([
            self::TAGS_URL => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
            self::ASSET_URL => static fn () => [302, 'https://internal.example/Widget.zip'],
        ], ['internal.example' => '127.0.0.1']);
        $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
        $this->assertSame('The download was refused: internal.example is not a public address.', $e->getMessage());
    }

    public function testReleaseTransportFailuresUseReleaseMessage(): void
    {
        $release = self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]);
        $cases = [
            'tags 3xx' => [self::TAGS_URL => static fn () => [302, self::TAGS_URL . '-moved']],
            'tags 500' => [self::TAGS_URL => self::json([], 500)],
            'invalid tags JSON' => [self::TAGS_URL => static function ($write) {
                $write(null, '[');
                return [200, null];
            }],
            'asset 500' => [self::TAGS_URL => $release, self::ASSET_URL => self::json([], 500)],
            'asset aborted' => [self::TAGS_URL => $release, self::ASSET_URL => static function ($write) {
                $write(null, 'PK');
                return [0, null];
            }],
            'asset transport throws' => [self::TAGS_URL => $release, self::ASSET_URL => static function () {
                throw new \RuntimeException('socket');
            }],
        ];
        foreach ($cases as $name => $routes) {
            $this->useReleaseRoutes($routes);
            $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
            $this->assertSame(self::RELEASE_FAILED, $e->getMessage(), $name);
            $this->assertNull($e->getPrevious(), $name);
        }
    }

    public function testCdnRefusalAfterAssetRedirectIsNotATokenRefusal(): void
    {
        foreach ([401, 403, 404] as $status) {
            $this->requests = [];
            $this->useReleaseRoutes([
                self::TAGS_URL => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
                self::ASSET_URL => static fn () => [302, self::SIGNED],
                self::SIGNED => static fn () => [$status, null],
            ]);
            $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
            $this->assertSame(self::RELEASE_FAILED, $e->getMessage(), (string) $status);
            $this->assertSame([self::TAGS_URL, self::ASSET_URL, self::SIGNED], array_column($this->requests, 'url'));
        }

        $cdn = 'https://cdn.example/plugins.json';
        $this->useDirectoryRoutes([self::ENDPOINT => static fn () => [302, $cdn], $cdn => static fn () => [403, null]]);
        $e = $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()));
        $this->assertSame("Couldn't load this directory listing.", $e->getMessage());
    }

    public function testMessageCarryingTheTokenFallsBack(): void
    {
        $token = 'tokentokentokentoken42';
        $host = $token . '.example';
        $this->container['vaultSecrets'] = $this->fakeVault($token);

        $this->useDirectoryRoutes([self::ENDPOINT => static fn () => [302, 'https://' . $host . '/plugins.json']], [$host => '127.0.0.1']);
        $e = $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()));
        $this->assertSame(self::FALLBACK, $e->getMessage());
        $this->assertNull($e->getPrevious());

        $this->useReleaseRoutes([
            self::TAGS_URL => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
            self::ASSET_URL => static fn () => [302, 'https://' . $host . '/Widget.zip'],
        ], [$host => '127.0.0.1']);
        $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
        $this->assertSame(self::RELEASE_FAILED, $e->getMessage());
        $this->assertStringNotContainsString($token, $e->getTraceAsString());
    }

    public function testOwnerMismatchRefused(): void
    {
        $this->useReleaseRoutes([]);
        foreach ([
            'https://github.com/other/widget/releases/download/v1.0.0/Widget-1.0.0.zip',
            'https://github.com/acme/widget/archive/refs/tags/v1.0.0.zip',
            'https://evil.example/acme/widget/releases/download/v1.0.0/Widget-1.0.0.zip',
        ] as $url) {
            $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), $url));
            $this->assertSame("This private plugin's download is not in its source's GitHub account", $e->getMessage(), $url);
        }
        $this->assertSame(0, $this->vault->reads);
        $this->assertSame([], $this->requests);
    }

    public function testGithub401MapsToRefusedMessage(): void
    {
        foreach ([401, 403, 404] as $status) {
            $this->useDirectoryRoutes([self::ENDPOINT => static fn () => [$status, null]]);
            $e = $this->failure(fn () => $this->fetcher()->fetchDirectory($this->source()));
            $this->assertSame('GitHub refused the token for this source (check its access to acme/plugins)', $e->getMessage());
            $this->assertNull($e->getPrevious());

            $this->useReleaseRoutes([self::TAGS_URL => static fn () => [$status, null]]);
            $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
            $this->assertSame('GitHub refused the token for this source (check its access to acme/widget)', $e->getMessage());

            $this->useReleaseRoutes([
                self::TAGS_URL => self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]),
                self::ASSET_URL => static fn () => [$status, null],
            ]);
            $e = $this->failure(fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD));
            $this->assertSame('GitHub refused the token for this source (check its access to acme/widget)', $e->getMessage());
        }
    }

    public function testCanaryNeverEscapesAnyFailure(): void
    {
        $canary = 'CANARY_' . str_repeat('x', 30);
        $release = self::release([['id' => 77, 'name' => 'Widget-1.0.0.zip']]);
        $cases = [
            'vault throws' => [new \RuntimeException($canary), [self::ENDPOINT => self::json([])], []],
            'directory transport throws' => [$canary, [self::ENDPOINT => static function () use ($canary) {
                throw new \RuntimeException('transport ' . $canary);
            }], []],
            'release transport throws' => [$canary, [], [self::TAGS_URL => static function () use ($canary) {
                throw new \LogicException($canary, 0, new \RuntimeException($canary));
            }]],
            'directory 500' => [$canary, [self::ENDPOINT => self::json(['error' => $canary], 500)], []],
            'asset 500' => [$canary, [], [self::TAGS_URL => $release, self::ASSET_URL => self::json(['error' => $canary], 500)]],
            'invalid JSON' => [$canary, [self::ENDPOINT => static function ($write) use ($canary) {
                $write(null, '{"' . $canary);
                return [200, null];
            }], [self::TAGS_URL => static function ($write) use ($canary) {
                $write(null, '[' . $canary);
                return [200, null];
            }]],
            // Curl aborts the transfer (status 0) when the write callback cannot store a chunk.
            'write failure' => [$canary, [self::ENDPOINT => static function ($write) use ($canary) {
                $write(null, $canary);
                return [0, null];
            }], [self::TAGS_URL => $release, self::ASSET_URL => static function ($write) use ($canary) {
                $write(null, $canary);
                return [0, null];
            }]],
        ];

        foreach ($cases as $name => [$token, $directoryRoutes, $releaseRoutes]) {
            $this->container['vaultSecrets'] = $this->fakeVault($token);
            $operations = [];
            if ($directoryRoutes !== []) {
                $this->useDirectoryRoutes($directoryRoutes);
                $operations[] = fn () => $this->fetcher()->fetchDirectory($this->source());
            }
            if ($releaseRoutes !== []) {
                $this->useReleaseRoutes($releaseRoutes);
                $operations[] = fn () => $this->fetcher()->downloadRelease($this->source(), self::DOWNLOAD);
            }

            foreach ($operations as $operation) {
                $e = $this->failure($operation);
                $this->assertStringNotContainsString('CANARY', $e->getMessage(), $name);
                $this->assertNull($e->getPrevious(), $name);
                $this->assertStringNotContainsString('CANARY', $e->getTraceAsString(), $name);
                $this->assertStringNotContainsString('CANARY', (string) $e, $name);
            }
        }
    }
}
