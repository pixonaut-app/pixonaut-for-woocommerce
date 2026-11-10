// Which e2e specs `ci:local --affected` runs, and whether tests/e2e/reach.mjs
// places every shipped source.
import { describe, expect, it } from 'vitest';
import {
	affectedSpecs,
	allSpecs,
	entryMatches,
	isMappedSource,
	loadReach,
} from '../../bin/ci-local-e2e.mjs';
import { git, tempDir, writeFiles } from './support/scripts';

/** A checkout with two specs, a helper only one of them imports, and a map. */
function checkout(): string {
	const dir = tempDir('e2e-select');
	writeFiles(dir, {
		'tests/e2e/fixtures.ts': "export const test = 'x';\n",
		'tests/e2e/orders.ts': "import { test } from './fixtures';\n",
		'tests/e2e/specs/cart.spec.ts': "import { test } from '../fixtures';\n",
		'tests/e2e/specs/order.spec.ts':
			"import { test } from '../fixtures';\nimport { buy } from '../orders';\n",
		'tests/e2e/reach.mjs': `export const NO_SPEC = ['src/Plugin.php'];
export const REACH = {
	'cart.spec.ts': ['src/Cart/', 'assets/src/storefront/cart.ts'],
	'order.spec.ts': ['src/Cart/Line.php', 'templates/order/'],
};
`,
		'src/Plugin.php': '',
		'src/Cart/Line.php': '',
		'src/Cart/Notice.php': '',
		'src/Admin/New.php': '',
		'assets/src/storefront/cart.ts': '',
		'templates/order/box.php': '',
	});
	return dir;
}

const BOTH = ['cart.spec.ts', 'order.spec.ts'];

describe('affectedSpecs', () => {
	it('runs a changed spec alone', async () => {
		expect(
			await affectedSpecs(['tests/e2e/specs/order.spec.ts'], checkout()),
		).toEqual({ specs: ['order.spec.ts'] });
	});

	it('runs the specs that import a changed helper, through other helpers', async () => {
		const dir = checkout();

		expect(await affectedSpecs(['tests/e2e/orders.ts'], dir)).toEqual({
			specs: ['order.spec.ts'],
		});
		expect(await affectedSpecs(['tests/e2e/fixtures.ts'], dir)).toEqual({
			specs: BOTH,
		});
	});

	it('runs the specs a changed source is mapped to, by file or directory', async () => {
		const dir = checkout();

		expect(await affectedSpecs(['src/Cart/Notice.php'], dir)).toEqual({
			specs: ['cart.spec.ts'],
		});
		expect(await affectedSpecs(['src/Cart/Line.php'], dir)).toEqual({
			specs: BOTH,
		});
		expect(await affectedSpecs(['templates/order/box.php'], dir)).toEqual({
			specs: ['order.spec.ts'],
		});
	});

	it('runs no spec for a source no spec is about, a unit test, or a file outside the plugin', async () => {
		const dir = checkout();

		expect(
			await affectedSpecs(
				[
					'src/Plugin.php',
					'assets/src/storefront/cart.test.ts',
					'docs/x.md',
					'bin/x.mjs',
				],
				dir,
			),
		).toEqual({ specs: [] });
	});

	it('runs every spec for a source the map does not place', async () => {
		expect(await affectedSpecs(['src/Admin/New.php'], checkout())).toEqual({
			specs: BOTH,
			everything: 'src/Admin/New.php is not in tests/e2e/reach.mjs',
		});
	});

	it('skips a removed source the map does not place', async () => {
		expect(await affectedSpecs(['src/Admin/Gone.php'], checkout())).toEqual({
			specs: [],
		});
	});

	it.each([
		'playwright.config.ts',
		'tests/e2e/mu-plugins/pixonaut-e2e-orders.php',
		'contracts/pixonaut/sdk.lock.json',
	])('runs every spec when the harness file %s changes', async (file) => {
		expect(await affectedSpecs([file], checkout())).toEqual({
			specs: BOTH,
			everything: `${file} changed`,
		});
	});
});

describe('tests/e2e/reach.mjs', async () => {
	const { NO_SPEC, REACH } = await loadReach();
	const root = process.cwd();
	const tracked = git(root, 'ls-files').split('\n');
	const entries = [...NO_SPEC, ...Object.values(REACH).flat()];

	it('has an entry for every spec, in order', () => {
		expect(Object.keys(REACH)).toEqual(allSpecs());
	});

	it('places every shipped source', () => {
		const unplaced = tracked
			.filter(isMappedSource)
			.filter((file) => !entries.some((entry) => entryMatches(entry, file)));

		expect(unplaced).toEqual([]);
	});

	it('names only files that exist', () => {
		const stale = entries.filter(
			(entry) => !tracked.some((file) => entryMatches(entry, file)),
		);

		expect(stale).toEqual([]);
	});
});
