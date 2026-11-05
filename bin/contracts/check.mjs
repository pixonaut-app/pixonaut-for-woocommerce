#!/usr/bin/env node
/**
 * Checks the pinned contract against what Pixonaut published on its public
 * CDN. Needs no token and no access to Pixonaut's source.
 *
 *   pnpm contract:check [--api <version>] [--no-live] [--cdn <origin>]
 *
 * Fails when the pinned API version is not published, when the pinned files
 * differ from it, and when the pinned SDK release's record differs from
 * `sdk.lock.json`. With `--api <version>`, also prints oasdiff's changelog of
 * the consumed surface against that release, and fails on an `ERR` (after the
 * downgrades in `oasdiff-levels.txt`) or on a consumed name or key kind it
 * dropped.
 *
 * Then, unless `--no-live`, the schema-only live smoke (`live.mjs`), which
 * skips and says so unless a local Pixonaut is named in the environment.
 */
import { parseArgs } from 'node:util';
import { liveSmoke } from './live.mjs';
import { checkPin } from './pinning.mjs';

const { values } = parseArgs({
	options: {
		api: { type: 'string' },
		cdn: { type: 'string' },
		'no-live': { type: 'boolean', default: false },
	},
});

let passed = await checkPin({ api: values.api, cdn: values.cdn });

if (!values['no-live']) {
	console.log('');
	passed = (await liveSmoke((line) => console.log(line))) && passed;
}

process.exitCode = passed ? 0 : 1;
