=== WP Telepilot ===
Contributors: alefsolutions
Tags: telegram, bot, notifications, moderation, remote management
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.4.0-beta.7
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
* optional OpenAI chat with administrator-configured keys, task/role grants, usage limits, and confirmed writes
* independently optional read-only MCP through the official WordPress MCP Adapter and Abilities API

WP Telepilot 0.4.0-beta.7 is a beta candidate with WordPress.org Plugin Check cleanup, a compact admin panel, site-management-only AI instructions, concise replies, terminal denial handling, and quieter Telegram feedback. It incorporates the 0.3.1 maintenance repairs, the AI continuation queue repair, and security, ordering, privacy, pagination and content fixes. Live staging checks are required before publication. Normal commands do not require OpenAI or MCP.

Full command reference:
https://github.com/alefsolutions/wp-telepilot/blob/v4/COMMANDS.md

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

The independently optional MCP endpoint does not require Telegram linkage or an OpenAI key.

= How do I enable AI chat? =

Open the AI Assistant settings, enter an OpenAI API key, choose a model (default gpt-4.1-mini), approve tasks and role grants, enable AI, and save. Test the saved configuration, then use /chat in your linked private chat. Use /context, /newchat, /cancel, and /endchat to control the session. Ordinary commands remain deterministic. AI writes require confirmation; publishing and plugin changes require a second warning. OpenSSL AES-256-GCM is required for key storage; unsupported legacy keys must be replaced.

= How do I enable MCP? =

MCP is off by default and independent of AI. Use WordPress 6.9+ with the Abilities API and the official WordPress MCP Adapter (tested with 0.6.1). Enable MCP, grant roles and explicitly approve reads in settings. Connect an authenticated client over HTTPS using a WordPress application password to /wp-json/wp-telepilot-mcp/v1/server. No Telepilot write abilities are exposed. Missing dependencies leave ordinary commands and AI usable.

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

Optional service provider: OpenAI
Endpoint: https://api.openai.com/v1/chat/completions
Terms: https://openai.com/policies/terms-of-use/
Privacy: https://openai.com/policies/privacy-policy/

Only when AI is enabled/configured, requests send user text, bounded conversation context, approved tool schemas and minimized authorized results to OpenAI. The saved-configuration test also makes a billable request. Credentials, IP addresses and security links are excluded; supported email fields require separate administrator opt-in. The plugin requests store=false, which does not override provider retention policies. An API key and provider charges are separate from this plugin.

Optional MCP returns approved read results to the authenticated client you configure. It does not automatically send data to OpenAI. Review that client's policies separately. Official Adapter: https://github.com/WordPress/mcp-adapter

== Privacy ==

WP Telepilot stores Telegram linkage metadata such as Telegram user IDs, chat IDs, usernames, link timestamps, and audit-log events related to bot activity and privileged actions. WordPress privacy export and erasure tools are supported for linked user data.

Queued payloads and bounded AI context participate in privacy export/erasure. Sessions expire after 60 minutes of inactivity by default; proposals expire after five minutes. Terminal queue payloads are removed within 24 hours and failed-delivery payloads within seven days under hourly maintenance. Redacted audits follow the configured retention. Reliable WordPress cron is required. Erasing local data cannot erase copies already delivered externally.

== Screenshots ==

1. WP Telepilot settings page with transport, security, and diagnostics controls.
2. Telegram command hub with capability-aware menus.
3. User profile tools for one-time linking and unlinking.

== Changelog ==

= 0.4.0-beta.7 =

* prepare SQL identifiers and values explicitly while preserving queue ordering, atomic claims, transactions, privacy erasure, and confirmation locks
* add translator comments, translate literal queue repair messages, and use automatic WordPress.org translation loading
* validate browser editor request types and token formats while preserving nonce checks, rich HTML permissions, and content backslashes
* use native temporary-file cleanup, prefix uninstall variables, normalize source line endings, and shorten upgrade notices
* add actor-scoped post statistics caching with native mutation invalidation and document necessary database and HTTP progress exceptions
* add pinned Plugin Check CI validation with twelve occurrence-specific warning explanations, package checksum verification, and regression coverage
* preserve existing branding, compatibility requirements, settings, and database schema

= 0.4.0-beta.6 =

* compact the branded admin header and move resource links into a plain text-link row directly below it
* reduce heading sizes, panel padding, field spacing, and table padding within a 1200px content width
* group status, readiness, dashboard, setup, and command cards into responsive layouts that follow their content height
* preserve all six tabs, settings, diagnostics, warnings, actions, and minimum 44px controls
* expand admin fixture checks for responsive layouts, resource links, keyboard access, wrapping, and isolated forms across eight viewport widths
* keep existing settings and database schema unchanged

= 0.4.0-beta.5 =

