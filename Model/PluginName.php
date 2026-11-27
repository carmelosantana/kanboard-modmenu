<?php

namespace Kanboard\Plugin\ModMenu\Model;

/**
 * The single identity for a plugin name: catalog keys, collisions, bindings,
 * the installed map, dependencies and lock files all compare by key().
 */
final class PluginName
{
    /**
     * ASCII-lowercase key of a valid plugin name, or null when the name is not
     * 1-64 ASCII letters, digits or underscores.
     */
    public static function key(string $name): ?string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1) {
            return null;
        }

        return strtolower($name);
    }

    /**
     * Whether two names are the same plugin: equal keys, or byte-equal when
     * either is not a valid name.
     */
    public static function same(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $key = self::key($a);

        return $key !== null && $key === self::key($b);
    }

    /**
     * The key of $map (installed folder or catalog name, as stored) that is
     * the same plugin as $name: the exact key first, else the first by key().
     * Null when none.
     */
    public static function find(array $map, string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        if (array_key_exists($name, $map)) {
            return $name;
        }
        if (self::key($name) === null) {
            return null;
        }
        foreach (array_keys($map) as $candidate) {
            if (self::same((string) $candidate, $name)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
