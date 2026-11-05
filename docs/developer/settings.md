---
title: Settings
description: Every setting of Pixonaut for WooCommerce, its key, default and allowed values.
sidebar:
  order: 4
---

The settings are on the Pixonaut settings page, the plugin's top-level item
in the admin menu (`admin.php?page=pixonaut`), which users who may manage
WooCommerce can open. WooCommerce > Settings > Pixonaut, where they used to
be, redirects there. They are stored together in the `pixonaut_wc_settings` option, one array keyed as
below. A value that is missing, or no longer allowed, reads as the default.

`api_base`, `server_api_base`, `studio_base`, `cdn_base`, `sdk_url`, `sdk_debug` and
`request_log` are the hidden [developer tools](../developer-tools/)' settings,
saved by their own form on the Connection tab.

`Settings\SettingsPage` shows the others on tabs, in groups (`GROUPS`: Product
page designer on Storefront; Approval and Proof by email on Approval; Plugin
updates on Updates), each field's description under it, as WooCommerce
settings fields. Each tab saves only its own fields. A tab without a setting
is not shown: Updates exists only in builds that update from GitHub. A
general setting no group names follows on Updates in a section without a
heading, so a new setting always shows; add it to a group.
`DEPENDENT` names the settings shown only while another has a value, as the
row class `wc-settings-row-pixonaut-dependent` with `data-pixonaut-depends`
and `data-pixonaut-when-checked`, `data-pixonaut-when-equals` or
`data-pixonaut-when-above` on the field,
which `assets/src/admin/settings.ts` reads. A required one
(`data-pixonaut-required`, the custom accent colour) saved empty keeps its
earlier value, and WooCommerce's error bar says what is missing.

<!-- BEGIN GENERATED settings: from the Settings registry, run pnpm docs:generate -->

### `default_layout`

**Default layout** (select). How personalised products show the designer, unless a product overrides it.

Default: `inline`.

| Value | Shown as |
|---|---|
| `inline` | Inline: live preview in place of the product image |
| `button` | Personalise button: opens the Pixonaut designer |

### `designer_language`

**Designer language** (select). The language of the designer on the product page, of Pixonaut’s designer the Personalise button opens, and of the proof pages customers approve their designs on. Following the site shows each visitor the site’s language they browse in when Pixonaut has it, and the workspace’s default language when it does not.

Default: `site`.

| Value | Shown as |
|---|---|
| `workspace` | Pixonaut workspace default |
| `site` | Follow the site |
| `en` | English |
| `sv` | Svenska |
| `de` | Deutsch |
| `fr` | Français |
| `es` | Español |
| `nl` | Nederlands |
| `da` | Dansk |
| `nb` | Norsk bokmål |
| `fi` | Suomi |

### `form_layout`

**Form layout** (select). How the designer lays out its form, on the product page and in Pixonaut’s designer the Personalise button opens, unless a product overrides it: as the template chose, every step on one page, or one step at a time.

Default: `template`.

| Value | Shown as |
|---|---|
| `template` | Template’s choice |
| `page` | One page |
| `steps` | Steps |

### `options_as_groups`

**As its own group** (checkbox). Puts each of a product's options, such as Size or Colour, in a group of its own in the designer's form on the product page, named after the option, in the product's attribute order. Off, the options stay on top of the form, unnumbered. A product whose groups were arranged by hand keeps them.

Default: `false`.

### `option_groups_position`

**Place of the option groups** (select). Whether the groups of a product's options come before or after the template's groups.

Default: `first`.

| Value | Shown as |
|---|---|
| `first` | First |
| `last` | Last |

### `group_numbers`

**Group numbers** (checkbox). Numbers the groups of the designer's form on the product page: 1 Photo, 2 Text on one page, Step 1 of 2 in steps. Off shows the group names with no numbers.

Default: `true`.

### `designer_preload`

**Load other colours and views** (select). Whether the designer loads a product's other colours, views and sides before the customer picks one, so they show at once. Choose In the background or Off for products with many large photos, or for customers mostly on phones.

Default: `auto`.

| Value | Shown as |
|---|---|
| `auto` | Auto: as much as the customer's device and connection allow |
| `background` | In the background, one at a time |
| `off` | Off: load each one when it is shown |

### `designer_accent`

**Designer accent colour** (select). The colour the designer on the product page marks the chosen view, colour and option in, and draws its Next button in. None takes the accent colour of the store's brand in Pixonaut, and keeps the designer's own colours when the brand has none.

Default: `none`.

| Value | Shown as |
|---|---|
| `none` | None: the accent of the store's brand in Pixonaut, else the designer's own colours |
| `theme` | The theme's primary colour, as its buttons show it |
| `custom` | A custom colour |

### `designer_accent_colour`

**Custom accent colour** (color). The accent colour when Designer accent colour is A custom colour, e.g. #1e73be.

Default: empty.

### `print_area_frame`

