# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-04

### Added

- The plugin now updates itself from this repository's GitHub releases through WordPress's normal update screens, since it is not on WordPress.org. It uses the `Update URI` header (WordPress 5.8+), finds the latest release from the github.com redirect rather than the GitHub API (no token, no rate limit), installs the release zip into the folder the plugin already lives in, and caches the lookup for 6 hours (1 hour after a failure) so an unreachable GitHub never slows the admin. "Check again" on Dashboard → Updates refreshes it.

## [1.0.0] - 2026-10-02

### Added

- Gravity Forms add-on settings (Forms → Settings → Listmonk) for the Listmonk URL, API user and API key, with a live connection check that lists how many lists the API user can see. A pasted `/admin` or `/api` URL is trimmed to the Listmonk root.
- Per-form Listmonk feeds: choose one or more lists, map the e-mail and name fields, map any form field or merge-tag value to Listmonk subscriber attributes, and gate the feed with Gravity Forms conditional logic.
- Per-feed "Skip double opt-in" option for forms that already record consent; otherwise Listmonk sends its own confirmation e-mail for double opt-in lists.
- Existing subscribers are handled without collateral damage. Listmonk's `PUT /api/subscribers/:id` replaces all attributes, drops every list not named in the request and re-sends pending opt-in e-mails, so it is not used to add lists. Instead only the lists the subscriber is missing are added, an earlier unsubscribe is left in place, blocklisted addresses are not touched, and a newly added double opt-in list gets its confirmation e-mail.
- Per-feed "Update name and attributes of existing subscribers" option, off by default. Attributes are merged into the existing ones and all current lists are kept; the update is skipped (and the entry note says why) when it would re-send a confirmation e-mail the subscriber has already been sent.
- An entry note on every submission saying what happened in Listmonk, the Listmonk subscriber ID stored in entry meta, and feed errors reported through Gravity Forms' standard feed-error handling. A Listmonk failure never blocks the form.
- `fwgtl_subscriber` filter, `fwgtl_after_subscribe` action and `fwgtl_http_args` filter for customisation.
- Suggested privacy-policy text describing the data sent to Listmonk.
