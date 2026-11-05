---
title: Local setup
description: Run Pixonaut for WooCommerce, its tests and its tools with only Node, pnpm and Docker.
sidebar:
  order: 1
---

The host needs Node 22 or newer, pnpm and Docker; no PHP. WordPress runs in
[wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/),
Composer runs in a throwaway container, and every PHP tool runs inside wp-env's
`tests-cli` container.

## First run

```sh
pnpm install
pnpm composer install
pnpm env:start
```

`pnpm composer install` also runs [Strauss](https://github.com/BrianHenryIE/strauss),
which moves the plugin's one runtime dependency, plugin-update-checker, into
the `Pixonaut\WooCommerce\Vendor\` namespace in place under `vendor/`, so it
cannot clash with another plugin's copy. Composer runs it, through
`bin/strauss.php`, after every install and update. The wrapper keeps Composer's
own `vendor/composer/InstalledVersions.php` unprefixed, so
`Composer\InstalledVersions` keeps working in a development checkout.

`pnpm env:start` brings up two sites with WooCommerce, Storefront (a classic
theme) and Twenty Twenty-Five (a block theme): a development site and a tests
site. It prints their URLs. Both run the
[MCP Adapter](https://github.com/WordPress/mcp-adapter), so an MCP client can
use the plugin's [abilities](../abilities/) at
`/wp-json/mcp/mcp-adapter-default-server`, as the e2e suite does. It also starts the
[fake Pixonaut](../testing/) the sites talk to.

## Ports and checkouts

wp-env reads its ports from `WP_ENV_PORT` (development site) and
`WP_ENV_TESTS_PORT` (tests site); without them it picks its own defaults. The
fake Pixonaut listens on `FAKE_PIXONAUT_PORT`.
Nothing in the repository fixes a port. Every script sets
`WP_ENV_HOME=./.wp-env-home`, so each checkout gets its own WordPress and
several checkouts can run at once on different ports.

## Other WordPress and WooCommerce releases

The sites install the latest WordPress and WooCommerce. To start them on
other releases, set wp-env's `WP_ENV_CORE` and this project's
`WP_ENV_WOOCOMMERCE` to a wp-env source, such as a zip URL, before
`pnpm env:start`:

```sh
WP_ENV_CORE=https://wordpress.org/wordpress-7.0.7.zip \
WP_ENV_WOOCOMMERCE=https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip \
pnpm env:start
```

The [nightly workflow](../contributing/#nightly) starts its integration
cells this way. wp-env cannot switch WordPress back to the latest in place:
run `pnpm env:destroy`, then `pnpm env:start` without the variables. That
deletes both sites' content.

## Commands

| Command | What it does |
|---|---|
| `pnpm build` | Builds `assets/src/` into `assets/build/` |
| `pnpm lint` | Biome, PHPCS (WPCS 3 and the WooCommerce sniffs), actionlint and `tsc` |
| `pnpm phpstan` | PHPStan level 8 with WordPress and WooCommerce stubs |
| `pnpm test:unit` | PHP unit tests (PHPUnit and Brain Monkey, no WordPress) |
| `pnpm test:integration` | PHP integration tests against WordPress and WooCommerce, once with HPOS off and once on, then the `multisite` group on a multisite install (`WP_MULTISITE=1`) |
| `pnpm test:js` | Vitest |
| `pnpm test:e2e` | Playwright on the tests site, with a classic and a block theme |
| `pnpm i18n:pot` | Writes `languages/pixonaut-for-woocommerce.pot` |
| `pnpm i18n:update` / `i18n:build` / `i18n:check` | Merges the `.pot` into every `.po`, compiles them, checks them; see [Translations](../translations/) |
| `pnpm docs:generate` | Rewrites the generated sections of `docs/developer/` |
| `pnpm plugin-check` | Plugin Check on the working tree |
| `pnpm check:conflicts` | Checks no tracked file has merge conflict markers |
| `pnpm check:headers` | Checks the stated minimum versions agree |
| `pnpm check:docs-links` | Checks every docs link resolves and every page is reachable from its index |
| `pnpm release:zip` | Builds and checks the release zip in `dist/`; see [Releasing](../releasing/) |
| `pnpm ci:local` | The merge gate: every check above; see [Contributing](../contributing/) |
| `pnpm format` / `pnpm format:php` | Fixes formatting with Biome / PHPCBF |

The PHP commands, `pnpm i18n:pot`, `pnpm docs:generate`, `pnpm plugin-check`,
`pnpm release:zip`, `pnpm test:e2e` and `pnpm ci:local` need wp-env running.
Run `pnpm exec playwright install chromium` once before the first e2e run.

The integration suite uses its own table prefix in the tests site's database,
so it never touches the site the e2e suite drives.

## Removing a checkout

Stop its environment before deleting the directory:

```sh
pnpm env:stop      # or pnpm env:destroy, which also deletes the sites' data
```

The fake Pixonaut is a container of its own, outside wp-env's Compose project,
so `docker compose down` neither removes it nor, while it is still attached,
the network. A checkout deleted with its environment still running leaves both
behind, and enough of those networks exhaust Docker's address pools:
`pnpm env:start` then fails with `all predefined address pools have been fully
subnetted`. To clean up after a checkout that is already gone, remove its
`wp-env-<checkout>-<hash>-fake-pixonaut` container, then its
`wp-env-<checkout>-<hash>_default` network:

```sh
docker rm --force wp-env-<checkout>-<hash>-fake-pixonaut
docker network rm wp-env-<checkout>-<hash>_default
```

## Never uninstall the plugin inside wp-env

wp-env mounts the checkout into WordPress. Uninstalling the plugin there
deletes the plugin directory, which is your checkout. Deactivate it instead.
