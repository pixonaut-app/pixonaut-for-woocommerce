---
title: Translations
description: How the plugin's strings are translated from context, how to add a language, and how to keep translations up to date.
sidebar:
  order: 5
---

Every user-facing string is translatable with the text domain
`pixonaut-for-woocommerce`. Translations live in `languages/`:

| File | What it is |
|---|---|
| `pixonaut-for-woocommerce.pot` | The template: every string, generated from the source by `pnpm i18n:pot` |
| `pixonaut-for-woocommerce-<locale>.po` | One language's translations, e.g. `pixonaut-for-woocommerce-sv_SE.po` |
| `glossary.md` | The word for each product and WooCommerce term in every language, and each language's register |
| `*.mo`, `*.l10n.php` | Compiled from the `.po` files by `pnpm i18n:build`; not committed |

The plugin is translated into the languages Pixonaut's customer surfaces
speak: Swedish, German, French, Spanish, Dutch, Danish, Norwegian Bokmål and
Finnish, beside the English source.

## Translating from context

A string is never translated word for word. Before translating one, read:

- **The glossary**, `languages/glossary.md`. Product terms (blank, template,
  side, proof, designer link and the rest) use the glossary's word, which
  follows Pixonaut's own wording, so the plugin reads like the workspace's
  hosted pages. WooCommerce terms (cart, checkout, order, variation) use
  WooCommerce core's translation, because the plugin's strings sit on the same
  pages as WooCommerce's. The glossary also fixes one form of address per
  locale, the one WooCommerce uses there (du in `de_DE`, Sie in
  `de_DE_formal`); keep to it.
- **The translator comment** (`#.` in the `.po` file): what the string labels,
  where it shows, who reads it, and what each placeholder holds.
- **The context** (`msgctxt`): the same English word in two senses is two
  entries, each with its own context, such as `Status` for a request's HTTP
  status and for a job's state.

Keep every placeholder (`%s`, `%d`, `%1$s`), and the ones in braces that
WooCommerce fills into an email's subject and heading (`{order_number}`,
`{site_title}`). A translation may reorder numbered placeholders,
`%2$s … %1$s`, and braced ones, but may not drop, add or change one.

## Writing translatable strings

When you add or change a string in the source, give the translator what the
string alone does not say:

- A string of a word or a few words (a label, button, column heading, status
  or option), and any string with a placeholder, gets a `translators:` comment
  directly before the call: what it labels, where it shows, who reads it, and
  what each placeholder holds.

  ```php
  /* translators: %s: the order number. Order note the merchant reads. */
  $note = sprintf( __( 'Sent to the printshop as order %s.', 'pixonaut-for-woocommerce' ), $number );
  ```

- An English word that the plugin uses in two senses, or that could be a noun
  or a verb, gets `_x()` context (`esc_html_x()`, `esc_attr_x()`, `_nx()`):

  ```php
  echo esc_html_x( 'Side', 'product side such as front or back', 'pixonaut-for-woocommerce' );
  ```

- A new product term goes into the glossary in the same change, with its word
  in every language.

Then run `pnpm i18n:pot` and `pnpm i18n:update`, and commit the `.pot` and the
`.po` files.

## Adding a language

1. Copy the template to the WordPress locale's file name:

   ```sh
   cp languages/pixonaut-for-woocommerce.pot languages/pixonaut-for-woocommerce-<locale>.po
   ```

2. In its header, set `Language` to the locale and `Plural-Forms` to the
   language's rule (the one WordPress core's translation for that locale
   uses, e.g. `nplurals=2; plural=(n != 1);`).
3. Add the language's column and register to `languages/glossary.md`, taking
   WooCommerce's words from WooCommerce core's translation for the locale.
4. Translate, then run `pnpm i18n:check` and commit the `.po` file and the
   glossary.

A regional locale of a language the plugin already has, such as `de_CH` or
`fr_CA`, starts as a copy of that language's `.po` file with the regional
differences fixed: the register and the WooCommerce words of WooCommerce's
own translation for that locale. WordPress does not fall back from a
regional locale to its base language, so each one is a complete file of its
own, and a change to a string is made in each of them. `REGIONAL_BASES` in
`bin/i18n/locales.mjs` maps each regional locale to the locale it was copied
from (`de_CH` from `de_DE_formal`, which comes from `de_DE`); add a new
regional locale there. After changing a base translation, apply the change to
every locale the map lists for that base, and to theirs in turn. The
[merchant guide](../../merchant/#languages) lists the locales the plugin
ships.

## Updating translations after a change

```sh
pnpm i18n:pot      # regenerate the template from the source (needs wp-env)
pnpm i18n:update   # merge it into every .po file
pnpm i18n:check    # list what needs translating
```

`i18n:update` merges with GNU gettext's `msgmerge`: it adds new strings
untranslated, marks strings whose English changed as fuzzy (with the old
translation kept as a starting point and the previous English in a `#|`
comment), and turns strings the source no longer has into obsolete `#~`
entries, which the check and the build ignore. Translate the new strings,
review each fuzzy one, remove its `fuzzy` flag and its `#|` lines, then commit.

`msgmerge` runs in the Composer container, which installs gettext on each run,
so `i18n:update` needs network access.

## Building

```sh
pnpm i18n:build
```

compiles every `.po` file to the `.mo` file WordPress loads, and to the
`.l10n.php` file WordPress 6.5 and later prefers because it reads faster.
Fuzzy strings are left out of the compiled files, so WordPress shows the
English source until their translation is reviewed. The build first removes
every compiled file in `languages/`, so a locale whose `.po` file was deleted
leaves nothing behind for the release zip.
`i18n:build` runs WP-CLI's `i18n` commands from the checkout's Composer dev
install, in the Composer container (wp-env's `tests-cli` cannot write to the
checkout), so `pnpm composer install` must have run. The compiled files are build output and ignored by git: run
`pnpm i18n:build` to see a translation in wp-env, with the site's language set
to the locale. `pnpm release:zip` compiles them into the zip.

## Checking

`pnpm i18n:check` reports, per locale, the strings that are untranslated or
fuzzy (a plural with fewer forms than the header's `nplurals` counts as
untranslated), and the translations whose placeholders, printf or braced,
differ from their source's.
Untranslated and fuzzy strings do not fail the check: WordPress shows the
English text for them. A placeholder mismatch fails it, because the
translation would print the wrong value or break `sprintf()`. A `.po` file
whose strings are not the template's also fails it: a string the template has
and the file lacks, or one the file has and the template no longer does,
means `pnpm i18n:update` did not run after `pnpm i18n:pot`. For each
regional locale the check names the locale it was copied from and how many
entries translate differently from that one's, which does not fail it; a
regional locale whose base has no `.po` file does. `pnpm ci:local`
runs the check as its **Translations** step.
