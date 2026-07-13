# WP Telepilot

## Command reference

This document lists the functioning Telegram commands currently available in WP Telepilot.

Each command below includes:

- scope
- syntax
- example
- behavior

## Core commands

### `/start`

- Scope: onboarding
- Syntax: `/start`
- Example: `/start`
- Behavior: starts the bot onboarding flow, shows your current chat ID, and gives next-step guidance for linking Telegram to a WordPress user.

### `/help`

- Scope: discovery
- Syntax: `/help`
- Example: `/help`
- Behavior: shows the available command surface based on the linked user and their WordPress capabilities.

### `/menu`

- Scope: navigation
- Syntax: `/menu`
- Example: `/menu`
- Behavior: opens the main command hub with shortcut buttons.

### `/site`

- Scope: dashboard
- Syntax: `/site`
- Example: `/site`
- Behavior: shows a site overview with WordPress, PHP, plugin, comment, and update summary information.

### `/dashboard`

- Scope: dashboard
- Syntax: `/dashboard`
- Example: `/dashboard`
- Behavior: legacy alias for `/site`.

### `/settings`

- Scope: configuration
- Syntax: `/settings`
- Example: `/settings`
- Behavior: shows a settings summary including the admin settings URL, transport mode, and linking status.

### `/chatid`

- Scope: identity
- Syntax: `/chatid`
- Example: `/chatid`
- Behavior: shows the current Telegram chat ID and Telegram user ID.

## Linking commands

### `/link CODE`

- Scope: account linking
- Syntax: `/link CODE`
- Example: `/link AB12CD34`
- Behavior: links the current Telegram user to a WordPress user using a one-time code generated from the WordPress user profile.

### `/unlink`

- Scope: account linking
- Syntax: `/unlink`
- Example: `/unlink`
- Behavior: removes the Telegram link from the currently linked WordPress user.

## Comments commands

### `/comments pending`

- Scope: comments
- Syntax: `/comments pending`
- Example: `/comments pending`
- Behavior: lists recent pending comments and presents moderation buttons.

### `/comments approve COMMENT_ID`

- Scope: comments
- Syntax: `/comments approve COMMENT_ID`
- Example: `/comments approve 123`
- Behavior: approves a pending comment immediately.

### `/comments reject COMMENT_ID`

- Scope: comments
- Syntax: `/comments reject COMMENT_ID`
- Example: `/comments reject 123`
- Behavior: moves a comment back to moderation hold after confirmation.

### `/comments spam COMMENT_ID`

- Scope: comments
- Syntax: `/comments spam COMMENT_ID`
- Example: `/comments spam 123`
- Behavior: marks a comment as spam after confirmation.

### `/comments trash COMMENT_ID`

- Scope: comments
- Syntax: `/comments trash COMMENT_ID`
- Example: `/comments trash 123`
- Behavior: moves a comment to trash after confirmation.

## Posts commands

### `/posts help`

- Scope: posts
- Syntax: `/posts help`
- Example: `/posts help`
- Behavior: shows the posts command cheat sheet.

### `/posts list`

- Scope: posts
- Syntax: `/posts list`
- Example: `/posts list`
- Behavior: lists recent posts with pagination and action buttons.

### `/posts drafts`

- Scope: posts
- Syntax: `/posts drafts`
- Example: `/posts drafts`
- Behavior: lists draft posts with pagination and action buttons.

### `/posts search KEYWORD`

- Scope: posts
- Syntax: `/posts search KEYWORD`
- Example: `/posts search launch`
- Behavior: searches posts by keyword with paginated results.

### `/posts stats`

- Scope: posts
- Syntax: `/posts stats`
- Example: `/posts stats`
- Behavior: shows post counts by status.

### `/posts publish POST_ID`

- Scope: posts
- Syntax: `/posts publish POST_ID`
- Example: `/posts publish 321`
- Behavior: publishes the specified post immediately.

### `/posts unpublish POST_ID`

- Scope: posts
- Syntax: `/posts unpublish POST_ID`
- Example: `/posts unpublish 321`
- Behavior: moves a published post back to draft after confirmation.

### Posts pagination

- Scope: posts
- Syntax: `/posts list page:N`
- Example: `/posts list page:2`
- Behavior: opens a specific result page. The same pattern also works for `/posts drafts` and `/posts search`.

## Pages commands

### `/pages help`

- Scope: pages
- Syntax: `/pages help`
- Example: `/pages help`
- Behavior: shows the pages command cheat sheet.

