// When a tree counts as gated. The failure that matters is a false
// attestation: a tree that never passed the full gate marked as if it had.
// `gh` is a stub that answers from a table and logs every call, so nothing
// here reaches GitHub.
import { chmodSync, existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
	git,
	repoWithCommit,
	runScript,
	tempDir,
	writeFiles,
} from './support/scripts';

const REPO_SLUG = 'owner/repo';

/**
 * A `gh` stub. A `repos/…` path containing one of `known` answers `{}`; any
 * other exits 1 with `error` on stderr (by default the 404 `gh api` reports).
 * Every call is appended to the returned log file.
 */
function ghStub(
	known: string[] = [],
	error = 'HTTP 404: Not Found',
): { gh: string; log: string } {
	const dir = tempDir('gh');
	const gh = join(dir, 'gh');
	const log = join(dir, 'calls.log');
	writeFiles(dir, {
		gh: `#!/usr/bin/env node
const { appendFileSync } = require('node:fs');
const args = process.argv.slice(2);
appendFileSync(${JSON.stringify(log)}, args.join(' ') + '\\n');
if (args.includes('-X')) process.exit(0);
const url = args.find((a) => a.startsWith('repos/')) ?? '';
if (${JSON.stringify(known)}.some((k) => url.includes(k))) process.exit(0);
process.stderr.write(${JSON.stringify(error)} + '\\n');
process.exit(1);
`,
	});
	chmodSync(gh, 0o755);
	return { gh, log };
}

function calls(log: string): string[] {
	return existsSync(log) ? readFileSync(log, 'utf8').trim().split('\n') : [];
}

/** `record` gets the tree the gate started on: HEAD's, unless given. */
function attest(
	command: 'record' | 'post',
	cwd: string,
	gh: string,
	startTree = head(cwd).tree,
) {
	const args = command === 'record' ? [command, startTree] : [command];
	return runScript('ci-local-attest.mjs', args, {
		cwd,
		env: { CI_LOCAL_GH: gh, GITHUB_REPOSITORY: REPO_SLUG },
	});
}

function markers(dir: string): string[] {
	const markerDir = join(dir, '.git', 'ci-local');
	return existsSync(markerDir) ? readdirSync(markerDir) : [];
}

function head(dir: string) {
	return {
		sha: git(dir, 'rev-parse', 'HEAD'),
		tree: git(dir, 'rev-parse', 'HEAD^{tree}'),
	};
}

describe('record (end of a full gate)', () => {
	it('records the tree and posts the status when HEAD is on GitHub', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		const { sha, tree } = head(dir);
		const { gh, log } = ghStub([`commits/${sha}`]);

		const result = attest('record', dir, gh);

		expect(result.status, result.stderr).toBe(0);
		expect(markers(dir)).toEqual([`pass-${tree}`]);
		expect(calls(log)).toContain(
			`api -X POST repos/${REPO_SLUG}/statuses/${sha} -f state=success -f context=ci:local -f description=full gate passed on tree ${tree}`,
		);
	});

	it.each([
		['HTTP 404: Not Found'],
		['No commit found for SHA: 0123abc (HTTP 422)'],
	])(
		'records but does not post when HEAD is not pushed (%s), and says how to post later',
		(error) => {
			const dir = repoWithCommit({ 'a.txt': 'a' });
			const { gh, log } = ghStub([], error);

			const result = attest('record', dir, gh);

			expect(result.status, result.stderr).toBe(0);
			expect(markers(dir)).toEqual([`pass-${head(dir).tree}`]);
			expect(calls(log).some((call) => call.includes('-X POST'))).toBe(false);
			expect(result.stdout).toContain('pnpm ci:local:attest');
		},
	);

	it('records nothing for a dirty tree', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		writeFiles(dir, { 'a.txt': 'edited' });
		const { gh, log } = ghStub([`commits/${head(dir).sha}`]);

		const result = attest('record', dir, gh);

		expect(result.status, result.stderr).toBe(0);
		expect(markers(dir)).toEqual([]);
		expect(calls(log)).toEqual([]);
		expect(result.stdout).toContain('Not attested');
	});

	it('records nothing when HEAD moved off the tree the gate started on', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		const started = head(dir).tree;
		writeFiles(dir, { 'a.txt': 'committed mid-run' });
		git(dir, 'commit', '-qam', 'mid-run');
		const { gh, log } = ghStub([`commits/${head(dir).sha}`]);

		const result = attest('record', dir, gh, started);

		expect(result.status, result.stderr).toBe(0);
		expect(markers(dir)).toEqual([]);
		expect(calls(log)).toEqual([]);
		expect(result.stdout).toContain('not the tree the gate started on');
	});

	it('fails rather than skipping the post when GitHub cannot be asked', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		const { gh, log } = ghStub([], 'HTTP 401: Bad credentials');

		const result = attest('record', dir, gh);

		expect(result.status).not.toBe(0);
		expect(result.stderr).toContain('Bad credentials');
		expect(calls(log).some((call) => call.includes('-X POST'))).toBe(false);
	});

	it('treats an untracked file as dirty', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		writeFiles(dir, { 'new.txt': 'new' });

		attest('record', dir, ghStub().gh);

		expect(markers(dir)).toEqual([]);
	});
});

describe('post (after a later push)', () => {
	it('refuses a tree with no recorded pass and posts nothing', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		const { gh, log } = ghStub([`commits/${head(dir).sha}`]);

		const result = attest('post', dir, gh);

		expect(result.status).toBe(1);
		expect(calls(log)).toEqual([]);
	});

	it('posts for a new commit whose tree passed', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		attest('record', dir, ghStub().gh);
		git(dir, 'commit', '-q', '--allow-empty', '-m', 'same tree');
		const { sha, tree } = head(dir);
		const { gh, log } = ghStub([`commits/${sha}`]);

		const result = attest('post', dir, gh);

		expect(result.status, result.stderr).toBe(0);
		expect(
			calls(log).some((call) =>
				call.endsWith(
					`statuses/${sha} -f state=success -f context=ci:local -f description=full gate passed on tree ${tree}`,
				),
			),
		).toBe(true);
	});

	it.each([
		['a changed tracked file', { 'a.txt': 'edited' }],
		['an untracked file', { 'new.txt': 'new' }],
	])('refuses a working tree with %s', (_, files) => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		attest('record', dir, ghStub().gh);
		writeFiles(dir, files);
		const { gh, log } = ghStub([`commits/${head(dir).sha}`]);

		expect(attest('post', dir, gh).status).toBe(1);
		expect(calls(log)).toEqual([]);
	});

	it('refuses once the tree has changed since the pass', () => {
		const dir = repoWithCommit({ 'a.txt': 'a' });
		attest('record', dir, ghStub().gh);
		writeFiles(dir, { 'a.txt': 'changed' });
		git(dir, 'commit', '-qam', 'new tree');

		expect(attest('post', dir, ghStub([`commits/`]).gh).status).toBe(1);
	});
});
