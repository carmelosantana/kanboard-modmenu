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
| **Browse** | Fetches plugin listings from the configured directory sources and shows install/update buttons for each entry, linked screenshot thumbnails, and an **Update all (N)** button when installed plugins have updates. A toolbar filters by search and category and sorts the list. |
| **Upload** | WordPress-style zip upload — drop a `.zip` archive to install a plugin directly. |
| **Sources** | Manage the directory source URLs that Browse fetches from, including [private sources](#private-sources). Ships with one bundled default source. |

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

## Update all

When the directory has newer versions of active plugins, Browse shows **Update all (N)**
with the list of `from → to` versions. It updates each one through the same verified swap
as a single update and keeps going past a failure; the result lists what was updated and
what failed. ModMenu itself, if outdated, is updated last.

## Browsing the directory

Browse lists entries newest update first. Above the cards, a toolbar narrows and re-orders
the list without reloading the page:

- **Search** — type to drill down. Every word you type must appear in the plugin's title,
  name, description, author or category (case-insensitive).
- **Category** — one category at a time, each with its count. Entries without a
  `category` are filed under **Other**.
- **Sort** — **Last updated** (newest first, the default), **First submitted** (oldest
  first) or **Author** (A–Z). Entries without the date or author sort last; ties sort by title, then name.

A "Showing N of M" line follows the filter, with "No plugins match." when nothing does.
Each card shows its category and, when known, the date it was last updated. The toolbar
needs JavaScript; without it Browse shows non-deprecated entries in the default order and no
controls.

### Requirement states

Each `requires` entry shows its state on the Browse and Installed tabs:

| State | Callout |
|---|---|
| Installed, active, new enough | Green check — **Requirement met:** `Dep 1.2.0 (≥ 1.0.0)` |
| Installed but older than `min_version` | Amber warning — **Update needed:** `Dep — 0.9.0 installed, needs ≥ 1.0.0` (Installed offers **Update via Browse**) |
| Not installed | Red — **Requires:** / **Missing requirement:** (Installed offers **Install**) |
| Installed but disabled | Red — **Requirement disabled:** (Installed offers **Enable**) |

## Renamed and deprecated plugins

A successor declares the folder names it replaces in its `plugin.json` (for example,
`"replaces": ["ShadcnTheme"]` in Harbor). The old plugin declares its successor in
its own `plugin.json` (for example,
`"deprecated": {"superseded_by": "Harbor", "since": "1.3.0", "reason": "Renamed to Harbor"}`
in ShadcnTheme). Directory entries carry the same fields as the corresponding manifests.

ModMenu treats the old plugin and its successor as conflicting. An active successor
can satisfy a `requires` or `recommends` entry for the old name. The old entry's
`min_version` applies only to the old plugin.

On **Installed**, a deprecated plugin shows **Replaced by** and a **Migrate** button.
When you confirm, ModMenu installs the successor if needed, disables the old plugin,
and enables the successor. ModMenu never uninstalls plugins on its own. The old plugin
stays installed but disabled.

Deprecated plugins are hidden from **Browse** by default. Enter the exact plugin
folder name in search to show its card and install it.

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
   `php_version` and `kanboard_version` (or the older `compatible_version`, when present) must match this server.
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
| **Private-source tokens** | The GitHub token lives only in Vault. ModMenu reads it for each request, sends it only to `raw.githubusercontent.com` and `api.github.com` on the first hop, and never stores, logs or shows it. See [Private sources](#private-sources). |
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
3. Switch to the **Browse** tab. ModMenu merges results from `https://` sources
   (first source wins for duplicate plugin names). Re-add any saved `http://` sources over HTTPS.

ModMenu stores a source URL in a normal form: lowercase scheme and host, no `:443`.
Two spellings of one URL count as the same source, and a URL with userinfo or a
`#fragment` is refused. Once a [private source](#private-sources) is configured, a plugin
name listed by more than one source is no longer first-wins: it is removed from Browse and
shown as a source error naming the plugin.

### `plugins.json` field reference

Each entry in the JSON array is an object. All fields are optional except `name`.

| Field | Type | Description |
|---|---|---|
| `name` | string | **Required.** Must match the plugin folder name exactly (case-sensitive) and use only letters, digits and `_` (at most 64). ModMenu compares names case-insensitively, so `Foo` and `foo` are the same plugin. An entry with any other name is left out and reported as a source error. |
| `title` | string | Human-readable display name shown in the Browse tab. Falls back to `name` if absent. |
| `category` | string | Browse category (e.g. `"Reporting"`). Any string is shown; a missing or empty one files the entry under `Other`. |
| `added` | string | `YYYY-MM-DD` the entry first appeared in the directory. Drives the **First submitted** sort. Ignored unless it is `YYYY-MM-DD`. |
| `last_updated` | string | `YYYY-MM-DD` the listed `version` was published. Drives the default **Last updated** sort and the card's "Updated" date. Ignored unless it is `YYYY-MM-DD`. |
| `author` | string | Plugin author name. |
| `description` | string | Short description shown under the title. |
| `version` | string | Semantic version string (e.g. `"1.2.0"`). Used for update detection: if this is greater than the installed version, an "Update available" badge appears. |
| `kanboard_version` (or `compatible_version`) | string | Minimum Kanboard version (e.g. `">=1.2.47"`). Checked against the plugin's own `plugin.json` on install and update: an incompatible plugin is refused. |
| `homepage` | string | URL to the plugin's home page or repository. |
| `download` | string | URL to the `.zip` archive. ModMenu downloads this URL when the admin clicks Install or Update. |
| `sha256` | string | 64-digit hexadecimal SHA-256 of the `download` archive. If present, the archive must match it before installation. Optional in public directories, required in a private one. |
| `screenshots` | array | List of screenshot URLs (or paths relative to the `plugins.json` URL). Displayed as thumbnails in the Browse tab. |
| `requires` | array | Hard dependencies — dep objects `{ "plugin": "Name", "min_version": "1.1.0" }`. ModMenu blocks activation until each is installed, active, and ≥ `min_version`, offering a one-click resolve. Reverse-protected: a required plugin can't be disabled/removed while an active dependent needs it. |
| `recommends` | array | Soft dependencies — same dep-object shape plus an optional `"reason"`. Non-blocking: ModMenu shows a "works better with" hint and a one-click install, but activation proceeds without them. |
| `conflicts` | array | Plugin names that should not run alongside this one (e.g. two themes), like `["ShadcnTheme"]`. Enabling or installing a plugin while a conflicting one is active asks first; ModMenu also flags active pairs on the Installed and Browse tabs. |

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
- **`conflicts`** asks before enable/install: "X conflicts with A, B. Disable them and continue?" Confirm disables them and carries on (dependency resolution included); Cancel leaves X inactive. Either side can declare the conflict, and for an install the directory entry's `conflicts` count too. Updates of already-active plugins are not gated; the Installed and Browse tabs flag active pairs.
- All three are optional and backward-compatible — a plugin without them behaves exactly as before.

Example:

```json
[
  {
    "name": "BulkProjectDelete",
    "title": "Bulk Project Delete",
    "category": "Administration",
    "author": "Carmelo Santana",
    "description": "Delete multiple projects in one action.",
    "version": "1.0.1",
    "added": "2026-07-03",
    "last_updated": "2026-11-17",
    "compatible_version": ">=1.2.47",
    "homepage": "https://github.com/carmelosantana/BulkProjectDelete",
    "download": "https://github.com/carmelosantana/BulkProjectDelete/archive/refs/heads/main.zip",
    "screenshots": ["screenshots/list.png"]
  }
]
```

---

## Private sources

A private source is a `plugins.json` in a private GitHub repository. Its plugins show
up in Browse with a **Private** tag and install, update and resolve dependencies like any
other plugin. ModMenu reads the GitHub token from the [Vault](https://github.com/carmelosantana/kanboard-vault)
plugin for each request that needs it. The token is never stored in Kanboard's settings,
and ModMenu never logs it, shows it or puts it in an error message.

With no private source configured, ModMenu works exactly as before.

### Setting one up

1. **Create the private directory.** Make a private GitHub repository holding a
   `plugins.json` in the [usual format](#pluginsjson-field-reference), with two extra rules
   for its entries:
   - `sha256` is required.
   - `download` must be a release asset on `github.com` in the same owner as the
     directory repository:
     `https://github.com/<owner>/<repo>/releases/download/<tag>/<asset>.zip`.
2. **Create a token.** On GitHub, make a fine-grained personal access token with
   **Contents: read** only, on the directory repository and the private plugin
   repositories, and nothing else.
3. **Store it in Vault.** Add a Vault entry with a custom field named `token` holding the
   token. Open the entry's sharing dialog and share it with **Server**: consumer `ModMenu`,
   field `token`, and a rate that covers your heaviest operation (see [Vault reads](#vault-reads)).
   Server sharing must be turned on in Vault.
4. **Add the source.** Go to **Settings → ModMenu → Sources**. Under **Private sources**,
   fill in **Private directory URL (raw.githubusercontent.com)** with the raw URL of the
   file, for example
   `https://raw.githubusercontent.com/<owner>/<repo>/main/plugins.json`, and **Vault
   reference to the GitHub token** with `vault://<entry id>/token`. Click **Add private
   source**. ModMenu fetches the directory once before saving it; if that fetch fails,
   nothing is added and the message says why.
5. **Adopt hand-installed plugins.** If a plugin the source lists is already installed (for
   example one you installed from a zip), it appears under the source with an **Adopt**
   button. Adopt binds it to the source so it updates from there; **Dismiss** keeps it
   updating from public sources. Until you choose, ModMenu does not update it.

Each private source row shows its token reference and what Vault says about it: **shared
with Server**, **not shared with Server** or **Vault off**. Adding a source reads the token
for real, so a wrong consumer or field fails the add and nothing is saved. Vault cannot
tell ModMenu in advance whether `ModMenu` is still an allowed consumer, so a sharing change
made later only shows up as an error on the next Browse.

### Adoption is explicit

ModMenu never binds a plugin to a private source by itself. A bound plugin installs and
updates only from its source, and a public entry with the same name is ignored for it. If
the source is removed or cannot be reached, the plugin shows **Private source unavailable**
and is not updated. **Unbind** (under the source's **Bound plugins**) releases it. If the
source still lists it, the plugin goes back to waiting for a choice: **Adopt** it again or
**Dismiss** it to update from public sources. If the source was removed, it updates from
public sources. Removing a private source keeps its bindings, so you can add it back
without adopting again.

A private plugin's `requires` install only from the same private source. A requirement
that would come from anywhere else shows **Install it yourself first**; an
already-installed plugin, public or private, satisfies it.

### When a private source is down

While any private source cannot be reached, ModMenu installs and updates nothing, public
plugins included, and Browse says so. Browse still lists public plugins. ModMenu cannot
check a down directory for a plugin name that collides with a public one, so it waits
rather than guess.

Remove source, Unbind, uninstall and **Reset private state** still work while a source is
down, so a revoked token or a deleted repository never locks you in.

### Vault reads

ModMenu asks Vault for the token once per operation on one source: once per directory
fetch, and once per private plugin download. Vault limits reads per hour and per burst on
the Server share, so set them to cover your heaviest operation.

These actions fetch every private source once, one read each: Browse; install (from Browse,
or by URL once private state exists); update; **Update all**; enable; upload, once private
state exists; **Adopt**; **Dismiss**; the **Migrate** confirmation and the migration
itself; confirming a dependency plan; and answering a conflict prompt. Adding a private
source reads only that source's token once. The Sources page, **Unbind**, removing a
source and **Reset private state** read nothing. Each private plugin download reads once
more, so **Update all** adds one read for each private plugin it updates. For example, with one private source and five private plugins,
a full Update all needs about six reads at once; set the burst at least that high and the
hourly rate with room for Browse page loads. A refused read shows Vault's reason, for example
"ModMenu reached the token's Vault rate limit. Try again later".

### Trust limit

Vault's consumer name is a label, not a password. Any plugin running in the same
Kanboard can ask Vault for the `ModMenu` token, so only install plugins you trust, and keep
the token read-only and scoped to the private directory repository and the private plugin
repositories. If you suspect it leaked, revoke it on GitHub and make a new one.

### What the checksum proves

A private entry's `sha256` catches a corrupted or swapped download. It is not a
signature: anyone who can edit the private directory can change both the download URL and
the checksum, so the directory repository and the token's scope are what you are
trusting. Treat release tags as fixed; if you re-upload an asset under the same tag, its
checksum no longer matches until you update the directory.

### Damaged state

ModMenu keeps private sources, bindings and dismissals in
`data/modmenu/private-state.json` (no secrets). If that file is missing or damaged,
ModMenu stops changing plugins, Browse shows public sources only, and the Sources tab
offers **Reset private state**. Reset sets the old file aside, drops every private
source, binding and dismissal, and leaves installed plugins as they are; add your private
sources again afterwards. If the `data/modmenu` folder itself has the wrong owner or
permissions, fix it by hand (owned by the web server user, folder `0700`, files `0600`).

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
