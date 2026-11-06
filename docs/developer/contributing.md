---
title: Contributing
description: Commands and rules for working on the plugin, the local merge gate, its affected-only mode, the ci:local status that attests a pass, and the nightly workflow.
sidebar:
  order: 2
---

Set up a checkout first ([local setup](../local-setup/)). A pull request runs lint and
unit tests on GitHub's runners (below), and merges after `pnpm ci:local` passed
on its tree, rebased on `origin/main`: it runs on your machine against wp-env,
and a maintainer's pass is attested on the pull request as a commit status.

## Commands

| Command | Runs |
|---|---|
| `pnpm lint` | Biome, PHPCS (WPCS 3 + WooCommerce sniffs), `tsc --noEmit` |
| `pnpm phpstan` | PHPStan level 8 |
| `pnpm test:unit` | PHPUnit unit suite (Brain Monkey, no WordPress) |
| `pnpm test:integration` | PHPUnit integration suite, HPOS off then on, then the `multisite` group on a multisite install |
| `pnpm test:js` | Vitest |
| `pnpm test:e2e` | Playwright, projects `classic` (Storefront) and `block-primary` (Twenty Twenty-Five) |
| `pnpm test:e2e:compat` | Playwright, one project per theme and page builder combination; needs `WP_ENV_COMPAT=1` on `env:start` too; runs in the Nightly, not in `ci:local` |
| `pnpm build` | `assets/src/` to `assets/build/` |
| `pnpm i18n:pot` | Regenerates `languages/pixonaut-for-woocommerce.pot` |
| `pnpm i18n:update` | Merges the `.pot` into every `.po` in `languages/` (`msgmerge`; changed strings turn fuzzy) |
| `pnpm i18n:build` | Compiles each `.po` to `.mo` and `.l10n.php` without fuzzy strings (build output, not committed) |
| `pnpm i18n:check` | Per locale: untranslated and fuzzy strings (reported), mismatched placeholders (fail) |
| `pnpm docs:generate` | Regenerates the hooks, label keys, settings, emails, REST routes and templates references in `docs/developer/` |
| `pnpm contracts:pin [<api-version>]` | Re-pins a published API release and SDK from Pixonaut's CDN and reports what changed (`docs/developer/contract.md`) |
| `pnpm contracts:consumed` | Regenerates `contracts/pixonaut/consumed.json` from the operation registries and the webhook event types (`--check` in `ci:local`) |
| `pnpm contract:check` | The pin against the release Pixonaut published on its CDN, SDK drift, and the scenario suite against a local Pixonaut when the environment names one (`--no-live` skips it) |
| `pnpm plugin-check` | Plugin Check on the working tree (skips `.distignore`) |
| `pnpm check:conflicts` | Merge conflict markers in tracked files |
| `pnpm check:docs-links` | Docs links resolve as the docs site serves them, and every page is reachable from its section's index |
| `pnpm check:headers` | Plugin header minimums vs `readme.txt`, `composer.json`, `Requirements` |
| `pnpm release:zip` | Release zip in `dist/` from the allowlist in `bin/release/allowlist.mjs`, with generated `readme.txt`; checks the allowlist, PHP 8.1 lint and Plugin Check on the unpacked zip (`docs/developer/releasing.md`) |
| `pnpm format`, `pnpm format:php` | Biome and PHPCBF fixes (PHPCBF exits 1 when it fixed something) |

PHP tools, `i18n:pot`, `docs:generate`, `plugin-check`, `release:zip`, e2e and
`ci:local` need wp-env running. PHP tools run in the `tests-cli` container through `bin/php.mjs`,
which cannot write to the checkout: anything that writes files
(`format:php`, `composer`) runs in the Composer container instead.

Run one PHP test: `node bin/php.mjs vendor/bin/phpunit --filter <name>`
(integration: `node bin/php.mjs --hpos=off vendor/bin/phpunit -c phpunit-integration.xml.dist --filter <name>`).

## Rules

- **Never `wp plugin uninstall` this plugin in wp-env.** The checkout is
  bind-mounted; uninstall deletes it. `uninstall.php` is tested by the
  integration suite instead.
- No test reaches a real Pixonaut or printshop host. The harnesses enforce
  it: the PHP integration bootstrap refuses non-loopback hosts in
  `pre_http_request`, Vitest's setup refuses non-loopback `fetch`, and the
  Playwright fixture aborts every request outside the tests site except the
  pinned SDK path on `cdn.pixonaut.app`.
- The pinned files in `contracts/pixonaut/` (the spec, vectors, `sdk/` and
  both locks) change only through `pnpm contracts:pin`; never edit them by
  hand. `consumed.json` is generated from `Api\Pixonaut::OPERATIONS`,
  `Sdk::OPERATIONS` and `Endpoint::EVENT_TYPES` by `pnpm contracts:consumed`
  (only its `pending` list of planned operations is kept by hand); the change
  that starts calling an operation regenerates it and adds the fake's handler.
