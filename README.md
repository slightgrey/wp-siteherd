# WP Siteherd: WordPress plugin

The companion plugin for the **[WP Siteherd dashboard](https://github.com/slightgrey/siteherd-app)**. Install it on each client site and paste the site's connection key. From then on, the site reports its versions, pending updates, Site Health, file integrity and failed logins, and it can be updated, rolled back and logged in to from the dashboard.

![Settings page](docs/screenshots/settings.png)

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A dashboard reachable over HTTPS (plain `http://localhost` is allowed for local development)

## Installation

1. Copy this folder to `wp-content/plugins/wp-siteherd` (or upload it as a zip) and activate it.
2. In the dashboard, add the site and copy its **connection key**.
3. In WordPress, go to **Settings → WP Siteherd**, paste the key and save.

The page shows the connection status, the next automatic report and the daily checks. It warns when WP-Cron is running late, and gives the `wp-cron.php` URL to call from a real server cron. That's recommended for low-traffic sites, because WP-Cron only runs on page visits.

## What it reports

Every 10 minutes, and about 30 seconds after any plugin, theme or core change:

- WordPress, PHP and database versions, multisite flag, locale
- Installed plugins and themes with their versions, active state and pending updates
- Pending core and translation updates
- Failed logins in the last 24 hours (hourly counts only, with no usernames or IP addresses)

Once a day (or on demand with **Run checks now**):

- **Site Health:** WordPress's own Site Health tests, including the details of each issue
- **File integrity:** core files and wordpress.org plugins compared with the official checksums. Changed, missing and unexpected files are reported. Premium and custom plugins are listed as "can't be checked".

## Remote actions

All of these are triggered from the dashboard and require a correctly signed request:

- **Updates:** plugins, themes, core and translations, run in the background with WordPress's own upgraders. Each plugin and theme is backed up first. After updating, the home page and login page are loaded, and a fatal error or HTTP 5xx restores the backup automatically.
- **Rollback:** restore the version kept from the last update (kept for 30 days), or install an earlier release from wordpress.org.
- **Resync:** refresh WordPress's update information and send a report immediately.
- **One-click login:** a signed, single-use link (valid 2 minutes) logs an administrator into wp-admin. It can be turned off, or limited to one administrator, on the settings page. The last 20 logins are listed there.

## Security

- The connection key holds a secret unique to the site. It's stored in a non-autoloaded option and never shown again.
- Every request in both directions is signed with HMAC-SHA256 over `"{timestamp}.{raw body}"`, sent in the `X-Siteherd-Site`, `X-Siteherd-Timestamp` and `X-Siteherd-Signature` headers. Incoming signatures are compared in constant time.
- Requests older than 5 minutes are rejected, and replayed signatures are blocked.
- Checksums are fetched over HTTPS only.
- Admin actions use nonces and require `manage_options`.

## REST endpoints

All endpoints are under `/wp-json/siteherd/v1/` and require a signed request.

| Endpoint | |
|---|---|
| `GET status` | The same payload as a report |
| `POST sync` | Refresh update info and send a report |
| `POST update` | Start an update or rollback job |

## Development

```bash
composer install
vendor/bin/phpunit                         # unit tests (no WordPress needed)
vendor/bin/phpunit --filter test_name      # a single test
php -l includes/class-siteherd.php         # syntax check
```

The code stays compatible with PHP 7.4. `Siteherd_Signer` (`includes/class-siteherd-signer.php`) is plain PHP so it can be unit-tested. It shares its test vectors with the dashboard's signing code.

```
wp-siteherd.php        bootstrap
includes/              core classes: collector, reporter, signer, updater, checks, SSO, REST API
admin/                 settings page and styles
tests/                 PHPUnit tests
```

## License

GPL-2.0-or-later, as stated in the plugin header. This repository is distributed under GPL-3.0; see [LICENSE](LICENSE).
