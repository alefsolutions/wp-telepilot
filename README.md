# TelePress

![TelePress Header](README_HEADER.png)

TelePress is a Telegram-first WordPress operations plugin. It lets authorized WordPress users link their Telegram account, inspect site state, and carry out short, structured operational tasks from chat without recreating all of `wp-admin` inside Telegram.

## What TelePress does

- Securely links a Telegram user to a WordPress user with a short-lived one-time code.
- Supports both Telegram webhook mode and polling fallback mode.
- Provides transport diagnostics for webhook health, polling health, stale updates, send failures, and link attempts.
- Gives a Telegram control surface for:
  - site overview
  - posts
  - pages
  - comments
  - media
  - users
  - categories
  - tags
- Supports search and pagination for the main content and user modules.
- Uses confirmations for destructive actions and writes activity to the TelePress audit log.

## Core commands

- `/start` starts onboarding.
- `/menu` opens the TelePress command hub.
- `/site` shows the site overview and module shortcuts.
- `/help` shows the available command surface.
- `/chatid` reveals the current Telegram chat ID.
- `/link CODE` links Telegram to the current WordPress user.
- `/unlink` removes the Telegram link.

## Admin settings

From `WordPress Admin -> TelePress`, you can:

- paste the Telegram bot token
- choose webhook or polling transport
- define the webhook secret
- restrict allowed chat IDs
- enable or disable user linking
- inspect transport diagnostics
- manually poll Telegram
- refresh webhook status
- flush queued Telegram updates

## Linking flow

1. Install and activate the plugin.
2. Create a Telegram bot in BotFather.
3. Paste the bot token into TelePress settings.
4. Save settings so TelePress can register webhook details or switch to polling fallback.
5. Open your WordPress profile and generate a one-time link code.
6. Open a private chat with the bot and send `/link CODE`.
7. Use `/menu` or `/site` to begin operating the site.

## Security and reliability highlights

- Link codes are short-lived and stored server-side as hashes.
- Sensitive actions are restricted to private chats.
- Webhook requests validate the Telegram secret header.
- Duplicate Telegram updates are ignored.
- Stale Telegram updates are dropped.
- Polling uses a lock to avoid overlapping workers.
- Audit records are captured for linking, moderation, content actions, and Telegram delivery.

## Requirements

- WordPress 6.6 or newer
- PHP 8.0 or newer
- HTTPS for webhook mode

## Project docs

- [Phase 0 feature inventory](docs/phase-0-feature-inventory.md)
- [Phase 0 threat model](docs/phase-0-threat-model.md)
- [Phase 0 scope freeze](docs/phase-0-scope-freeze.md)

## Current direction

TelePress is designed around one principle:

> Telegram is for awareness, decisions, and short actions. WordPress remains the place for long-form editing, visual design, and complex configuration.
