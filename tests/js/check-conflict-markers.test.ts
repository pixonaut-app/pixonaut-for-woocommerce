import { rmSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { repoWithCommit, runScript, writeFiles } from './support/scripts';

/** Built rather than written out, so this file holds no markers itself. */
const MARKER_LENGTH = 7;
const OURS = `${'<'.repeat(MARKER_LENGTH)} HEAD`;
const SEPARATOR = '='.repeat(MARKER_LENGTH);
const THEIRS = `${'>'.repeat(MARKER_LENGTH)} 123f78c (feat: other side)`;

const CONFLICT = ['intro', OURS, 'ours', SEPARATOR, 'theirs', THEIRS, ''].join(
	'\n',
);

function check(dir: string) {
	return runScript('check-conflict-markers.mjs', [dir], { cwd: dir });
}

describe('conflict markers', () => {
	it('passes the checkout itself', () => {
		const result = runScript('check-conflict-markers.mjs', [], {
			cwd: process.cwd(),
		});
		expect(result.status, result.stderr).toBe(0);
	});

	it('passes a repository without markers', () => {
		expect(check(repoWithCommit({ 'a.md': '# a\n' })).status).toBe(0);
	});

	it('names each marker of a conflict by file and line', () => {
		const result = check(repoWithCommit({ 'docs/a.md': CONFLICT }));

		expect(result.status).toBe(1);
		expect(result.stderr).toContain('docs/a.md:2');
		expect(result.stderr).toContain('docs/a.md:4');
		expect(result.stderr).toContain('docs/a.md:6');
	});

	it('finds an opening or closing marker on its own', () => {
		const result = check(
			repoWithCommit({ 'a.txt': `${OURS}\n`, 'b.txt': `x\n${THEIRS}\n` }),
		);

		expect(result.status).toBe(1);
		expect(result.stderr).toContain('a.txt:1');
		expect(result.stderr).toContain('b.txt:2');
	});

	it('allows a heading underline outside a conflict', () => {
		const dir = repoWithCommit({ 'a.md': `Title\n${SEPARATOR}\n\ntext\n` });

		expect(check(dir).status).toBe(0);
	});

	it('allows a marker that does not start the line', () => {
		const dir = repoWithCommit({ 'a.ts': `\tconst s = '${OURS}';\n` });

		expect(check(dir).status).toBe(0);
	});

	it('checks the working tree of tracked files', () => {
		const dir = repoWithCommit({ 'a.md': 'clean\n' });
		writeFiles(dir, { 'a.md': CONFLICT });

		expect(check(dir).status).toBe(1);
	});

	it('ignores untracked files', () => {
		const dir = repoWithCommit({ 'a.md': 'clean\n' });
		writeFiles(dir, { 'b.md': CONFLICT });

		expect(check(dir).status).toBe(0);
	});

	it('skips a tracked file deleted in the working tree', () => {
		const dir = repoWithCommit({ 'a.md': 'clean\n', 'b.md': 'gone\n' });
		rmSync(join(dir, 'b.md'));

		const result = check(dir);

		expect(result.status, result.stderr).toBe(0);
	});
});
