=== Digifix TotalCare ===
Contributors: digifix
Tags: backup, restore, updates, maintenance, s3
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Incremental backups to S3 or Cloudflare R2, one-click restores, safe updates with automatic rollback, Wordfence scans and client reports.

== Description ==

Digifix TotalCare runs a site's routine maintenance on its own and tells you what it did.

**Backups to your own S3-compatible storage**

* Works with Amazon S3, Cloudflare R2 and other S3-compatible services (Wasabi, DigitalOcean Spaces, MinIO and similar).
* A full backup once a week (configurable) and only the changed files in between. The database is saved in full every time.
* Your own retention rules: the newest N backups, daily, weekly and monthly copies, and pinned backups that are never deleted. There is no limit on how many backups are kept.
* Built for cheap shared hosting. Memory use stays the same whatever the size of the site, about 16 MB of disk space is used while a backup runs, and each request does a limited amount of work before handing over to the next one.
* Archives are ordinary `.tar.gz` and `.sql.gz` files that you can open with any tool.

**Restores**

* Restore the whole site or only parts of it: the database, plugins, themes, uploads or single folders.
* Restore onto the same site, onto a fresh install, or onto a new domain. URLs and paths are rewritten everywhere in the database, including serialized data, and a different table prefix is handled.
* The database is imported into temporary tables while the site stays online, then swapped in at once.
* Restores are driven before other plugins load, so a broken plugin can't stop a restore.

**Safe updates**

* Plugins, themes and WordPress core are updated on a schedule, one item at a time.
* A backup is taken first, and the site's health is checked after each item.
* An item that breaks the site is rolled back on its own, and that version is skipped until a newer release comes out.
* If the site is still broken, the database and code are restored from the backup taken before the run.

**Malware scans**

* Starts Wordfence scans on TotalCare's own schedule and alerts you at once when serious issues are found. This needs the free Wordfence Security plugin.

**Reporting**

* A dashboard and an activity log in WordPress admin.
* HTML emails for failures and summaries, to you and, if you choose, to your client.
* A signed JSON webhook for Slack, n8n and similar tools.
* A monthly client report, as a PDF or a printable page.

**WP-CLI**

`wp totalcare backup`, `wp totalcare list`, `wp totalcare restore`, `wp totalcare verify` and `wp totalcare prune`. Run `wp help totalcare` for the options.

= What TotalCare adds to your site =

So that you know exactly what changes on the server:

* Two database tables, `{prefix}digifix_tc_jobs` and `{prefix}digifix_tc_events`, for the job queue and the activity log.
* Two folders, `wp-content/digifix-tc-data/` (settings for the guardian, work files and an encryption key) and `wp-content/digifix-tc-rollback/` (copies of plugins and themes taken just before they are updated). Work files containing database data are kept in a folder with a random, unguessable name.
* A small "guardian" must-use plugin, `wp-content/mu-plugins/digifix-tc-guardian.php`. It records PHP fatal errors, rolls back an update that crashes the site, and drives restores. Where `mu-plugins` is read-only, it is loaded through the `wp-content/fatal-error-handler.php` drop-in instead, unless another plugin already uses that drop-in.
* On Apache-compatible servers, a short rule in `.htaccess`, between `# BEGIN Digifix TotalCare` and `# END Digifix TotalCare` markers. It only takes effect on LiteSpeed servers, where it stops background requests to `admin-ajax.php` and `wp-cron.php` from being cut off.

Deactivating the plugin removes the guardian and the `.htaccess` rule (unless a restore is still running). Deleting the plugin also removes its tables, options and folders. Backups in your storage are never deleted when you remove the plugin.

= Requirements =

