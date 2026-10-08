# ModMenu — Kanboard Plugin Manager

A standalone plugin manager for Kanboard. Install from directory sources, upload
a zip, enable or disable installed plugins, detect and apply updates, and
uninstall — all from one admin settings page, with no server restart required.

> **Note:** Screenshots will be added after the companion directory and Hello Harmozi
> demo plugin are published.

---

## What it does

ModMenu adds a **Settings → ModMenu** page with four tabs:

| Tab | Purpose |
|---|---|
| **Installed** | Lists every plugin currently in `plugins/` (active) or `data/modmenu_disabled/` (disabled), with enable, disable, and remove actions. |
| **Browse** | Fetches plugin listings from the configured directory sources and shows install/update buttons for each entry. |
| **Upload** | WordPress-style zip upload — drop a `.zip` archive to install a plugin directly. |
| **Sources** | Manage the directory source URLs that Browse fetches from. Ships with one bundled default source. |

---

## Requirements

- Kanboard >= 1.2.47
- PHP >= 8.4 with the `zip` extension enabled
- The `plugins/` directory must be writable by the web-server process

If either the `zip` extension or write permission is missing, ModMenu shows an
explanatory banner on the Installed tab and disables all mutation actions.

---

## Installation

1. Download or clone this repository into your Kanboard `plugins/` directory:

   ```
   plugins/
   └── ModMenu/
       ├── Plugin.php
       ├── plugin.json
       └── ...
   ```

   The directory name **must** be `ModMenu` (case-sensitive).

2. In Kanboard, go to **Settings → ModMenu** to confirm the plugin loaded.
   No database migration is needed.

---

## Enable / Disable mechanism

ModMenu toggles plugins by **moving their folder** between two locations on disk
— no database entry, no restart:

| State | Folder location |
|---|---|
| Active | `plugins/<PluginName>/` |
| Disabled | `data/modmenu_disabled/<PluginName>/` |

Enabling a disabled plugin moves it back into `plugins/`. Because the folder is
moved (not copied or deleted), all plugin data and configuration are fully
preserved during a disable/enable cycle.

### Bind-mount caveat

When Kanboard runs in Docker with a plugin folder bind-mounted from the host
(the typical dev-suite setup), the container cannot move or delete that folder
— the OS refuses to rename or rmdir a mount point. As a result:

- **Bind-mounted plugins** (e.g. the four dev-suite plugins) **cannot be
  disabled, enabled from the disabled state, or uninstalled** via ModMenu.
  ModMenu will show an error flash when you try.
- **Zip-installed plugins** live inside the named Docker volume (`/var/www/app/plugins`
  is writable), so install, enable, disable, and uninstall all work normally for
  those.

ModMenu surfaces the appropriate error message when a folder-move fails so the
cause is clear.

---

## Self-update

ModMenu updates itself the same way it updates any other plugin: **Browse → Update**
on the ModMenu entry. Every install and update (from Browse or Upload) is a staged,
verified swap:

1. **Stage.** The archive is extracted into `plugins/.modmenu-staging-<16 hex chars>/<Name>/`.
   The staging folder sits inside `plugins/`, so the later renames stay on one
   filesystem and are atomic. Its name starts with a dot, and Kanboard's plugin
   loader skips dot folders, so a half-written copy is never loaded. The staging
   folder is always removed afterwards.
2. **Verify.** The staged copy must have a `Plugin.php` that declares the namespace
   `Kanboard\Plugin\<Name>`, every `.php` file must parse, and its `plugin.json`
   `php_version` and `compatible_version` (when present) must match this server.
   The syntax check uses the tokenizer extension, or OPcache's compiler when PHP
   lacks tokenizer (as the official Kanboard Docker image does). With neither
   available the check is skipped; a copy that then fails to load is rolled back.
   For ModMenu itself the staged version must also be newer than the installed one.
   Any failure stops here with a message, and the installed copy is untouched.