- Only pixonaut-php, built by `Api\Pixonaut`, talks to Pixonaut. Hooks are named `pixonaut_wc_<area>_<name>`
  and carry a docblock (`@since`, params, example), which the unit suite
  checks; run `pnpm docs:generate` after changing one. Every hook fires in an
  integration test or is listed, with the reason, in `HookInventoryTest`.
  Retire a hook through `DeprecatedHooks`.
- Every user-facing string is translatable with the text domain
  `pixonaut-for-woocommerce`. A string whose meaning is not clear on its own
  (a word or two, a placeholder, a word with two senses) gets a
  `translators:` comment or `_x()` context; terms follow
  `languages/glossary.md` (`docs/developer/translations.md`).
- HPOS-safe order APIs only; integration tests run with HPOS on and off.
- Stored schema changes go through a new `Migration` appended to
  `Migrations::all()`; migrations are forward-only.
- Docs live in `docs/merchant/` and `docs/developer/` (Starlight frontmatter);
  update them in the same change.
- Conventional Commits (release-please reads them).


## The gate

Start wp-env first (`pnpm env:start`), then:

```sh
pnpm ci:local
```

It runs every step below, prints a pass or FAIL line for each with its time,
and exits non-zero if any step failed.

| Step | Command | Needs wp-env |
|---|---|---|
| Conflict markers | `pnpm check:conflicts` | |
| Biome | `pnpm lint:js` | |
| TypeScript | `pnpm typecheck` | |
| Workflow lint (actionlint, ShellCheck) | `pnpm lint:workflows` | |
| Header minimums | `pnpm check:headers` | |
| Docs links | `pnpm check:docs-links` | |
| PHPCS (WPCS 3, WooCommerce sniffs) | `pnpm lint:php` | yes |
| PHPStan level 8 | `pnpm phpstan` | yes |
| PHP unit | `pnpm test:unit` | yes |
| JS unit | `pnpm test:js` | |
| `.pot` freshness | `node bin/i18n-pot.mjs --check` | yes |
| Translations | `pnpm i18n:check` | |
| `consumed.json` freshness | `node bin/contracts/consumed-generate.mjs --check` | yes |
| Generated docs | `node bin/docs-generate.mjs --check` | yes |
| Plugin Check | `pnpm plugin-check` | yes |
| e2e, themes and page builders | `pnpm test:e2e:compat`: one project per combination in `tests/compat/combinations.json` ([testing](../testing/#themes-and-page-builders)) |
| Release zip | `pnpm release:zip` | yes |
| Release zip (wordpress.org), with its listing assets | `pnpm release:zip --flavor wporg` | yes |
| PHP integration, HPOS off then on, then the `multisite` group | `pnpm test:integration` | yes |
| e2e, `classic`, `block-primary` and `astra` | `pnpm test:e2e` | yes |

The `block-secondary` e2e project is not part of the gate; run it with
`pnpm test:e2e:secondary` after a change to the product page.

- **Conflict markers** fails when a tracked file has a line starting with
  `<<<<<<< ` or `>>>>>>> `, or a `=======` line between the two, as a merge
  leaves them, and names each by file and line.
- **Workflow lint** runs a pinned
  [actionlint](https://github.com/rhysd/actionlint) in a container over
  `.github/workflows/`, with ShellCheck on each `run:` script.
- **Header minimums** checks that `Requires PHP` in the plugin header agrees
  with `require.php` and `config.platform.php` in `composer.json` and with
  `Requirements::MIN_PHP`, that `WC requires at least` agrees with
  `Requirements::MIN_WOOCOMMERCE`, and that `readme.txt`, when there is one, repeats
  the header's `Requires at least`, `Tested up to` and `Requires PHP`, with a
  `Stable tag` equal to `Version`.
- **Docs links** resolves every link in `docs/` the way the docs site serves
  the pages (`docs/merchant/connect.md` at `/merchant/connect/`, an
  `index.md` at its directory), so a link is relative to the page's URL, not
  its file: `../layouts/` from a page, `./layouts/` from an index. It fails on
  a link to a page or heading that does not exist, on a page its section's
  index does not reach by links, and on a relative link in `README.md` to a
  missing file.
- **`.pot` freshness** regenerates the template in wp-env and compares it with
  `languages/pixonaut-for-woocommerce.pot`, ignoring its creation date. When it
  fails, run `pnpm i18n:pot` and commit the result.
- **Translations** reports each locale's untranslated and fuzzy strings and
  fails on a translation whose placeholders differ from its source's (see
  [Translations](../translations/#checking)).
- **`consumed.json` freshness** regenerates
  `contracts/pixonaut/consumed.json` from the operation registries and the
  webhook event types and fails when it differs: run `pnpm contracts:consumed`
  (see [Pixonaut contract](../contract/#the-consumed-list)).
- **Generated docs** rebuilds the generated sections of the
  [hooks](../hooks/), [label keys](../label-keys/), [settings](../settings/),
  [emails](../emails/), [REST routes](../rest-api/) and
  [templates](../templates/) pages and fails when one differs from the
  committed page: a hook docblock, label, setting, email, route or template
  header changed without `pnpm docs:generate`. Hooks are read from their
  docblocks in `src/`, templates from their headers in `templates/`; the rest
  from what the plugin registers in the tests site.
- **Plugin Check** runs the pinned
  [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin on the
  working tree in the tests site, skipping the development files listed in
  `.distignore`. Errors fail the step; warnings are printed. Its runtime
  checks (enqueued asset size and scope) do not run in wp-env and are left
  out. `plugin_updater_detected` is expected, since releases come from GitHub
  through the `Update URI` header; `no_plugin_readme` is expected, since
  `readme.txt` is generated into the release zip only. Nothing else is
  ignored, since wordpress.org's scan runs every check: phpcs.xml.dist leaves
  out `WordPress.Security.EscapeOutput.ExceptionNotEscaped`, as exception
  messages are not output, so a file that throws one with a message built at
  run time disables that sniff in its code, saying why, and Plugin Check
  names a new one that does not.
- **Release zip** builds the release zip and checks it: the allowlist, a PHP
  lint with the minimum PHP version, and Plugin Check on the unpacked zip. See
  [Releasing](../releasing/).

## Affected steps only

```sh
pnpm ci:local --affected
```

runs only the steps that the files changed since `origin/main` touch,
committed or not: a PHP change runs the PHP steps, a TypeScript change the
TypeScript ones, a change to `package.json`, `composer.json`, their lock files,
`.wp-env.json` or the wrappers most steps run through (`bin/env.mjs`,
`bin/php.mjs`, `bin/wp-env*.mjs`, `bin/ci-local.mjs`) runs everything. The conflict marker check runs for any change. Set `CI_LOCAL_BASE` to compare with another
ref. Use it while working; it never attests. `--dry-run` prints the steps
either mode would run and runs none.

## Attestation

A full pass on a clean working tree is recorded against the commit's tree.
When the commit is already on GitHub, the gate then posts a `ci:local` commit
status, `success`, whose description names the tree. If it is not pushed
yet, push and run:

```sh
pnpm ci:local:attest
```

That posts the status for `HEAD` only when its tree has a recorded pass and
the working tree is clean, and refuses otherwise. A gate that starts with
uncommitted or untracked files, or whose `HEAD` moves while it runs, records
nothing, since its steps did not all test the tree `HEAD` holds.

The status names the tree, not the commit, because a squash merge makes a new
commit on `main`: a branch rebased on `origin/main`, gated, and merged
straight after lands exactly the tree the status names. Recorded passes live
in the clone's common git directory, so a pass in one worktree counts for the
same tree in another.

## Nightly

The `Nightly` GitHub Actions workflow (`.github/workflows/nightly.yml`) runs
what the gate leaves out, once a night on `main`:

| Job | Runs |
|---|---|
| PHP unit | `vendor/bin/phpunit` on PHP 8.1 (the plugin's minimum) and PHP 8.5 |
| Integration | `pnpm test:integration` on each pairing of WordPress {latest, latest-1} and WooCommerce {latest, latest-1}, and on the declared minimums |
| e2e | Every Playwright project (`classic`, `block-primary`, `block-secondary`, `astra`), split by test file across four shards with a runner each, on the latest releases |
| Release zip | `pnpm release:zip`: the allowlist, the PHP lint and Plugin Check on the zip |

`latest` and `latest-1` are the newest patch of the two newest release
branches (WordPress 7.1 and 7.0, WooCommerce 11.2 and 11.1), as WordPress.org
lists them that night; a branch below the plugin header's `Requires at least`
or `WC requires at least` is left out. One more pairing tests the minimums
themselves: the newest patch of the `Requires at least` WordPress branch with
the newest patch of the `WC requires at least` WooCommerce branch (WordPress
6.9 and WooCommerce 10.6). `node bin/nightly-versions.mjs` prints the pairings
it would test.

A scheduled run is skipped when `main` and those releases are unchanged since
the last green run. Run it by hand from the Actions tab (`workflow_dispatch`)
to run it regardless. A newer run cancels one still in progress.

It has no `pull_request` or `push` trigger: pull requests are gated by
`ci:local`.

## Pull requests

The `Pull request` GitHub Actions workflow
(`.github/workflows/pull-request.yml`) runs on every pull request, a fork's
included, on GitHub-hosted runners, with read-only contents access and no
secrets:

| Job | Runs |
|---|---|
| Lint | Biome, TypeScript and workflow lint |
| PHPCS | `vendor/bin/phpcs` on PHP 8.1 |
| PHP unit | `vendor/bin/phpunit` on PHP 8.1 and PHP 8.5 |
| JS unit | `pnpm test:js` |

Integration, e2e, Plugin Check and the release zip stay in `ci:local`.
