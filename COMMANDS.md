# WP Telepilot

## Command reference

This document lists the functioning Telegram commands currently available in WP Telepilot.

Each command below includes:

- scope
- syntax
- example
- behavior

## Quick index

- Core and linking: `/start`, `/help`, `/menu`, `/site`, `/dashboard`, `/settings`, `/chatid`, `/link`, `/unlink`
- Notifications and comments: `/notifications`, `/comments`
- Content: `/posts`, `/pages`, `/media`
- Administration: `/users`, `/plugins`, `/categories`, `/tags`

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

### `/settings help`

- Scope: configuration
- Syntax: `/settings help`
- Example: `/settings help`
- Behavior: shows the safe settings command cheat sheet.

### `/settings title TITLE`

- Scope: configuration
- Syntax: `/settings title TITLE`
- Example: `/settings title My Site`
- Behavior: updates the WordPress site title.

### `/settings tagline TAGLINE`

- Scope: configuration
- Syntax: `/settings tagline TAGLINE`
- Example: `/settings tagline Telegram-first operations`
- Behavior: updates the WordPress site tagline.

### `/settings admin-email EMAIL`

- Scope: configuration
- Syntax: `/settings admin-email EMAIL`
- Example: `/settings admin-email admin@example.com`
- Behavior: updates the WordPress admin email.

### `/settings timezone TIMEZONE`

- Scope: configuration
- Syntax: `/settings timezone TIMEZONE`
- Example: `/settings timezone Pacific/Port_Moresby`
- Behavior: updates the WordPress timezone string.

### `/settings date-format FORMAT`

- Scope: configuration
- Syntax: `/settings date-format FORMAT`
- Example: `/settings date-format F j, Y`
- Behavior: updates the WordPress date format.

### `/settings time-format FORMAT`

- Scope: configuration
- Syntax: `/settings time-format FORMAT`
- Example: `/settings time-format g:i a`
- Behavior: updates the WordPress time format.

### `/settings retention DAYS`

- Scope: hardening
- Syntax: `/settings retention DAYS`
- Example: `/settings retention 45`
- Behavior: updates how many days WP Telepilot keeps audit records before daily cleanup removes older entries.

### `/settings rate-limit COMMANDS_PER_MINUTE`

- Scope: hardening
- Syntax: `/settings rate-limit COMMANDS_PER_MINUTE`
- Example: `/settings rate-limit 30`
- Behavior: updates the inbound per-minute Telegram command limit used for throttling.

### `/settings stale-window SECONDS`

- Scope: hardening
- Syntax: `/settings stale-window SECONDS`
- Example: `/settings stale-window 180`
- Behavior: updates how old a delayed Telegram update can be before WP Telepilot drops it.

### `/settings linking on|off`

- Scope: hardening
- Syntax: `/settings linking on|off`
- Example: `/settings linking off`
- Behavior: enables or disables generation and use of new Telegram linking codes.

### `/settings uninstall-cleanup on|off`

- Scope: hardening
- Syntax: `/settings uninstall-cleanup on|off`
- Example: `/settings uninstall-cleanup on`
- Behavior: chooses whether plugin uninstall will remove WP Telepilot data or preserve it for a later reinstall.

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

## Notifications commands

### `/notifications list`

- Scope: notifications
- Syntax: `/notifications list`
- Example: `/notifications list`
- Behavior: lists Telegram notification types and their enabled or disabled state.

### `/notifications help`

- Scope: notifications
- Syntax: `/notifications help`
- Example: `/notifications help`
- Behavior: shows the notifications command cheat sheet.

### `/notifications enable KEY`

- Scope: notifications
- Syntax: `/notifications enable KEY`
- Example: `/notifications enable new_comment`
- Behavior: enables the selected Telegram notification type.

### `/notifications disable KEY`

- Scope: notifications
- Syntax: `/notifications disable KEY`
- Example: `/notifications disable plugin_updates`
- Behavior: disables the selected Telegram notification type.

### `/notifications toggle KEY`

- Scope: notifications
- Syntax: `/notifications toggle KEY`
- Example: `/notifications toggle failed_login`
- Behavior: toggles the selected Telegram notification type between enabled and disabled.

## Comments commands

### `/comments help`

- Scope: comments
- Syntax: `/comments help`
- Example: `/comments help`
- Behavior: shows the comments command cheat sheet.

### `/comments pending`

