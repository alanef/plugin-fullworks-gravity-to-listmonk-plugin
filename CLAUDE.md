<!-- tooling:start (managed by wordpress-plugin-boilerplate/tooling - do not edit by hand) -->
# Fullworks Gravity to Listmonk - Development Guide

Tooling in this repository is standardised across the Fullworks free plugins. The master
description lives in
[wordpress-plugin-boilerplate/CLAUDE.md](https://github.com/alanef/wordpress-plugin-boilerplate/blob/main/CLAUDE.md).
**Fix tooling problems there first, then roll out** with its `bin/sync-tooling.sh`; never
hand-edit the managed files listed there.

## This repository

| | |
|---|---|
| Plugin directory | `fullworks-gravity-to-listmonk/` |
| Main file | `fullworks-gravity-to-listmonk/fullworks-gravity-to-listmonk.php` |
| Default branch | `main` |
| WordPress.org slug | `fullworks-gravity-to-listmonk` |
| wp-env ports | dev `8850`, tests `8851` |
| Version locations | plugin header `Version:`, `readme.txt` `Stable tag:` and `FWGTL_VERSION` in the main file |

CI fails when the version locations disagree.

## Commands

```bash
composer install && npm install   # first time
composer run check                # PHPCompatibility + WordPress security sniffs
npm run start                     # wp-env (dev :8850, tests :8851, admin/password)
npm test                          # PHPUnit inside the wp-env tests container
npm test -- --filter Foo          # pass PHPUnit args through
composer run build                # zipped/fullworks-gravity-to-listmonk-free.zip via wp dist-archive
```

## Release

1. Update `CHANGELOG.md` (move Unreleased to the version and date).
2. Set the version in every location above (no prerelease suffix).
3. `composer run check && npm test`.
4. Commit, tag `vX.Y.Z`, push branch and tag.
5. The `Build Release` workflow re-runs the checks, creates the GitHub release with the zip
   attached, then deploys to whatever the plugin's `Type:` calls for.

## Where a release goes

`Build Release` reads a `Type:` header from the plugin's `readme.txt` and deploys
accordingly. Every plugin gets a GitHub release; `Type:` only decides what else:

| `readme.txt` | GitHub | WordPress.org | Freemius |
|---|---|---|---|
| *(no `Type:` header)* | yes | | |
| `Type: free` | yes | yes | |
| `Type: freemium` | yes | yes | yes |
| `Type: premium` | yes | | yes |

Omitting the header is the safe default -- a plugin is never published anywhere
public by accident. A header that is present but unrecognised **fails the
release**, because that is a typo rather than a choice, and silently falling back
to GitHub-only is how a plugin quietly stops reaching WordPress.org for months.
The resolved targets are echoed in the job log and the run summary.

WordPress.org deploys need the `SVN_USERNAME` and `SVN_PASSWORD` repository
secrets; the step fails loudly if they are missing or the SVN tag already exists.
<!-- tooling:end -->

## Project notes

- Plugin code: `includes/api/class-client.php` (Listmonk HTTP), `includes/class-subscriber-sync.php`
  (create-or-update rules), `includes/gf/class-feed-addon.php` (GF settings, feeds, `process_feed`).
  The feed add-on is `require`d at `gform_loaded` and excluded from the classmap; keep
  GF-independent logic out of it so PHPUnit (which runs without Gravity Forms) can cover it.
- Listmonk gotchas the sync relies on (verified against Listmonk source and `npm run test:e2e`):
  `POST /api/subscribers` answers 409 for an existing e-mail; `PUT /api/subscribers/:id`
  replaces attribs, deletes subscriptions not listed and re-sends pending double opt-in
  e-mails; `PUT /api/subscribers/lists` `action=add` leaves existing subscriptions alone and
  sends no e-mail, so follow it with `POST /api/subscribers/:id/optin`. Auth header is
  `Authorization: token user:key`.
- `npm test` for the PHPUnit suite, `npm run test:e2e` for the real-Listmonk run (needs
  Gravity Forms in `.wp-env-plugins/`, see README). Gravity Forms is licensed: never commit it.
- Releases are GitHub only for now (no `Type:` header in `readme.txt`).
