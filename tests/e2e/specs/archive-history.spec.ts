import type { Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';

/** Most digits of the clock a unique slug takes: attribute slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** The query argument naming the attributes whose Product Filters block the
 *  support plugin renders beside a listing. */
const COUNTS_ARG = 'pixonaut_e2e_counts';

type Poster = {
	category: string;
	taxonomies: Record<string, { id: number; taxonomy: string }>;
};

/** The listing's address, from its path on. */
const address = (page: Page) => {
	const url = new URL(page.url());
	return url.pathname + url.search;
};

test('back and forward on a filtered archive show each address’s results in place, under the short names', async ({
	page,
	request,
	theme,
}) => {
	test.skip(
		theme === 'storefront',
		'the Product Filters block navigates in place on a block theme',
	);
	const key = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
	const response = await request.post(
		restUrl('/pixonaut-e2e/v1/dependent-poster'),
		{ data: { key, split: [] } },
	);
	expect(response.status()).toBe(201);
	const poster = (await response.json()) as Poster;
	try {
		const framing = poster.taxonomies.inramning;
		const name = framing.taxonomy.replace(/^pa_/, '');
		const opened = new URL(poster.category);
		opened.searchParams.set(COUNTS_ARG, String(framing.id));
		await page.goto(opened.toString());
		// Every full page load from here on.
		await page.evaluate(() => {
			sessionStorage.setItem('pixonaut-e2e-loads', '0');
		});
		await page.addInitScript(() => {
			window.addEventListener('load', () => {
				const loads = Number(sessionStorage.getItem('pixonaut-e2e-loads') ?? 0);
				sessionStorage.setItem('pixonaut-e2e-loads', String(loads + 1));
			});
		});
		const loads = () =>
			page.evaluate(() => Number(sessionStorage.getItem('pixonaut-e2e-loads')));
		const filters = page.locator('[data-pixonaut-e2e-counts]');
		const start = address(page);

		await filters.getByRole('checkbox', { name: /Hängare/ }).check();
		await expect
			.poll(() => new URL(page.url()).searchParams.get(name))
			.toBe('hangare');
		const one = address(page);
		await filters.getByRole('checkbox', { name: /Ingen/ }).check();
		await expect
			.poll(() => new URL(page.url()).searchParams.get(name))
			.toBe('hangare,ingen');
		const two = address(page);
		expect(two).not.toContain(`filter_${name}`);

		await page.goBack();
		await expect.poll(() => address(page)).toBe(one);
		await expect(
			filters.getByRole('checkbox', { name: /Ingen/ }),
		).not.toBeChecked();
		await expect(
			filters.getByRole('checkbox', { name: /Hängare/ }),
		).toBeChecked();

		await page.goBack();
		await expect.poll(() => address(page)).toBe(start);
		await expect(
			filters.getByRole('checkbox', { name: /Hängare/ }),
		).not.toBeChecked();

		await page.goForward();
		await expect.poll(() => address(page)).toBe(one);
		await expect(
			filters.getByRole('checkbox', { name: /Hängare/ }),
		).toBeChecked();

		expect(await loads()).toBe(0);
	} finally {
		await request.delete(restUrl('/pixonaut-e2e/v1/dependent-poster'), {
			params: { key },
		});
	}
});
