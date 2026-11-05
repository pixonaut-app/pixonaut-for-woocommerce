---
title: Updates
description: How the plugin is updated from its GitHub releases, the optional token, the beta channel, and the warning shown before a major update.
sidebar:
  order: 9
---

The plugin updates like any other plugin, from **Plugins** or **Dashboard >
Updates**. New versions are published as releases of its GitHub repository.

WordPress checks twice a day. **Check for updates** under the plugin's name in
**Plugins** checks at once.

The checks need no setup: the plugin reads the public releases of
[its GitHub repository](https://github.com/pixonaut-app/pixonaut-for-woocommerce/releases)
and downloads the release zip from there.

## Update with a token

A token is needed only for a build of the plugin that updates from a private
repository, such as a private copy given to you for testing. It also lifts
GitHub's limit on anonymous requests, which a site sharing its address with
many others can reach. Without a token, the plugin checks the public releases.

1. In GitHub, create a fine-grained personal access token with read-only
   access to the **Contents** of the repository and nothing else.
2. Add it to `wp-config.php`, above the line
   `/* That's all, stop editing! */`:

   ```php
   define( 'PIXONAUT_WC_UPDATE_TOKEN', 'github_pat_…' );
   ```

The token stays in `wp-config.php`. The plugin sends it to GitHub only, in a
request header, and never saves it in the database or writes it to a log. It
is not part of the plugin's files, so updating or reinstalling the plugin
keeps it. To check the public releases again, remove the line.

## Beta releases

Go to **Pixonaut > Updates** and set **Updates**:

- **Stable releases** (the default): only finished releases, such as `1.4.0`.
- **Beta releases**: also beta versions, such as `1.5.0-beta.2`, which bring
  new features before they are stable. Try them on a staging site first.

A store on the beta channel is offered the highest version, a beta or a stable
release. Switching back to stable does not downgrade the plugin: the store
stays on its beta until a stable release newer than it comes out.

## Major updates

A major update can change or remove something a theme or custom code relies
on: a hook, a template or a stored setting. Before version 1.0.0, a new minor
version (`0.4.0` after `0.3.2`) counts as major. **Plugins** shows a warning
under such an update, with the release's own upgrade notice when it has one.
Read the changelog (**View version details**) and back up the site before you
update.

## After an update: theme templates

A theme can carry its own copies of the plugin's templates. When an update
changes a template your theme copies, **WooCommerce > Status** flags the copy
as out of date in its *Pixonaut for WooCommerce templates* section, with the
theme's version and the plugin's. Ask whoever maintains the theme to update
the copy; until then the page shows the theme's older markup, which may lack
what the update added.
