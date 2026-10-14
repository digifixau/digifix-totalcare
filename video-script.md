# Digifix TotalCare: Promo + Tutorial Video Script

**Runtime:** about 8:00 (about 1,180 words of voiceover at a relaxed 145–150 words per minute, plus short silent demo beats)
**Format:** screen recording of wp-admin with voiceover, plus a short opening montage and a closing title card
**Tone:** calm and confident. Show what it does instead of hyping it.

---

## Before you record

Some of these take real time to happen, so set them up before you start recording:

- **Demo site:** a clean WordPress site with TotalCare and Wordfence installed and a few real-looking plugins (WooCommerce, a contact form plugin). Turn on a real cron job so jobs move without waiting for visitors.
- **Pre-made backups:** run several backups across a few days first, so the Backups list shows full and incremental backups, different sizes and one pinned backup.
- **Outdated plugin:** `wp plugin install hello-dolly --version=1.6 --force` so "Run updates now" has something to update.
- **Broken update for the rollback demo:** serve a deliberately broken update for a test plugin through the `pre_set_site_transient_update_plugins` filter (testing checklist step 4 in the README). Record the rollback email when it arrives.
- **Second site:** a fresh WordPress install on a different domain, for the migration scene.
- **Slack:** an incoming webhook in a demo Slack channel, so a TotalCare message shows up during the reporting scene.
- **Monthly report:** have at least one month of activity on the site so the report has real content in it.
- **Blur:** the access key, secret key, R2 account ID, email addresses and anything identifying a real client.
- **Editing:** speed up anything that makes you wait (backups, restores, update runs) to 4–8× speed with a small "sped up" label.

---

## 1. Cold open (0:00 – 0:25)

**On screen:** A phone lock screen shows 3:04 a.m. Cut to a browser with the WordPress "There has been a critical error on this website" screen. Quick cut to the TotalCare Activity Log showing the update being rolled back, then to the same site loading normally. Then the Digifix TotalCare logo.

**VO:**
> It's 3 a.m., and a plugin update has just broken your client's website.
>
> By the time anyone's awake, it's already fixed. The bad update was rolled back, the site was checked, and an email is waiting that explains what happened.
>
> This is Digifix TotalCare.

---

## 2. What it does (0:25 – 0:55)

**On screen:** TotalCare → Dashboard. Highlight each panel as it's mentioned: **Backups**, **Safe updates**, **Malware scans**, **Recent activity**.

**VO:**
> TotalCare is a WordPress plugin that looks after a site's routine care on its own. It does four things.
>
> It backs the site up to your own cloud storage. It updates plugins, themes and WordPress one at a time, and undoes any update that breaks the site. It runs scheduled malware scans with Wordfence. And it reports on all of it to you and your client.
>
> I'll set it up from scratch and show you each of them.

---

## 3. Install (0:55 – 1:30)

**On screen:** Plugins → Add New → Upload Plugin → choose `digifix-totalcare.zip` → Activate. The **TotalCare** menu with the shield icon appears in the sidebar. Briefly show Settings → Tests → **Test background requests** returning a pass.
**Lower third:** `* * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron`

**VO:**
> Install it like any other plugin: upload the zip and activate it. TotalCare appears in the sidebar.
>
> When you activate it, it also installs a small safety layer called the guardian. That's what lets TotalCare repair a site even when a plugin is crashing every page. We'll see it in action later.
>
> You'll need WordPress 6 or newer, PHP 7.4 or newer, and the free Wordfence plugin. One tip: set up a real cron job that calls the site every minute, because WordPress's built-in scheduler only runs when someone visits. Then click "Test background requests" to make sure your host lets TotalCare's work finish.

---

## 4. Connect your storage (1:30 – 2:25)

**On screen:** TotalCare → Settings → **Remote storage**. Provider: Cloudflare R2. Fill in Access key and Secret key (blurred), Bucket, and Endpoint (account ID). Point at the **Folder** field, which already holds the site's domain. Click **Save settings** and show the success notice.

**VO:**
> First, tell TotalCare where to put the backups. Under Settings, Remote storage.
>
> It works with Amazon S3, Cloudflare R2, or any S3-compatible service. I'm using R2. Paste in the access key and secret, the bucket name, and your R2 account ID as the endpoint.
>
> The folder defaults to the site's domain, so one bucket can hold all your sites, each in its own folder. Each folder remembers which site owns it, so a staging copy can never overwrite or delete the live site's backups.
>
> When you save, TotalCare tests the connection by uploading, downloading, listing and deleting a small test file. The secret key is stored encrypted.
>
> One rule: don't add lifecycle rules in the bucket that expire old files. Let TotalCare do the cleanup, because newer backups depend on older ones.

---

## 5. Backups (2:25 – 3:40)

**On screen:** Settings → **Backups**. Show Frequency, "Full backup every 7 days", then the **Keep backups** rules, then **Exclude files** and **Server load** (Work per request, Low impact).
Cut to TotalCare → **Backups** → click **Back up now**. Dashboard "Running now" table (sped up). Back to the Backups list: point at the Date, Type, Size and Files changed columns, click **Pin** on one row, then open **Download**.

**VO:**
> Next, the backup schedule. Once a week TotalCare takes a full backup. Between those, it only saves the files that changed. The database is saved in full every time. That keeps backups small and fast.
>
> You decide how long to keep them, and there's no limit on how many. Keep the newest few, everything from the last week, then one a day, one a week and one a month for as long as you like. Backups taken before updates get their own setting.
>
> It's built for budget shared hosting. Memory use stays the same however big the site is. It needs only about 16 megabytes of disk space, and it uploads as it goes, so no copy of the backup is left on the server. The work runs in short steps that hosts won't cut off. If a server is still struggling, turn on Low impact mode.
>
> Let's take one now. Backups, then Back up now.
>
> *(beat, sped-up progress)*
>
> There it is: the date, whether it's full or incremental, its size and how many files changed. Pin a backup to keep it forever. And Download gives you ordinary tar.gz and SQL files that open with any tool, so your backups never depend on TotalCare.

