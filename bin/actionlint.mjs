#!/usr/bin/env node
// actionlint, with ShellCheck on every `run:` script, over
// .github/workflows/ in a throwaway container, so the host needs neither.
import { dockerBin, root, run } from './env.mjs';

/** Pinned: a new release can add checks that fail an unchanged workflow. */
const IMAGE = 'rhysd/actionlint:1.7.12';

run(dockerBin, [
	'run',
	'--rm',
	'-v',
	`${root}:/repo:ro`,
	'-w',
	'/repo',
	IMAGE,
	...process.argv.slice(2),
]);
