=== BoardKit ===
Contributors: brendan
Tags: kanban, editorial calendar, workflow, editorial
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.3.1
License: GPLv2 or later

A Kanban board and editorial calendar for WordPress content.

== Description ==

BoardKit adds two views of your posts to wp-admin: a board (cards in lanes such as Pitch, Draft, In Review, Published — drag a card to update the post) and a calendar (drag a card to reschedule it). It also adds the editorial fields a board needs: workflow stage, assignees and due date.

It works on its own. With ColumnKit active, boards can use a ColumnKit view as their card fields and any editable fixed-choice column as a lane source.

== Changelog ==

= 0.3.1 =
* Fixed: drag and drop didn't work when BoardKit was installed from the 0.3.0 release zip, because the drag library was left out of the zip. The keyboard and the ⋯ menu were unaffected.
* Fixed: after an update, browsers could keep running the previous version of the board script. Its files now carry their own version.

= 0.3.0 =
* New: move cards between lanes three ways: drag and drop, the keyboard (select a card, press Space or Enter to pick it up, the arrow keys to choose a lane, Space or Enter to drop, Escape to cancel; a hint shows the keys while you move), or the card's ⋯ menu → Move to. On small screens, use the menu.
* New: a lane can also set the WordPress status. On the Editorial board, Ready submits the post for review, Scheduled schedules it, and Published publishes it. Pitch, Writing and Editing only track where the post is in your workflow. Each such lane says what it does under its name.
* New: dropping a card into Scheduled asks when to publish it (in the site's timezone). Cancel puts the card back.
* Changed: the starter Editorial board's lanes are now Pitch → Writing → Editing → Ready → Scheduled → Published. An unedited board from 0.2.0 is updated automatically, and posts stay in their lanes.
* Moves show at once and are undone if the server refuses them. If someone else moved or changed the card first, it's redrawn where it really is, with a notice.
* Each move is checked on the server against WordPress's own permissions. A Contributor can't publish or schedule a post by dragging it. Dragging to Ready submits it for review.
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
