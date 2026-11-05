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