### `/pages list`

- Scope: pages
- Syntax: `/pages list`
- Example: `/pages list`
- Behavior: lists recent pages with pagination, action buttons, and preview or admin links where available.

### `/pages search KEYWORD`

- Scope: pages
- Syntax: `/pages search KEYWORD`
- Example: `/pages search about`
- Behavior: searches pages by keyword with paginated results.

### `/pages trashed`

- Scope: pages
- Syntax: `/pages trashed`
- Example: `/pages trashed`
- Behavior: lists trashed pages with pagination and restore options.

### `/pages publish PAGE_ID`

- Scope: pages
- Syntax: `/pages publish PAGE_ID`
- Example: `/pages publish 45`
- Behavior: publishes the specified page immediately.

### `/pages draft PAGE_ID`

- Scope: pages
- Syntax: `/pages draft PAGE_ID`
- Example: `/pages draft 45`
- Behavior: moves a page back to draft after confirmation.

### `/pages trash PAGE_ID`

- Scope: pages
- Syntax: `/pages trash PAGE_ID`
- Example: `/pages trash 45`
- Behavior: moves a page to trash after confirmation.

### `/pages restore PAGE_ID`

- Scope: pages
- Syntax: `/pages restore PAGE_ID`
- Example: `/pages restore 45`
- Behavior: restores a trashed page immediately.

### Pages pagination

- Scope: pages
- Syntax: `/pages list page:N`
- Example: `/pages list page:2`
- Behavior: opens a specific result page. The same pattern also works for `/pages search` and `/pages trashed`.

## Media commands

### `/media help`

- Scope: media
- Syntax: `/media help`
- Example: `/media help`
- Behavior: shows the media command cheat sheet.

### `/media list`

- Scope: media
- Syntax: `/media list`
- Example: `/media list`
- Behavior: lists recent media items with pagination and action buttons.

### `/media search KEYWORD`

- Scope: media
- Syntax: `/media search KEYWORD`
- Example: `/media search logo`
- Behavior: searches media by title with paginated results.

### `/media delete ATTACHMENT_ID`

- Scope: media
- Syntax: `/media delete ATTACHMENT_ID`
- Example: `/media delete 88`
- Behavior: deletes a media item after confirmation.

### Media upload by message

- Scope: media
- Syntax: send a photo or document to the bot in a private chat
- Example: upload an image directly in Telegram without typing a slash command
- Behavior: imports the uploaded Telegram file into the WordPress media library and returns the new attachment details.

### Media pagination

- Scope: media
- Syntax: `/media list page:N`
- Example: `/media list page:2`
- Behavior: opens a specific result page. The same pattern also works for `/media search`.

## Users commands

### `/users help`

- Scope: users
- Syntax: `/users help`
- Example: `/users help`
- Behavior: shows the users command cheat sheet.

### `/users list`

- Scope: users
- Syntax: `/users list`
- Example: `/users list`
- Behavior: lists users with pagination and action buttons.

### `/users search KEYWORD`

- Scope: users
- Syntax: `/users search KEYWORD`
- Example: `/users search jane`
- Behavior: searches users by username, display name, or email with paginated results.

### `/users create USERNAME EMAIL ROLE`

- Scope: users
- Syntax: `/users create USERNAME EMAIL ROLE`
- Example: `/users create jane jane@example.com editor`
- Behavior: creates a new WordPress user after confirmation.

### `/users disable USER_ID`

- Scope: users
- Syntax: `/users disable USER_ID`
- Example: `/users disable 17`
- Behavior: disables a user account after confirmation.

### `/users enable USER_ID`

- Scope: users
- Syntax: `/users enable USER_ID`
- Example: `/users enable 17`
- Behavior: re-enables a previously disabled user account immediately.

### `/users reset-password USER_ID`

- Scope: users
- Syntax: `/users reset-password USER_ID`
- Example: `/users reset-password 17`
- Behavior: generates a password reset link and returns that link to the Telegram admin after confirmation.

### `/users send-reset USER_ID`

- Scope: users
- Syntax: `/users send-reset USER_ID`
- Example: `/users send-reset 17`
- Behavior: sends the official WordPress password reset email to the selected user after confirmation.

### `/users role USER_ID ROLE`

- Scope: users
- Syntax: `/users role USER_ID ROLE`
- Example: `/users role 17 editor`
- Behavior: changes the target user’s role after confirmation.

### Users pagination

- Scope: users
- Syntax: `/users list page:N`
- Example: `/users list page:2`
- Behavior: opens a specific result page. The same pattern also works for `/users search`.

