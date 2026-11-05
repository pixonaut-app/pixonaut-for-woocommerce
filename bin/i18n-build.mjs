#!/usr/bin/env node
// Compiles every PO file in languages/ to the `.mo` file WordPress loads, and
// to the `.l10n.php` file WordPress 6.5+ prefers, which it reads faster. Both
// are build output: ignored by git, made for the release zip.
//
// Fuzzy translations are left out, so WordPress shows the English source until
// someone reviews them: WP-CLI would compile them, so it compiles copies of
// the PO files without them. Compiled files of a locale whose PO file is gone
// are removed first, so the release zip does not ship them.
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { root } from './env.mjs';
import {
	compiledFiles,
	LANGUAGES_DIR,
	poFiles,
	requirePoFiles,
	wpI18n,
} from './i18n/files.mjs';
import { withoutFuzzy } from './i18n/po.mjs';

/**
 * Where the filtered copies go: inside the checkout, the only directory the
 * Composer container mounts. Ignored by git.
 */
const STAGE_DIR = '.i18n-build';

const languages = join(root, LANGUAGES_DIR);
for (const file of compiledFiles(languages)) {
	rmSync(file);
}
requirePoFiles();

const stage = join(root, STAGE_DIR);
rmSync(stage, { recursive: true, force: true });
mkdirSync(stage);
try {
	for (const { path } of poFiles(languages)) {
		writeFileSync(
			join(stage, basename(path)),
			withoutFuzzy(readFileSync(path, 'utf8')),
		);
	}
	const status =
		wpI18n(['make-mo', STAGE_DIR, LANGUAGES_DIR]) ||
		wpI18n(['make-php', STAGE_DIR, LANGUAGES_DIR]);
	process.exitCode = status;
} finally {
	rmSync(stage, { recursive: true, force: true });
}
