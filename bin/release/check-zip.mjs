#!/usr/bin/env node
// Lists a release zip and fails on any path outside the allowlist
// (allowlist.mjs), on a missing required file, and on a development-only
// Composer package.
//
// Usage: check-zip.mjs [--flavor <name>] <zip> [composer.lock]
//        (default: the github flavor, this checkout's composer.lock)
import { join, resolve } from 'node:path';
import { root } from '../env.mjs';
import { zipFileProblems } from './allowlist.mjs';
import { flavorFromArgs } from './flavor.mjs';

const args = process.argv.slice(2);
const flavor = flavorFromArgs(args);
const [zip, lock = join(root, 'composer.lock')] = args.filter(
	(arg, index) => !arg.startsWith('--flavor') && args[index - 1] !== '--flavor',
);
if (zip === undefined) {
	console.error('Usage: check-zip.mjs [--flavor <name>] <zip> [composer.lock]');
	process.exit(2);
}

const problems = zipFileProblems(resolve(zip), resolve(lock), flavor);
if (problems.length > 0) {
	console.error(problems.join('\n'));
	process.exit(1);
}
console.log(`${zip}: only allowlisted files.`);
