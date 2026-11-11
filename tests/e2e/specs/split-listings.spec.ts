import type { APIRequestContext, Locator, Page } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

/** Read by the e2e support mu-plugin: how many products a page of the shop
 *  lists for this browser context. */
const PER_PAGE_COOKIE = 'pixonaut_e2e_per_page';

/** The first configuration version with an option's own group. */
const SCHEMA_VERSION = 12;

/** Most digits of the clock a unique slug takes: attribute slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

type SplitProducts = {
	poster: { id: number; permalink: string };
	vase: { id: number; permalink: string };
	category: string;
	taxonomies: Record<'bild' | 'storlek', { id: number; taxonomy: string }>;
};

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** A poster listed once per picture, and a vase, in a category of their own;
 *  removed again after `run`. The designer's form holds both of the
 *  poster's options. */
async function withSplitProducts(
	request: APIRequestContext,
	run: (products: SplitProducts) => Promise<void>,
): Promise<void> {
	const key = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
	const config = (taxonomies: string[]) => ({
		...PERSONALISED,
		v: SCHEMA_VERSION,
		sides: PERSONALISED.sides.map((side) => ({
			...side,
			formGroups: {
				groups: [
					{
						id: 'options-front',
						title: 'Your poster',
						items: taxonomies.map((attribute) => ({ attribute })),
					},
				],
			},
		})),
	});
	const response = await request.post(
		restUrl('/pixonaut-e2e/v1/split-products'),
		{
			data: {
				key,
				pixonaut: config([`pa_bild${key}`, `pa_storlek${key}`]),
			},
		},
	);
	expect(response.status()).toBe(201);
	try {
		await run((await response.json()) as SplitProducts);
	} finally {
		await request.delete(restUrl('/pixonaut-e2e/v1/split-products'), {
			params: { key },
		});
	}
}

/** The listing's cards: a classic theme's and a Product Collection's. */
function cards(page: Page): Locator {
	return page.locator('main li.product');
}

/** "Showing 1–2 of 3 results": a classic theme prints it over and under
 *  the cards. */
function resultCount(page: Page): Locator {
	return page.locator('.woocommerce-result-count').first();
}

/** Each card's link to its product. */
async function cardLinks(page: Page): Promise<string[]> {
	return cards(page).evaluateAll((items) =>
		items.map(
			(item) => (item.querySelector('a[href]') as HTMLAnchorElement).href,
		),
	);
}

/** A link's query parameters, its `attribute_*` ones only. */
function chosen(url: string): Record<string, string> {
	return Object.fromEntries(
		[...new URL(url).searchParams].filter(([name]) =>
			name.startsWith('attribute_'),
		),
	);
}

test('a category lists a card per picture that opens with it chosen', async ({
	page,
	request,
}) => {
	await withSplitProducts(request, async ({ poster, category, taxonomies }) => {
		const picture = `attribute_${taxonomies.bild.taxonomy}`;
		const size = `attribute_${taxonomies.storlek.taxonomy}`;

		await page.goto(category);
		await expect(cards(page)).toHaveCount(3);
		await expect(resultCount(page)).toContainText('3 results');
		// A Product Collection keys its cards by product; each card has its own.
		const keys = await cards(page).evaluateAll((items) =>
			items.map((item) => item.getAttribute('data-wp-key')).filter(Boolean),
		);
		expect(new Set(keys).size).toBe(keys.length);
		const links = await cardLinks(page);
		expect(links.map(chosen)).toEqual([
			{ [picture]: 'med-bild' },
			// Without a photo comes in 30x40 only, so its card chooses that too.
			{ [picture]: 'utan-bild', [size]: '30x40' },
			{},
		]);
		expect(new URL(links[0]).searchParams.get('product')).toBe(
			new URL(poster.permalink).searchParams.get('product'),
		);

		await cards(page).nth(1).locator('a[href]').first().click();
		const form = page.locator(
			'#pixonaut-form-front [data-testid="pixonaut-form"]',
		);
		await expect(form.locator(`select[name="${picture}"]`)).toHaveValue(
			'utan-bild',
		);
		await expect(form.locator(`select[name="${size}"]`)).toHaveValue('30x40');

		await page.goBack();
		await cards(page).first().locator('a[href]').first().click();
		await expect(form.locator(`select[name="${picture}"]`)).toHaveValue(
			'med-bild',
		);
		await expect(form.locator(`select[name="${size}"]`)).toHaveValue('');
	});
});

test('a filter by picture keeps that picture’s card', async ({
	page,
	request,
}) => {
	await withSplitProducts(request, async ({ category, taxonomies }) => {
		const filtered = new URL(category);
		filtered.searchParams.set(
			`filter_${taxonomies.bild.taxonomy.replace(/^pa_/, '')}`,
			'utan-bild',
		);

		await page.goto(filtered.toString());

		await expect(cards(page)).toHaveCount(1);
		expect((await cardLinks(page)).map(chosen)).toEqual([
			expect.objectContaining({
				[`attribute_${taxonomies.bild.taxonomy}`]: 'utan-bild',
			}),
		]);
	});
});

test('a product search lists a card per picture', async ({ page, request }) => {
	await withSplitProducts(request, async ({ poster }) => {
		const product = await request.get(
			restUrl(`/wc/store/v1/products/${poster.id}`),
		);
		const { name } = (await product.json()) as { name: string };
		const search = new URL(poster.permalink);
		search.search = new URLSearchParams({
			s: name,
			post_type: 'product',
		}).toString();

		await page.goto(search.toString());

		await expect(cards(page)).toHaveCount(2);
	});
});

test('the cards are counted and paged as products', async ({
	page,
	request,
	baseURL,
}) => {
	await page
		.context()
		.addCookies([{ name: PER_PAGE_COOKIE, value: '2', url: baseURL ?? '' }]);
	await withSplitProducts(request, async ({ category }) => {
		await page.goto(category);
		await expect(cards(page)).toHaveCount(2);
		await expect(resultCount(page)).toContainText(/1.2 of 3 results/);
		const first = await cardLinks(page);

		const second = new URL(category);
		second.searchParams.set('paged', '2');
		await page.goto(second.toString());
		await expect(cards(page)).toHaveCount(1);
		const rest = await cardLinks(page);
		expect(new Set([...first, ...rest]).size).toBe(3);
	});
});

test('related products show the poster once', async ({ page, request }) => {
	await withSplitProducts(request, async ({ poster, vase }) => {
		await page.goto(vase.permalink);
		const posterId = new URL(poster.permalink).searchParams.get('product');
		const posterCards = cards(page).filter({
			has: page.locator(`a[href*="product=${posterId}"]`),
		});
		await expect(posterCards).toHaveCount(1);
	});
});