3. **Rename aside.** The current `plugins/<Name>/` is renamed to
   `plugins/.modmenu-previous-<Name>/`.
4. **Rename in.** The staged copy is renamed to `plugins/<Name>/`. If that rename
   fails, the previous copy is renamed back (if even that fails, the message names
   the `.modmenu-previous-<Name>` folder to rename back by hand). After a swap or a
   rollback, every `.php` file of the copy now in place is invalidated in opcache
   (when PHP allows it), so the next request runs the new code.
5. **Clean up.** For other plugins the previous copy is deleted straight away. For
   ModMenu it is kept until the new version has loaded: on the next page load the new
   ModMenu registers, checks that the `plugin.json` on disk matches the version that
   is running, and deletes `.modmenu-previous-ModMenu`. While that folder exists the
   Installed tab says an update was just installed. An old copy that cannot be
   deleted (for example root-owned files) is moved to `plugins/.modmenu-trash-<16 hex
   chars>/` instead, so it never blocks a later update; delete those folders on the
   host when convenient.
6. **Rollback.** If the new ModMenu throws while loading, the old copy is restored:
   the new one is parked in `plugins/.modmenu-failed-ModMenu/`,
   `.modmenu-previous-ModMenu` is renamed back to `plugins/ModMenu/`, and a critical
   message is logged. The next request loads the old version again. Only the version
   that failed is rolled back, so two requests failing at once do not undo each other.
   If there is nothing to roll back to, or the rollback itself fails (both errors are
   logged), ModMenu throws a `RuntimeException`: Kanboard's loader catches it and the
   board keeps running without ModMenu instead of going down.

**Bind mounts.** A bind-mounted plugin folder cannot be renamed, so step 3 fails:
ModMenu reports that the folder may be a bind mount or read-only, and nothing is
changed. Update bind-mounted plugins on the host.

---

## PLUGIN_INSTALLER

Kanboard has its own built-in plugin installer, switched on by `PLUGIN_INSTALLER` in
`config.php`. It is `false` by default, and many hosted boards leave it off.

ModMenu does not read that setting. It is a separate installer for administrators:
it keeps working while the plugins folder is writable and the PHP `zip` extension is
loaded, and every action is admin-only and CSRF-protected. When `PLUGIN_INSTALLER` is
off, the Installed tab shows a short note saying so, so the two are not confused.

---

## Upgrading from ModMenu < 1.3.0

