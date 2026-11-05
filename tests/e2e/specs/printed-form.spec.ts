import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	PERSONALISED,
} from '../personalised';

/** The variations of the product, and the one its form opens on. */
const SMALL = { widthMm: 210, heightMm: 270, label: 'Small' };
const LARGE = { widthMm: 300, heightMm: 400, label: 'Large' };
const SMALL_PRICE = /23[.,]00/;
const LARGE_PRICE = /47[.,]00/;

/** How long WooCommerce's variation script is held back: longer than the
 *  storefront script waits for the form's choices to settle. */
const SLOW_SCRIPT_MS = 1500;

/** The fixture template's fields, as `formSchema` names them. */
const TEXT_FIELD = 'slogan:content';
const SIZE_FIELD = 'slogan:fontSizeFrac';
const COLOUR_FIELD = 'slogan:fill';
const FONT_FIELD = 'slogan:fontFamily';
const PICTURE_FIELD = 'motiv:src';
const TEXT_MAX = 40;
const TYPED = 'Table 12';

/** Its labels, and the props they must not read as. */
const FIXTURE_LABELS = ['Motiv', 'Slogan', 'Storlek', 'Färg'];
const FIXTURE_PROPS = ['src', 'content', 'fontFamily', 'fontSizeFrac', 'fill'];

const FRONT_FORM = '#pixonaut-form-front [data-testid="pixonaut-form"]';

/** What the page showed in one frame while it loaded. */
type Frame = { price: string; quantity: number | null; button: number | null };

async function createProduct(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const sized = (size: typeof SMALL) => ({
		...PERSONALISED,
		sides: [{ ...PERSONALISED.sides[0], printSizes: [size] }],
	});
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name,
			pixonaut: PERSONALISED,
			variations: [SMALL.label, LARGE.label],
			default: LARGE.label,
			prices: { [SMALL.label]: '23.00', [LARGE.label]: '47.00' },
			overrides: { [SMALL.label]: sized(SMALL), [LARGE.label]: sized(LARGE) },
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

async function createSimple(
	request: APIRequestContext,
	name: string,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { name: `${name} ${Date.now()}`, pixonaut: PERSONALISED },
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

/** Holds the SDK script back until the returned function is called. */
async function holdSdk(page: Page): Promise<() => void> {
	let release: () => void = () => undefined;
	const held = new Promise<void>((resolve) => {
		release = resolve;
	});
	await page.route(/\/pixonaut\.iife\.js/, async (route) => {
		await held;
		await route.continue();
	});
	return release;
}

/** The summary price, outside the form and any list of products. */
function summaryPrice(page: Page) {
	return page
		.locator('.summary .price, .wc-block-components-product-price')
		.and(page.locator(':not(form *):not(.products *)'))
		.first();
}

/** Records the summary price and the row of the quantity and the button in
 *  every frame, from the first. */
async function recordFrames(page: Page): Promise<void> {
	await page.addInitScript(() => {
		const frames: Frame[] = [];
		(window as unknown as { frames_: Frame[] }).frames_ = frames;
		const top = (selector: string) =>
			document.querySelector(selector)?.getBoundingClientRect().top ?? null;
		const sample = () => {
			const price = Array.from(
				document.querySelectorAll(
					'.summary .price, .wc-block-components-product-price',
				),
			).find((element) => element.closest('form, .products') === null);
			if (price !== undefined) {
				frames.push({
					price: price.textContent ?? '',
					quantity: top('form.cart .quantity'),
					button: top('form.cart .single_add_to_cart_button'),
				});
			}
			requestAnimationFrame(sample);
		};
		requestAnimationFrame(sample);
	});
}

function frames(page: Page): Promise<Frame[]> {
	return page.evaluate(
		() => (window as unknown as { frames_: Frame[] }).frames_,
	);
}

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a chosen variation’s price stands from first paint to settled, and the range comes back once none is chosen', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createProduct(
		request,
		`Opening price ${theme} ${Date.now()}`,
	);
	await recordFrames(page);
	await page.route('**/add-to-cart-variation*', async (route) => {
		await new Promise((resolve) => setTimeout(resolve, SLOW_SCRIPT_MS));
		await route.continue();
	});
	await page.goto(permalink);
	await expect(page.locator('input[name="variation_id"]')).not.toHaveValue(
		/^0?$/,
	);
	await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
	await page.waitForTimeout(SLOW_SCRIPT_MS);

	const seen = await frames(page);
	expect(seen.length).toBeGreaterThan(0);
	const prices = new Set(seen.map((frame) => frame.price));
	for (const price of prices) {
		expect(price).toMatch(LARGE_PRICE);
		expect(price).not.toMatch(SMALL_PRICE);
	}
	// Quantity and the button keep the row they were printed in.
	const rows = new Set(
		seen
			.filter((frame) => frame.quantity !== null && frame.button !== null)
			.map((frame) => Math.round((frame.button ?? 0) - (frame.quantity ?? 0))),
	);
	expect(rows.size).toBe(1);

	await page.locator('select[name="attribute_size"]').selectOption('');
	await expect(summaryPrice(page)).toContainText(SMALL_PRICE);
	await expect(summaryPrice(page)).toContainText(LARGE_PRICE);
});

