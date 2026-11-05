#!/usr/bin/env node
// Merges languages/pixonaut-for-woocommerce.pot into every PO file beside
// it with GNU gettext's msgmerge: new strings arrive untranslated, strings
// whose English changed keep their old translation marked fuzzy, and removed
// ones become obsolete (`#~`) entries. Run it after `pnpm i18n:pot`.
import { join, relative } from 'node:path';
import { root } from './env.mjs';
import {
	LANGUAGES_DIR,
	msgmerge,
	POT_FILE,
	poFiles,
	requirePoFiles,
} from './i18n/files.mjs';

requirePoFiles();
// The container sees the checkout at its working directory.
const pos = poFiles(join(root, LANGUAGES_DIR)).map(({ path }) =>
	relative(root, path),
);
process.exit(msgmerge(POT_FILE, pos));