ModMenu before 1.3.0 refuses to install over itself, so it cannot update itself to
1.3.0. The companion plugin
[ModMenuUpdater](https://github.com/carmelosantana/kanboard-modmenu-updater) does
that one step:

1. In **Settings → ModMenu → Browse**, install **ModMenuUpdater**.
2. Open **Settings → ModMenu Updater** and click **Update ModMenu to &lt;version&gt;**.
3. In **Settings → ModMenu → Installed**, remove **ModMenuUpdater**.

From 1.3.0 on, ModMenu updates itself from Browse and the companion is no longer needed.

---

## Security posture

| Concern | What ModMenu does |
|---|---|
| **Admin-only** | Every controller action calls `isAdmin()` at the top; non-admins receive `AccessForbiddenException`. |
| **CSRF protection** | Every mutation POST is guarded by Kanboard's standard `checkCSRFForm()`. |
| **Zip validation (size)** | Archives larger than 50 MB are rejected before opening. |
| **Zip validation (entry count)** | Archives with more than 5 000 entries are rejected. |
| **Zip validation (structure)** | Archive must contain exactly one top-level directory, and that directory must contain a `Plugin.php`. Any other structure is rejected. |
| **Path-traversal protection** | Every zip entry name is checked: entries starting with `/`, containing `..`, or containing `\` are rejected. |
| **Self-protection** | ModMenu cannot disable or uninstall itself. It updates itself only to a newer version, through the staged swap described under [Self-update](#self-update). |

---

## Directory sources and `plugins.json` format

The **Browse** tab fetches a `plugins.json` file from each configured source URL
and displays the results. The **Sources** tab lets you add or remove source URLs.
ModMenu ships with one default source; you can point it at any `https://` URL that
returns a JSON array of plugin objects.

### Adding a custom source

1. Go to **Settings → ModMenu → Sources**.
2. Enter the full `https://` URL to a `plugins.json` file and click **Add source**.
3. Switch to the **Browse** tab — ModMenu fetches all sources and merges the results
   (first source wins for duplicate plugin names).

### `plugins.json` field reference

Each entry in the JSON array is an object. All fields are optional except `name`.

| Field | Type | Description |
|---|---|---|
| `name` | string | **Required.** Must match the plugin folder name exactly (case-sensitive). Used as the unique identifier for status checks. |
| `title` | string | Human-readable display name shown in the Browse tab. Falls back to `name` if absent. |
| `author` | string | Plugin author name. |
| `description` | string | Short description shown under the title. |
| `version` | string | Semantic version string (e.g. `"1.2.0"`). Used for update detection: if this is greater than the installed version, an "Update available" badge appears. |
| `compatible_version` | string | Minimum Kanboard version (e.g. `">=1.2.47"`). Checked against the plugin's own `plugin.json` on install and update: an incompatible plugin is refused. |
| `homepage` | string | URL to the plugin's home page or repository. |
| `download` | string | URL to the `.zip` archive. ModMenu downloads this URL when the admin clicks Install or Update. |
| `screenshots` | array | List of screenshot URLs (or paths relative to the `plugins.json` URL). Displayed as thumbnails in the Browse tab. |
| `requires` | array | Hard dependencies — dep objects `{ "plugin": "Name", "min_version": "1.1.0" }`. ModMenu blocks activation until each is installed, active, and ≥ `min_version`, offering a one-click resolve. Reverse-protected: a required plugin can't be disabled/removed while an active dependent needs it. |
| `recommends` | array | Soft dependencies — same dep-object shape plus an optional `"reason"`. Non-blocking: ModMenu shows a "works better with" hint and a one-click install, but activation proceeds without them. |
| `conflicts` | array | Plugin names that should not run alongside this one (e.g. two themes), like `["ShadcnTheme"]`. Non-blocking: ModMenu warns on install/enable and flags active pairs on the Installed and Browse tabs. |

### Dependencies

A plugin declares dependencies on other plugins in its own `plugin.json` (authoritative) and, for directory listings, the same fields are mirrored into `plugins.json`:

```json
{
  "name": "DependencyPlugin",
  "requires":   [ { "plugin": "CalendarPlugin", "min_version": "1.1.0" } ],
  "recommends": [ { "plugin": "CalendarPlugin", "min_version": "1.1.0", "reason": "adds calendar badges" } ]
}
```

- **`requires`** blocks enable/install until satisfied (with a one-click resolve that installs/enables the chain), and blocks disable/uninstall of anything an active plugin still needs.
- **`recommends`** only prompts an easy install; it never blocks.
- **`conflicts`** only warns (on install, enable, and on the Installed/Browse tabs); it never blocks.
- All three are optional and backward-compatible — a plugin without them behaves exactly as before.

Example:

```json
[
  {
    "name": "BulkProjectDelete",
    "title": "Bulk Project Delete",
    "author": "Carmelo Santana",
    "description": "Delete multiple projects in one action.",
    "version": "1.0.1",
    "compatible_version": ">=1.2.47",
    "homepage": "https://github.com/carmelosantana/BulkProjectDelete",
    "download": "https://github.com/carmelosantana/BulkProjectDelete/archive/refs/heads/main.zip",
    "screenshots": ["screenshots/list.png"]
  }
]
```

---

## Development

Tests live in `Test/` and run against the Kanboard 1.2.47 core source:

```bash
# From the kanboard-plugins repo root:
./testing/run-plugin-tests.sh ModMenu
```

See [`CHANGELOG.md`](CHANGELOG.md) for what shipped in each release.

---

## License

MIT — see [LICENSE](LICENSE) for full text.
