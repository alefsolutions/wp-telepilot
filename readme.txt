=== WP Telepilot ===
Contributors: alefsolutions
Tags: telegram, bot, notifications, moderation, remote management
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Telegram-first WordPress operations for linked site teams who want structured site actions, moderation, and notifications from chat.

== Description ==

WP Telepilot lets approved WordPress users link a Telegram account to a WordPress account and then perform short, structured operational tasks from Telegram without trying to replace all of wp-admin.

WP Telepilot currently includes:

* secure Telegram-to-WordPress account linking with short-lived one-time codes
* webhook mode with polling fallback
* role-aware Telegram menus based on the linked WordPress user's capabilities
* site overview, notifications, comments, posts, pages, users, plugins, categories, and tags command groups
* search, pagination, confirmation flows, and audit logging
* secure browser handoff for long-form post editing
* transport diagnostics, queue processing, and webhook/polling health controls
* personal-data export and erasure hooks for linked Telegram account data

WP Telepilot 0.3.0 is the first stable release line. It focuses on secure Telegram linking, structured operational workflows, transport resilience, and practical WordPress administration from chat.

Full command reference:
https://github.com/alefsolutions/wp-telepilot/blob/main/COMMANDS.md

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it through the WordPress admin.
2. Activate WP Telepilot.
3. Create a Telegram bot with BotFather.
4. Open `WordPress Admin -> WP Telepilot` and paste in the bot token.
5. Choose webhook mode or polling fallback.
6. Save settings.
7. Generate a one-time link code from a user's WordPress profile.
8. In Telegram, send `/link CODE` to the bot from a private chat.
9. Use `/menu` or `/help` to begin.

== Frequently Asked Questions ==

= Does this plugin require Telegram? =

Yes. WP Telepilot is built around the Telegram Bot API and requires a Telegram bot token before the messaging features can work.

= Does it support both webhooks and polling? =

Yes. Webhook mode is the default. Polling fallback is available for hosts or reverse proxies that interfere with Telegram webhook delivery.

= Can it edit long post content directly in chat? =

Long-form post content is handled through a secure browser editing bridge. Telegram remains the place for shorter actions, decisions, and operational workflows.

= Can it upload media directly from Telegram? =

No. Media is read-only in the current stable release. You can list, search, inspect, and open media items from Telegram, but uploads and destructive media changes remain in wp-admin for now.

= Does it include privacy tooling? =

Yes. WP Telepilot registers privacy-policy helper text plus personal-data exporter and eraser callbacks for linked Telegram metadata and matching audit-log payloads.

== External services ==

This plugin connects to Telegram's Bot API to receive updates and send bot responses.

It sends data to Telegram only after a site administrator configures a bot token.

When enabled, the plugin may send:

* the configured bot token as part of authenticated Bot API requests from your site to Telegram
* Telegram chat identifiers and Telegram user identifiers needed to route responses
* bot response payloads generated from WordPress data that an authorized linked user explicitly requested
* webhook registration data when webhook mode is enabled

Service provider: Telegram
Terms of service: https://telegram.org/tos
Privacy policy: https://telegram.org/privacy

== Privacy ==

WP Telepilot stores Telegram linkage metadata such as Telegram user IDs, chat IDs, usernames, link timestamps, and audit-log events related to bot activity and privileged actions. WordPress privacy export and erasure tools are supported for linked user data.

== Screenshots ==

1. WP Telepilot settings page with transport, security, and diagnostics controls.
2. Telegram command hub with capability-aware menus.
3. User profile tools for one-time linking and unlinking.

== Changelog ==

= 0.3.1 =

* preserve draft schedule dates, nested transaction ownership and polling offsets when persistence fails
* recheck notification targets and suppress stale private response data during delayed delivery
* exercise real concurrent workers/browser saves and standard/custom-role command reads
* enforce active, linked, authorized private-chat access and actor-scoped content queries
* separate durable WordPress execution outcomes from retried Telegram delivery
* quarantine interrupted actions instead of replaying mutations; add Queue Recovery controls
* make post-editor links atomically single-use, revision-checked and limited to one hour
* redact sensitive logs and add queued-data retention, privacy erasure and lifecycle cleanup
* add isolated WordPress regression tests, compatibility CI and immutable release packaging

= 0.3.0 =

* first stable WP Telepilot release
* secure Telegram-to-WordPress account linking with short-lived one-time codes
* webhook transport with polling fallback, diagnostics, queue controls, and delivery hardening
* capability-aware command menus and structured workflows for posts, pages, comments, users, plugins, categories, tags, notifications, and site settings
* guided post and page creation flows plus secure browser handoff for long-form post editing
* privacy export and erasure support for linked Telegram metadata and audit records

= 0.3.0-beta.3 =

* add WordPress.org packaging metadata and plugin-directory readme
* align text-domain metadata with the `wp-telepilot` slug
* add privacy-policy helper content plus exporter and eraser hooks
* add linked-data and audit-log privacy anonymization flows

== Upgrade Notice ==

= 0.3.1 =

Existing content, normal settings and linked accounts are preserved. Legacy pending operations are quarantined; legacy editor links and confirmations must be regenerated. Notifications now require an active linked private chat, not just an allowlisted ID. Review Queue Recovery and configure reliable WordPress cron for background delivery and retention.

= 0.3.0 =

First stable WP Telepilot release with secure linking, structured Telegram workflows, transport resilience, and admin diagnostics.
