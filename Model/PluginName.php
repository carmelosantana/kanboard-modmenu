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
}
