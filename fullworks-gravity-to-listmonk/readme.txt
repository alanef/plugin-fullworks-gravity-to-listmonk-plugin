=== Fullworks Gravity to Listmonk ===
Contributors: fullworks, alanfuller
Tags: gravity forms, listmonk, newsletter, mailing list, subscribers
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.0-alpha.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Gravity Forms submitters to your self-hosted Listmonk mailing lists, with per-form feeds, field mapping and conditional logic.

== Description ==

Connects Gravity Forms to [Listmonk](https://listmonk.app), the self-hosted newsletter and mailing-list manager. Requires Gravity Forms 2.5 or later and a Listmonk install (v4 or later) with an API user.

= Features =

* One connection under Forms → Settings → Listmonk: your Listmonk URL, API user and API key, with a live connection check.
* Per-form feeds (Form Settings → Listmonk): choose one or more lists, map the e-mail and name fields, and map any form fields or merge-tag values to Listmonk subscriber attributes.
* Gravity Forms conditional logic decides whether a feed runs, e.g. only when a consent box is ticked.
* Double opt-in is respected: Listmonk sends its own confirmation e-mail for double opt-in lists. You can choose to skip opt-in per feed when the form already records consent.
* Existing subscribers are handled safely: they are added to any selected lists they are not on, without removing them from other lists, without re-subscribing anyone who unsubscribed, and without touching blocklisted addresses. Updating an existing subscriber's name and attributes is an opt-in per feed.
* Each entry gets a note saying what happened in Listmonk. Failures never block the form submission.

= Filters and actions =

* `fwgtl_subscriber` — filter the email, name, attributes, list IDs and options before they are sent.
* `fwgtl_after_subscribe` — action fired with the outcome after each feed.
* `fwgtl_http_args` — filter the HTTP arguments of every Listmonk API request.

"Gravity Forms" is a trademark of Rocketgenius, Inc. This plugin is an independent add-on and is not affiliated with or endorsed by Rocketgenius or the Listmonk project.

== Installation ==

1. Install and activate Gravity Forms.
2. Upload and activate this plugin.
3. In Listmonk, go to Users → New, create an API user with a role that can read lists and manage subscribers, and copy its token.
4. In WordPress, go to Forms → Settings → Listmonk and enter the Listmonk URL, the API user name and the token.
5. Open a form, go to Settings → Listmonk, add a feed, tick the lists and map the e-mail field.

== Frequently Asked Questions ==

= What happens when someone who is already a subscriber submits the form? =

They are added to any of the feed's lists they are not already on. Their other list memberships are left as they are. If they previously unsubscribed from one of the feed's lists they stay unsubscribed, and blocklisted addresses are never changed. Their name and attributes are only updated if you tick "Update name and attributes of existing subscribers" on the feed.

= Why was an existing subscriber's name not updated? =

When the subscriber still has an unconfirmed double opt-in subscription, Listmonk would re-send the confirmation e-mail on any update, so the update is skipped and the entry note says so.

= Does this send data to a third party? =

It sends the mapped submission data to the Listmonk server you configure, which is normally your own. A suggested privacy-policy paragraph is added under Settings → Privacy.

== Changelog ==

See [CHANGELOG.md](https://github.com/alanef/plugin-fullworks-gravity-to-listmonk-plugin/blob/main/CHANGELOG.md).