- Scope: comments
- Syntax: `/comments pending`
- Example: `/comments pending`
- Behavior: lists recent pending comments and presents moderation buttons.

### `/comments approved`

- Scope: comments
- Syntax: `/comments approved`
- Example: `/comments approved`
- Behavior: lists approved comments with moderation actions and pagination.

### `/comments spam`

- Scope: comments
- Syntax: `/comments spam`
- Example: `/comments spam`
- Behavior: lists spam comments with recovery and delete actions.

### `/comments trash`

- Scope: comments
- Syntax: `/comments trash`
- Example: `/comments trash`
- Behavior: lists trashed comments with restore and permanent delete actions.

### `/comments search KEYWORD`

- Scope: comments
- Syntax: `/comments search KEYWORD`
- Example: `/comments search checkout`
- Behavior: searches comments by author, email, URL, or content with paginated results.

### `/comments details COMMENT_ID`

- Scope: comments
- Syntax: `/comments details COMMENT_ID`
- Example: `/comments details 123`
- Behavior: shows full comment context including status, post, author, admin link, and content excerpt.

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

### `/comments restore COMMENT_ID`

- Scope: comments
- Syntax: `/comments restore COMMENT_ID`
- Example: `/comments restore 123`
- Behavior: restores a trashed comment immediately.

### `/comments unspam COMMENT_ID`

- Scope: comments
- Syntax: `/comments unspam COMMENT_ID`
- Example: `/comments unspam 123`
- Behavior: removes a comment from spam immediately.

### `/comments delete COMMENT_ID`

- Scope: comments
- Syntax: `/comments delete COMMENT_ID`
- Example: `/comments delete 123`
- Behavior: permanently deletes a comment after confirmation.

### `/comments reply COMMENT_ID MESSAGE`

- Scope: comments
- Syntax: `/comments reply COMMENT_ID MESSAGE`
- Example: `/comments reply 123 Thanks for the feedback`
- Behavior: posts an approved reply as the linked WordPress user.

### Comments pagination

- Scope: comments
- Syntax: `/comments pending page:N`
- Example: `/comments pending page:2`
- Behavior: opens a specific result page. The same pattern also works for `/comments approved`, `/comments spam`, `/comments trash`, and `/comments search`.

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

### `/posts latest`

- Scope: posts
- Syntax: `/posts latest`
- Example: `/posts latest`
- Behavior: alias for `/posts list`.

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

### `/posts new`

- Scope: posts
- Syntax: `/posts new`
- Example: `/posts new`
- Behavior: starts a guided draft-creation flow and asks for the title in chat.

### `/posts create TITLE`

- Scope: posts
- Syntax: `/posts create TITLE`
- Example: `/posts create Launch checklist`
- Behavior: creates a new draft post immediately. If no title is supplied, WP Telepilot prompts for one in chat.

### `/posts title POST_ID NEW_TITLE`

- Scope: posts
- Syntax: `/posts title POST_ID NEW_TITLE`
- Example: `/posts title 321 Launch checklist v2`
- Behavior: updates the title of an existing post.

### `/posts excerpt POST_ID NEW_EXCERPT`

- Scope: posts
- Syntax: `/posts excerpt POST_ID NEW_EXCERPT`
- Example: `/posts excerpt 321 Short launch summary`
- Behavior: updates the post excerpt.

### `/posts categories POST_ID`

- Scope: posts
- Syntax: `/posts categories POST_ID`
- Example: `/posts categories 321`
- Behavior: opens an inline category checklist so you can toggle category assignments for the post.

### `/posts categories POST_ID ID_LIST`

- Scope: posts
- Syntax: `/posts categories POST_ID ID_LIST`
- Example: `/posts categories 321 4,8`
- Behavior: replaces the post category assignment using comma-separated term IDs.

### `/posts tags POST_ID ID_LIST`

- Scope: posts
- Syntax: `/posts tags POST_ID ID_LIST`
- Example: `/posts tags 321 5,9`
- Behavior: replaces the post tag assignment using comma-separated term IDs.

### `/posts schedule POST_ID YYYY-MM-DD HH:MM`

- Scope: posts
- Syntax: `/posts schedule POST_ID YYYY-MM-DD HH:MM`
- Example: `/posts schedule 321 2026-07-20 14:30`
- Behavior: schedules a post in the site's local timezone.

### `/posts open POST_ID`

