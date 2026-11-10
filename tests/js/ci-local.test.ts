// Which steps `ci:local` runs. `--dry-run` prints the selected steps and runs
// none of them.
import { describe, expect, it } from 'vitest';
import { git, repoWithCommit, runScript, writeFiles } from './support/scripts';

const ALL_STEPS = [
	'Conflict markers',
	'Biome',
	'TypeScript',
	'Workflow lint',
	'Header minimums',
	'Docs links',
	'PHPCS',
	'PHPStan',
	'PHP unit',
	'JS unit',
	'.pot freshness',
	'Translations',
	'consumed.json freshness',
	'Generated docs',
	'Plugin Check',
	'Release zip',
	'Release zip (wordpress.org)',
	'PHP integration (HPOS off, on)',
	'e2e (classic, block-primary, astra)',
];

/** The dry run's lines: a step's name, then its e2e specs indented. */
function dryRun(args: string[], cwd: string): string[] {
	const result = runScript('ci-local.mjs', [...args, '--dry-run'], {
		cwd,
		env: { CI_LOCAL_BASE: 'main' },
	});
	expect(result.status, result.stderr).toBe(0);
	return result.stdout.split('\n').filter(Boolean);
}

/** A spec line's indent in the dry run. */
const SPEC_INDENT = '  ';

function steps(args: string[], cwd = process.cwd()): string[] {
	return dryRun(args, cwd).filter((line) => !line.startsWith(SPEC_INDENT));
}

/** The e2e specs the dry run lists. */
function specs(args: string[], cwd: string): string[] {
	return dryRun(args, cwd)
		.filter((line) => line.startsWith(SPEC_INDENT))
		.map((line) => line.trim());
}

/** A repo whose branch `work` changes `files` relative to `main`. */
function branchChanging(files: Record<string, string>): string {
	const dir = repoWithCommit({ 'README.md': 'x' });
	git(dir, 'checkout', '-qb', 'work');
	writeFiles(dir, files);
	git(dir, 'add', '.');
	git(dir, 'commit', '-q', '--allow-empty', '-m', 'change');
	return dir;
}

/** A repo whose branch `work` edits `edited` and removes `removed`, both on
 *  `main` already. */
function branchEditing(edited: string[], removed: string[] = []): string {
	const dir = repoWithCommit(
		Object.fromEntries([...edited, ...removed].map((file) => [file, 'x'])),
	);
	git(dir, 'checkout', '-qb', 'work');
	writeFiles(dir, Object.fromEntries(edited.map((file) => [file, 'y'])));
	for (const file of removed) {
		git(dir, 'rm', '-q', file);
	}
	git(dir, 'commit', '-q', '-am', 'change');
	return dir;
}

