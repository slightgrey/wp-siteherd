=== WP Siteherd ===
Contributors: slightgrey
Tags: monitoring, updates, security, agency, maintenance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this site to your WP Siteherd dashboard so you can watch versions, pending updates and failed logins across all your client sites.

== Description ==

Every 10 minutes, and shortly after any plugin, theme or core update, the plugin sends a signed report to your WP Siteherd dashboard containing:

* WordPress, PHP and database versions
* Installed plugins and themes, with pending updates
* The number of failed logins in the last 24 hours (counts only, no usernames or IP addresses)

Once a day it also runs heavier checks and includes the results in every report:

* WordPress's own Site Health tests, with details of each critical issue and recommendation
* File integrity: WordPress core files and plugins from the wordpress.org directory are compared with the official checksums published by wordpress.org. Modified, missing and unexpected files are reported. Premium and custom plugins can't be verified this way and are listed as such.

Reports are signed with HMAC-SHA256 using a secret unique to this site. The dashboard can also request the same information from `/wp-json/siteherd/v1/status`, which only answers correctly signed requests.

== Installation ==

1. Install and activate the plugin.
2. In your WP Siteherd dashboard, add this site and copy its connection key.
3. In WordPress, go to Settings → WP Siteherd, paste the key and click Connect.

WP-Cron only runs when the site gets visits. On low-traffic sites, have a real cron job request `wp-cron.php` every few minutes so reports stay on time.

== One-click login ==

From the dashboard you can open this site's wp-admin without a password. The dashboard creates a single-use link signed with this site's secret that expires after 2 minutes, and the plugin logs the browser in as an administrator: the one chosen under Settings → WP Siteherd, or, with "Ask every time", the administrator picked in the dashboard. Only administrator accounts can be used. Turn it off with the "Allow one-click login" checkbox.

== Remote updates ==

From the dashboard you can update plugins, themes and WordPress core, one at a time or several at once. Updates run in the background on this site using WordPress's own updater. Before each plugin or theme update, a backup copy is made in wp-content/siteherd-backups/ (blocked from the web). After the update the plugin loads the homepage and login page. If either fails, for example with a fatal error, the backup is restored automatically. Core updates are checked the same way but can't be rolled back automatically. Remote updates need direct file access (no FTP credentials) and are not possible with DISALLOW_FILE_MODS.

== Rollback ==

After a successful plugin or theme update, the copy of the version it replaced is kept in wp-content/siteherd-backups/kept/ for 30 days (one per plugin/theme). From the dashboard you can roll back to that copy. If there's no saved copy, plugins and themes from wordpress.org can be rolled back to an earlier version downloaded from wordpress.org. The current version is backed up first, and restored if the site stops loading after the rollback.

== Changelog ==

= 0.10.0 =
* Login lockout (off until turned on, here or from the dashboard): after too many failed logins from one IP address (5 by default), logins from it are refused for a while (15 minutes by default, 24 hours after the 4th lockout in a day) without checking the password. Covers wp-login.php, XML-RPC and other logins through WordPress. One-click login from the dashboard always works. It stays off while another plugin that locks out IPs is active (Wordfence, Solid Security, Limit Login Attempts Reloaded and others).
* Behind Cloudflare, the visitor's address is read from CF-Connecting-IP, but only for requests that really come from Cloudflare. Other proxies can be chosen on the settings page. Addresses are stored only as salted hashes in short-lived transients and never sent to the dashboard.
* Reports send the lockout settings and its 24-hour counts (`lockout`, schema 9). `POST /siteherd/v1/settings` accepts `lockout` and `unlock_all`.

= 0.9.0 =
* New "Block XML-RPC logins" switch (Settings → WP Siteherd → Login protection, or from the dashboard). WordPress then refuses XML-RPC calls that need a login before checking the password, which stops password guessing through xmlrpc.php. Leave it off for Jetpack and the WordPress mobile app.
* Reports send failed logins per hour (`failed_logins_hourly`) so the dashboard keeps a history, and the login protection settings (`hardening`). Schema 8.
* New signed endpoint `POST /siteherd/v1/settings` for the dashboard's XML-RPC switch.

= 0.8.0 =
* Failed logins are counted per source: login forms, XML-RPC and REST API (still counts only, no usernames or IP addresses). Reports send them as `failed_logins_by_source` (schema 7), so the dashboard shows where attacks come from. Counts stored by older versions show as "Unknown" until they expire after 24 hours.

= 0.7.3 =
* Remote jobs keep a heartbeat while they work. If the host ends the background request early (LiteSpeed does), the site accepts new jobs again after 5 minutes without a heartbeat instead of 30 minutes after the job started.
* New signed endpoint `POST /siteherd/v1/update/release`: when the dashboard confirms a cut-off job from the site's report, it releases the job lock right away (only for that job, and only after a minute without a heartbeat).

= 0.7.2 =
* Fix: right after WP Siteherd updated itself, WordPress kept offering the same update and the dashboard showed the old version. The update check and reports now read the installed version from the plugin file, not the version loaded in memory.

= 0.7.1 =
* One-click login skips two-factor plugins (Solid/Kadence Security, Two Factor, WP 2FA and others that interrupt logins on `wp_login`). On by default; turn it off under Settings → WP Siteherd → One-click login. Password logins still ask for the code. One-click logins fire `siteherd_sso_login` instead of `wp_login` while skipping.
* The dashboard now sends a User-Agent when calling the site, so host firewalls that block requests without one no longer answer 403.

= 0.7.0 =
* Install plugins from wordpress.org, and activate, deactivate or delete plugins, from the dashboard (report schema 6). Activating is health-checked: if the site stops loading, the plugin is deactivated again. Deleting keeps a copy of the files for 30 days.
* Updates for WP Siteherd itself from GitHub releases, shown in wp-admin and in the dashboard like any other plugin update.

= 0.6.0 =
* Resync from the dashboard, and translation (language pack) updates (report schema 5 adds `translations` and `site_icon`).

= 0.5.0 =
* Rollback for plugins and themes: from the copy saved at the last update, or from wordpress.org (report schema 4 adds `rollbacks`).

= 0.4.0 =
* Remote plugin, theme and core updates from the dashboard, with automatic backup, health check and rollback for plugins and themes.

= 0.3.0 =
* One-click login from the dashboard (on by default, can be turned off; log in as a fixed administrator or choose each time).
* Redesigned settings page: Connection, Monitoring and One-click login cards.

= 0.2.0 =
* Daily Site Health and file-integrity checks, included in reports (report schema 2).
* "Run checks now" button on the settings page.

= 0.1.0 =
* First version: scheduled signed reports, failed-login counting, status endpoint.
