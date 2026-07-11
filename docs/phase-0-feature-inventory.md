# TelePress Phase 0 Feature Inventory

## Stable

- Telegram bot settings and configuration UI
- Webhook registration and webhook status inspection
- Polling fallback and manual poll trigger
- One-time Telegram linking and unlinking
- Allowed chat ID restriction
- Audit logging
- Transport diagnostics
- Posts listing, search, publish, and unpublish
- Pages listing, search, publish, draft, trash, and restore
- Comment queue review and moderation
- Media listing, search, upload, and delete
- Users listing, search, create, disable, enable, reset-password link generation, and role assignment
- Categories and tags listing, search, create, rename, and delete

## Needs Hardening

- Transport switching between webhook and polling under delivery edge cases
- Callback-heavy workflows under delayed Telegram delivery
- Media deletion warnings around content references
- User workflows that expose sensitive results outside private chat

## Experimental

- Telegram-first user administration beyond short operational actions
- Password reset generation in chat

## Deferred

- WooCommerce
- theme editing
- menu editing
- widget management
- AI action planning
- cloud multi-site control

## Duplicate Or Consolidate

- `/dashboard` should remain only as a legacy alias for `/site`
- command discovery should favor `/menu` and inline navigation over memorizing long command lists
