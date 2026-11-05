// The WordPress and WooCommerce releases the nightly workflow picks.
import { describe, expect, it } from 'vitest';
import {
	matrix,
	newestBranches,
	newestPatch,
	withMinimums,
} from '../../bin/nightly-versions.mjs';

/** Keys as WordPress.org lists them: not in version order. */
const WOOCOMMERCE = [
	'11.1.2',
	'9.9.7',
	'11.2.0',
	'11.2.1',
	'11.3.0-beta.1',
	'11.3.0-rc.1',
	'11.1.0',
	'trunk',
	'10.10.0',
];

describe('newestBranches', () => {
	it('takes the newest patch of the two newest stable branches, newest first', () => {
		expect(newestBranches(WOOCOMMERCE, '10.6')).toEqual(['11.2.1', '11.1.2']);
	});

	it('orders branches by number, not as text', () => {
		expect(newestBranches(['10.9.3', '10.10.0', '10.8.1'], '10.6')).toEqual([
			'10.10.0',
			'10.9.3',
		]);
	});

	it('reads a release without a patch number as patch 0', () => {
		expect(newestBranches(['7.1', '7.0.7', '7.1.3', '7.0'], '6.9')).toEqual([
			'7.1.3',
			'7.0.7',
		]);
	});

	it('leaves out branches below the minimum', () => {
		expect(newestBranches(['6.8.11', '6.9.10', '6.9.2'], '6.9')).toEqual([
			'6.9.10',
		]);
	});
});

describe('matrix', () => {
	it('pairs every WordPress release with every WooCommerce release, as wp-env sources', () => {
		const zip = 'https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip';

		expect(matrix(['7.1.3', '7.0'], ['11.2.1'], { '11.2.1': zip })).toEqual([
			{
				wp: '7.1.3',
				wc: '11.2.1',
				core: 'https://wordpress.org/wordpress-7.1.3.zip',
				woocommerce: zip,
			},
			{
				wp: '7.0',
				wc: '11.2.1',
				core: 'https://wordpress.org/wordpress-7.0.zip',
				woocommerce: zip,
			},
		]);
	});
});

describe('newestPatch', () => {
	it('takes the newest stable patch of the minimum branch', () => {
		expect(
			newestPatch(
				['10.6.0', '10.6.2', '10.6.10', '10.7.0', '10.6.3-rc.1'],
				'10.6',
			),
		).toBe('10.6.10');
	});

	it('reads a release without a patch number as that branch', () => {
		expect(newestPatch(['6.9', '7.0'], '6.9')).toBe('6.9');
	});

	it('finds nothing when the branch has no stable release', () => {
		expect(newestPatch(['10.7.0', '10.6.0-beta.1'], '10.6')).toBeUndefined();
	});
});

describe('withMinimums', () => {
	const zips = {
		'11.2.1': 'https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip',
		'10.6.2': 'https://downloads.wordpress.org/plugin/woocommerce.10.6.2.zip',
	};

	it('adds a cell pairing the minimum WordPress and WooCommerce releases', () => {
		const cells = withMinimums(
			matrix(['7.1.3'], ['11.2.1'], zips),
			'6.9.4',
			'10.6.2',
			zips,
		);

		expect(cells.map(({ wp, wc }) => [wp, wc])).toEqual([
			['7.1.3', '11.2.1'],
			['6.9.4', '10.6.2'],
		]);
		expect(cells[1]).toEqual({
			wp: '6.9.4',
			wc: '10.6.2',
			core: 'https://wordpress.org/wordpress-6.9.4.zip',
			woocommerce: zips['10.6.2'],
		});
	});

	it('adds nothing when a cell already pairs the minimums', () => {
		const cells = matrix(['6.9.4'], ['10.6.2'], zips);

		expect(withMinimums(cells, '6.9.4', '10.6.2', zips)).toEqual(cells);
	});
});