describe('ci:local', () => {
	it('runs every step without --affected', () => {
		expect(steps([])).toEqual(ALL_STEPS);
	});

	describe('--affected', () => {
		it('runs nothing when nothing changed', () => {
			expect(steps(['--affected'], branchChanging({}))).toEqual([]);
		});

		it('runs the PHP steps for a change in src/', () => {
			const dir = branchChanging({ 'src/Plugin.php': '<?php' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'PHPCS',
				'PHPStan',
				'PHP unit',
				'.pot freshness',
				'consumed.json freshness',
				'Generated docs',
				'Plugin Check',
				'Release zip',
				'Release zip (wordpress.org)',
				'PHP integration (HPOS off, on)',
			]);
		});

		it('builds the release zips for a removed source, not for an edited one', () => {
			const edited = steps(['--affected'], branchEditing(['src/Plugin.php']));
			const removed = steps(
				['--affected'],
				branchEditing([], ['src/Plugin.php']),
			);

			expect(edited).not.toContain('Release zip');
			expect(edited).not.toContain('Release zip (wordpress.org)');
			expect(removed).toContain('Release zip');
			expect(removed).toContain('Release zip (wordpress.org)');
		});

		it('builds the release zips when the main plugin file is edited', () => {
			const dir = branchEditing(['pixonaut-for-woocommerce.php']);

			expect(steps(['--affected'], dir)).toContain('Release zip');
		});

		it('runs only a changed e2e spec', () => {
			const dir = branchChanging({ 'tests/e2e/specs/smoke.spec.ts': '' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Biome',
				'TypeScript',
				'e2e (classic, block-primary, astra)',
			]);
			expect(specs(['--affected'], dir)).toEqual(['smoke.spec.ts']);
		});

		it('runs every e2e spec when the Playwright config changes', () => {
			const dir = branchChanging({ 'playwright.config.ts': '' });

			expect(specs(['--affected'], dir)).toEqual([]);
			expect(steps(['--affected'], dir)).toContain(
				'e2e (classic, block-primary, astra)',
			);
		});

		it('runs the JS steps for a change in tests/js/', () => {
			const dir = branchChanging({ 'tests/js/a.test.ts': '' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Biome',
				'TypeScript',
				'JS unit',
			]);
		});

		it('counts uncommitted and untracked files', () => {
			const dir = branchChanging({});
			writeFiles(dir, { 'readme.txt': 'new' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Header minimums',
				'Plugin Check',
			]);
		});

		it('runs the .pot check when its script changes', () => {
			const dir = branchChanging({ 'bin/i18n-pot.mjs': '' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Biome',
				'JS unit',
				'.pot freshness',
			]);
		});

		it('checks the translations when a PO file changes', () => {
			const dir = branchChanging({
				'languages/pixonaut-for-woocommerce-sv_SE.po': '',
			});

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'.pot freshness',
				'Translations',
				'Plugin Check',
				'Release zip',
				'Release zip (wordpress.org)',
			]);
		});

		it('builds the release zip when the translation build changes', () => {
			const dir = branchChanging({ 'bin/i18n-build.mjs': '' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Biome',
				'JS unit',
				'Release zip',
				'Release zip (wordpress.org)',
			]);
		});

		it('builds the wordpress.org zip when a listing asset changes', () => {
			const dir = branchChanging({ '.wordpress-org/screenshot-1.png': '' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Release zip (wordpress.org)',
			]);
		});

		it.each(['bin/php.mjs', 'bin/wp-env.mjs', 'bin/wp-env-override.mjs'])(
			'runs everything when the shared wrapper %s changes',
			(file) => {
				expect(steps(['--affected'], branchChanging({ [file]: '' }))).toEqual(
					ALL_STEPS,
				);
			},
		);

		it('runs everything when the dependencies change', () => {
			const dir = branchChanging({ 'composer.lock': '{}' });

			expect(steps(['--affected'], dir)).toEqual(ALL_STEPS);
		});

		it('runs only the workflow lint for a workflow change', () => {
			const dir = branchChanging({ '.github/workflows/x.yml': 'on: {}' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Workflow lint',
			]);
		});

		it('runs the release zip when its sources change', () => {
			const dir = branchChanging({ 'readme.src.txt': '=== x ===' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Release zip',
				'Release zip (wordpress.org)',
			]);
		});

		it('checks consumed.json and runs the tests that read it when it changes', () => {
			const dir = branchChanging({
				'contracts/pixonaut/consumed.json': '{}',
			});

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Biome',
				'PHP unit',
				'JS unit',
				'consumed.json freshness',
				'PHP integration (HPOS off, on)',
			]);
		});

		it('runs only the docs checks for developer docs only', () => {
			const dir = branchChanging({ 'docs/developer/x.md': '# x' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Docs links',
				'Generated docs',
			]);
		});

		it('runs only the conflict marker and links checks for merchant docs only', () => {
			const dir = branchChanging({ 'docs/merchant/x.md': '# x' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Docs links',
			]);
		});

		it('checks the docs links when README.md changes', () => {
			const dir = branchChanging({ 'README.md': 'y' });

			expect(steps(['--affected'], dir)).toEqual([
				'Conflict markers',
				'Docs links',
			]);
		});
	});
});
