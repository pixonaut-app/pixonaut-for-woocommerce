// Helpers for tests that run the scripts in bin/ as processes, against
// throwaway directories and git repositories.
import { type SpawnSyncReturns, spawnSync } from 'node:child_process';
import {
	chmodSync,
	mkdirSync,
	mkdtempSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { afterAll } from 'vitest';

export const BIN = join(__dirname, '..', '..', '..', 'bin');

const made: string[] = [];
afterAll(() => {
	for (const dir of made.splice(0)) {
		rmSync(dir, { recursive: true, force: true });
	}
});

/** A temporary directory, removed after the test file. */
export function tempDir(prefix: string): string {
	const dir = mkdtempSync(join(tmpdir(), `${prefix}-`));
	made.push(dir);
	return dir;
}

/**
 * The environment without GIT_* variables: a git hook exports GIT_DIR and
 * GIT_INDEX_FILE, which would point the git commands below at the repository
 * running the tests instead of the throwaway one.
 */
export function cleanEnv(
	extra: Record<string, string> = {},
): NodeJS.ProcessEnv {
	const env = Object.fromEntries(
		Object.entries(process.env).filter(([key]) => !key.startsWith('GIT_')),
	);
	return { ...env, ...extra };
}

export function git(cwd: string, ...args: string[]): string {
	const result = spawnSync('git', args, {
		cwd,
		encoding: 'utf8',
		env: cleanEnv(),
	});
	if (result.status !== 0) {
		throw new Error(`git ${args.join(' ')}: ${result.stderr}`);
	}
	return result.stdout.trim();
}

/** A git repository on branch `main` with one commit holding `files`. */
export function repoWithCommit(files: Record<string, string>): string {
	const dir = tempDir('repo');
	git(dir, 'init', '-q', '-b', 'main');
	git(dir, 'config', 'user.email', 't@example.test');
	git(dir, 'config', 'user.name', 't');
	git(dir, 'config', 'commit.gpgsign', 'false');
	writeFiles(dir, files);
	git(dir, 'add', '.');
	git(dir, 'commit', '-qm', 'c');
	return dir;
}

export function writeFiles(dir: string, files: Record<string, string>): void {
	for (const [file, content] of Object.entries(files)) {
		mkdirSync(dirname(join(dir, file)), { recursive: true });
		writeFileSync(join(dir, file), content);
	}
}

/** What a stub answers for a call whose arguments start with a key. */
export type Answer = { stdout?: string; stderr?: string; status?: number };

/** A logging stub: appends `<name> <args>` to `log`, answers the first entry
 *  of `answers` whose key starts the arguments, and runs `extra`. */
export function stub(
	dir: string,
	name: string,
	log: string,
	{
		answers = {},
		extra = '',
	}: { answers?: Record<string, Answer>; extra?: string },
): string {
	const file = join(dir, name);
	writeFiles(dir, {
		[name]: `#!/usr/bin/env node
const { appendFileSync } = require('node:fs');
const line = process.argv.slice(2).join(' ');
appendFileSync(${JSON.stringify(log)}, ${JSON.stringify(name)} + ' ' + line + '\\n');
${extra}
const answers = ${JSON.stringify(answers)};
const key = Object.keys(answers).find((k) => line.startsWith(k));
const answer = key ? answers[key] : {};
process.stdout.write(answer.stdout ?? '');
process.stderr.write(answer.stderr ?? '');
process.exit(answer.status ?? 0);
`,
	});
	chmodSync(file, 0o755);
	return file;
}

/** Runs `node bin/<script> ...args` in `cwd`. */
export function runScript(
	script: string,
	args: string[],
	{ cwd, env = {} }: { cwd: string; env?: Record<string, string> },
): SpawnSyncReturns<string> {
	return spawnSync('node', [join(BIN, script), ...args], {
		cwd,
		encoding: 'utf8',
		env: cleanEnv(env),
	});
}
