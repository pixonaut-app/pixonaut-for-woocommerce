import { expect, restUrl, test } from '../fixtures';
import {
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

/** The accent a block theme defines, as the Pixonaut Shop theme does, and
 *  its colour as the page computes it. */
const ACCENT = '#2f5bd3';
const ACCENT_RGB = 'rgb(47, 91, 211)';

/** A label of its own beside the number: not empty, no number in it. */
const STEP_LABEL = /^[^\d.]\S*/;

const FRONT_FORM = '#pixonaut-form-front [data-testid="pixonaut-form"]';

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a theme’s accent draws the designer’s step numbers as badges', async ({
	page,
	request,
	theme,
}) => {
	// The preset on `:root` before the storefront script reads it: the
	// document turns interactive before its deferred scripts run.
	await page.addInitScript((accent) => {
		document.addEventListener('readystatechange', () => {
			if (document.readyState !== 'interactive') {
				return;
			}
			const style = document.createElement('style');
			style.textContent = `:root { --wp--preset--color--accent: ${accent}; }`;
			document.head.append(style);
		});
	}, ACCENT);
	await page.goto(
		await createProduct(
			request,
			`Step numbers ${theme} ${Date.now()}`,
			PERSONALISED,
		),
	);

	const steps = page.locator(`${FRONT_FORM} > h3.pixonaut-step`);
	await expect(steps.first()).toBeVisible();
	const count = await steps.count();
	await expect(steps.locator('.pixonaut-step__number')).toHaveText(
		Array.from({ length: count }, (_, index) => String(index + 1)),
	);
	await expect(steps.first().locator('.pixonaut-step__label')).toHaveText(
		STEP_LABEL,
	);
	await expect(steps.first()).toHaveCSS('color', ACCENT_RGB);
	const badge = steps.first().locator('.pixonaut-step__number');
	await expect(badge).toHaveCSS('background-color', ACCENT_RGB);
	await expect(badge).toHaveCSS('border-radius', '50%');
});

test('a theme without an accent keeps the SDK’s numbered headings', async ({
	page,
	request,
	theme,
}) => {
	await page.goto(
		await createProduct(
			request,
			`No step numbers ${theme} ${Date.now()}`,
			PERSONALISED,
		),
	);

	const first = page.locator(`${FRONT_FORM} > h3`).first();
	await expect(first).toHaveText(/^1\. /);
	await expect(page.locator('.pixonaut-step')).toHaveCount(0);
});
