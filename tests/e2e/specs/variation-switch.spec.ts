import type { APIRequestContext, Page, Request } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	PERSONALISED,
	viewCartLink,
} from '../personalised';

/** The product's own front. */
const TEE = PERSONALISED;

const SMALL = { widthMm: 210, heightMm: 270, label: 'Small' };

/** mini-poster's 420:297 ratio at A4. */
const A4_LANDSCAPE = { widthMm: 297, heightMm: 210, label: 'A4 landscape' };

/** The front of TEE with `changes`. */
function front(changes: object, config: object = TEE) {
	return {
		...config,
		sides: [{ ...TEE.sides[0], ...changes }],
	};
}

/** A variation per switch path, one that inherits the product, and one
 *  bought without a design. */
const OVERRIDES = {
	Small: front({ printSizes: [SMALL] }),
	Hoodie: front(
		{},
		{ ...TEE, blank: { id: FIXTURE.blank.hoodie, name: 'Fixture hoodie' } },
	),
	Poster: front({
		template: { baseId: 'mini-poster', version: 1, name: 'Mini poster' },
		printSizes: [A4_LANDSCAPE],
	}),
	Plain: { ...TEE, personalise: false },
};

/** Dark inherits the product, and sells the tee in black. */
const SIZES = ['Tee', 'Dark', ...Object.keys(OVERRIDES)];

/** The blank variant each variation sells: the tee's white, but for Dark,
 *  and the hoodie's for Hoodie. */
const VARIANTS = {
	Dark: FIXTURE.variant.teeBlackS,
	Hoodie: FIXTURE.variant.hoodieWhiteS,
};

/** What each path's proof is made for, and so what its design commits. */
const PATHS = [
	{
		path: 'size',
		variation: 'Small',
		proof: { templateId: FIXTURE.template.live, widthMm: 210, heightMm: 270 },
	},
	{
		path: 'variant',
		variation: 'Dark',
		proof: { templateId: FIXTURE.template.live, widthMm: 280, heightMm: 360 },
		photo: FIXTURE.mockup.frontDark,
	},
	{
		path: 'placement',
		variation: 'Hoodie',
		proof: {
			blankId: FIXTURE.blank.hoodie,
			placementId: FIXTURE.placement.front,
		},
	},
	{
		path: 'template',
		variation: 'Poster',
		proof: {
			templateId: FIXTURE.template.landscape,
			widthMm: 297,
			heightMm: 210,
		},
	},
];

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: TEE,
			variations: SIZES,
			overrides: OVERRIDES,
			variant: FIXTURE.variant.teeWhiteS,
			variants: VARIANTS,
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

function sizeSelect(page: Page) {
	return page.locator('select[name="attribute_size"]');
}

/** Chooses the variation, and waits until WooCommerce takes an add to cart
 *  for it: the classic form enables its button 300 ms after it found the
 *  variation, and refuses a click before then. */
async function chooseSize(page: Page, size: string): Promise<void> {
	await sizeSelect(page).selectOption(size);
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
}

/** The mockup a keyless image link opens: the fake names it in the token. */
function linkedMockup(url: string): string | undefined {
	const { pathname } = new URL(url);
	if (!pathname.startsWith('/i/')) {
		return undefined;
	}
	const ref = JSON.parse(
		Buffer.from(pathname.slice('/i/'.length), 'base64url').toString('utf8'),
	) as { mockupId?: string };
	return ref.mockupId;
}

/** The mockups the front's preview shows behind the design. */
async function shownMockups(page: Page): Promise<Array<string | undefined>> {
	const backdrop = page.locator(
		'#pixonaut-preview-front [data-testid="pixonaut-mockup-backdrop"]',
	);
	return (
		await backdrop.evaluateAll((els) =>
			els.map((el) => (el as HTMLImageElement).src),
		)
	).map(linkedMockup);
}

function proofBody(request: Request): Record<string, unknown> {
	const body = request.postDataJSON() as Record<string, unknown>;
	const { printSize, ...rest } = body as {
		printSize?: Record<string, unknown>;
	};
	return { ...rest, ...printSize };
}

for (const { path, variation, proof, photo } of PATHS as Array<
	(typeof PATHS)[number] & { photo?: string }
>) {
	test(`switches the ${path} for another variation and adds that variation’s design`, async ({
		page,
		request,
		theme,
	}) => {
		await page.goto(
			await createProduct(request, `Switch ${path} ${theme} ${Date.now()}`),
		);
		await chooseSize(page, 'Tee');
		await expect(page.locator('#pixonaut-form-front *').first()).toBeVisible();

		await chooseSize(page, variation);
		// The designer shows the variation's variant, in its own photo. The
		// SDK may have preloaded that photo already, so the photo shown is
		// checked rather than a request for it.
		if (photo !== undefined) {
			await expect.poll(() => shownMockups(page)).toContain(photo);
		}
		const proofMade = page.waitForRequest(
			(r) =>
				r.method() === 'POST' && new URL(r.url()).pathname === '/v1/proofs',
		);
		await addToCartButton(page).click();

		expect(proofBody(await proofMade)).toMatchObject(proof);
		await expect(page.getByText(/has been added to your cart/)).toBeVisible();
		await viewCartLink(page).click();
		// The classic cart's and the Cart block's line details.
		await expect(
			page
				.locator('.variation-Size, .wc-block-components-product-details__value')
				.filter({ hasText: variation }),
		).toBeVisible();
	});
}

test('the variation form chooses the colour, and the designer offers no picker of its own', async ({
	page,
	request,
	theme,
}) => {
	await page.goto(
		await createProduct(request, `Picker ${theme} ${Date.now()}`),
	);
	await chooseSize(page, 'Tee');
	await expect(page.locator('#pixonaut-form-front *').first()).toBeVisible();
	// The variation form offers every colour the product sells: the
	// designer's colour and size picker would offer them twice.
	await expect(page.getByTestId('pixonaut-variant-picker')).toHaveCount(0);

	await chooseSize(page, 'Dark');
	await expect
		.poll(() => shownMockups(page))
		.toContain(FIXTURE.mockup.frontDark);

	await chooseSize(page, 'Small');
	await expect.poll(() => shownMockups(page)).toContain(FIXTURE.mockup.front);
});

test('a variation bought without a design hides the designer and adds as it is', async ({
	page,
	request,
	theme,
	fake,
}) => {
	await page.goto(await createProduct(request, `Plain ${theme} ${Date.now()}`));
	await chooseSize(page, 'Tee');
	await expect(page.locator('#pixonaut-form-front *').first()).toBeVisible();

	await chooseSize(page, 'Plain');

	await expect(page.locator('.pixonaut-form')).toBeHidden();
	await expect(page.locator('.pixonaut-preview')).toBeHidden();
	await addToCartButton(page).click();
	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
	const requests = await fake.requests();
	expect(requests.filter((r) => r.operation === 'POST /v1/designs')).toEqual(
		[],
	);
});
