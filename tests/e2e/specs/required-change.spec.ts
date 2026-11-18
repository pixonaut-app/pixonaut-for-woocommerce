import type { Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

/** The tee whose slogan must be changed from its default before it is
 *  taken. */
const MUST_CHANGE = {
	...PERSONALISED,
	sides: [
		{
			...PERSONALISED.sides[0],
			template: { baseId: 'mini-tee-change', version: 1, name: 'Mini tee' },
		},
	],
};

/** A phone, where the form's message sits a screen above its button, and a
 *  desktop. */
const SCREENS = [
	{ name: 'phone', width: 390, height: 844 },
	{ name: 'desktop', width: 1280, height: 800 },
];

/** Taps add to cart from the end of the page, where the customer reaches it
 *  after scrolling past the form. */
async function addFromPageEnd(page: Page): Promise<void> {
	await page.evaluate(() =>
		window.scrollTo(0, document.documentElement.scrollHeight),
	);
	await addToCartButton(page).click();
}

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a default left unchanged names the field to change before any proof is asked for, and the changed design is added', async ({
	page,
	request,
	theme,
}) => {
	await page.goto(
		await createProduct(
			request,
			`Must change ${theme} ${Date.now()}`,
			MUST_CHANGE,
		),
	);
	const form = page.locator('#pixonaut-form-front');
	const slogan = form.getByRole('textbox', { name: /Slogan/ });
	await expect(slogan).toBeVisible();
	let proofs = 0;
	page.on('request', (r) => {
		if (r.method() === 'POST' && new URL(r.url()).pathname === '/v1/proofs') {
			proofs++;
		}
	});

	await addToCartButton(page).click();

	await expect(page.locator('.pixonaut-form__error')).toHaveText(
		'Please change these fields: Slogan',
	);
	await expect(page.getByText(/has been added to your cart/)).toHaveCount(0);
	expect(proofs).toBe(0);

	await slogan.fill('Klass 9B');
	await addToCartButton(page).click();

	await expect(page.getByText(/has been added to your cart/)).toBeVisible();
});

for (const screen of SCREENS) {
	test(`on a ${screen.name}, add to cart with a default left unchanged brings the field into view, focused, and names it`, async ({
		page,
		request,
		theme,
	}) => {
		await page.setViewportSize(screen);
		await page.goto(
			await createProduct(
				request,
				`Must change in view ${screen.name} ${theme} ${Date.now()}`,
				MUST_CHANGE,
			),
		);
		const form = page.locator('#pixonaut-form-front');
		const slogan = form.getByRole('textbox', { name: /Slogan/ });
		await expect(slogan).toBeVisible();

		await addFromPageEnd(page);

		await expect(page.locator('.pixonaut-form__error')).toHaveText(
			'Please change these fields: Slogan',
		);
		await expect(slogan).toBeFocused();
		await expect(slogan).toBeInViewport();
		await expect(form.getByTestId('violation-slogan-content')).toBeInViewport();
		await page.screenshot({
			path: test.info().outputPath(`unchanged-text-${screen.name}.png`),
		});
	});

	test(`on a ${screen.name}, add to cart without a required photo brings its upload into view, focused, and names it`, async ({
		page,
		request,
		baseURL,
		theme,
		fake,
	}) => {
		const required = await fake.control.post(
			`/__fake/templates/${FIXTURE.template.requireChange}/require-change`,
			{ data: { field: 'motiv.src' } },
		);
		expect(required.ok(), 'the fake requires the picture').toBe(true);
		// Connecting again clears the template reads the store keeps.
		await connectFixtureStore(request, baseURL ?? '');
		await page.setViewportSize(screen);
		await page.goto(
			await createProduct(
				request,
				`Must upload in view ${screen.name} ${theme} ${Date.now()}`,
				MUST_CHANGE,
			),
		);
		const form = page.locator('#pixonaut-form-front');
		await form.getByRole('textbox', { name: /Slogan/ }).fill('Klass 9B');
		const upload = form.getByTestId('field-motiv-upload-tile');
		await expect(upload).toBeVisible();

		await addFromPageEnd(page);

		await expect(page.locator('.pixonaut-form__error')).toHaveText(
			'Please change these fields: Motiv',
		);
		await expect(upload).toBeFocused();
		await expect(upload).toBeInViewport();
		await expect(form.getByTestId('violation-motiv-src')).toBeInViewport();
		await expect(page.getByText(/has been added to your cart/)).toHaveCount(0);
		await page.screenshot({
			path: test.info().outputPath(`missing-photo-${screen.name}.png`),
		});
	});
}
