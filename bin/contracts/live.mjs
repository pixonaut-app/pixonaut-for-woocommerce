/**
 * The schema-only live smoke of `pnpm contract:check`: runs the scenario
 * suite (`contracts/scenarios/`) against a Pixonaut on this machine, named by
 * `PIXONAUT_LIVE_API_URL`, `PIXONAUT_LIVE_TENANT`, `PIXONAUT_LIVE_EMAIL` and
 * `PIXONAUT_LIVE_PASSWORD`. Every answer is held to the pinned spec; no value
 * is compared. Without them it skips, and says what to set.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const ROOT = path.join(import.meta.dirname, '..', '..');

/** Bundles the TypeScript scenarios, which plain Node cannot import. */
async function loadScenarios() {
	const outdir = fs.mkdtempSync(path.join(os.tmpdir(), 'pixonaut-scenarios-'));
	const outfile = path.join(outdir, 'live.mjs');
	await build({
		entryPoints: [path.join(ROOT, 'contracts', 'scenarios', 'live.ts')],
		outfile,
		bundle: true,
		platform: 'node',
		format: 'esm',
		target: 'node26',
		logLevel: 'warning',
		// Bundled CommonJS dependencies (Ajv) call `require`.
		banner: {
			js: "import { createRequire } from 'node:module'; const require = createRequire(import.meta.url);",
		},
	});
	// The bundle reads the pinned contract from the checkout, not beside it.
	process.env.PIXONAUT_FAKE_CONTRACTS_DIR = path.join(
		ROOT,
		'contracts',
		'pixonaut',
	);
	return import(pathToFileURL(outfile).href);
}

/**
 * @param {(line: string) => void} log
 * @returns {Promise<boolean>} Whether every scenario passed, or the smoke skipped.
 */
export async function liveSmoke(log) {
	const { liveConfig, runLive } = await loadScenarios();
	const config = liveConfig(process.env);
	if ('missing' in config) {
		log(`Live: skipped; set ${config.missing.join(', ')} to run it.`);
		return true;
	}
	const { report, lines } = await runLive(config);
	for (const line of lines) {
		log(line);
	}
	log(
		report.passed
			? 'Live: it answers every scenario within the pinned spec.'
			: 'Live: it breaks a scenario.',
	);
	return report.passed;
}
