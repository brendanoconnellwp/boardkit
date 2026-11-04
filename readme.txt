=== BoardKit ===
Contributors: brendan
Tags: kanban, editorial calendar, workflow, editorial
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later

A Kanban board and editorial calendar for WordPress content.

== Description ==

BoardKit adds two views of your posts to wp-admin: a board (cards in lanes such as Pitch, Draft, In Review, Published — drag a card to update the post) and a calendar (drag a card to reschedule it). It also adds the editorial fields a board needs: workflow stage, assignees and due date.

It works on its own. With ColumnKit active, boards can use a ColumnKit view as their card fields and any editable fixed-choice column as a lane source.

== Changelog ==

= 0.3.0 =
* New: move cards between lanes three ways: drag and drop, the keyboard (focus a card, Space to pick it up, the arrow keys to choose a lane, Space to drop, Escape to cancel), or the card's ⋯ menu → Move to. On small screens, use the menu.
* Moves show at once and are undone if the server refuses them. If someone else moved or changed the card first, it's redrawn where it really is, with a notice.
* Each move is checked on the server against WordPress's own permissions. A Contributor can't publish or schedule a post by dragging it, and scheduling needs a future date.
* Each post keeps a history of its last 50 moves.
* Every move is announced to screen readers.

= 0.2.0 =
* New: Boards menu with a read-only board. Cards sit in lanes by workflow stage (or post status), with an Unsorted lane for posts in none of them. Each lane loads 50 cards at a time, with Load more.
* New: a starter "Editorial" board for Posts (Pitch, Draft, In Review, Approved, Scheduled, Published), created once on first run.
* New: a Board link next to All / Published / Drafts on list tables whose post type has a board.
* Cards show the title, status, author, assignees and due date (overdue dates are flagged in red and in text).
* Who sees what: users who can edit others' posts see every card; everyone else sees the posts they could open in the editor.

= 0.1.0 =
* Plugin skeleton: autoloader, self-updates from GitHub releases, uninstall cleanup, test harness.
* Updates are never offered when the plugin folder is a git checkout, so a linked development copy can't be overwritten by a release zip.
