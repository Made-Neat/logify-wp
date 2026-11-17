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

**Logify WP** provides real-time, detailed logs of activities happening across your WordPress website. Whether you're an **agency**, **freelancer**, **IT team**, **developer**, or **website administrator**, Logify WP gives you full visibility into your website’s activity with a comprehensive **activity log** and **audit log**. From tracking post edits to user login attempts and plugin updates, Logify WP helps you monitor and secure your site with clear and easy-to-understand logs.

Take your activity logs to the next level with activity **Notes**! This feature allows you to attach **searchable notes** linked to logged events, providing valuable context. Need to document why a plugin was installed, who approved an update, or what prompted a change? Now you can, with simple markup support for clarity.

Built to be simple yet powerful, Logify WP features a clean layout of activity information, easy filtering and search options, and customizable role-based access controls. The user-friendly dashboard widget makes it easy to review recent critical activities at a glance.

### Key Features:

- **Activity Log Overview:** Get a complete chronological view of all logged activities across your WordPress site. Ideal for tracking patterns, diagnosing issues, and maintaining a transparent record of site events.
- **User Audit Log:** Drill down into individual user activity with dedicated audit trails. See exactly what each user did, when, and from where, perfect for accountability and compliance.
- **Track Core WordPress Activities:** Record actions on posts, pages, custom post types, taxonomies, plugins, themes, users, and more.
- **Real-time Monitoring:** Get instant insights into who made changes, when, and where, via a secure **event log**.
- **Action Notes:** Add and search **notes** linked to actions for improved tracking and accountability.
- **User Login Monitoring:** Track user logins, logouts, and failed attempts with IP addresses.
- **Media Management:** Know who is uploading, editing, or deleting media files and when.
- **Role-Based Access Control:** Limit who can access the activity logs based on their WordPress role.
- **Advanced Search & Filters:** Filter logs by user, date, post type, and more to quickly find specific actions.
- **User-Friendly Dashboard Widget:** View the most recent critical activities in a quick summary.
- **IP Address Information Integration:** One-click access to IP information via WhatIsMyIpAddress.com.
- **CSV Export:** Export the events matching your current filters to a CSV file, with the export itself recorded in the log.
- **Privacy Tools Support:** Works with WordPress's built-in Export and Erase Personal Data tools, so you can answer GDPR and CCPA requests. Erasure anonymises log entries rather than deleting them, so the audit trail stays intact.

**Who is Logify WP for?**

Logify WP is perfect for:
- **Agencies** managing multiple client sites.
- **Freelancers** who need a detailed audit trail for their client work.
- **IT Teams** maintaining the security of large WordPress environments.
- **Website Administrators** responsible for monitoring site activity and detecting unauthorized changes.
- **Developers** looking for a simple yet powerful logging tool.
- **Everyday Website Users** who want a simple way to monitor and track activity on their site.

Logify WP is actively being developed, with new features in the pipeline. If you'd like to suggest features, submit them via [https://logifywp.com/suggest/](https://logifywp.com/suggest/).

== Installation ==

1. Download and install Logify WP from the WordPress Plugin Directory or upload the plugin files to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Logify WP > Settings** to configure tracking options, activate **Notes** and start monitoring your website’s activity.

== Frequently Asked Questions ==

### What does Logify WP track?

Logify WP logs activities such as changes to posts, pages, custom post types, taxonomies, users, plugins, themes, and more. It also tracks user logins, logouts, failed login attempts, and media management activities.

### Does Logify WP affect website performance?

Logify WP processes many events in the background through Action Scheduler and loads log results in pages to reduce server load. Performance depends on site activity, hosting and database size.

### My site is behind Cloudflare. Why do all events show the same IP address?

Logify WP automatically recognises verified Cloudflare connections. Other proxy-provided IP addresses may be shown as **reported, unverified**. The advanced **Trust proxy headers** setting should only be enabled when the connecting proxy is explicitly trusted and configured.

### Can I export the log?