- Scope: posts
- Syntax: `/posts open POST_ID`
- Example: `/posts open 321`
- Behavior: generates a secure temporary browser editor link for long-form post editing.

### `/posts publish POST_ID`

- Scope: posts
- Syntax: `/posts publish POST_ID`
- Example: `/posts publish 321`
- Behavior: publishes the specified post immediately.

### `/posts draft POST_ID`

- Scope: posts
- Syntax: `/posts draft POST_ID`
- Example: `/posts draft 321`
- Behavior: moves a published post back to draft after confirmation.

### `/posts trashed`

- Scope: posts
- Syntax: `/posts trashed`
- Example: `/posts trashed`
- Behavior: lists trashed posts with restore and permanent delete actions.

### `/posts restore POST_ID`

- Scope: posts
- Syntax: `/posts restore POST_ID`
- Example: `/posts restore 321`
- Behavior: restores a trashed post after confirmation.

### `/posts trash POST_ID`

- Scope: posts
- Syntax: `/posts trash POST_ID`
- Example: `/posts trash 321`
- Behavior: moves a post to trash after confirmation.

### `/posts delete POST_ID`

- Scope: posts
- Syntax: `/posts delete POST_ID`
- Example: `/posts delete 321`
- Behavior: permanently deletes a post after confirmation.

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

### `/pages latest`

- Scope: pages
- Syntax: `/pages latest`
- Example: `/pages latest`
- Behavior: alias for `/pages list`.

### `/pages drafts`

- Scope: pages
- Syntax: `/pages drafts`
- Example: `/pages drafts`
- Behavior: lists draft pages only with pagination and action buttons.

### `/pages search KEYWORD`

- Scope: pages
- Syntax: `/pages search KEYWORD`
- Example: `/pages search about`
- Behavior: searches pages by keyword with paginated results.

### `/pages details PAGE_ID`

- Scope: pages
- Syntax: `/pages details PAGE_ID`
- Example: `/pages details 45`
- Behavior: shows page status, slug, modified time, and the best browser access link for that page.

### `/pages title PAGE_ID NEW_TITLE`

- Scope: pages
- Syntax: `/pages title PAGE_ID NEW_TITLE`
- Example: `/pages title 45 About Telepilot`
- Behavior: updates a page title.

### `/pages slug PAGE_ID NEW_SLUG`

- Scope: pages
- Syntax: `/pages slug PAGE_ID NEW_SLUG`
- Example: `/pages slug 45 about-telepilot`
- Behavior: updates a page slug.

### `/pages status PAGE_ID STATUS`

- Scope: pages
- Syntax: `/pages status PAGE_ID STATUS`
- Example: `/pages status 45 private`
- Behavior: changes a page status to `draft`, `publish`, or `private`.

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

### `/pages delete PAGE_ID`

- Scope: pages
- Syntax: `/pages delete PAGE_ID`
- Example: `/pages delete 45`
- Behavior: permanently deletes a page after confirmation.

### Pages pagination

- Scope: pages
- Syntax: `/pages list page:N`
- Example: `/pages list page:2`
- Behavior: opens a specific result page. The same pattern also works for `/pages drafts`, `/pages search`, and `/pages trashed`.

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

### `/media recent`

- Scope: media
- Syntax: `/media recent`
- Example: `/media recent`
- Behavior: alias for `/media list`.

### `/media search KEYWORD`

- Scope: media
- Syntax: `/media search KEYWORD`
- Example: `/media search logo`
- Behavior: searches media by title with paginated results.

### `/media details ATTACHMENT_ID`

- Scope: media
- Syntax: `/media details ATTACHMENT_ID`
- Example: `/media details 88`
- Behavior: shows attachment metadata, alt text, caption, dimensions, size, and preview link when available.

### `/media open ATTACHMENT_ID`

- Scope: media
- Syntax: `/media open ATTACHMENT_ID`
- Example: `/media open 88`
- Behavior: returns the media details view together with a browser link to open the file directly.

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

### `/users details USER_ID`

- Scope: users
- Syntax: `/users details USER_ID`
- Example: `/users details 17`
- Behavior: shows a user summary including email, roles, status, registration time, and Telegram link status.

### `/users create USERNAME EMAIL ROLE`

- Scope: users
- Syntax: `/users create USERNAME EMAIL ROLE`
- Example: `/users create jane jane@example.com editor`
- Behavior: creates a new WordPress user after confirmation.

