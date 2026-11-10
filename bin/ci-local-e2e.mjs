// Which e2e specs `ci:local --affected` runs for a set of changed files.
//
// A spec runs when it changed itself, when a helper it imports changed (the
// import graph of tests/, followed transitively), or when a source it is about
// changed: tests/e2e/reach.mjs maps each spec to those sources. A source in
// the map's NO_SPEC list selects no spec. A source in neither list (a new
// one, or one removed with its entry), or a change to the e2e harness itself,
// runs every spec. Only the specs of `pnpm test:e2e`'s projects count.
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, normalize, relative } from 'node:path';
import { pathToFileURL } from 'node:url';
import { root } from './env.mjs';

export const SPECS_DIR = 'tests/e2e/specs';
const SPEC = /\.spec\.ts$/;

/**
 * Specs no project of `pnpm test:e2e` runs: playwright.config.ts gives the
 * compatibility spec to the Nightly's theme and page builder projects only.
 */
const NIGHTLY_ONLY = /^compatibility\.spec\.ts$/;
const TS = /\.ts$/;

/** Sources tests/e2e/reach.mjs has to place: what the plugin ships. */
const SHIPPED =
	/^(src\/|templates\/|assets\/src\/|pixonaut-for-woocommerce\.php$|uninstall\.php$)/;

/** A unit test beside the code it tests: Vitest's, not the e2e suite's. */
const UNIT_TEST = /\.test\.ts$/;

/** @param {string} file */
export function isMappedSource(file) {
	return SHIPPED.test(file) && !UNIT_TEST.test(file);
}

/** The harness every spec runs on: a change here runs them all. */
const HARNESS =
	/^(playwright\.config\.ts$|tests\/e2e\/mu-plugins\/|contracts\/pixonaut\/sdk\.lock\.json$)/;

/** A relative import or re-export in a test file. */
const RELATIVE_IMPORT = /\bfrom\s+'(\.{1,2}\/[^']+)'/g;

/**
 * The map's entries: a directory prefix ends in `/`, anything else is a file.
 *
 * @param {string} entry
 * @param {string} file
 */
export function entryMatches(entry, file) {
	return entry.endsWith('/') ? file.startsWith(entry) : file === entry;
}

/**
 * @param {string} [dir]
 * @returns {Promise<{ NO_SPEC: string[], REACH: Record<string, string[]> }>}
 */
export async function loadReach(dir = root) {
	return import(pathToFileURL(join(dir, 'tests/e2e/reach.mjs')).href);
}

/**
 * Every spec file name, sorted.
 *
 * @param {string} [dir]
 */
export function allSpecs(dir = root) {
	return readdirSync(join(dir, SPECS_DIR))
		.filter((name) => SPEC.test(name))
		.sort();
}

/**
 * The spec file names `pnpm test:e2e` runs, sorted.
 *
 * @param {string} [dir]
 */
export function gateSpecs(dir = root) {
	return allSpecs(dir).filter((name) => !NIGHTLY_ONLY.test(name));
}

/**
 * Every .ts file under tests/, relative to `dir`.
 *
 * @param {string} dir
 */
function testSources(dir) {
	return readdirSync(join(dir, 'tests'), { recursive: true })
		.map((path) => join('tests', String(path)))
		.filter((path) => TS.test(path));
}

/**
 * For each test file, the spec file names that import it, directly or
 * through other test files.
 *
 * @param {string} dir
 */
function importersBySource(dir) {
	/** @type {Map<string, Set<string>>} file to the files importing it */
	const importedBy = new Map();
	for (const file of testSources(dir)) {
		const text = readFileSync(join(dir, file), 'utf8');
		for (const [, target] of text.matchAll(RELATIVE_IMPORT)) {
			const imported = normalize(join(dirname(file), `${target}.ts`));
			const importers = importedBy.get(imported) ?? new Set();
			importers.add(file);
			importedBy.set(imported, importers);
		}
	}
	/**
	 * @param {string} file
	 * @param {Set<string>} [seen]
	 * @returns {Set<string>}
	 */
	const specsImporting = (file, seen = new Set()) => {
		/** @type {Set<string>} */
		const specs = new Set();
		for (const importer of importedBy.get(file) ?? []) {
			if (seen.has(importer)) {
				continue;
			}
			seen.add(importer);
			if (importer.startsWith(`${SPECS_DIR}/`)) {
				specs.add(relative(SPECS_DIR, importer));
			}
			for (const spec of specsImporting(importer, seen)) {
				specs.add(spec);
			}
		}
		return specs;
	};
	return specsImporting;
}

/**
 * The spec file names to run for `files` (paths relative to the repository),
 * and why every spec runs when it does.
 *
 * @param {string[]} files
 * @param {string} [dir]
 * @returns {Promise<{ specs: string[], everything?: string }>}
 */
export async function affectedSpecs(files, dir = root) {
	const specs = gateSpecs(dir);
	const harness = files.find((file) => HARNESS.test(file));
	if (harness !== undefined) {
		return { specs, everything: `${harness} changed` };
	}
	const { NO_SPEC, REACH } = await loadReach(dir);
	const specsImporting = importersBySource(dir);
	/** @type {Set<string>} */
	const selected = new Set();
	for (const file of files) {
		if (file.startsWith(`${SPECS_DIR}/`)) {
			const name = relative(SPECS_DIR, file);
			if (specs.includes(name)) {
				selected.add(name);
			}
			continue;
		}
		if (file.startsWith('tests/') && TS.test(file)) {
			for (const spec of specsImporting(file)) {
				selected.add(spec);
			}
			continue;
		}
		if (!isMappedSource(file)) {
			continue;
		}
		const reaching = Object.entries(REACH)
			.filter(([, entries]) => entries.some((e) => entryMatches(e, file)))
			.map(([spec]) => spec);
		const placed =
			reaching.length > 0 || NO_SPEC.some((e) => entryMatches(e, file));
		// A removed source counts too: its map entry goes with it, and what
		// used it may still break.
		if (!placed) {
			return {
				specs,
				everything: `${file} is not in tests/e2e/reach.mjs`,
			};
		}
		for (const spec of reaching) {
			selected.add(spec);
		}
	}
	return { specs: specs.filter((spec) => selected.has(spec)) };
}
