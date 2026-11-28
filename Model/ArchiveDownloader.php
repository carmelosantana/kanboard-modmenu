<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Closure;
use JsonException;
use Kanboard\Core\Http\Client;
use Kanboard\Plugin\ModMenu\Exception\ModMenuException;

/** Downloads plugin archives and directory listings over checked HTTPS connections. */
class ArchiveDownloader
{
    private const MAX_REDIRECTS = 5;
    private const MAX_DIRECTORY_BYTES = 5 * 1024 * 1024;

    private Closure $resolver;
    private ?Closure $transport;
    private Closure $clock;
    private int $lastStatus = 0;
    private int $lastHop = 0;

    /**
     * @param callable(string): string[]|null $resolver Resolves all A and AAAA addresses.
     * @param callable|null $transport Receives URL, pinned IP, curl header and write callbacks, request headers,
     *                                 and total and connect timeouts in milliseconds.
     *                                 Returns [status, absolute redirect URL or null], with status 0 on transfer failure.
     * @param callable(): int|null $clock Monotonic time in milliseconds.
     */
    public function __construct(?callable $resolver = null, ?callable $transport = null, ?callable $clock = null)
    {
        $this->resolver = Closure::fromCallable($resolver ?? static fn (string $host): array =>
            self::resolveHost($host, \dns_get_record(...), \gethostbynamel(...)));
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
        $this->clock = Closure::fromCallable($clock ?? static fn (): int => (int) (hrtime(true) / 1_000_000));
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

    /** The caller owns the returned temporary file. */
    public function download(string $url): string
    {
        return $this->downloadWith($url, [], []);
    }

    /**
     * The caller owns the returned temporary file.
     *
     * @param string[] $firstHopHeaders Sent on the first request only, never on a redirect.
     * @param string[] $headers Sent on every request.
     */
    public function downloadWith(string $url, #[\SensitiveParameter] array $firstHopHeaders, array $headers): string
    {
        return $this->downloadToTemp(
            $url,
            PluginArchive::MAX_ARCHIVE_BYTES,
            t('The plugin archive is larger than 50 MB.'),
            t('Unable to download the plugin archive.'),
            timeout: 120_000,
            connectTimeout: 10_000,
            headers: $headers,
            firstHopHeaders: $firstHopHeaders
        );
    }

    public function fetchJson(string $url): array
    {
        return $this->fetchJsonWith($url, [], self::MAX_DIRECTORY_BYTES, true, ['Accept: application/json']);
    }

    /**
     * @param string[] $firstHopHeaders Sent on the first request only, never on a redirect.
     * @param string[] $headers Sent on every request.
     * @param bool $followRedirects When false, any 3xx response is a failure.
     * @param bool $assoc When false, a top-level JSON object comes back as an
     *   array of its members with nested objects left as \stdClass, so a JSON
     *   object stays distinguishable from a JSON array ([] when not an object).
     */
    public function fetchJsonWith(
        string $url,
        #[\SensitiveParameter] array $firstHopHeaders,
        int $maxBytes,
        bool $followRedirects,
        array $headers,
        bool $assoc = true
    ): array {
        $failureMessage = e("Couldn't load this directory listing.");
        $path = $this->downloadToTemp(
            $url,
            $maxBytes,
            t('The directory listing is larger than %d MB.', intdiv($maxBytes, 1024 * 1024)),
            $failureMessage,
            timeout: 15_000,
            connectTimeout: 5_000,
            headers: $headers,
            firstHopHeaders: $firstHopHeaders,
            followRedirects: $followRedirects
        );
        try {
            $body = @file_get_contents($path);
            if ($body === false) {
                throw new ModMenuException($failureMessage);
            }
            if (! $assoc) {
                $json = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
                return $json instanceof \stdClass ? get_object_vars($json) : [];
            }
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return is_array($json) ? $json : [];
        } catch (JsonException $e) {
            throw new ModMenuException(t('This directory listing is not valid JSON.'), 0, $e);
        } finally {
            @unlink($path);
        }
    }

    private function downloadToTemp(
        string $url,
        int $maxBytes,
        string $oversizeMessage,
        string $failureMessage,
        int $timeout,
        int $connectTimeout,
        array $headers = [],
        #[\SensitiveParameter] array $firstHopHeaders = [],
        bool $followRedirects = true
    ): string {
        $this->lastStatus = 0;
        $this->lastHop = 0;
        $deadline = ($this->clock)() + $timeout;
        $remaining = fn (): int => $deadline - ($this->clock)();
        $url = self::checkedUrl($url);
        if ($this->transport === null && ! extension_loaded('curl')) {
            throw new ModMenuException(t('Plugin downloads need the PHP curl extension.'));
        }
        $transport = $this->transport ?? self::request(...);

        $path = @tempnam(sys_get_temp_dir(), 'modmenu_dl');
        if ($path === false) {
            throw new ModMenuException($failureMessage);
        }

        $file = @fopen($path, 'wb');
        $keep = false;
        try {
            if ($file === false) {
                throw new ModMenuException($failureMessage);
            }

            $bytes = 0;
            $bodyBytes = 0;
            $failure = null;
            // Returning a short count stops curl immediately. Report the cause after the transfer.
            $canContinue = static function () use ($remaining, $failureMessage, &$failure): bool {
                if ($remaining() <= 0) {
                    $failure ??= $failureMessage;
                }
                return $failure === null;
            };
            $header = static function ($curl, string $line) use ($canContinue, $maxBytes, $oversizeMessage, &$bytes, &$failure): int {
                if (! $canContinue()) {
                    return 0;
                }
                if (preg_match('/^Content-Length:[ \t]*([0-9]+)[ \t]*\r?\n$/iD', $line, $matches)
                    && (int) $matches[1] > $maxBytes - $bytes) {
                    $failure = $oversizeMessage;
                    return 0;
                }
                return strlen($line);
            };
            $write = static function ($curl, string $chunk) use ($canContinue, $file, $maxBytes, $oversizeMessage, $failureMessage, &$bytes, &$bodyBytes, &$failure): int {
                if (! $canContinue()) {
                    return 0;
                }
                $length = strlen($chunk);
                if ($length > $maxBytes - $bytes) {
                    $failure = $oversizeMessage;
                    return 0;
                }
                if (@fwrite($file, $chunk) !== $length) {
                    $failure = $failureMessage;
                    return 0;
                }
                $bytes += $length;
                $bodyBytes += $length;
                return $length;
            };

            for ($redirects = 0; ; $redirects++) {
                if ($remaining() <= 0) {
                    throw new ModMenuException($failureMessage);
                }
                $address = $this->checkedAddress(parse_url($url, PHP_URL_HOST));
                $timeLeft = $remaining();
                if ($timeLeft <= 0) {
                    throw new ModMenuException($failureMessage);
                }
                // A redirect never carries the first hop's headers, even back to the same host.
                $hopHeaders = $redirects === 0 ? array_merge($headers, $firstHopHeaders) : $headers;
                [$status, $location] = $transport($url, $address, $header, $write, $hopHeaders, $timeLeft, min($connectTimeout, $timeLeft));
                unset($hopHeaders);
                $this->lastStatus = (int) $status;
                $this->lastHop = $redirects;
                if (! $canContinue()) {
                    throw new ModMenuException($failure);
                }

                if ($status >= 200 && $status < 300 && $bodyBytes > 0) {
                    $keep = true;
                    return $path;
                }
                if (! $followRedirects
                    || ! in_array($status, [301, 302, 303, 307, 308], true) || $location === null || $location === '') {
                    throw new ModMenuException($failureMessage);
                }
                if ($redirects === self::MAX_REDIRECTS) {
                    throw new ModMenuException(t('Too many redirects while downloading the plugin archive.'));
                }

                $url = self::checkedUrl($location);
                if ($bodyBytes > 0 && (! @ftruncate($file, 0) || ! @rewind($file))) {
                    throw new ModMenuException($failureMessage);
                }
                $bodyBytes = 0;
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

    /** The status of the most recent request, or 0 before any request of the latest download. */
    public function lastStatus(): int
    {
        return $this->lastStatus;
    }

    /** The index of the most recent request of the latest download: 0 for the first hop, 1 after one redirect, … */
    public function lastHop(): int
    {
        return $this->lastHop;
    }

    private static function checkedUrl(string $url): string
    {
        $parts = filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : [];
        if (str_contains($url, '#') || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443
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

    private static function resolveHost(string $host, callable $lookupRecords, callable $lookupIpv4): array
    {
        $records = @$lookupRecords($host . '.', DNS_A | DNS_AAAA) ?: [];
        $ipv4 = array_column($records, 'ip');
        if ($ipv4 === []) {
            $ipv4 = @$lookupIpv4($host) ?: [];
        }
        return array_merge($ipv4, array_column($records, 'ipv6'));
    }

    private static function request(
        string $url,
        string $address,
        callable $header,
        callable $write,
        #[\SensitiveParameter] array $headers,
        int $timeout,
        int $connectTimeout
    ): array {
        $curl = curl_init($url);
        if ($curl === false) {
            return [0, null];
        }

        try {
            if (! curl_setopt_array($curl, self::curlOptions($url, $address, $header, $write, $headers, $timeout, $connectTimeout))
                || curl_exec($curl) === false) {
                return [0, null];
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

    protected static function curlOptions(
        string $url,
        string $address,
        callable $header,
        callable $write,
        #[\SensitiveParameter] array $headers,
        int $timeout,
        int $connectTimeout
    ): array {
        $host = parse_url($url, PHP_URL_HOST);
        $options = [
            // Verbose output would copy request headers, including any token, to stderr.
            CURLOPT_VERBOSE => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            // A proxy would resolve the destination independently of the pin.
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeout,
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_USERAGENT => Client::HTTP_USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => $header,
            CURLOPT_WRITEFUNCTION => $write,
        ];
        // For DNS names, pin the validated IP while keeping the URL host for TLS.
        // IP literals already identify the checked endpoint without a lookup.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
            $options[CURLOPT_RESOLVE] = [$host . ':443:' . $target];
        }

        return $options;
    }
}
