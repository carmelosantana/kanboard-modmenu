<?php

use Kanboard\Plugin\ModMenu\Exception\ModMenuException;
use Kanboard\Plugin\ModMenu\Model\ArchiveDownloader;
use Kanboard\Plugin\ModMenu\Model\PluginArchive;
use PHPUnit\Framework\TestCase;

class ArchiveDownloaderTest extends TestCase
{
    public function testPublicAddressPolicy(): void
    {
        foreach ([
            '0.0.0.0', '10.255.255.255', '100.64.0.1', '100.127.255.254',
            '127.0.0.1', '127.255.255.254', '169.254.169.254', '172.16.0.1',
            '172.31.255.254', '192.168.3.4', '192.0.0.8', '192.0.2.1',
            '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1',
            '239.255.255.255', '240.0.0.1', '255.255.255.255', '::', '::1',
            'fc00::1', 'fdff::1', 'fe80::1', 'febf::1', 'ff02::1', '2001:db8::1',
            '3fff::1', '64:ff9b::7f00:1', '2002:7f00:1::1',
            '::ffff:127.0.0.1', '::ffff:192.168.3.4', '::ffff:100.64.0.1',
            '::ffff:192.0.2.1', '::ffff:224.0.0.1', 'invalid',
        ] as $ip) {
            $this->assertFalse(ArchiveDownloader::isPublicAddress($ip), $ip);
        }

        foreach (['140.82.112.3', '2606:50c0:8000::153', '::ffff:140.82.112.3'] as $ip) {
            $this->assertTrue(ArchiveDownloader::isPublicAddress($ip), $ip);
        }
    }

    public function testMixedAndEmptyAnswersAreRefusedBeforeTransport(): void
    {
        foreach ([['140.82.112.3', '192.168.3.4'], ['2606:50c0:8000::153', '::1'], []] as $answers) {
            $downloader = new ArchiveDownloader(
                fn ($host) => $answers,
                fn () => $this->fail('Transport must only receive approved hops.')
            );

            $this->assertRefused($downloader, 'The download was refused: downloads.example is not a public address.');
        }
    }

    public function testIpv4FallbackIsCheckedAlongsideDnsIpv6Answers(): void
    {
        $resolve = (new ReflectionMethod(ArchiveDownloader::class, 'resolveHost'))->getClosure();
        $downloader = new ArchiveDownloader(
            fn ($host) => $resolve(
                $host,
                function ($name, $types) {
                    $this->assertSame('downloads.example.', $name);
                    $this->assertSame(DNS_A | DNS_AAAA, $types);
                    return [['ipv6' => '2606:50c0:8000::153']];
                },
                function ($name) {
                    $this->assertSame('downloads.example', $name);
                    return ['140.82.112.3', '127.0.0.1'];
                }
            ),
            fn () => $this->fail('A private fallback address must block the download.')
        );

        $this->assertRefused($downloader, 'The download was refused: downloads.example is not a public address.');
    }

