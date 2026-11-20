# Changelog

All notable changes to ModMenu are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