**Show the print area's frame** (checkbox). Draws a thin grey frame on the preview around where the design can go, in the designer on the product page and in Pixonaut's designer the Personalise button opens. Off, the design sits on the product photo as it will be printed.

Default: `false`.

### `attribute_options`

**Product options in the designer** (select). How the designer on the product page shows the options of a variable product, such as its sizes, when it draws them in its form. As the theme shows them (the default) keeps the theme's own field, and any swatches another plugin draws in it. As swatches shows each option as a button like the designer's own choices, an option that cannot be bought with the other choices greyed out.

Default: `theme`.

| Value | Shown as |
|---|---|
| `theme` | As the theme shows them |
| `swatches` | As swatches, like the designer's own choices |

### `product_pictures`

**Use Pixonaut pictures as product images** (checkbox). Sets each personalised product’s and variation’s image to Pixonaut’s picture of it, its template on the blank, and draws it again when the product changes. A product can show its template’s showcase sets instead, chosen under Product images in its Pixonaut tab. An image you set yourself is kept. Off, each product gets back the image it had before.

Default: `true`.

### `show_field_values`

**List the design's fields** (checkbox). Lists what the customer filled in under a personalised item in the cart, the checkout, their orders and the order emails, as the designer showed it. Off, the item shows only its name. The order screen in the admin always lists them.

Default: `false`.

### `line_picture_view`

**Picture shows** (select). A personalised item shows the customer’s design on the product in the cart, the checkout, their orders and the order emails. This chooses the view of the product it is drawn in: the one the customer previewed their design in, or the first of the placement’s views the product is shown in.

Default: `previewed`.

| Value | Shown as |
|---|---|
| `previewed` | The view the customer previewed |
| `first` | The placement’s first view |

### `default_approval_mode`

**Default approval** (select). How customers approve their design before it is printed, unless a product overrides it.

Default: `add_to_cart`.

| Value | Shown as |
|---|---|
| `add_to_cart` | Adding to the cart approves the design |
| `checkbox` | Customer ticks "I approve the preview" |
| `proof_email` | Proof by email after payment |

### `proof_deadline_days`

**Proof approval deadline (days)** (number). With proof by email, how many days the customer has to approve their proof. The link in the email works a week longer.

Default: `14`.

### `proof_no_answer`

**When no answer by the deadline** (select). With proof by email, what happens to a design the customer neither approved nor rejected by the deadline.

Default: `deny`.

| Value | Shown as |
|---|---|
| `approve` | Approve the design as shown |
| `deny` | Deny the design |

### `proof_on_deny`

**On deny** (select). What happens to an item whose design the customer rejected, or which was denied at the deadline.

Default: `needs_attention`.

| Value | Shown as |
|---|---|
| `refund_cancel` | Refund and cancel the item |
| `cancel` | Cancel the item |
| `needs_attention` | Mark the item as needing attention |

### `proof_reminder_after_days`

**First proof reminder after (days)** (number). Days after the proof email that a customer who has not answered is reminded. Only reminders before the deadline are sent.

Default: `2`.

### `proof_reminder_every_days`

**Then remind every (days)** (number). Days between one proof reminder and the next.

Default: `2`.

### `proof_reminders`

**Proof reminders at most** (number). How many reminders a customer gets at most; 0 sends none. The last one says when the deadline is and what happens then.

Default: `2`.

### `api_base`

**API base** (url). The Pixonaut API as the browser reaches it, e.g. a local Pixonaut. Empty uses Pixonaut’s own. The store’s key is sent to this address.

Default: empty.

### `server_api_base`

**Server-to-server API base** (url). The Pixonaut API as WordPress itself reaches it, when that differs from the browser’s address (WordPress inside Docker, say). Empty uses the API base.

Default: empty.

### `studio_base`

**Studio base** (url). The Pixonaut studio, where a connect is approved and the admin’s studio links lead. Empty uses Pixonaut’s own.

Default: empty.

### `cdn_base`

**CDN base** (url). Where the pinned designer SDK release loads from, e.g. Pixonaut’s staging CDN. The release’s integrity is still checked. Empty uses Pixonaut’s own.

Default: empty.

### `sdk_url`

**SDK script** (url). Loads the designer SDK from this script, e.g. a local build, instead of the pinned release from the CDN base. Its integrity is not checked. Empty loads the pinned release.

Default: empty.

### `sdk_debug`

**SDK debug** (checkbox). Logs every designer mount and its failures to the browser console. On the storefront, only for logged-in store managers.

Default: `false`.

### `request_log`

**Request log** (checkbox). Records the last 50 requests to Pixonaut, without keys or tokens.

Default: `false`.

### `update_channel`

**Updates** (select). Beta releases bring new features before they are stable. Use them on a staging site first.

Default: `stable`.

| Value | Shown as |
|---|---|
| `stable` | Stable releases |
| `beta` | Beta releases |

<!-- END GENERATED settings -->
