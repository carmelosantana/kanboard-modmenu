<?php

namespace Kanboard\Plugin\ModMenu\Model;

/**
 * Canonical form and id of a public source URL. The canonical URL is what is
 * stored, hashed and compared for duplicates.
 */
final class SourceUrl
{
    /**
     * https://<host>[:port]<path>[?query] with scheme and host lowercased and a
     * :443 port dropped; path and query are kept byte-for-byte. Null when the
     * URL is not https, has no host, or carries userinfo or a fragment.
     */
    public static function canonical(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)) {
            return null;
        }

        $canonical = 'https://'.strtolower($parts['host']);

        if (isset($parts['port']) && $parts['port'] !== 443) {
            $canonical .= ':'.$parts['port'];
        }

        $canonical .= $parts['path'] ?? '';

        if (array_key_exists('query', $parts)) {
            $canonical .= '?'.$parts['query'];
        }

        return $canonical;
    }

    /**
     * A source's id: full hex sha256 of its canonical URL.
     */
    public static function id(string $canonical): string
    {
        return hash('sha256', $canonical);
    }
}