* instruct AI chat to discuss only management of the connected WordPress site and brief, relevant WordPress guidance
* target 2-3 sentences and about 60 words for explanations, allowing longer requested lists of site results
* refresh instructions and an account-specific capability catalog on every provider request, including existing queued continuations
* distinguish supported tasks, disabled tasks, account restrictions, and exhausted tool-call budgets using the same assessment as offered tools
* stop unsupported and denied tool requests locally, record the denial in conversation history, and avoid repeated proposals or confirmation loops
* preserve real confirmation buttons, exact change previews, additional warnings, and clarification for correctable input errors
* add AI policy regressions and document live model scope and brevity evaluation; no new tools, settings, database migration, or extra classification/shortening calls

= 0.4.0-beta.4 =

* replace generic processing messages with best-effort typing feedback for Telegram commands, buttons, guided input and uploaded files
* acknowledge buttons silently and keep their existing message and keyboard visible until the final result
* suppress routine AI continuation messages while preserving provider retry notices, confirmations and errors
* refresh typing at work boundaries and during compatible cURL transfers, with bounded feedback timeouts and failure isolation
* preserve existing queued reply targets and delivery-only retries without repeating WordPress writes
* add regression coverage for quiet delivery, typing refreshes, AI continuations, legacy placeholders and feedback failures

= 0.4.0-beta.3 =

* block suspended accounts across browser and application-password authentication, invalidate browser sessions, and enforce the linking switch throughout code creation and consumption
* validate taxonomy assignments before mutation and search all eligible link-code candidates in bounded batches
* anonymize every matching audit record and retain identifiers for retry when privacy cleanup fails
* preserve arrival order within chats, prevent blocked chats from starving unrelated jobs, and return expected validation errors without quarantining subsequent commands
* add actor-bound search pagination and enforce Telegram callback byte limits, including saved delivery outcomes
* preserve backslashes through content writes and refuse default-category deletion before reassignment
* add 30 regression tests covering the fixes and equivalent shared service paths

= 0.4.0-beta.2 =

* explicitly repair historical non-null update_id columns, preserving records and Telegram update deduplication
* verify queue type, nullability and unique index using a separately tracked, locked, retryable migration
* distinguish table existence from AI queue readiness in diagnostics and Site Health; add an administrator Retry Database Repair button
* pause provider requests while repair is unresolved and stop failed continuation requests without automatic replay
* capture safe database error categories, numbers and reference IDs before rollback clears them
* add real MySQL/MariaDB migration, concurrency, denied-permission and complete mocked AI delivery regression coverage

= 0.4.0-beta.1 =

* share authorization, input validation, execution, audit and cache policies across domain commands and AI/MCP abilities
* configure authenticated-encrypted OpenAI keys, task and role grants, usage limits and provider diagnostics
* run bounded natural-language reads and follow-ups through durable continuation jobs
* bind single-use write proposals to actor, chat, site, session, linkage generation and target revision
* add independent read-only MCP with actual Adapter HTTP transport, explicit allowlists and per-call native permissions
* improve keyboard-accessible admin tabs, mobile sizing, local Site Health checks and lifecycle/privacy cleanup
* test native plugin upgrades, real concurrent queue claims and browser saves, and packaged installation

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

= 0.4.0-beta.7 =

WordPress.org Plugin Check cleanup adds explicit SQL preparation, translation comments, editor input validation, and a pinned submission gate. Existing settings and data are preserved; no schema migration is required. Complete live staging checks before publication.

= 0.4.0-beta.6 =

The admin panel now uses a compact header, plain resource links, smaller typography and spacing, and responsive card groupings. All settings, diagnostics, warnings and actions remain available. No new setting or database migration is required. Complete a live wp-admin review before publication.

= 0.4.0-beta.5 =


AI chat now stays focused on site management with concise replies and terminal denial handling. No new setting or database migration is required. Complete the documented live model evaluation before publication.

= 0.4.0-beta.4 =


Telegram uses typing feedback instead of generic processing messages. Existing queued placeholders still receive final results. Retry notices, confirmations and errors remain visible. No migration is required; typing may lapse during blocking work or unsupported transports.

= 0.4.0-beta.3 =


Settings, accounts, content and queue outcomes are preserved. Suspended users cannot authenticate; re-enabling permits fresh logins while old sessions remain invalid. Reconcile uncertain operations through Queue Recovery. Expired search controls require a new search.

= 0.4.0-beta.2 =


Back up before upgrading. Existing data is preserved. If AI queue verification fails, ask your host to check ALTER permissions and the schema, then use Retry Database Repair. Failed AI requests are not resent. Verify final AI read replies on staging before publication.

= 0.4.0-beta.1 =


Back up before this beta. Existing content, settings and linked accounts are preserved. Legacy AI actions and sessions are invalidated; unsupported keys require replacement. AI and MCP are separate opt-ins. Recheck settings and complete staging checks before release.

= 0.3.1 =


Existing data is preserved. Legacy pending operations are quarantined; regenerate old editor links and confirmations. Notifications require an active linked private chat. Review Queue Recovery and configure reliable WordPress cron for delivery and retention.

= 0.3.0 =

First stable WP Telepilot release with secure linking, structured Telegram workflows, transport resilience, and admin diagnostics.