---

## 6. Restore and migrate (3:40 – 5:00)

**On screen:** Backups list → **Restore…** on a backup. Show the part checkboxes (Database, Core, Plugins, Themes, mu-plugins, Content, Uploads) and the **Only these paths** box. Type `wp-content/plugins/woocommerce` and run a partial restore.
Then a full restore: the visitor-facing maintenance page in one tab, **Follow the restore** progress page in another (sped up), then the site loads normally.
Cut to the second site (new domain): Settings → Remote storage with the same bucket → Backups → **Other sites in this bucket** → **View backups** → Restore. Show the notice: "Its URLs and paths are rewritten for this site." Log in with the old site's username.

**VO:**
> Restoring is where most backup tools let you down. Click Restore on any backup and pick exactly what comes back: the database, plugins, themes, uploads, everything, or just one folder. If one plugin's files are damaged, enter its folder here and only that plugin is restored.
>
> TotalCare only downloads the files that differ from what's on the site, so restores are quick. The database is prepared in the background while the site stays online, then switched over in one step. Visitors only see a maintenance page for that short switch. The old tables are kept until the restored site passes a health check.
>
> The guardian runs the restore before any other plugin loads, so a broken plugin can't get in the way. You don't need to deactivate anything first.
>
> The same feature moves sites. On a fresh WordPress install, add TotalCare and connect the same bucket. Under Other sites in this bucket, pick the old site and restore a backup. TotalCare rewrites every URL and file path in the database, so the site works at its new address. Then log in with the old site's username and password.

---

## 7. Safe updates (5:00 – 6:25)

**On screen:** Settings → **Safe updates**. Set **When** to Tuesday at 03:00. Show **Never update these plugins**, then type `/shop` and `/checkout` into **Extra pages to check**.
Dashboard → **Run updates now** → confirm the dialog ("This takes a backup, then updates plugins, themes and core one at a time"). Activity Log entries appear (sped up).
Rollback demo: the broken test update runs, the health check fails, and the Activity Log shows it rolled back. Cut to the rollback email. Then back to the Dashboard: the **Blocked versions** list and **Clear blocked versions**.

**VO:**
> Now the feature that saves the most time: safe updates. Pick a day and time each week, like Tuesday at 3 a.m.
>
> First, TotalCare takes a backup. Then it checks the site is healthy before it touches anything. Then it updates plugins and themes one at a time. Before each update it sets the old version aside. After each update it loads your home page and login page and looks for trouble: server errors, PHP fatal errors, WordPress's critical error screen, database errors, or a page that didn't finish loading.
>
> Add your most important pages, like the shop, the checkout or the contact form, and they're checked too. Anything you'd rather update by hand goes in this list.
>
> If an update breaks the site, only that plugin is rolled back, and the other updates carry on. That version is blocked until the developer releases a newer one. If the site is still broken at the end, TotalCare restores the database and code from the backup it took at the start.
>
> Let's see it. I've prepared a plugin update that crashes the site on purpose. Run updates now.
>
> *(beat, sped-up progress)*
>
> The update goes in, the health check fails, and it's rolled back automatically. Here's the email that came in. The site is fine.
>
> The guardian is watching too. It keeps running even if TotalCare itself is switched off, and if a fatal error happens partway through an update, it puts the previous version back straight away.

---

## 8. Malware scans (6:25 – 6:50)

**On screen:** Settings → **Malware scans**: **When**, **Scan type**, **Alert immediately at**. Dashboard → **Scan now** → the **Wordfence results** link.

**VO:**
> Security scans use Wordfence. TotalCare runs them on a schedule you choose, which the free version of Wordfence can't do on its own. Choose the scan type and how serious a finding must be to trigger an alert. Serious problems are reported straight away instead of waiting for the next summary.

---

## 9. Reporting (6:50 – 7:35)

**On screen:** Settings → **Reporting**: Client name, Digifix recipients, Client recipients, Brand colour, Webhook URL. Click **Send test webhook**, then cut to the message arriving in Slack. TotalCare → **Reports** → Monthly care report → **View**, scroll through it, then **Download PDF**.

**VO:**
> Finally, reporting. Everything TotalCare does is recorded in the Activity Log. Your team gets alerts and summaries by email. Add a webhook and every event is posted to Slack, n8n or whatever tools you already use.
>
> On the first of every month, your client gets a monthly care report, in your brand colour with their name on it: backups taken, updates applied, problems handled and scan results. It shows them the work being done on their site every month, even when nothing went wrong.
>
> Use the test buttons to check the emails and the webhook before you go live.

---

## 10. Outro (7:35 – 8:00)

**On screen:** Slow push-in on the Dashboard showing every panel healthy. Fade to the Digifix TotalCare logo and `digifix.com.au`.

**VO:**
> That's Digifix TotalCare. Backups you can actually restore, updates that undo themselves when something breaks, regular security scans, and reports your clients can see.
>
> Set it up once, and every site stays cared for, every week.
>
> Find out more at digifix.com.au.

---

## Optional cut-downs

- **60-second promo:** scenes 1 and 2, the rollback demo from scene 7, one line each from scenes 5, 6 and 9, then the outro.
- **Chapters for YouTube:** 0:00 Intro · 0:25 What it does · 0:55 Install · 1:30 Storage · 2:25 Backups · 3:40 Restore & migrate · 5:00 Safe updates · 6:25 Malware scans · 6:50 Reporting · 7:35 Wrap-up
