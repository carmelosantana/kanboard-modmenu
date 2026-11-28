<?php

namespace Kanboard\Plugin\ModMenu\Model;

use Kanboard\Core\Base;

/**
 * Pure dependency logic for ModMenu. No filesystem or network I/O — every input
 * (installed map, directory catalog, per-plugin deps) is passed in by
 * PluginManager. Classifies a plugin's declared dependencies, builds the ordered
 * plan to satisfy hard requirements, and finds reverse dependents that block
 * removal.
 *
 * A "dep object" is ['plugin' => string, 'min_version' => ?string, 'reason' => ?string].
 * A plugin declares two arrays of them: `requires` (hard) and `recommends` (soft).
 *
 * A private dependant passes its source id: its dependencies then resolve only
 * from entries of that source or from installed plugins, and anything else is
 * unresolvable ("Install it yourself first").
 */
class DependencyResolver extends Base
{
    public const INSTALL_IT_YOURSELF = 'Install it yourself first';

    /** The catalog a dependant from $sourceId may resolve from (all of it when public). */
    public static function catalogFor(array $catalog, ?string $sourceId): array
    {
        if ($sourceId === null) {
            return $catalog;
        }
        return array_filter($catalog, static fn ($entry) => is_array($entry)
            && ! empty($entry['private']) && ($entry['source_id'] ?? null) === $sourceId);
    }

    /**
     * The source id of a private catalog entry, or null for a public one. A
     * private entry without a valid id gets '', a scope nothing resolves from.
     */
    private static function sourceOf($entry): ?string
    {
        if (! is_array($entry) || empty($entry['private'])) {
            return null;
        }
        $id = $entry['source_id'] ?? null;
        return is_string($id) && preg_match('/\A[0-9a-f]{64}\z/', $id) === 1 ? $id : '';
    }

    /**
     * Prefer an installed successor, with an active one ahead of a disabled one.
     */
    public static function successorFor(string $name, array $installedMap, array $catalog): ?string
    {
        if ($name === '') {
            return null;
        }

        $candidates = [];
        foreach ([$installedMap, $catalog] as $entries) {
            $deprecated = PluginManager::normalizeDeprecated(self::entry($entries, $name)['deprecated'] ?? null);
            $successor = $deprecated['superseded_by'] ?? null;
            if ($successor !== null && ! PluginName::same($successor, $name)) {
                $candidates[$successor] = true;
            }
            foreach ($entries as $candidate => $entry) {
                if (! PluginName::same((string) $candidate, $name) && self::listsName(PluginManager::normalizeReplaces($entry['replaces'] ?? []), $name)) {
                    $candidates[$candidate] = true;
                }
            }
        }

        $installed = null;
        $listed = null;
        foreach (array_keys($candidates) as $candidate) {
            $folder = PluginName::find($installedMap, (string) $candidate);
            if ($folder !== null && ($installedMap[$folder]['status'] ?? null) === 'active') {
                return $folder;
            }
            if ($folder !== null) {
                $installed ??= $folder;
            }
            $listed ??= PluginName::find($catalog, (string) $candidate);
        }
        return $installed ?? $listed;
    }

    public static function isSatisfied(array $dep, array $installedMap): bool
    {
        return self::classify($dep, $installedMap, [])['status'] === 'satisfied';
    }

    /**
     * Classify one dependency into a status + the action needed to satisfy it.
     */
    public static function classify(array $dep, array $installedMap, array $catalog): array
    {
        $name   = (string) ($dep['plugin'] ?? '');
        $min    = isset($dep['min_version']) && $dep['min_version'] !== '' ? (string) $dep['min_version'] : null;
        $reason = (string) ($dep['reason'] ?? '');

        // Names compare by PluginName::key(); the installed folder (else the
        // catalog name) is kept for display and for the plan.
        $successor = null;
        $folder    = PluginName::find($installedMap, $name);
        $display   = $folder ?? PluginName::find($catalog, $name) ?? $name;
        $installed = $folder === null ? null : $installedMap[$folder];
        if (($installed['status'] ?? null) !== 'active') {
            $successor = self::successorFor($name, $installedMap, $catalog);
            // Preserve the action for an installed original unless its successor is already active.
            if ($successor !== null && $installed !== null && (self::entry($installedMap, $successor)['status'] ?? null) !== 'active') {
                $successor = null;
            }
        }
        if ($successor !== null) {
            $installed = self::entry($installedMap, $successor);
            $min = null;
        }

        $installedVersion = $installed['version'] ?? null;
        $catalogEntry     = self::entry($catalog, $successor ?? $name);
        $download         = $catalogEntry['download'] ?? null;
        $catalogVersion   = $catalogEntry['version'] ?? null;

        $catalogMeetsMin = $download !== null
            && ($min === null || version_compare((string) $catalogVersion, $min, '>='));

        if ($installed === null) {
            $status = 'missing';
            $action = $download !== null ? 'install' : 'unresolvable';
        } elseif (($installed['status'] ?? '') !== 'active') {
            $status = 'disabled';
            if ($min !== null && ! version_compare((string) $installedVersion, $min, '>=')) {
                // Present but too old even once enabled → needs a newer copy.
                $action = $catalogMeetsMin ? 'update' : 'unresolvable';
            } else {
                $action = 'enable';
            }
        } elseif ($min !== null && ! version_compare((string) $installedVersion, $min, '>=')) {
            $status = 'outdated';
            $action = $catalogMeetsMin ? 'update' : 'unresolvable';
        } else {
            $status = 'satisfied';
            $action = 'none';
        }

        $result = [
            'plugin'            => $successor === null ? $display : ($status === 'satisfied' ? $name : $successor),
            'status'            => $status,
            'action'            => $action,
            'installed_version' => $installedVersion,
            'min_version'       => $min,
            'reason'            => $reason,
            'download'          => in_array($action, ['install', 'update'], true) ? $download : null,
        ];
        if ($successor !== null) {
            $result['satisfied_by'] = $successor;
        }
        return $result;
    }