test('the printed form is the form the customer uses, and a printed field’s label is never its prop', async ({
	page,
	request,
	theme,
}) => {
	const permalink = await createSimple(request, `Printed ${theme}`);
	const release = await holdSdk(page);
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });

	const labels = await page
		.locator('.pixonaut-form__placeholder .pixonaut-placeholder__label')
		.allTextContents();
	expect(labels).toEqual(expect.arrayContaining(FIXTURE_LABELS));
	for (const prop of FIXTURE_PROPS) {
		expect(labels).not.toContain(prop);
	}

	const printed = page.locator('.pixonaut-form__placeholder');
	const text = printed.locator(`[data-pixonaut-field="${TEXT_FIELD}"]`);
	await expect(text).toBeEditable();
	await expect(
		printed.locator(`[data-pixonaut-field="${SIZE_FIELD}"]`),
	).toHaveAttribute('type', 'range');
	await expect(
		printed.locator(`[data-pixonaut-field="${COLOUR_FIELD}"]`),
	).toHaveAttribute('type', 'color');
	// What the designer draws has no input to stand for it.
	await expect(
		printed.locator(`[data-pixonaut-field="${FONT_FIELD}"]`),
	).toHaveCount(0);
	await expect(
		printed.locator(`[data-pixonaut-field="${PICTURE_FIELD}"]`),
	).toHaveCount(0);

	await text.click();
	await text.fill(TYPED);
	await expect(
		printed.getByText(`${TYPED.length} / ${TEXT_MAX}`),
	).toBeVisible();
	await text.evaluate((element) => {
		(element as HTMLElement & { printedMark?: boolean }).printedMark = true;
	});
	// A marked input naming no field goes; a field with none is drawn.
	await page.locator('.pixonaut-form__placeholder').evaluate((form, size) => {
		form.querySelector(`[data-pixonaut-field="${size}"]`)?.remove();
		form.insertAdjacentHTML(
			'afterbegin',
			'<input type="text" data-pixonaut-field="no-such-layer:content" value="Stale">',
		);
	}, SIZE_FIELD);
	const proofs: string[] = [];
	page.on('request', (sent) => {
		if (
			sent.method() === 'POST' &&
			new URL(sent.url()).pathname === '/v1/proofs'
		) {
			proofs.push(sent.postData() ?? '');
		}
	});

	release();

	const drawn = page.locator(FRONT_FORM);
	const taken = drawn.locator(`[data-pixonaut-field="${TEXT_FIELD}"]`);
	await expect(taken).toHaveValue(TYPED);
	expect(
		await taken.evaluate(
			(element) =>
				(element as HTMLElement & { printedMark?: boolean }).printedMark,
		),
	).toBe(true);
	await expect(taken).toBeFocused();
	await expect(
		page.locator('[data-pixonaut-field="no-such-layer:content"]'),
	).toHaveCount(0);
	await expect(
		drawn.locator(`[data-testid="field-${SIZE_FIELD.replace(':', '-')}"]`),
	).toBeAttached();
	await expect(drawn.locator('[data-testid="field-motiv-src"]')).toBeAttached();

	await addToCartButton(page).click();
	await expect.poll(() => proofs.length).toBeGreaterThan(0);
	expect(proofs[0]).toContain(TYPED);
});
