=== Logify WP - Activity Log & User Audit Log ===

Contributors: madeneat
Tags: activity log, audit log, security, user tracking, event log
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logify WP - Activity Log & User Audit Log tracks critical changes, logins, and updates with searchable logs for site security.

== Description ==

**Logify WP** helps you see what changed on your WordPress site, who made the change, and when. It tracks activity across posts, pages, users, plugins, themes, settings and supported integrations.

Use searchable logs to investigate changes, and add **Notes** to explain why work was done. Administrators can control which user roles are tracked and who can view the log.

**Features**

* Activity log with search, filters, configurable columns and dashboard widget.
* Tracking for logins, failed logins, content, users, media, settings, plugins and themes.
* Notes linked to activity, plus a separate Notes page.
* CSV export (up to 50,000 filtered events by default).
* Retention controls, WordPress privacy tools and restricted log access.
* Visitor IP attribution with clear labels for unverified proxy-reported addresses.

== Installation ==

1. Install and activate Logify WP.
2. Open **Logify WP > Settings** to choose tracking, access and retention options.
3. Open **Logify WP > View Log** to review activity and add Notes.

== Frequently Asked Questions ==

= Will it slow down my site? =

Many events are processed in the background using Action Scheduler. Performance depends on site traffic, database size and cron processing.

= Can I control who sees the logs? =

Yes. Administrators can grant log access by role or user. CSV export and editing Notes have separate permission requirements.

= How does IP detection work behind a proxy? =

Logify uses the connection IP and automatically recognizes verified Cloudflare connections. If the host supplies an unverified forwarded IP, Logify can display it as **reported, unverified** rather than treating it as authoritative. Advanced trusted-proxy settings and diagnostics are available in **Settings > IP addresses**.

= Does Logify send IP addresses to a geolocation service? =

No automatic geolocation requests are made. Clicking an IP opens the third-party WhatIsMyIPAddress.com website, which receives that IP in the link.

= Does Logify record passwords or API keys? =

New events exclude known sensitive fields. Third-party option changes are recorded without full settings values. Avoid putting credentials in Notes; automated detection cannot identify every secret.

= Does Logify support personal-data requests? =

Yes. It integrates with WordPress's Export and Erase Personal Data tools. Administrators should review free-text Notes and other content for personal information that automated tools may miss.

= How can I report a security issue? =

Please use the [Patchstack vulnerability disclosure form](https://patchstack.com/database/vdp/6069fd7e-a4f8-499a-8641-d86656d2268e).

== Screenshots ==

1. Activity Log – Review and search site activity.
2. Access Control – Choose who can view logs.
3. Search and Filters – Narrow events by date, user and type.
4. Dashboard Widget – See recent activity at a glance.
5. Log Retention – Configure how long logs are kept.

== Changelog ==

= 1.5.0 =

* **Redesigned activity log:** cleaner filtering, configurable columns, improved pagination and CSV export.
* **More tracking:** additional user/security events, plugin/theme update outcomes, Gravity Forms notifications, file edits and other WordPress actions.
* **Improved IP attribution:** preserves request IPs through background jobs, identifies verified Cloudflare connections and labels unverified proxy-reported IPs.
* **Notes and access:** improved Notes management, separate export/edit permissions and better event attribution.
* **Security and privacy:** sensitive-value redaction, safer output handling and WordPress personal-data export/erasure support.
* **Reliability and performance:** corrected missing/duplicate events, background-job handling, retention cleanup and batched queries; database date index installed using a deferred migration.
* **Removed:** PHP error capture and automatic external IP-geolocation lookups. Logify remains focused on activity auditing.

= 1.4.0 =

* Security fixes for activity-log filtering and displayed values; improved permissions and tracking reliability.
* Fixed missing core events, compatibility issues and overly broad Action Scheduler cleanup.
* Added more user, update and rollback events.

= 1.3.3 =

* Beaver Builder compatibility and bug fixes.

= 1.3.2 =

* Minor fixes.

= 1.3.1 =

* Background logging, IP attribution, bulk updates and plugin integrations improved.

= 1.3.0 =

* Added asynchronous logging with Action Scheduler.

= 1.2.6 =

* Updated disclosure information and fixed a Notes dialog issue.

= 1.2.5 =

* Updated plugin name and description.

= 1.2.4 =

* Fixed a custom post type date error.

= 1.2.1 =

* Notes fixes.

= 1.2.0 =

* Added Notes linked to activity events.

= 1.1 =

* Security code style improvements and third-party service documentation.

= 1.0 =

* Initial release.

== Upgrade Notice ==

= 1.5.0 =

Major audit-log update. PHP error capture removed; existing logs and Notes remain. CSV export and Notes editing now have separate permissions. Check activity/IP attribution after upgrading.

= 1.4.0 =

Security and reliability release: fixes unsafe filtering and display handling, and missing activity events.

== Links ==

- [Plugin Website](https://logifywp.com/)
- [Suggest Features](https://logifywp.com/suggest/)

== Credits ==

This plugin bundles the following third-party libraries:

* [DataTables](https://datatables.net) 2.0.8, released under the [MIT License](https://datatables.net/license/mit). DataTables ©2007-2024 SpryMedia Ltd.
* [Action Scheduler](https://actionscheduler.org/), released under the [GPLv3 License](https://www.gnu.org/licenses/gpl-3.0.html). Used to process log entries in the background. If another plugin on your site already provides Action Scheduler, that copy is used instead of this one.
* [jQuery UI](https://jqueryui.com/) theme stylesheet 1.14.0, released under the [MIT License](https://jquery.org/license/). Only the stylesheet is bundled; the JavaScript comes from WordPress core.

Both readable and production-suffixed CSS/JavaScript assets are included; some production-suffixed files are synchronized copies rather than compressed builds. Define `SCRIPT_DEBUG` to load readable assets.

== Third-Party Services ==

Logify WP does not automatically send visitor IP addresses to a geolocation API. Optional links opened by administrators may send information to these external sites:

* **WordPress documentation:** [wordpress.org/documentation](https://wordpress.org/documentation/) – Links include the site's WordPress version. [Privacy policy](https://wordpress.org/about/privacy/).
* **IP details:** [WhatIsMyIPAddress.com](https://whatismyipaddress.com/) – Clicking a logged IP opens a URL containing that IP. [Privacy policy](https://whatismyipaddress.com/privacy-policy) and [terms](https://whatismyipaddress.com/terms-of-use).

No automatic external IP-geolocation lookup is performed.
