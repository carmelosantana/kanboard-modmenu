# Changelog

All notable changes to ModMenu are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## 1.7.0 — Unreleased

### Added

- **Private sources.** Add a `plugins.json` from a private GitHub repository with a Vault reference to a GitHub token (`vault://<entry>/token`, shared with Server for consumer `ModMenu`). Its plugins show a **Private** tag and install, update and resolve dependencies like public ones. The token is read from Vault for each request and is never stored, logged or shown by ModMenu. Private entries need a `sha256` and a `github.com` release-asset download in the directory's owner.
- **Adopt, Dismiss and Unbind.** Installed plugins a private source lists are bound to it only when you click **Adopt**; **Dismiss** keeps one updating from public sources, and **Unbind** releases a bound plugin. A bound plugin updates only from its source.
- **Reset private state.** If the private-state file is missing or damaged, ModMenu stops changing plugins until you reset it from the Sources tab.

### Changed

- While any private source is unreachable, nothing installs or updates, public plugins included. Removing a source, Unbind, uninstall and Reset still work.
- With a private source configured, a plugin name listed by more than one source is removed from Browse and reported, instead of first source wins.
- Plugin names are compared case-insensitively. Directory entries whose names are not letters, digits and `_` (at most 64) are left out and reported as source errors, public sources included, and duplicates are found regardless of case. An entry installs or updates only a folder spelled exactly as its `name`; a case variant is refused.
- Installed plugin folders whose names are not letters, digits and `_` can no longer be changed through ModMenu (Kanboard cannot load them either); remove them by hand.
- Source URLs are stored in a normal form (lowercase scheme and host, no `:443`), and a new spelling of a URL already in the list is refused as a duplicate; URLs with userinfo or a fragment are refused.
- A source list saved by an earlier version with two spellings of one URL now fails closed: both show "Duplicate source; remove and re-add it" until you remove one, with or without private sources.
- Adding or removing a public source now creates `DATA_DIR/modmenu` (mode 0700) for the catalog lock.
- Downloads for migrations and dependency installs now run while source edits wait, so adding or removing a source can pause until a running download finishes.

### Security and reliability

- Installing a plugin over an installed folder whose name differs only in case is refused.
- When two installed folders share a name apart from case, neither is offered updates until one is removed.
- A `#fragment` in any download or source URL is refused, not only on redirects.
- Plugin lock files are named after the lowercased plugin name, so `Foo` and `foo` share one lock. During the upgrade, an operation already running under 1.6.0 on a mixed-case plugin does not block a new one.

---

## 1.6.0 — 2026-11-25

### Added

- Plugins and directory entries can declare `replaces` and `deprecated`. ModMenu treats a successor and the plugin it replaces as conflicting. An active successor can satisfy dependencies on the old name.
- Installed deprecated plugins show their successor and offer **Migrate**. ModMenu installs the successor if needed, disables the old plugin, and enables the successor. The old plugin stays installed.
- Browse hides deprecated plugins by default. Search for the exact plugin name to find and install one.

### Security and reliability

- Plugin downloads only reach public HTTPS hosts and stop at the 50 MB limit while downloading.
- Plugin downloads connect directly and no longer use Kanboard's HTTP proxy settings.
- Concurrent installs cannot create two copies of the same plugin.
- **Migrate** makes changes only after the migration is confirmed and restores the previous plugin state if a step fails.

---

## 1.5.0 — 2026-11-20

### Added

- **Categories.** Each Browse card shows its category, and a category menu (with counts) narrows the list. Entries without a `category` are filed under **Other**, so other directories keep working.
- **Search.** Type to drill down: every word must appear in the title, name, description, author or category.
- **Sort.** Last updated (default, newest first), First submitted (oldest first) or Author (A–Z); entries without the date or author sort last, and ties sort by title, then name. Without JavaScript the list shows in the default order and the toolbar stays hidden.
- A "Showing N of M" line and a "No plugins match." empty state.
- Cards show "Updated YYYY-MM-DD" when the directory gives `last_updated`.
- Directory fields `category`, `added` and `last_updated` (dates must be `YYYY-MM-DD`, else ignored).

### Changed

- **Requirement states.** Every required plugin shows its state on Browse and Installed: a green check when met, an amber "Update needed" warning when installed but older than `min_version`, red when missing or disabled. The Installed tab now lists met requirements too.

---

## 1.4.2 — 2026-11-17

### Fixed
- Update all no longer compiles staged copies with OPcache, so a batch update can no longer fatal; the updates list is tidier.

---

## 1.4.1 — 2026-11-17

### Fixed
- Installs and updates are tied to their directory entry; kanboard_version is honoured; archive checks are tighter.

## [1.4.0] — 2026-11-17

### Added

- **Update all.** Browse shows **Update all (N)** when active plugins have newer versions in the directory. Each one goes through the verified swap; a failure does not stop the rest, and the result names what was updated and what failed. ModMenu itself goes last and returns you to the Installed tab, like a single self-update.
- **Conflict prompt.** Enabling or installing a plugin while a conflicting plugin is active now asks first: "X conflicts with A, B. Disable them and continue?" Confirm disables them, then carries on into the usual dependency flow; Cancel leaves X inactive. For an install, the directory entry's `conflicts` count as well. The dependency-confirm step refuses while a conflict is active, so the prompt cannot be skipped.