* A single-site WordPress install. Multisite is not supported.
* MySQL or MariaDB through `mysqli`. SQLite and custom database drop-ins are not supported.
* The PHP extensions `zlib`, `mysqli`, `hash`, `json`, and `openssl` or `sodium`. The dashboard checks them for you.
* WordPress must be able to write files directly (`FS_METHOD` set to `direct`, the default on most hosts).
* A bucket on an S3-compatible service, with a key that can upload, download, list and delete objects. Give each site its own folder, and don't add lifecycle rules that expire objects, because incremental backups still need older files.
* For malware scans: the free [Wordfence Security](https://wordpress.org/plugins/wordfence/) plugin.

= Source code and bundled libraries =

The full source code, including the build and release workflow, is on GitHub: [github.com/digifixau/digifix-totalcare](https://github.com/digifixau/digifix-totalcare).

PDF reports use these libraries, installed unmodified with Composer (see `composer.json`) and shipped in the `vendor` folder:

* [Dompdf](https://github.com/dompdf/dompdf) (LGPL-2.1), with the DejaVu fonts it includes (Bitstream Vera / DejaVu font licence)
* [php-font-lib](https://github.com/dompdf/php-font-lib) (LGPL-2.1-or-later)
* [php-svg-lib](https://github.com/dompdf/php-svg-lib) (LGPL-3.0-or-later)
* [HTML5-PHP](https://github.com/Masterminds/html5-php) (MIT)
* [PHP CSS Parser](https://github.com/MyIntervals/PHP-CSS-Parser) (MIT)

= External services =

TotalCare connects to the services below. It sends no data anywhere else and has no tracking or telemetry.

**Your S3-compatible storage (Amazon S3, Cloudflare R2 or another provider you choose)**

TotalCare's backups are stored in the bucket you enter under TotalCare → Settings → Remote storage. Nothing is sent until you enter these details.

* What is sent: backup archives of your site, which contain the database and the site's files, plus small index and manifest files. They are sent with your access key, over HTTPS unless you enter an `http://` endpoint yourself.
* When: when you save the storage settings (a small test file is uploaded and deleted), during each backup, when backups are listed or pruned by retention, and when you restore or verify a backup.
* Amazon S3: [AWS Service Terms](https://aws.amazon.com/service-terms/), [AWS Privacy Notice](https://aws.amazon.com/privacy/).
* Cloudflare R2: [Cloudflare Terms](https://www.cloudflare.com/website-terms/), [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/).
* Other providers: the terms and privacy policy of the provider whose endpoint you enter.

**Amazon S3 region lookup**

When you save the settings with Amazon S3 selected, TotalCare sends one unsigned request to `https://<bucket>.s3.amazonaws.com/` to find the bucket's region. Only the bucket name is sent. [AWS Service Terms](https://aws.amazon.com/service-terms/), [AWS Privacy Notice](https://aws.amazon.com/privacy/).

**Webhook (optional)**

If you enter a webhook URL under TotalCare → Settings, TotalCare sends a JSON message to that URL whenever a backup, update run, restore or scan finishes or fails, and for the monthly report. The message contains the site name and URL, the client name you entered, the event, and its details, such as which plugins were updated or how many scan issues were found. It is signed with your webhook secret. Nothing is sent if the field is empty. The terms and privacy policy of the service behind the URL you enter apply.

**Your own site**

TotalCare sends requests to your own site to run background work, health checks after updates and restore steps. These requests only go to your own site's address. Emails are sent through WordPress's `wp_mail()`, using whatever mail setup your site already has.

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the `digifix-totalcare` folder to `wp-content/plugins/`.
2. Activate it.
3. Go to TotalCare → Settings → Remote storage and enter your bucket details. When you save, TotalCare tests upload, download, list, multipart upload and delete with a small file. The secret key is stored encrypted. You can also define `DIGIFIX_TC_S3_SECRET` in `wp-config.php` instead.
4. Check the schedules for backups, updates and scans on the same page, and add the email addresses that should get reports.
5. Optional but recommended: set up a real cron job, because WP-Cron only runs when the site gets visitors. For example, run `curl -s https://example.com/wp-cron.php?doing_wp_cron` every minute, then add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

== Frequently Asked Questions ==

= Do I need Wordfence? =

Only for malware scans. Backups, restores, safe updates and reports work without it.

= Where are my backups stored? =

Only in the bucket you configure. TotalCare doesn't run a storage service of its own and keeps no local copies of backups.

= Can I move a site to a new domain with it? =

Yes. Install WordPress and TotalCare on the new site, enter the same bucket details, then go to Backups → Other sites in this bucket and restore the old site's latest backup. URLs, paths and the table prefix are adjusted for you. Afterwards you log in with the old site's users.

= Can a staging copy overwrite my live site's backups? =

No. Each backup folder records which site owns it. A site with a different address refuses to write to or prune that folder. After a real domain change, use Backups → Take over this folder.

= What happens if an update breaks my site? =

The update is rolled back straight away, from a copy of the plugin or theme taken just before. If a PHP fatal error stops every request, the guardian swaps the copy back on the next request. If the site is still broken after the run, TotalCare restores the database and code from the backup taken before the run, and emails you.

= Does it work on LiteSpeed or Hostinger? =

Yes. TotalCare was built for shared hosting and adds the LiteSpeed rule that background requests need. Use the "Test background requests" button in Settings to check your host.

= I'm moving from WPvivid. What happens to my old backups? =

They stay where they are and can still be restored with WPvivid. TotalCare's first backup is a full one. While WPvivid is active, you can also choose it as the backup engine under Settings → Backup engine.

= Does it support multisite? =

No, only single-site installs.

= Are views, triggers and stored procedures backed up? =

No. The backup warns you when it finds any. Empty folders aren't backed up either.

== Changelog ==

= 1.2.0 =
* First release on WordPress.org. Updates now come from WordPress.org; the GitHub updater has been removed.
* Option, table, hook and file names now use the `digifix_tc` prefix.
* Requires WordPress 6.2 or later.
* Security hardening: stricter input sanitising, output escaping and prepared database queries.

= 1.1.0 =
* New built-in backup and restore engine: weekly full and daily incremental backups straight to S3-compatible storage, configurable retention, partial restores, and restores onto a fresh install or a new domain. WPvivid is no longer required.

= 1.0.4 =
* Safe updates with health checks and automatic rollback, Wordfence scan scheduling, email and webhook reports, and monthly PDF reports.

== Upgrade Notice ==

= 1.2.0 =
First WordPress.org release. Settings and history saved by earlier test builds are not carried over: enter your storage details again after installing.