    public function testPublicIpv4FallbackIsPinnedWhenDnsHasNoARecords(): void
    {
        $resolve = (new ReflectionMethod(ArchiveDownloader::class, 'resolveHost'))->getClosure();
        $downloader = new ArchiveDownloader(
            fn ($host) => $resolve(
                $host,
                fn () => [['ipv6' => '2606:50c0:8000::153']],
                fn () => ['140.82.112.3']
            ),
            function ($url, $address, $header, $write) {
                $this->assertSame('140.82.112.3', $address);
                $write(null, 'archive');
                return [200, null];
            }
        );

        $path = $downloader->download('https://downloads.example/plugin.zip');
        try {
            $this->assertSame('archive', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testIpv4FallbackIsSkippedWhenDnsReturnsARecords(): void
    {
        $resolve = (new ReflectionMethod(ArchiveDownloader::class, 'resolveHost'))->getClosure();
        $addresses = $resolve(
            'downloads.example',
            fn () => [['ip' => '140.82.112.3'], ['ipv6' => '2606:50c0:8000::153']],
            fn () => $this->fail('IPv4 fallback must be skipped when DNS returns an A record.')
        );

        $this->assertSame(['140.82.112.3', '2606:50c0:8000::153'], $addresses);
    }

    public function testEachRedirectHopIsResolvedAndPinned(): void
    {
        $resolved = [];
        $addresses = ['downloads.example' => '140.82.112.3', 'assets.example' => '2606:50c0:8000::153'];
        $downloader = new ArchiveDownloader(
            function ($host) use (&$resolved, $addresses) {
                $resolved[] = $host;
                return [$addresses[$host]];
            },
            function ($url, $address, $header, $write) use (&$resolved) {
                if ($url === 'https://downloads.example/plugin.zip') {
                    $this->assertSame(['downloads.example'], $resolved);
                    $this->assertSame('140.82.112.3', $address);
                    $write(null, 'redirect body');
                    return [302, 'https://assets.example/plugin.zip'];
                }
                $this->assertSame(['downloads.example', 'assets.example'], $resolved);
                $this->assertSame('https://assets.example/plugin.zip', $url);
                $this->assertSame('2606:50c0:8000::153', $address);
                $write(null, 'archive');
                return [200, null];
            }
        );

        $path = $downloader->download('https://downloads.example/plugin.zip');
        try {
            $this->assertSame('archive', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testUnsafeRedirectsNeverReachTransport(): void
    {
        foreach ([
            'http://assets.example/plugin.zip' => 'The download URL must be a valid https:// URL.',
            'https://assets.example/plugin.zip' => 'The download was refused: assets.example is not a public address.',
        ] as $location => $message) {
            $calls = 0;
            $downloader = new ArchiveDownloader(
                fn ($host) => [$host === 'downloads.example' ? '140.82.112.3' : '::1'],
                function () use (&$calls, $location) {
                    $this->assertSame(1, ++$calls);
                    return [302, $location];
                }
            );

            $this->assertRefused($downloader, $message);
            $this->assertSame(1, $calls);
        }
    }

    public function testSixthRedirectIsRefused(): void
    {
        $calls = 0;
        $downloader = new ArchiveDownloader(
            fn () => ['140.82.112.3'],
            function ($url, $address) use (&$calls) {
                $this->assertSame('140.82.112.3', $address);
                return [302, 'https://downloads.example/hop-' . ++$calls];
            }
        );

        $this->assertRefused($downloader, 'Too many redirects while downloading the plugin archive.');
        $this->assertSame(6, $calls);
    }

    public function testContentLengthOverCapAbortsBeforeBody(): void
    {
        $before = $this->tempFiles();
        $downloader = new ArchiveDownloader(
            fn () => ['140.82.112.3'],
            function ($url, $address, $header) use ($before) {
                $path = $this->newTempFile($before);
                $line = 'Content-Length: ' . (PluginArchive::MAX_ARCHIVE_BYTES + 1) . "\r\n";
                $this->assertSame(0, $this->invokeCallback($header, $line));
                $this->assertSame(0, filesize($path));
                return [0, null];
            }
        );

        $this->assertRefused($downloader, 'The plugin archive is larger than 50 MB.');
        $this->assertSame($before, $this->tempFiles());
    }

    public function testStreamingAcceptsExactlyTheCapAndAbortsOnTheNextByte(): void
    {
        $before = $this->tempFiles();
        foreach ([false, true] as $oversized) {
            $downloader = new ArchiveDownloader(
                fn () => ['140.82.112.3'],
                function ($url, $address, $header, $write) use ($before, $oversized) {
                    $path = $this->newTempFile($before);
                    $chunk = str_repeat('x', 1024 * 1024);
                    for ($bytes = 0; $bytes < PluginArchive::MAX_ARCHIVE_BYTES; $bytes += strlen($chunk)) {
                        $this->assertSame(strlen($chunk), $this->invokeCallback($write, $chunk));
                    }
                    clearstatcache(true, $path);
                    $this->assertSame(PluginArchive::MAX_ARCHIVE_BYTES, filesize($path));
                    if ($oversized) {
                        $this->assertSame(0, $this->invokeCallback($write, 'x'));
                        return [0, null];
                    }
                    return [200, null];
                }
            );

            if ($oversized) {
                $this->assertRefused($downloader, 'The plugin archive is larger than 50 MB.');
            } else {
                $path = $downloader->download('https://downloads.example/plugin.zip');
                try {
                    $this->assertSame(PluginArchive::MAX_ARCHIVE_BYTES, filesize($path));
                } finally {
                    unlink($path);
                }
            }
            $this->assertSame($before, $this->tempFiles());
        }
    }

    private function invokeCallback(callable $callback, string $data): mixed
    {
        try {
            return $callback(null, $data);
        } catch (\Throwable $e) {
            $this->fail('callback must return the abort value, not throw: ' . $e->getMessage());
        }
    }

    private function assertRefused(ArchiveDownloader $downloader, string $message): void
    {
        try {
            $path = $downloader->download('https://downloads.example/plugin.zip');
            if (is_file($path)) {
                unlink($path);
            }
            $this->fail('Expected the download to be refused.');
        } catch (ModMenuException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function tempFiles(): array
    {
        return glob(sys_get_temp_dir() . '/modmenu_dl*');
    }

    private function newTempFile(array $before): string
    {
        $files = array_values(array_diff($this->tempFiles(), $before));
        $this->assertCount(1, $files);
        return $files[0];
    }
}
