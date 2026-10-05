# Digifix TotalCare

A WordPress plugin, installed on each client site, that runs routine maintenance by itself:

- **Scheduled backups.** WPvivid makes a full files and database backup and uploads it to Amazon S3 or an S3-compatible service.
- **Weekly safe updates** for plugins, themes and WordPress core:
  - a backup is taken first;
  - items are updated one at a time, with a site health check after each one;
  - an item that breaks the site is rolled back on its own;
  - if the site is still broken, the full backup is restored.
- **Scheduled malware scans** with Wordfence. You get an immediate alert when serious issues are found.
- **Reporting:**
  - an admin dashboard and activity log;
  - HTML emails;
  - a JSON webhook (Slack, n8n and similar);
  - a monthly client report, as a PDF or a printable page.

## Requirements

- WordPress 6.0 or later and PHP 7.4 or later. Single-site installs only; multisite is not supported.
- **WPvivid Backup & Migration** (free), tested with 0.9.136.
- **Wordfence Security** (free), tested with 9.0.2.
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
4. Go to **TotalCare → Settings** and enter the S3 details. When you save, WPvivid uploads a test file, saves the remote as its default backup destination, and turns off its own schedule.

## How it works

### Job engine
- Backups, update runs, scans and restores are stored as jobs in `wp_dtc_jobs`.
- They move forward one step at a time on a cron tick every minute, or straight away through a signed loopback request.
- Only one job runs at a time, so a backup, an update run and a scan never overlap.

### Safe updates
The steps run in this order:

`preflight → backup (local + S3) → baseline health check → for each item: snapshot → update → health check → (rollback if needed) → core → final check → report`

- **Snapshots.** Before each item is updated, its folder is copied to `wp-content/dtc-rollback/<job>/`.
- **Blocked versions.** A version that broke the site isn't installed again until a newer release comes out. Clear this list from the dashboard.
- **Health checks.** Each check is a separate HTTP request to the home page, the login page and any extra URLs you add.
  - **Hard failures** cause a rollback: a status of 500 or higher, a page that was 200 now returning an error, PHP fatal or parse errors, the WordPress "critical error" screen, a database connection error, truncated output, or a PHP fatal recorded by the guardian.
  - **Soft failures** are only logged, unless "strict" is turned on: a page title changed, or the page size changed by more than 50%.

### Guardian mu-plugin
- Stays loaded even when TotalCare is turned off or a plugin crashes every request.
- Records PHP fatal errors.
- If a fatal happens while an update is in progress, it swaps the snapshot back right away, so the site recovers even if no request can finish.
- Drives full WPvivid restores. WPvivid turns off every other plugin while it restores, including TotalCare.
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

## Testing checklist

1. **Backup:** click "Back up now", wait for a `backup.completed` event, and check that the zip is in S3.
2. **Scan:** click "Scan now", and check that the open issue count matches Wordfence → Scan.
3. **Normal update:** install an older plugin version, for example `wp plugin install hello-dolly --version=1.6 --force`, then click "Run updates now".
4. **Rollback:** serve a deliberately broken update for a test plugin through the `pre_set_site_transient_update_plugins` filter. Check that only that plugin is rolled back and that you get a `update.rolled_back` email.
5. **Full restore:** click "Restore this backup" on the dashboard, and check that the site comes back with TotalCare still active.
6. **Reports:** use Settings → "Send test email" and "Send test webhook", then Reports → View, PDF and Email.

## Known limits

- These parts use WPvivid and Wordfence internal classes, so a major update to either plugin can break them. The dashboard warns when either plugin is newer than the tested version.
- WPvivid free uploads to one remote at a time, and can't restore encrypted database backups.
- Wordfence free can't schedule scans at custom times. TotalCare starts the scans itself on its own schedule. Wordfence's own automatic scans keep running too.
- A full restore runs as a chain of short requests. If the host kills one of them without PHP shutting down cleanly, WPvivid's maintenance file can keep the site offline for up to about 30 minutes before the guardian watchdog picks the restore up again. A restore with no progress for 3 hours is marked failed and reported.
- Premium plugins without a download package (for example, an expired licence) are skipped and reported.
