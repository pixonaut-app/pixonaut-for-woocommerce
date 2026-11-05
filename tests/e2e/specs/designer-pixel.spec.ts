// The pinned SDK's Create with AI control on a shop's product page, through
// each of its states. The control is the release the page loads from the CDN;
// the fake offers Pixel on the template and answers it.
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, SDK_SCRIPT, test } from '../fixtures';
import {
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

const OPERATION = 'POST /v1/pixel/designer';
/** What Pixel writes into the fixture template's slogan. */
const WRITTEN = 'Written by Pixel';
/** Long enough for the test to see the request under way and cancel it. */
const SLOW_ANSWER_MS = 20_000;
const PROMPT = 'Write something';

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a product page loads the pinned SDK and walks Create with AI through its states', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const offered = await fake.control.post(
		`/__fake/templates/${FIXTURE.template.live}/pixel`,
		{ data: { layerId: 'slogan', prop: 'content', value: WRITTEN } },
	);
	expect(offered.ok()).toBe(true);
	const arm = async (fault: object) => {
		const armed = await fake.control.post('/__fake/faults', {
			data: { operation: OPERATION, fault, times: 1 },
		});
		expect(armed.ok()).toBe(true);
	};
	const permalink = await createProduct(
		request,
		`Create with AI ${theme} ${Date.now()}`,
		PERSONALISED,
	);

	const sdkLoaded = page.waitForResponse(SDK_SCRIPT.url);
	await page.goto(permalink);
	expect((await sdkLoaded).ok()).toBe(true);

	const create = page
		.getByTestId('pixonaut-pixel')
		.getByRole('button', { name: 'Create with AI' });
	const prompt = page.getByTestId('pixonaut-pixel-prompt');
	const generate = page.getByTestId('pixonaut-pixel-generate');
	const status = page.getByTestId('pixonaut-pixel-status');
	const field = page
		.locator('#pixonaut-form-front')
		.getByRole('textbox', { name: /Slogan/ });
	await expect(field).toBeVisible();
	const before = await field.inputValue();

	// The closed control, and the prompt it opens and closes.
	await create.click();
	await page.getByTestId('pixonaut-pixel-close').click();
	await create.click();
	await prompt.fill(PROMPT);

	// Generating, with Cancel back to the prompt and nothing applied.
	await arm({ kind: 'timeout', ms: SLOW_ANSWER_MS });
	await generate.click();
	await expect(status).toContainText('Generating…');
	await page.getByTestId('pixonaut-pixel-cancel').click();
	await expect(prompt).toHaveValue(PROMPT);
	await expect(field).toHaveValue(before);

	// A failure says so and offers Try again.
	await arm({ kind: 'status', status: 503, error: 'pixel_unavailable' });
	await generate.click();
	await expect(page.getByTestId('pixonaut-pixel-message')).toBeVisible();
	await expect(generate).toHaveText('Try again');

	// Applied, then taken back.
	await generate.click();
	await expect(status).toContainText('Changes applied');
	await expect(field).toHaveValue(WRITTEN);
	await page.getByTestId('pixonaut-pixel-undo').click();
	await expect(field).toHaveValue(before);
	await expect(create).toBeVisible();
});

test('Create with AI offers Undo for an answer that only adds a customer layer', async ({
	page,
	request,
	fake,
	theme,
}) => {
	const offered = await fake.control.post(
		`/__fake/templates/${FIXTURE.template.live}/pixel`,
		{ data: { addsLayer: 'text' } },
	);
	expect(offered.ok()).toBe(true);
	const permalink = await createProduct(
		request,
		`Create with AI layer ${theme} ${Date.now()}`,
		PERSONALISED,
	);

	await page.goto(permalink);

	const added = page.getByTestId('pixonaut-added-layer');
	await page
		.getByTestId('pixonaut-pixel')
		.getByRole('button', { name: 'Create with AI' })
		.click();
	await expect(added).toHaveCount(0);
	await page.getByTestId('pixonaut-pixel-prompt').fill(PROMPT);
	await page.getByTestId('pixonaut-pixel-generate').click();

	await expect(page.getByTestId('pixonaut-pixel-status')).toContainText(
		'Changes applied',
	);
	await expect(added).toHaveCount(1);
	await expect(page.getByTestId('pixonaut-pixel-message')).toHaveCount(0);

	await page.getByTestId('pixonaut-pixel-undo').click();
	await expect(added).toHaveCount(0);
});
