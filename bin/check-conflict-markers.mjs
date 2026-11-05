#!/usr/bin/env node
// Fails when a tracked file still holds merge conflict markers: a line that
// starts with `<<<<<<< ` or `>>>>>>> `, or a `=======` line between them (on
// its own, `=======` is a Markdown heading underline). Exits 1 and names each
// marker by file and line.
//
// Usage: check-conflict-markers.mjs [repo-root]   (default: this checkout)
import { spawnSync } from 'node:child_process';
import { lstatSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { root as checkoutRoot } from './env.mjs';

/** Git writes each marker as the same character seven times. */
const MARKER_LENGTH = 7;
const OURS = `${'<'.repeat(MARKER_LENGTH)} `;
const THEIRS = `${'>'.repeat(MARKER_LENGTH)} `;
const SEPARATOR = '='.repeat(MARKER_LENGTH);

const root = resolve(process.argv[2] ?? checkoutRoot);

const listed = spawnSync('git', ['ls-files', '-z'], {
	cwd: root,
	encoding: 'utf8',
});
if (listed.status !== 0) {
	console.error(`git ls-files failed: ${listed.stderr}`);
	process.exit(1);
}

/** The `file:line` of each marker in `text`. */
function markers(file, text) {
	const found = [];
	let open = false;
	text.split(/\r?\n/).forEach((line, index) => {
		const at = `${file}:${index + 1}`;
		if (line.startsWith(OURS)) {
			open = true;
			found.push(at);
		} else if (line.startsWith(THEIRS)) {
			open = false;
			found.push(at);
		} else if (open && line === SEPARATOR) {
			found.push(at);
		}
	});
	return found;
}

const problems = [];
for (const file of listed.stdout.split('\0').filter(Boolean)) {
	const path = join(root, file);
	// Skip a tracked file deleted in the working tree, a symlink and a
	// submodule: none has lines of its own to check.
	if (!lstatSync(path, { throwIfNoEntry: false })?.isFile()) {
		continue;
	}
	const content = readFileSync(path);
	// Binary files have no lines to hold markers.
	if (content.includes(0)) {
		continue;
	}
	problems.push(...markers(file, content.toString('utf8')));
}

if (problems.length > 0) {
	console.error(
		`Merge conflict markers:\n${problems.map((at) => `  ${at}`).join('\n')}`,
	);
	process.exit(1);
}
console.log('No merge conflict markers.');
