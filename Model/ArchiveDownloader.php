<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Closure;
use Kanboard\Core\Http\Client;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/** Downloads one archive to a temporary file owned by the caller on success. */
class ArchiveDownloader
{
    private const MAX_REDIRECTS = 5;

    private Closure $resolver;
    private ?Closure $transport;

    /**
     * @param callable(string): string[]|null $resolver Resolves all A and AAAA addresses.
     * @param callable|null $transport Receives URL, pinned IP, length and write callbacks.
     *                                 Returns [status, absolute redirect URL or null].
     */
    public function __construct(?callable $resolver = null, ?callable $transport = null)
    {
        $this->resolver = Closure::fromCallable($resolver ?? self::resolve(...));
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function isPublicAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return self::isPublicAddress(inet_ntop(substr($packed, 12)));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $first = ord($packed[0]);
        if (strlen($packed) === 4) {
            // PHP's global-range filter allows multicast and the retired 6to4 relay prefix.
            return $first < 224 && ! str_starts_with($packed, "\xc0\x58\x63");
        }

        // Use global unicast space, excluding the newer 3fff::/20 documentation prefix.
        return ($first & 0xe0) === 0x20
            && ! (str_starts_with($packed, "\x3f\xff") && (ord($packed[2]) & 0xf0) === 0);
    }

    public function download(string $url): string
    {
        $url = self::checkedUrl($url);
        if ($this->transport === null && ! extension_loaded('curl')) {
            throw new ModMenuException(t('Plugin downloads need the PHP curl extension.'));
        }
        $transport = $this->transport ?? self::request(...);

        $path = @tempnam(sys_get_temp_dir(), 'modmenu_dl');
        if ($path === false) {
            throw new ModMenuException(t('Unable to download the plugin archive.'));
        }

        $file = @fopen($path, 'wb');
        $keep = false;
        try {
            if ($file === false) {
                throw new ModMenuException(t('Unable to download the plugin archive.'));
            }

            $bytes = 0;
            $checkLength = static function (string $length): void {
                if ((int) $length > PluginArchive::MAX_ARCHIVE_BYTES) {
                    throw new ModMenuException(t('The plugin archive is larger than 50 MB.'));
                }
            };
            $write = static function (string $chunk) use ($file, &$bytes): void {
                $length = strlen($chunk);
                if ($length > PluginArchive::MAX_ARCHIVE_BYTES - $bytes) {
                    throw new ModMenuException(t('The plugin archive is larger than 50 MB.'));
                }
                if (@fwrite($file, $chunk) !== $length) {
                    throw new ModMenuException(t('Unable to download the plugin archive.'));
                }
                $bytes += $length;
            };

            for ($redirects = 0; ; $redirects++) {
                $address = $this->checkedAddress(parse_url($url, PHP_URL_HOST));
                [$status, $location] = $transport($url, $address, $checkLength, $write);

                if ($status >= 200 && $status < 300 && $bytes > 0) {
                    $keep = true;
                    return $path;
                }
                if (! in_array($status, [301, 302, 303, 307, 308], true) || $location === null || $location === '') {
                    throw new ModMenuException(t('Unable to download the plugin archive.'));
                }
                if ($redirects === self::MAX_REDIRECTS) {
                    throw new ModMenuException(t('Too many redirects while downloading the plugin archive.'));
                }

                $url = self::checkedUrl($location);
                if ($bytes > 0 && (! @ftruncate($file, 0) || ! @rewind($file))) {
                    throw new ModMenuException(t('Unable to download the plugin archive.'));
                }
                $bytes = 0;
            }
        } finally {
            if ($file !== false) {
                fclose($file);
            }
            if (! $keep) {
                @unlink($path);
            }
        }
    }

    private static function checkedUrl(string $url): string
    {
        $parts = filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : [];
        if (strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new ModMenuException(t('The download URL must be a valid https:// URL.'));
        }

        $host = strtolower($parts['host']);
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $address = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);
        if ($address === false && (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]*)$/D', $host))) {
            // Only canonical IP literals and DNS names reach curl's URL parser.
            throw new ModMenuException(t('The download URL must be a valid https:// URL.'));
        }

        return 'https://' . $host . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function checkedAddress(string $host): string
    {
        $literal = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);
        $addresses = $literal === false ? ($this->resolver)($host) : [$literal];

        if ($addresses === [] || array_filter($addresses, self::isPublicAddress(...)) !== $addresses) {
            throw new ModMenuException(t('The download was refused: %s is not a public address.', $host));
        }
        return reset($addresses);
    }

    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host . '.', DNS_A | DNS_AAAA) ?: [];
        return array_merge(array_column($records, 'ip'), array_column($records, 'ipv6'));
    }

    private static function request(string $url, string $address, callable $checkLength, callable $write): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ModMenuException(t('Unable to download the plugin archive.'));
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            // A proxy would resolve the destination independently of the pin.
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => Client::HTTP_USER_AGENT,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use ($checkLength): int {
                if (preg_match('/^Content-Length:[ \t]*([0-9]+)[ \t]*\r?\n$/iD', $line, $matches)) {
                    $checkLength($matches[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($write): int {
                $write($chunk);
                return strlen($chunk);
            },
        ];
        // For DNS names, pin the validated IP while keeping the URL host for TLS.
        // IP literals already identify the checked endpoint without a lookup.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $options[CURLOPT_RESOLVE] = [$host . ':443:' . $target];
        }

        try {
            if (! curl_setopt_array($curl, $options) || curl_exec($curl) === false) {
                throw new ModMenuException(t('Unable to download the plugin archive.'));
            }

            return [
                curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                // Curl resolves relative Location values without making another request.
                curl_getinfo($curl, CURLINFO_REDIRECT_URL) ?: null,
            ];
        } finally {
            curl_close($curl);
        }
    }
}