### Changed

- Required-plugin and conflict notices are callouts (tinted background, border, accent, warning icon, bold label) whose text keeps the theme's colour, so they read on light and dark themes. "Recommends" stays a quiet line.
- Browse screenshots are uniform 16:10 thumbnails in a wrapping row; each opens the full image in a new tab.

### Fixed

- A form action that read more than one posted field got `''` from the second read (core `getValues()` strips the CSRF token as it reads), so the dependency-confirm step always ran as "enable", even for an install. Posted fields are now read once per request.

---

## [1.3.3] — 2026-11-12

### Fixed

- Clean URLs (`/config/modmenu`, `/config/modmenu/directory`, …) now open ModMenu. With URL rewriting on they returned "Action not implemented" or the dashboard, because the routes were registered without their plugin; only the `?controller=…` form worked. ModMenu's own links and forms now use the clean addresses.

## [1.3.2] — 2026-10-08

### Fixed

- Hidden working folders (`.modmenu-*`) left by an update are no longer listed as plugins on the Installed tab.

## [1.3.1] — 2026-10-08

### Fixed

- Self-update works on PHP builds without the tokenizer extension (e.g. the official Kanboard Docker image) by falling back to OPcache's compiler for the pre-swap syntax check.

## [1.3.0] — 2026-10-08

### Added

- **Self-update.** ModMenu updates itself from Browse → Update. The new copy must be newer than the installed one; the previous copy is kept in `plugins/.modmenu-previous-ModMenu/` until the new version has loaded, then deleted. If the new version fails to load, the previous copy is restored and the failed one parked in `plugins/.modmenu-failed-ModMenu/`. A load failure that cannot be rolled back surfaces as a `RuntimeException`, which Kanboard logs while the board stays up.
- **Upgrade path from older versions.** ModMenu before 1.3.0 cannot install over itself; the companion plugin ModMenuUpdater performs that one update (see the README).

### Changed

- **Every plugin install and update is a verified, staged swap with rollback.** The archive is extracted to a dot-prefixed staging folder inside `plugins/`, checked (`Plugin.php` namespace, every `.php` file parses, `php_version` and `compatible_version`), then swapped in by rename. If the new copy cannot be moved in, the previous copy is put back. A bind-mounted plugin folder is reported and left unchanged. The swapped-in files are invalidated in opcache, and an old copy that cannot be deleted is moved to `plugins/.modmenu-trash-<hex>/` instead of blocking later updates.
- **PLUGIN_INSTALLER note.** When Kanboard's built-in plugin installer is off (the default), the Installed tab explains that ModMenu is a separate, admin-only installer that does not depend on that setting.
- The Installed tab's ModMenu card now points to Browse for updates.

---

## [1.2.1] — 2026-10-08

### Fixed

- **Badges readable on dark themes.** The Installed and Disabled badges now set their own text colour, so a dark theme's light body text no longer lands on their light background.

---

## [1.2.0] — 2026-10-08

### Added

- **Plugin conflicts.** A plugin can list `conflicts` (plugin names) in `plugin.json`, mirrored in the directory `plugins.json`. ModMenu warns when you install or enable a plugin whose conflict is active, and flags conflicting pairs on the Installed and Browse tabs. It never blocks.

---

## [1.1.0] — 2026-07-09

### Added

- **Plugin dependency system.** Plugins declare `requires` (hard) and `recommends` (soft) dependencies in `plugin.json` (mirrored in the directory `plugins.json`).
  - `requires` blocks enable/install until each dependency is installed, active, and ≥ its `min_version`, with a one-click resolve that installs/enables the whole chain (transitive, deps-first).
  - Reverse protection: a plugin an active dependent hard-requires cannot be disabled or uninstalled — ModMenu names the dependents instead.
  - `recommends` surfaces a non-blocking "works better with" hint plus one-click install on the Installed and Browse tabs.
- New pure `DependencyResolver` model (classify / transitive plan / reverse dependents), fully unit-tested.

---

## [1.0.1] — 2026-07-03

### Fixed

- **List bullets removed** from the Sources list (`ul.modmenu-sources`), which
  rendered default disc bullets in the left margin outside the card content.

---

## [1.0.0] — 2026-07-03

### Added

- Standalone admin plugin manager with four tabs: Installed, Browse, Upload, Sources.
- Install from a directory source (multiple sources; ships a bundled default).
- WordPress-style `.zip` upload with safe validation (single top-level dir + Plugin.php, path-traversal + size/entry caps).
- Enable/Disable by moving a plugin folder between `plugins/` and `data/modmenu_disabled/` (data preserved; no restart).
- Update detection ("update available" badge) via installed-vs-directory version compare, with one-click update.
- Uninstall with a typed confirmation modal.
- Self-protection: ModMenu can never disable or uninstall itself.
- PHPUnit suite: PluginArchive, PluginManager, SourceRepository, DirectoryClient, controller admin gates.
