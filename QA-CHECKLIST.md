# WP Telepilot

## QA checklist

Use this checklist before release candidates, version bumps, or production rollout changes.

## Environment and setup

- Activate the plugin on a clean WordPress install with HTTPS enabled.
- Confirm `WordPress Admin -> WP Telepilot` loads without PHP warnings or layout breakage.
- Save a bot token, webhook secret, and transport mode, then confirm the success notice appears.
- Confirm the hero section, cards, tabs, and tables render cleanly on desktop and mobile-width admin screens.

## Transport and security

- In webhook mode, save settings and confirm the webhook status turns healthy.
- Run `Refresh Webhook Status` and `Run Transport Self-Test`, then confirm diagnostics update.
- In polling mode, save settings and confirm a polling schedule is created.
- Confirm stale updates are rejected when older than the configured stale-update window.
- Confirm allowed-chat restrictions block unauthorized chats.
- Confirm sensitive actions fail outside direct/private chat.

## Linking and access

- Generate a link code from a WordPress user profile.
- Send `/link CODE` in a private Telegram chat and confirm the user profile shows the linked Telegram ID.
- Send `/unlink` in Telegram and confirm the user profile returns to `Not linked yet`.
- Disable linking from settings and confirm new `/link` attempts are rejected.

## Core command surface

- Confirm `/start`, `/help`, `/menu`, `/site`, `/chatid`, and `/settings` all return formatted responses.
- Confirm `/notifications list` renders correctly and toggles persist after enable and disable actions.
- Confirm `/settings retention 45`, `/settings rate-limit 30`, `/settings stale-window 180`, `/settings linking off`, and `/settings uninstall-cleanup on` all persist correctly.

## Content and moderation

- Confirm `/comments pending`, `/comments details ID`, `/comments approve ID`, and a destructive moderation flow all work.
- Confirm `/posts list`, `/posts search TERM`, `/posts create TITLE`, `/posts draft ID`, and `/posts open ID` all work.
- Confirm `/pages list`, `/pages drafts`, `/pages details ID`, and page preview links render as expected.
- Confirm `/media list`, `/media search TERM`, `/media details ID`, and `/media open ID` all work.

## Administration

- Confirm `/users list`, `/users create`, `/users role`, `/users reset-password`, and `/users email-reset-password` work for administrators only.
- Confirm `/plugins list`, `/plugins updates`, and plugin state changes work and log correctly.
- Confirm `/categories` and `/tags` list, search, create, update, and delete flows work within capability limits.

## Diagnostics and logs

- Confirm `Recent Command Timings` records new command executions.
- Confirm `Recent Activity` records actions, commands, chat IDs, and success or failure state.
- Confirm `Hardening Readiness` shows schema version, table readiness, cron timing, polling lock state, and uninstall behavior.
- Confirm failed Telegram deliveries surface in diagnostics and audit logs.

## Uninstall behavior

- With uninstall cleanup disabled, delete the plugin and confirm Telepilot data remains in the database for reinstall scenarios.
- With uninstall cleanup enabled, delete the plugin and confirm custom tables, options, user meta, and Telepilot transients are removed.
- Confirm uninstall clears scheduled hooks and attempts to remove the Telegram webhook.
