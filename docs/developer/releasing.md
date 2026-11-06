---
title: Releasing
description: How versions, the changelog, readme.txt and the release zip are made, what the zip may contain, and how stores get updates.
sidebar:
  order: 4
---

Releases are cut by [release-please](https://github.com/googleapis/release-please)
from the Conventional Commits on `main`, and built and checked by
`pnpm release:zip`.

## Versions and the changelog

The `Release` workflow (`.github/workflows/release.yml`) runs only when
started by hand, on GitHub-hosted runners: from the repository's **Actions**
tab (**Release**, then **Run workflow** on `main`), or with

```sh
gh workflow run Release --ref main
```

Each run opens or updates one release pull request, which:

- bumps the version in `package.json`, the plugin header's `Version` and
  `Plugin::VERSION` (both marked for release-please in their files);
- adds the release's section to `CHANGELOG.md`.

The version follows semver from the commit types: `fix` is a patch, `feat` a
minor, and a breaking change (`feat!:` or a `BREAKING CHANGE:` footer) a major.
The first release is 1.0.0, from the repository's first releasable commit.
Configuration is in `release-please-config.json`; `.release-please-manifest.json`
records the last released version.

release-please opens that pull request with the workflow's `GITHUB_TOKEN`,
so the repository needs **Settings → Actions → General → Allow GitHub Actions
to create and approve pull requests**. Without it the run fails with "GitHub
Actions is not permitted to create or approve pull requests"; the alternative
is a personal access token passed to the action as its `token`.

Merge the release pull request, then run the workflow again: that run tags
the version (`v1.1.0`) and creates the GitHub Release, checks that no
template's `@version` is newer than the version
(`pnpm release:check-templates`), then builds the zip
from that tag, verifies them, and attaches `pixonaut-for-woocommerce-<version>.zip`,
the [wordpress.org flavor](#flavors) `pixonaut-for-woocommerce-<version>-wporg.zip`
and a `.sha256` file for each to the Release. Last, it
[publishes the release to wordpress.org](#wordpressorg) when the repository
has the SVN secrets.

## Following an SDK release

A shop loads the SDK release the plugin pins, so a shop shows nothing of a new
SDK until the plugin moves to it. Move in a pull request of its own when
Pixonaut releases one, and before cutting a plugin release:

1. Read that release's notes on
   [Pixonaut's SDK releases page](https://docs.pixonaut.app/sdk-releases/).
2. [Move the pin](../contract/#moving-the-pin):
   `pnpm contracts:pin --sdk <version>`.
3. Copy the version, script path and integrity it prints into the
   `Storefront\Sdk` constants, and list any new call the SDK makes in
   `Sdk::OPERATIONS`.
4. Run `pnpm typecheck`, fix what the notes name (renamed messages, changed
   mount options, the storefront's styles), and update the merchant docs for
   what customers now see.
5. Run the storefront e2e specs, then `pnpm ci:local`.

## The zip

```sh
pnpm env:start
pnpm release:zip
```

builds `dist/pixonaut-for-woocommerce-<version>.zip` and its SHA-256 file, then
verifies the zip. It needs Docker, and wp-env running for Plugin Check.

1. Builds the assets (`pnpm build`) and compiles the translations
   (`pnpm i18n:build`; see [Translations](../translations/)).
2. Copies the allowlisted sources, without the translators' glossary
   (`NOT_SHIPPED` in the allowlist), into a staging directory and runs
   `composer install --no-dev --no-scripts` there, in a Composer container,
   so `vendor/` holds production packages only. On rootful Docker, as on
   GitHub's runners, the container runs as the calling user, so its files
   stay removable.
3. Prefixes the bundled packages with Strauss (pixonaut-php, `nyholm/psr7`,
   the PSR interfaces and plugin-update-checker, as
   `Pixonaut\WooCommerce\Vendor\…`), run from the checkout's Composer dev
   install through `bin/strauss.php` (so `pnpm composer install` must have
   run), drops Strauss's development-only
   `vendor/composer/autoload_aliases.php` and the packages' own dotfiles, and
   writes the production autoloader
   (`composer dump-autoload --no-dev --classmap-authoritative`). The
   checkout is mounted read-only, so `bin/strauss-psr-log.php` hands
   Strauss's logger the unprefixed `Psr\Log` interfaces from the staging
   directory.
4. Generates `readme.txt` (below).
5. Zips the staging directory under a top-level `pixonaut-for-woocommerce/`,
   in sorted order with a fixed timestamp on every entry, so the same tag
   always builds the same bytes and the same SHA-256.
6. Lists the zip and fails on any path outside the allowlist, on a missing
   required file (a shipped `.po` file's compiled `.mo` and `.l10n.php`
   included), and on any development-only Composer package.
7. Unpacks the zip, lints every PHP file with the plugin's minimum PHP
   (`Requires PHP`, in a `php:<version>-cli` container), and runs Plugin Check
   on the unpacked files in the tests site.

`pnpm ci:local` runs the same command as its **Release zip** step.
`pnpm release:check-zip <zip>` runs only the listing check on any zip.

### What the zip may contain

The allowlist is `bin/release/allowlist.mjs`. Every path sits under
`pixonaut-for-woocommerce/`:

| Path | |
|---|---|
| `pixonaut-for-woocommerce.php`, `uninstall.php` | required |
| `readme.txt`, `LICENSE`, `CHANGELOG.md` | required |
| `composer.json` | required: names the packages in `vendor/` (the staged one, so the wporg flavor's has no update checker) |
| `src/**.php`, `templates/**.php` | |
| `assets/build/` | built scripts, styles, `.asset.php`, images and fonts |
| `languages/` | `.pot`, `.po`, `.mo`, `.json`, `.l10n.php`; each `pixonaut-for-woocommerce-<locale>.po` requires its `.mo` and `.l10n.php` |
| `vendor/` | Composer's production install, prefixed by Strauss; `vendor/autoload.php` required |

Never, even inside those directories: dotfiles, TypeScript sources and
declaration files (`.ts`, `.d.ts`), source maps, `tests/` or `test/`
directories, `vendor/bin/`, packages from `composer.lock`'s `packages-dev`,
Strauss's `vendor/composer/autoload_aliases.php`, and the Pixonaut SDK (`pixonaut*.js`, such as `pixonaut.iife.js` and its glue
`pixonaut_wasm.js`, and any `.wasm`), which loads from
`cdn.pixonaut.app` only. Everything else in the repository, from `contracts/`
and `docs/` to tooling config and lockfiles, is outside the allowlist.

A file that should ship but fails the check needs an allowlist entry in the
same change, with a test in `tests/js/release.test.ts`.

### Flavors

`pnpm release:zip` builds the **github** flavor, the one attached to GitHub
Releases. `pnpm release:zip --flavor wporg` builds the **wordpress.org**
flavor, `dist/pixonaut-for-woocommerce-<version>-wporg.zip`, for when the
plugin is listed there and wordpress.org delivers its updates:

- `src/Updates/GitHubUpdater.php`, `src/Updates/GitHubReleases.php` and
  plugin-update-checker are left out: the build drops it from the staged
  `composer.json`, its Strauss settings and `composer.lock`
  (`composer update yahnis-elsts/plugin-update-checker --no-install`), and
  installs the other packages as the lock pins them. The
  plugin then has no update channel setting and never contacts GitHub.
- The `Update URI` header is removed from the main plugin file.
- The translations' `.po` sources are left out: WordPress loads only the
  compiled `.mo` and `.l10n.php` files, and without them the zip fits
  wordpress.org's 10 MB upload limit for a new plugin, which the build
  enforces.
- The allowlist check fails if any of them is left, and Plugin Check runs
  without ignoring `plugin_updater_detected`.
- The build also checks `readme.txt` against wordpress.org's readme rules
  (both flavors: every header, at most five tags, a short description of at
  most 150 characters, a Description section) and the
  [listing assets](#listing-assets) against its Screenshots section.

`pnpm release:check-zip --flavor wporg <zip>` checks a wordpress.org zip's
listing. The github flavor's zip, in turn, must hold the update checker.
Building one flavor leaves the other's zip in `dist/`, so the release
workflow attaches both. `pnpm ci:local` builds both, as its **Release zip**
and **Release zip (wordpress.org)** steps.

## Updates

A store updates from the repository's GitHub Releases through
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
(`src/Updates/`), which reads the repository from the `Update URI` header.
For the merchant's side, see [Updates](../../merchant/updates/).

- **Token.** The repository is public, so checks need no token: the checker
  reads the release list anonymously and downloads the zip from its public
  download URL. `PIXONAUT_WC_UPDATE_TOKEN` in `wp-config.php` is optional, for
  a build whose `Update URI` names a private repository, or past GitHub's
  limit on anonymous requests; when it is set, every
  request carries it as a Basic authorization header, never in a URL, so the
  update the checker caches holds only the asset's API URL.
- **What is offered.** Only GitHub Releases, never tags or branches, and only
  their `pixonaut-for-woocommerce-<version>.zip` asset (never the
  wordpress.org zip or the checksum); a release without that zip, say while
  the release workflow is still uploading it, is skipped. The stable channel
  takes the highest `X.Y.Z` release that is not a pre-release; the beta
  channel also takes `X.Y.Z-beta.N`. Versions are compared, not publication
  dates, so a maintenance `1.4.1` published after `2.0.0` does not hide it.
  The 20 most recently published releases are searched.
- **Update state.** Each channel caches its last check and the update it
  found in its own site option, `pixonaut_wc_update_state_stable` or
  `pixonaut_wc_update_state_beta`: on a network whose sites are on different
  channels, a stable site never sees a beta another site found. Changing the
  channel needs no reset.
- **Major updates.** The plugins list shows a warning under an update whose
  major version is higher, or, below 1.0.0, whose minor version is higher,
  with the update's `upgrade_notice` when it has one. wordpress.org reads it
  from `readme.txt`; the GitHub build takes it from the `BREAKING CHANGES`
  section of the release notes, as plain text cut to 300 characters. Filter
  the warning with `pixonaut_wc_updates_upgrade_notice`.

A beta is a GitHub pre-release tagged `vX.Y.Z-beta.N` with the github zip
attached.

## readme.txt

`readme.txt` exists only in the zip. It is generated from:

- `readme.src.txt`: the title, tags, licence, descriptions, FAQ and the
  **External services** section, which names the Pixonaut API and the SDK on
  `cdn.pixonaut.app` separately: what each is for, what is sent, and when.
  Edit this file to change the readme.
- The plugin header: `Requires at least`, `Tested up to`, `Requires PHP`, and
  `Version` as `Stable tag`.
- `CHANGELOG.md`: the **Changelog** section, every release with its sections
  and entries, links reduced to their text.
- The **Upgrade Notice** section: one entry for the version being released,
  and one for every release with breaking changes, holding those changes (cut
  at 300 characters, wordpress.org's limit). A release without breaking
  changes reads "No breaking changes: safe to update."

`readme.src.txt` must not contain the Changelog or Upgrade Notice sections;
the build refuses it if it does.

## wordpress.org

### Listing assets

The plugin page's banners, icons and screenshots are in `.wordpress-org/`,
published to the SVN repository's `assets/` directory and never in the zip:

| File | |
|---|---|
| `banner-772x250.png`, `banner-1544x500.png` | the page's banner, and its high-DPI copy |
| `icon-128x128.png`, `icon-256x256.png`, `icon.svg` | the plugin's icon |
| `screenshot-<n>.png` | caption `<n>.` of the Screenshots section in `readme.src.txt` |

`node bin/release/listing-assets.mjs <readme.txt>` checks that every banner
and icon is there at its size, and that the screenshots and the captions
match one to one; the wordpress.org zip build runs it. A new screenshot needs
its caption in the same change.

### Publishing

`.github/workflows/wporg.yml` publishes a release to
`https://plugins.svn.wordpress.org/pixonaut-for-woocommerce` with plain `svn`
commands. It downloads the release's wordpress.org zip and checks its SHA-256
and its `Stable tag`, syncs the unpacked zip into `trunk/` (files the release
no longer has are deleted), copies `trunk/` to `tags/<version>`, syncs
`.wordpress-org/` at that tag into `assets/`, and commits. It refuses a
pre-release and a version whose tag SVN holds already.

It runs as the last job of the Release workflow, and by hand for a release
that exists already, such as the first one once wordpress.org has approved
the plugin:

```sh
gh workflow run wordpress.org --ref main -f tag=v1.1.0
```

It needs the repository secrets `WPORG_SVN_USERNAME` and `WPORG_SVN_PASSWORD`
(**Settings → Secrets and variables → Actions**): the wordpress.org username
with commit access to the plugin and its SVN password, set under **Account &
Security** in the wordpress.org profile. Without them the job publishes
nothing and says so.

