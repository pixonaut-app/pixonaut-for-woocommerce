import { describe, expect, it } from 'vitest';
import {
	addressName,
	NAMES_GLOBAL,
	readQueryNames,
	shortenAddress,
} from './query-names';

const NAMES = { storlek: 'storlek', inramning: 'ram' };

function shortened(href: string): string | null {
	return shortenAddress(new URL(href), NAMES)?.href ?? null;
}

describe('readQueryNames', () => {
	it('reads the names the page printed, and none from a page that printed none', () => {
		expect(
			readQueryNames({ [NAMES_GLOBAL]: { storlek: 'size', x: 1 } }),
		).toEqual({ storlek: 'size' });
		expect(readQueryNames({})).toEqual({});
	});
});

describe('addressName', () => {
	it("names a global attribute's field by its short name, and any other field by itself", () => {
		expect(addressName('attribute_pa_inramning', NAMES)).toBe('ram');
		expect(addressName('attribute_pa_colour', NAMES)).toBe(
			'attribute_pa_colour',
		);
		expect(addressName('attribute_size', NAMES)).toBe('attribute_size');
	});
});

describe('shortenAddress', () => {
	it("names a product page's attributes by their short names, keeping the rest in order", () => {
		expect(
			shortened(
				'https://shop.test/p/poster/?utm=mail&attribute_pa_storlek=50x70-cm&attribute_pa_inramning=ek&attribute_size=s#reviews',
			),
		).toBe(
			'https://shop.test/p/poster/?utm=mail&storlek=50x70-cm&ram=ek&attribute_size=s#reviews',
		);
	});

	it("names an archive's filters by their short names, dropping the way of combining a short address means", () => {
		expect(
			shortened(
				'https://shop.test/shop/?filter_storlek=50x70-cm%2C30x40-cm&query_type_storlek=or&filter_inramning=ek&query_type_inramning=and&filter_colour=red&query_type_colour=or',
			),
		).toBe(
			'https://shop.test/shop/?storlek=50x70-cm%2C30x40-cm&ram=ek&query_type_inramning=and&filter_colour=red&query_type_colour=or',
		);
	});

	it('keeps a filter naming no way of combining as one holding all its values', () => {
		expect(shortened('https://shop.test/shop/?filter_storlek=a%2Cb')).toBe(
			'https://shop.test/shop/?storlek=a%2Cb&query_type_storlek=and',
		);
	});

	it("keeps the long name's value over a short name the address also gives, in either order", () => {
		expect(
			shortened(
				'https://shop.test/p/poster/?storlek=30x40&attribute_pa_storlek=50x70',
			),
		).toBe('https://shop.test/p/poster/?storlek=50x70');
		expect(
			shortened(
				'https://shop.test/p/poster/?attribute_pa_storlek=50x70&storlek=30x40',
			),
		).toBe('https://shop.test/p/poster/?storlek=50x70');
		expect(
			shortened(
				'https://shop.test/shop/?ram=ek&filter_inramning=vit&query_type_inramning=or',
			),
		).toBe('https://shop.test/shop/?ram=vit');
	});

	it('leaves an address naming no attribute the long way as it is', () => {
		expect(
			shortened(
				'https://shop.test/shop/?storlek=50x70-cm&query_type_storlek=or',
			),
		).toBeNull();
	});
});