    /**
     * Resolve a plugin's declared deps of one kind ('requires' | 'recommends').
     */
    public function resolveForward(array $deps, string $kind, array $installedMap, array $catalog, ?string $sourceId = null): array
    {
        $catalog = self::catalogFor($catalog, $sourceId);
        $resolved  = [];
        $satisfied = true;
        foreach ($deps as $dep) {
            if (! is_array($dep) || empty($dep['plugin'])) {
                continue; // defensive: ignore malformed entries
            }
            $c = self::classify($dep, $installedMap, $catalog);
            $c['kind'] = $kind;
            if ($c['status'] !== 'satisfied') {
                $satisfied = false;
            }
            $resolved[] = $c;
        }
        return ['satisfied' => $satisfied, 'deps' => $resolved];
    }

    /**
     * Which ACTIVE installed plugins hard-require $target and are satisfied by it
     * today? Only `requires` count; `recommends` never block removal. A dependent
     * whose requirement is already unmet is not a blocker (nothing to break).
     *
     * @param array $installedPluginsDeps  name => ['status'=>..., 'requires'=>[dep objects]]
     */
    public function resolveReverse(string $target, array $installedPluginsDeps, array $installedMap): array
    {
        $blockers = [];
        foreach ($installedPluginsDeps as $name => $info) {
            if (PluginName::same((string) $name, $target) || ($info['status'] ?? '') !== 'active') {
                continue;
            }
            foreach (($info['requires'] ?? []) as $dep) {
                if (! is_array($dep)) {
                    continue;
                }
                $c = self::classify($dep, $installedMap, []);
                if ($c['status'] === 'satisfied' && PluginName::same((string) ($c['satisfied_by'] ?? $c['plugin']), $target)) {
                    $blockers[] = ['plugin' => (string) $name, 'min_version' => $dep['min_version'] ?? null];
                }
            }
        }
        return $blockers;
    }

    /**
     * Build the ordered plan (deps-first, deduped) needed to satisfy a plugin's
     * hard `requires`, transitively. Satisfied deps are omitted. A required dep
     * that cannot be auto-resolved appears with action 'unresolvable' so the
     * caller blocks instead of half-installing.
     *
     * @param array $requires  the starting plugin's `requires` list
     * @return array list of ['plugin','action','download','min_version']
     */
    public function resolveClosure(array $requires, array $installedMap, array $catalog, ?string $sourceId = null): array
    {
        $plan = [];
        $seen = [];
        $this->walk($requires, $installedMap, $catalog, $plan, $seen, $sourceId);
        return array_values($plan);
    }

    private function walk(array $requires, array $installedMap, array $fullCatalog, array &$plan, array &$seen, ?string $sourceId): void
    {
        $catalog = self::catalogFor($fullCatalog, $sourceId);
        foreach ($requires as $dep) {
            if (! is_array($dep) || empty($dep['plugin'])) {
                continue;
            }
            $name = (string) $dep['plugin'];
            // One visit per name and scope, so a public visit never hides a private one.
            $visit = ($sourceId ?? '-') . '|' . (PluginName::key($name) ?? $name);
            if (isset($seen[$visit])) {
                continue;
            }
            $seen[$visit] = true;

            $c = self::classify($dep, $installedMap, $catalog);
            if ($c['status'] === 'satisfied') {
                continue;
            }
            $target = $c['plugin'];

            // Recurse into this dep's own requires (from the catalog) FIRST, so the
            // plan is deps-first (post-order): grandchildren before children.
            $targetEntry = self::entry($catalog, $target);
            $childRequires = $targetEntry['requires'] ?? [];
            if (is_array($childRequires) && $childRequires !== []) {
                $this->walk($childRequires, $installedMap, $fullCatalog, $plan, $seen, self::sourceOf($targetEntry));
            }

            // A requirement naming the successor directly can impose its own version floor.
            if (isset($c['satisfied_by'], $plan[$target])) {
                continue;
            }
            $step = [
                'plugin'      => $target,
                'action'      => $c['action'],
                'download'    => $c['download'],
                'min_version' => $c['min_version'],
            ];
            if ($sourceId !== null && $c['action'] === 'unresolvable') {
                $step['reason'] = self::INSTALL_IT_YOURSELF;
            }
            // Unresolvable in any scope stays unresolvable, whichever order the scopes ran in.
            if (($plan[$target]['action'] ?? null) === 'unresolvable' && $step['action'] !== 'unresolvable') {
                continue;
            }
            $plan[$target] = $step;
        }
    }

    /** The entry of $map that is the same plugin as $name, or null. */
    private static function entry(array $map, string $name): ?array
    {
        $found = PluginName::find($map, $name);
        $entry = $found === null ? null : $map[$found];

        return is_array($entry) ? $entry : null;
    }

    private static function listsName(array $names, string $name): bool
    {
        foreach ($names as $listed) {
            if (PluginName::same((string) $listed, $name)) {
                return true;
            }
        }

        return false;
    }
}