Yes. Use **Export CSV** on the activity log screen to export everything matching your current filters and search. The export is written out in batches so it will not exhaust your host's memory limit, and the export itself is recorded in the log.

### Does Logify WP help with GDPR requests?

Yes. Logify WP integrates with WordPress's **Tools > Export Personal Data** and **Tools > Erase Personal Data**. Erasure anonymises matching event records and removes their related detail rows, while retaining the event headers. Free-text references elsewhere may still need manual review.

### Does Logify WP automatically look up IP locations?

No. Automatic IP geolocation requests were removed in version 1.5.0. Clicking an IP address in a log opens WhatIsMyIPAddress.com, which receives that IP address through the link.


### Can I control who views the activity logs?

Yes, Logify WP includes role-based access control, allowing you to restrict access to logs based on user roles. You can also grant access to specific users.

### Can I search and filter logs?

Absolutely! Logify WP provides advanced search and filtering options, allowing you to filter logs by user, date, post type, action type, and more.

### How can I report security bugs?

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team helps validate, triage and handle any security vulnerabilities. [Report a security vulnerability.]( https://patchstack.com/database/vdp/6069fd7e-a4f8-499a-8641-d86656d2268e )

== Screenshots ==

1. **Real-Time Monitoring** – Stay in control with live updates on every change happening on your WordPress site.
2. **Role-Based Access Control** – Keep your logs private with customizable role-based access controls.
3. **Advanced Search & Filtering** – Easily find the information you need with powerful search and filtering tools.
4. **User-Friendly Dashboard Widget** – Get an at-a-glance view of critical site activities with a convenient dashboard widget. 
5. **Log Retention** – Take full control of your activity logs with customizable log retention settings. 

== Changelog ==

= 1.0 =
* Initial release with core logging features, advanced search and filtering, and a dashboard widget.

= 1.1 =
* Code style updates to improve security.
* Added documentation of third-party services.

= 1.2.0 =
* Added Notes feature with advanced search and filtering
* Add or update a note against a log

= 1.2.1 =
* Fixed issue in Notes feature

= 1.2.4 =
* Fixed an issue where get_last_modified_datetime() could return null, causing a fatal error with certain custom post types.

= 1.2.5 =
* Updated plugin name to "Logify WP – Activity Log & User Audit Log" for clarity and search visibility.
* Improved short description for SEO and readability.

= 1.2.6 =
* Added VDP disclaimer and fixed Notes modal close button issue

= 1.3.0 =
* Added asynchrnous log processing using action scheduler

= 1.3.1 =
* Added asynchrnous log processing fixes which were causing DB bloating
* Fixed IP logging issue
* Bulk updates issue through dashboard
* object size limit issue
* Gravity form tracking
* Bricks builder tracking

= 1.3.2 =
* minor fixes

= 1.3.3 =
* bug fixes and added support for beaver builder

= 1.4.0 =

Security:
* Fixed a SQL injection in the event log filter. The object-type filter came from a cookie and was used in a query without being checked against the known object types.
* Logged values are now escaped when log details are displayed. A captured value, such as the username from a failed login, could previously contain HTML that ran in an administrator's browser.
* Adding and editing notes now requires permission to view the activity log.
* Removed a filter that disabled WordPress comment flood protection site-wide.

Fixed:
* Core updates, theme switches and deletions, user registrations and deletions, and comment activity were not being recorded at all. The acting user was not passed to the background process that writes the entry, so the event could not be attributed and was discarded without any error.
* Fatal error when publishing a page built with Beaver Builder. The page saved but the editor reported a failure and kept loading.
* Fatal error when deleting an event that had notes attached, which could happen after editing a media item without changing anything.
* The Notes and Actions columns never appeared in the activity log, even with Notes enabled.
* Action Scheduler cleanup was deleting scheduled task history belonging to other plugins, such as WooCommerce.
* Action Scheduler cleanup was deleting Logify's own queued entries before they were processed, on sites where WP-Cron is disabled or delayed.
* Comment updates no longer error when another plugin passes an unexpected value.

Added and changed:
* Show or hide columns in the activity log, including Notes.
* Notes is now a single global setting. Enabled shows the checkbox, the Notes column and the Actions column; disabled hides all of them, removes the View Notes page and stops the log page running note queries.
* Tested with WordPress 7.1.

= 1.5.0 =

* Added – CSV export for filtered activity logs, with exports recorded as audit events.
* Added – Integration with WordPress's personal data export and erasure tools.
* Improved – Activity Log, Notes and Settings screens with better filtering, navigation and note management, including note deletion.
* Improved – Visitor IP attribution, distinguishing verified addresses from unverified proxy-reported IPs and preserving request details through background processing.
* Fixed – Reliability issues affecting user, comment, plugin, theme and Gravity Forms activity logging.
* Improved – Security through sensitive-data redaction, safer handling of stored values, and additional access and database query protections.
* Fixed – Log retention timezone calculations; improved activity-log queries and background cleanup performance.
* Removed – PHP error capture and automatic external IP geolocation lookups.

== Upgrade Notice ==

= 1.5.0 =
Major update. PHP error capture has been removed so the plugin concentrates on the activity log. This release also improves IP attribution, display, filtering and retention. Verified Cloudflare connections are recognised automatically; other proxy headers require explicit trusted-proxy configuration.

= 1.4.0 =
Security and reliability release. Fixes a SQL injection and a stored cross-site scripting issue, and restores logging of core updates, theme changes, user registrations and deletions, and comment activity, which were being silently discarded. Also fixes a fatal error when publishing Beaver Builder pages.

== Links ==

- [Plugin Website](https://logifywp.com/)
- [Suggest Features](https://logifywp.com/suggest/)

== Credits ==

This plugin bundles the following third-party libraries:

* [DataTables](https://datatables.net) 2.0.8, released under the [MIT License](https://datatables.net/license/mit). DataTables ©2007-2024 SpryMedia Ltd.
* [Action Scheduler](https://actionscheduler.org/), released under the [GPLv3 License](https://www.gnu.org/licenses/gpl-3.0.html). Used to process log entries in the background. If another plugin on your site already provides Action Scheduler, that copy is used instead of this one.
* [jQuery UI](https://jqueryui.com/) theme stylesheet 1.14.0, released under the [MIT License](https://jquery.org/license/). Only the stylesheet is bundled; the JavaScript comes from WordPress core.

Both readable and minified builds of the plugin's own CSS and JavaScript are included. Define `SCRIPT_DEBUG` to load the readable files.

== Third-Party Services ==

This plugin utilizes third-party services under certain circumstances:

### 1. WordPress Documentation Links

When viewing logs, this plugin provides links to the official WordPress documentation corresponding to the version of WordPress that has been installed on your site. These links direct users to:

- **Service URL:** [https://wordpress.org/documentation/wordpress-version/version-&lt;version&gt;/](https://wordpress.org/documentation/)
- **Purpose:** To offer quick access to documentation for the specific WordPress version installed.
- **Data Sent:** The WordPress version number is included in the URL.
- **Privacy Policy:** [https://wordpress.org/about/privacy/](https://wordpress.org/about/privacy/)

### 2. IP Address Lookup

This plugin allows users to click on logged IP addresses to view their origin information. When a user clicks an IP address in the log, it opens a link to an external service:

- **Service Name:** WhatIsMyIPAddress.com
- **Service URL:** [https://whatismyipaddress.com/ip/&lt;IP&gt;](https://whatismyipaddress.com/)
- **Purpose:** To provide detailed information about the IP address's geographical location and other related data.
- **Data Sent:** The IP address clicked in the log is included in the URL.
- **Privacy Policy:** [https://whatismyipaddress.com/privacy-policy](https://whatismyipaddress.com/privacy-policy)
- **Terms of Use:** [https://whatismyipaddress.com/terms-of-use](https://whatismyipaddress.com/terms-of-use)

**Please Note:** Logify WP does not automatically send IP addresses to a geolocation service. Clicking an external IP or WordPress documentation link shares the information included in that URL with the linked service. Review your privacy notice as appropriate.
