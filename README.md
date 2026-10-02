# Fullworks Gravity to Listmonk

<!-- tooling:start (managed by wordpress-plugin-boilerplate/tooling - do not edit by hand) -->
## Development

This repository uses the standard Fullworks free-plugin tooling, documented in
[wordpress-plugin-boilerplate](https://github.com/alanef/wordpress-plugin-boilerplate/blob/main/CLAUDE.md).

[![Plugin Check](https://github.com/alanef/plugin-fullworks-gravity-to-listmonk-plugin/actions/workflows/checks.yml/badge.svg)](https://github.com/alanef/plugin-fullworks-gravity-to-listmonk-plugin/actions/workflows/checks.yml)

```
plugin-fullworks-gravity-to-listmonk-plugin/                     # repository root: development tooling
├── .github/workflows/             # checks.yml on push/PR, release.yml on tag
├── tests/                         # PHPUnit suite, run inside wp-env
├── .wp-env.json                   # dev :8850, tests :8851
├── composer.json                  # dev dependencies and quality scripts
├── package.json                   # wp-env and test scripts
├── phpunit.xml.dist / run-tests.sh
└── fullworks-gravity-to-listmonk/                # the plugin (shipped as-is via .distignore)
```

```bash
composer install && npm install        # dev tools
npm run start                          # http://localhost:8850  (admin / password)
composer run check                     # PHPCompatibility + security sniffs
npm test                               # PHPUnit in the wp-env tests container
composer run build                     # zipped/fullworks-gravity-to-listmonk-free.zip
```

Releases: set the version in the plugin header and `readme.txt`, update `CHANGELOG.md`,
tag `vX.Y.Z` and push. CI builds the zip, creates the GitHub release and deploys to
WordPress.org.
<!-- tooling:end -->

Gravity Forms feed add-on that adds form submitters to [Listmonk](https://listmonk.app) lists.
Distributed as a GitHub release zip (no WordPress.org listing yet).

- **Settings:** Forms → Settings → Listmonk — Listmonk URL, API user, API key.
- **Feeds:** Form → Settings → Listmonk — lists, e-mail/name mapping, attribute mapping,
  skip-opt-in and update-existing options, conditional logic.

## How existing subscribers are handled

Listmonk answers `409` when the e-mail already exists, and its `PUT /api/subscribers/:id`
replaces attributes wholesale, deletes every subscription not in the request and re-sends
pending double opt-in e-mails. So for an existing address the plugin:

1. leaves blocklisted subscribers untouched;
2. adds only the missing lists via `PUT /api/subscribers/lists` (`action=add`), which never
   changes existing subscriptions, so an unsubscribe stays an unsubscribe;
3. requests the opt-in e-mail (`POST /api/subscribers/:id/optin`) when a new unconfirmed
   subscription was added;
4. only when the feed opts in, updates name/attributes with `PUT /api/subscribers/:id`,
   sending merged attributes and the full list set, and skipping it when the subscriber has a
   pending double opt-in that the update would re-send.

See `fullworks-gravity-to-listmonk/includes/class-subscriber-sync.php`.

## Local Gravity Forms

Gravity Forms is licensed and never committed. For the dev site, unzip it into
`.wp-env-plugins/` (gitignored) and reference it from `.wp-env.override.json`:

```bash
mkdir -p .wp-env-plugins && unzip ~/Downloads/gravityforms_X.Y.Z.zip -d .wp-env-plugins
```

```json
{ "plugins": ["./fullworks-gravity-to-listmonk", "./.wp-env-plugins/gravityforms"] }
```

The PHPUnit suite does not need Gravity Forms: the Listmonk client and subscriber sync are
tested with mocked HTTP.

## End-to-end test

```bash
npm run test:e2e        # KEEP=1 npm run test:e2e leaves Listmonk up on :9850
```

Needs wp-env running with Gravity Forms active. Starts throwaway Postgres, Listmonk and
Mailpit containers on the wp-env network, submits real entries through a real feed (new,
repeat, existing, unsubscribed, blocklisted, update-existing, pending opt-in, bad key),
checks the resulting Listmonk state and entry notes, and counts the opt-in e-mails sent.
