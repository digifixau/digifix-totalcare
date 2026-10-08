# Digifix TotalCare

A WordPress plugin, installed on each client site, that runs routine maintenance by itself:

- **Scheduled backups** to Amazon S3, Cloudflare R2 or another S3-compatible service, with TotalCare's own engine:
  - a full backup once a week (configurable), and in between only the files that changed; the database is saved in full every time;
  - your own retention rules (daily, weekly and monthly copies), with no limit on how many backups are kept;
  - built to use as little of the server as possible (see [Backup engine](#backup-engine)).
- **Restores** of the whole site or only parts of it (the database, plugins, themes, uploads, or single folders), onto the same site, onto a fresh install, or onto a new domain.
- **Weekly safe updates** for plugins, themes and WordPress core:
  - a backup is taken first;
  - items are updated one at a time, with a site health check after each one;
  - an item that breaks the site is rolled back on its own;
  - if the site is still broken, the database and code are restored from that backup.
- **Scheduled malware scans** with Wordfence. You get an immediate alert when serious issues are found.
- **Reporting:**
  - an admin dashboard and activity log;
  - HTML emails;
  - a JSON webhook (Slack, n8n and similar);
  - a monthly client report, as a PDF or a printable page.

## Requirements

- WordPress 6.0 or later and PHP 7.4 or later. Single-site installs only; multisite is not supported.
- PHP extensions `zlib`, `mysqli`, `hash`, `json`, and `openssl` or `sodium` (the dashboard checks them). MySQL or MariaDB through `mysqli`; SQLite and custom database drop-ins are not supported.
- A bucket on Amazon S3, Cloudflare R2 or another S3-compatible service, with a key that can upload, download, list and delete. Each site needs its own folder in the bucket. Don't add lifecycle rules that expire objects: an incremental backup still needs older files.
- **Wordfence Security** (free), tested with 9.0.2.
- WPvivid is no longer needed. Sites that used it keep it as a fallback engine until it's uninstalled (see [Moving from WPvivid](#moving-from-wpvivid)).
- WordPress must be able to write files directly (`FS_METHOD` is `direct`, which is the default on most hosts).
- A real cron job is strongly recommended. WP-Cron only runs when the site gets visitors.

  ```
  # crontab: every minute
  * * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
  ```
  Then add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

## Install

1. Copy the `digifix-totalcare` folder to `wp-content/plugins/`.
2. Optional, for PDF reports: run `composer install --no-dev` in the plugin folder. A release zip should include `vendor/`.
3. Activate the plugin. Activation:
   - creates the tables `wp_dtc_jobs` and `wp_dtc_events`;
   - creates `wp-content/dtc-data/` and `wp-content/dtc-rollback/`;
   - installs the guardian mu-plugin, `wp-content/mu-plugins/dtc-guardian.php`.
     If `mu-plugins` is read-only (on some Hostinger plans it links to a host-managed folder), the guardian is loaded instead through the WordPress drop-in `wp-content/fatal-error-handler.php`, from `wp-content/dtc-data/dtc-guardian.php`. If another plugin already uses that drop-in, the dashboard shows the guardian as missing.
   - adds a `noabort` rule for LiteSpeed to `.htaccess` (only applies under LiteSpeed; see [Background requests](#background-requests)).
4. Go to **TotalCare → Settings → Remote storage** and enter the bucket details. When you save, TotalCare tests upload, download, list, multipart upload and delete with a small file. The secret key is stored encrypted, with a key kept in `wp-content/dtc-data/secret.php`. You can also define `DTC_S3_SECRET` in `wp-config.php` instead.

## Releasing an update

Sites update themselves from GitHub Releases. They need version 1.0.4 or later installed once by hand; after that, updates are automatic.

1. Bump the version in `digifix-totalcare.php`, in both the `Version:` header and `DTC_VERSION`.
2. Commit, tag and push:
   ```bash
   git commit -am "Release 1.0.5"
   git tag v1.0.5
   git push origin main
   git push origin v1.0.5   # push the tag on its own; pushing it with the branch can skip the release run
   ```
3. GitHub Actions (`.github/workflows/release.yml`):
   - lints the PHP with PHP 7.4;
   - checks the tag matches the version in the plugin file;
   - runs `composer install --no-dev` (dompdf);
   - builds `digifix-totalcare.zip`;
   - publishes a GitHub Release with the zip attached.
4. Each site checks GitHub for a new release at most every 6 hours. Go to **Dashboard → Updates → Check again** to check immediately.
   - The new version shows like any other plugin update.
   - With "Install new TotalCare releases from GitHub automatically" turned on (Settings → Safe updates, on by default), WordPress's background updater installs it within about 12 hours.
   - It never installs while a TotalCare job or restore is running.

Every push to `main` also lints the code and uploads a test zip as a workflow artifact, without releasing it.

## How it works

### Job engine
- Backups, update runs, scans and restores are stored as jobs in `wp_dtc_jobs`.
- They move forward one step at a time on a cron tick every minute, or straight away through a signed loopback request.
- Only one job runs at a time, so a backup, an update run and a scan never overlap.

- Each request does at most "Work per request" seconds of work (Settings, default 25) and then saves its progress, so hosts that stop PHP after 30 seconds don't interrupt anything. When work is left, the next request starts straight away; **Low impact** mode waits for the next cron minute instead.
- A step that the server kills 5 times in a row without making progress fails the job (lower "Work per request" if that happens).
- Cancelling a job removes what it left behind: unfinished uploads, partial backups and work files.

### Background requests
TotalCare's background work runs in "loopback" requests that the site sends to itself and doesn't wait for. LiteSpeed (used by Hostinger) normally kills such a request when the sender disconnects, so TotalCare adds this to `.htaccess`:

```
# BEGIN Digifix TotalCare
<IfModule LiteSpeed>
RewriteEngine On
RewriteRule ^(wp-admin/admin-ajax\.php|wp-cron\.php)$ - [E=noabort:1,E=noconntimeout:1]
</IfModule>
# END Digifix TotalCare
```

### Backup engine
A backup runs in this order: `preflight → database → files → finalize → retention`.

- **Remote layout** (folder defaults to the site's domain):
  ```
  <folder>/site.json                   which site owns the folder
  <folder>/catalog.json                list of backups (a cache, rebuilt from the manifests when needed)
  <folder>/backups/<id>/manifest.json  written last: a backup without it is incomplete
  <folder>/backups/<id>/db.sql.gz      database
  <folder>/backups/<id>/core.tar.gz    and content, plugins, themes, muplugins, uploads (only parts with changes)
  <folder>/backups/<id>/index.tsv.gz   every file at that moment, and which backup holds its content
  <folder>/backups/<id>/members.tsv.gz byte offsets and SHA-256 of each archive chunk
  ```
  IDs sort by time: `20261008-020000-fs-a1b2`. `f` means full and `i` incremental; `s` means scheduled, `m` manual and `u` before updates.
- **Archives are ordinary `.tar.gz` and `.sql.gz` files.** You can open them with any tool, for example `tar xzf uploads.tar.gz`. Internally each file is a chain of gzip "members". A member ends at every request boundary, and none is larger than 16 MB compressed, so work resumes without extra state and a restore can fetch just the byte ranges it needs.
- **Incrementals.** Files are compared with the previous backup by size and modification time; files aren't hashed. A full backup is taken every *N* days (Settings), and also whenever the exclusions change, the previous backup's chain is missing from storage, or there is no earlier backup on the site.
- **Resource use:**
  - **Memory:** constant whatever the site's size. Folders are read one at a time, database rows in batches of about 2 MB, and files in 1 MB chunks.
  - **Disk:** about 16 MB of spool space. Archive data is uploaded in 8 MiB parts as soon as each part is full, and no local copy of the backup is kept.
  - **CPU:** media and other already-compressed formats are stored without recompressing. Text and SQL use fast compression (level 3). Database tables are read page by page on their key, without OFFSET.
- **Database:** every table with the site's prefix, except TotalCare's own (`dtc_jobs`, `dtc_events`) and transients. Views, triggers and stored routines are not backed up; the backup warns when it finds any.
- **Default exclusions** (editable): cache folders, other backup plugins' folders, `node_modules`, `.git`, logs. `wp-content/dtc-data` and `dtc-rollback` are never backed up.
- **Folder ownership.** `site.json` records the home URL. A site whose address doesn't match refuses to write or prune in that folder, so a staging copy can't overwrite or delete the live site's backups. Use **Backups → Take over this folder** after a real domain change.
- **Retention** runs after each backup and once a day. It keeps:
  - the newest *N* backups;
  - every backup from the last *A* days;
  - one per day, week and month for the configured periods (weekly and monthly picks prefer full backups);
  - pre-update backups for *P* days;
  - pinned backups.

  Every backup that a kept backup depends on is also kept. Incomplete backups older than two days and abandoned multipart uploads are removed.

### Restores
Restores are driven by the guardian, before regular plugins and the theme load. A broken plugin can't stop a restore, and nothing needs to be deactivated. The guardian loads a copy of the restore engine pinned in `wp-content/dtc-data/engine/<version>/`, so a broken TotalCare update can't stop one either.

`preflight → plan → database import → files → clean → swap → finish`

- **Plan.** Only files that differ from the site (by size and modification time) are restored, and only the archive chunks that contain them are downloaded and checked against their SHA-256.
- **Database.** It is imported into temporary tables (`dtcr_*`) while the site stays online. They then replace the live tables in one atomic `RENAME TABLE`. The replaced tables are kept as `dtcold_*` until the restored site passes its health check, or for 24 hours.
- **Maintenance page.** It is shown only while code files and tables are being switched; uploads are restored while the site is up. The guardian serves the maintenance page itself (HTTP 503, not cached), and every request that reaches it also restarts a stalled restore. The admin notice links to a progress page that keeps working during maintenance.
- **Never restored:**
  - TotalCare itself, the guardian and `dtc-data`;
  - `wp-config.php`;
  - `.htaccess`, unless you tick the option;
  - on another server, also host-specific files: `object-cache.php`, `advanced-cache.php`, `db.php`, `.user.ini`, `php.ini`, `wordfence-waf.php` and `wflogs`.
- **Clean.** For full restores, core, plugin and theme files that aren't in the backup are removed (excluded paths are kept). This undoes injected files and half-installed updates.
- **New domain or fresh install.** On a fresh install:
  1. Install WordPress and TotalCare.
  2. Enter the bucket details.
  3. Go to **Backups → Other sites in this bucket** and pick the old site's folder.

  URLs and server paths are rewritten everywhere in the database: plain, JSON-escaped and URL-encoded forms, inside serialized data (string lengths are fixed), with `guid` left alone. A different table prefix is handled too. Afterwards you log in with the old site's users. This site's storage settings are kept.
- **Automatic restore after a failed update** restores the database, core, plugins, themes and mu-plugins, but not uploads.

### Safe updates
The steps run in this order:

`preflight → backup (incremental, to remote storage) → baseline health check → for each item: snapshot → update → health check → (rollback if needed) → core → final check → report`

- **Snapshots.** Before each item is updated, its folder is copied to `wp-content/dtc-rollback/<job>/`.
- **Blocked versions.** A version that broke the site isn't installed again until a newer release comes out. Clear this list from the dashboard.
- **Health checks.** Each check is a separate HTTP request to the home page, the login page and any extra URLs you add.
  - **Hard failures** cause a rollback: a status of 500 or higher, a page that was 200 now returning an error, PHP fatal or parse errors, the WordPress "critical error" screen, a database connection error, truncated output, or a PHP fatal recorded by the guardian.
  - **Soft failures** are only logged, unless "strict" is turned on: a page title changed, or the page size changed by more than 50%.

### Guardian mu-plugin
- Stays loaded even when TotalCare is turned off or a plugin crashes every request.
- Records PHP fatal errors.
- If a fatal happens while an update is in progress, it swaps the snapshot back right away, so the site recovers even if no request can finish.
- Drives restores before other plugins load, serves the maintenance page during a restore, and restarts a stalled restore (see [Restores](#restores)).
- State is kept in `wp-content/dtc-data/`, not in the database, so it survives the restore.
- TotalCare's own tables are excluded from backups, so a restore doesn't rewind the job or the log.

## Webhook format

```json
POST <webhook url>
X-DTC-Event: update.rolled_back
X-DTC-Signature: sha256=<hmac of body with the webhook secret>

{ "event": "update.rolled_back", "site": "…", "site_url": "…", "client": "…",
  "time": "2026-10-05T03:12:00+00:00", "job_id": 42, "data": { … } }
```

Events:

| Area | Events |
|---|---|
| Backups | `backup.completed`, `backup.failed` |
| Updates | `update.completed`, `update.failed`, `update.rolled_back`, `update.restored` |
| Restores | `restore.completed`, `restore.failed` |
| Scans | `scan.completed`, `scan.failed`, `scan.issues_found` |
| Reports | `report.monthly`, `test` |

## WP-CLI

```
wp totalcare backup [--full]          take a backup now and wait for it
wp totalcare list [--folder=<f>]      list backups (another site's folder with --folder)
wp totalcare restore <id> [--scope=db,core,plugins,themes,muplugins,content,uploads]
                     [--paths=wp-content/plugins/woocommerce] [--clean] [--htaccess] [--folder=<f>] [--yes]
wp totalcare verify <id>              download every archive chunk and check checksums and decompression
wp totalcare prune                    apply the retention settings now
```

## Moving from WPvivid

- Updating to 1.1.0 copies the S3 key from WPvivid once and switches backups to the built-in engine. The first new backup is a full one.
- Old WPvivid backups stay where they are and can still be restored from WPvivid, including from Cloudflare R2. While WPvivid is active, **Settings → Backup engine** can switch a site back to WPvivid.
- Once a site has enough built-in backups, uninstall WPvivid and delete its old backups from the bucket by hand.

## Testing checklist

1. **Backup:** click "Back up now", wait for a `backup.completed` event, and check the backup in **Backups**. Run `wp totalcare verify <id>`.
2. **Scan:** click "Scan now", and check that the open issue count matches Wordfence → Scan.
3. **Normal update:** install an older plugin version, for example `wp plugin install hello-dolly --version=1.6 --force`, then click "Run updates now".
4. **Rollback:** serve a deliberately broken update for a test plugin through the `pre_set_site_transient_update_plugins` filter. Check that only that plugin is rolled back and that you get a `update.rolled_back` email.
5. **Full restore:** on **Backups**, restore a backup with every part ticked. Check that the site comes back, TotalCare is still active and the replaced `dtcold_*` tables are gone after the health check.
6. **Partial restore:** restore only a single plugin folder, then only the database.
7. **Migration:** on a fresh install with another domain and table prefix, restore the first site's backup from **Backups → Other sites in this bucket**.
8. **Reports:** use Settings → "Send test email" and "Send test webhook", then Reports → View, PDF and Email.

## Known limits

- The scan integration uses Wordfence internal classes, so a major Wordfence update can break it. The dashboard warns when Wordfence is newer than the tested version. The legacy WPvivid engine has the same caveat.
- The database dump runs across several requests, so it isn't a single consistent snapshot. This is the same as WPvivid; it matters only for sites with heavy writes during the backup.
- Change detection uses size and modification time. A file changed without either changing is only picked up by the next full backup.
- Empty folders aren't backed up. Views, triggers and stored routines aren't backed up.
- If WordPress core itself can't start (for example, a half-copied core update), the guardian can't run a restore. Restore from WP-CLI on a working copy, or by hand from the `.tar.gz` and `.sql.gz` files.
- The S3 secret is encrypted with a key on the same server. This protects database dumps and exports, not a compromised server.
- Wordfence free can't schedule scans at custom times. TotalCare starts the scans itself on its own schedule. Wordfence's own automatic scans keep running too.
- A restore runs as a chain of short requests. If loopback requests are blocked (basic auth on a staging site, a firewall), the restore advances only when someone visits the site, or the progress page, or runs `wp totalcare restore`. A restore with no progress for 3 hours is marked failed and reported.
- Premium plugins without a download package (for example, an expired licence) are skipped and reported.
