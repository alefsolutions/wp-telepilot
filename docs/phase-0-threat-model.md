# TelePress Phase 0 Threat Model

## Threats addressed in Phase 0

- Stolen Telegram account attempting to act as a linked WordPress user
- Bot token exposure
- Forged webhook request without the correct Telegram secret header
- Duplicate Telegram updates replaying the same action
- Stale Telegram updates arriving long after the user intended them
- Group-chat or channel use for sensitive administrative actions
- Brute-force attempts against short-lived linking codes
- Linked WordPress user losing privileges after linking
- Linked WordPress account being deleted or disabled
- Media uploads containing unexpected or malicious file payloads
- Polling overlap causing duplicate execution
- Webhook and polling transport racing each other

## Current mitigations

- Linking codes expire and are stored hashed server-side
- Webhook requests validate `X-Telegram-Bot-Api-Secret-Token`
- Duplicate update IDs are recorded and ignored
- Stale updates are dropped based on a configurable window
- Sensitive workflows are restricted to private chats
- WordPress capability checks run at action time
- Audit logging records the actor, transport, and action
- Polling uses a worker lock
- Webhook processing is ignored while polling mode is active

## Remaining risks to monitor

- A Telegram account compromise still maps to the linked WordPress user until unlinking occurs
- Password reset links sent into chat are powerful and should be used only in trusted private chats
- Media deletion cannot perfectly detect every external or plugin-side reference
- Server-level caching, WAF rules, or cron delays can still affect delivery reliability