### `/users email USER_ID EMAIL`

- Scope: users
- Syntax: `/users email USER_ID EMAIL`
- Example: `/users email 17 jane@example.com`
- Behavior: updates a user email address.

### `/users display-name USER_ID DISPLAY_NAME`

- Scope: users
- Syntax: `/users display-name USER_ID DISPLAY_NAME`
- Example: `/users display-name 17 Jane Doe`
- Behavior: updates a user display name.

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

### `/users email-reset-password USER_ID`

- Scope: users
- Syntax: `/users email-reset-password USER_ID`
- Example: `/users email-reset-password 17`
- Behavior: sends the official WordPress password reset email to the selected user after confirmation.

### `/users welcome-email USER_ID`

- Scope: users
- Syntax: `/users welcome-email USER_ID`
- Example: `/users welcome-email 17`
- Behavior: re-sends the WordPress welcome email to the selected user after confirmation.

### `/users role USER_ID ROLE`

- Scope: users
- Syntax: `/users role USER_ID ROLE`
- Example: `/users role 17 editor`
- Behavior: changes the target user's role after confirmation.

### `/users delete USER_ID [REASSIGN_USER_ID]`

- Scope: users
- Syntax: `/users delete USER_ID [REASSIGN_USER_ID]`
- Example: `/users delete 17 1`
- Behavior: deletes a user after confirmation and can optionally reassign their content to another user ID.

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

### `/plugins refresh`

- Scope: plugins
- Syntax: `/plugins refresh`
- Example: `/plugins refresh`
- Behavior: refreshes WordPress plugin update metadata so list and updates results are current.

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

### `/categories help`

- Scope: categories
- Syntax: `/categories help`
- Example: `/categories help`
- Behavior: shows the category command cheat sheet.

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

### `/categories details TERM_ID`

- Scope: categories
- Syntax: `/categories details TERM_ID`
- Example: `/categories details 12`
- Behavior: shows category details including slug, description, count, and parent.

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

### `/categories slug TERM_ID NEW-SLUG`

- Scope: categories
- Syntax: `/categories slug TERM_ID NEW-SLUG`
- Example: `/categories slug 12 editorial-updates`
- Behavior: updates a category slug immediately.

### `/categories description TERM_ID NEW DESCRIPTION`

- Scope: categories
- Syntax: `/categories description TERM_ID NEW DESCRIPTION`
- Example: `/categories description 12 Editorial planning and updates`
- Behavior: updates a category description immediately.

### `/categories parent TERM_ID PARENT_ID|none`

- Scope: categories
- Syntax: `/categories parent TERM_ID PARENT_ID|none`
- Example: `/categories parent 12 3`
- Behavior: assigns or clears a category parent immediately.

### `/categories post TERM_ID`

- Scope: categories
- Syntax: `/categories post TERM_ID`
- Example: `/categories post 12`
- Behavior: starts a guided draft-creation flow with the selected category preassigned.

### `/categories post TERM_ID TITLE`

- Scope: categories
- Syntax: `/categories post TERM_ID TITLE`
- Example: `/categories post 12 Editorial rollout checklist`
- Behavior: creates a new draft immediately with the chosen category preassigned, then opens the category checklist for refinement.

### `/categories delete TERM_ID`

- Scope: categories
- Syntax: `/categories delete TERM_ID`
- Example: `/categories delete 12`
- Behavior: deletes a category after confirmation.

## Tags commands

### `/tags help`

- Scope: tags
- Syntax: `/tags help`
- Example: `/tags help`
- Behavior: shows the tag command cheat sheet.

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

### `/tags details TERM_ID`

- Scope: tags
- Syntax: `/tags details TERM_ID`
- Example: `/tags details 8`
- Behavior: shows tag details including slug, description, and count.

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

### `/tags slug TERM_ID NEW-SLUG`

- Scope: tags
- Syntax: `/tags slug TERM_ID NEW-SLUG`
- Example: `/tags slug 8 campaign-launch`
- Behavior: updates a tag slug immediately.

### `/tags description TERM_ID NEW DESCRIPTION`

- Scope: tags
- Syntax: `/tags description TERM_ID NEW DESCRIPTION`
- Example: `/tags description 8 Launch campaign taxonomy`
- Behavior: updates a tag description immediately.

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
- Media is read-only in Telegram for this release. Use `wp-admin` for uploads, then return to `/media` commands for review and browser access.