## Plugins commands

### `/plugins help`

- Scope: plugins
- Syntax: `/plugins help`
- Example: `/plugins help`
- Behavior: shows the plugins command cheat sheet.

### `/plugins list`

- Scope: plugins
- Syntax: `/plugins list`
- Example: `/plugins list`
- Behavior: lists installed plugins with pagination, current status, version information, and action buttons.

### `/plugins search KEYWORD`

- Scope: plugins
- Syntax: `/plugins search KEYWORD`
- Example: `/plugins search seo`
- Behavior: searches installed plugins by identifier, name, file, or author with paginated results.

### `/plugins updates`

- Scope: plugins
- Syntax: `/plugins updates`
- Example: `/plugins updates`
- Behavior: lists installed plugins that currently have updates available.

### `/plugins details IDENTIFIER`

- Scope: plugins
- Syntax: `/plugins details IDENTIFIER`
- Example: `/plugins details akismet`
- Behavior: shows plugin file, version, active state, author, and update availability.

### `/plugins activate IDENTIFIER`

- Scope: plugins
- Syntax: `/plugins activate IDENTIFIER`
- Example: `/plugins activate akismet`
- Behavior: activates an installed plugin after confirmation.

### `/plugins deactivate IDENTIFIER`

- Scope: plugins
- Syntax: `/plugins deactivate IDENTIFIER`
- Example: `/plugins deactivate akismet`
- Behavior: deactivates an installed plugin after confirmation.

### `/plugins update IDENTIFIER`

- Scope: plugins
- Syntax: `/plugins update IDENTIFIER`
- Example: `/plugins update akismet`
- Behavior: updates an installed plugin after confirmation.

### `/plugins delete IDENTIFIER`

- Scope: plugins
- Syntax: `/plugins delete IDENTIFIER`
- Example: `/plugins delete akismet`
- Behavior: deletes an installed plugin after confirmation. The plugin must be inactive first.

### Plugins pagination

- Scope: plugins
- Syntax: `/plugins list page:N`
- Example: `/plugins list page:2`
- Behavior: opens a specific result page. The same pattern also works for `/plugins search` and `/plugins updates`.

## Categories commands

### `/categories list`

- Scope: categories
- Syntax: `/categories list`
- Example: `/categories list`
- Behavior: lists categories with pagination and action buttons.

### `/categories search KEYWORD`

- Scope: categories
- Syntax: `/categories search KEYWORD`
- Example: `/categories search news`
- Behavior: searches categories by name with paginated results.

### `/categories create NAME`

- Scope: categories
- Syntax: `/categories create NAME`
- Example: `/categories create Editorial`
- Behavior: creates a new category immediately.

### `/categories rename TERM_ID NEW NAME`

- Scope: categories
- Syntax: `/categories rename TERM_ID NEW NAME`
- Example: `/categories rename 12 Editorial Updates`
- Behavior: renames an existing category immediately.

### `/categories delete TERM_ID`

- Scope: categories
- Syntax: `/categories delete TERM_ID`
- Example: `/categories delete 12`
- Behavior: deletes a category after confirmation.

## Tags commands

### `/tags list`

- Scope: tags
- Syntax: `/tags list`
- Example: `/tags list`
- Behavior: lists tags with pagination and action buttons.

### `/tags search KEYWORD`

- Scope: tags
- Syntax: `/tags search KEYWORD`
- Example: `/tags search launch`
- Behavior: searches tags by name with paginated results.

### `/tags create NAME`

- Scope: tags
- Syntax: `/tags create NAME`
- Example: `/tags create Campaign`
- Behavior: creates a new tag immediately.

### `/tags rename TERM_ID NEW NAME`

- Scope: tags
- Syntax: `/tags rename TERM_ID NEW NAME`
- Example: `/tags rename 8 Campaign Launch`
- Behavior: renames an existing tag immediately.

### `/tags delete TERM_ID`

- Scope: tags
- Syntax: `/tags delete TERM_ID`
- Example: `/tags delete 8`
- Behavior: deletes a tag after confirmation.

## Permissions and behavior notes

- Commands only appear or work when the linked WordPress user has the required capability.
- Sensitive actions are restricted to private Telegram chats.
- Some destructive operations use inline confirmation buttons before they execute.
- Pagination uses the `page:N` suffix pattern on supported list and search commands.
- Telegram file upload to media works by sending a file directly to the bot in private chat rather than by slash command.
