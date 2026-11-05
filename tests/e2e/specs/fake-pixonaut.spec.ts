import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, SDK_LOCK, SDK_SCRIPT, test } from '../fixtures';

const MAIN = FIXTURE.store.main;
const TEMPLATE = FIXTURE.template.live;

/** `GET /v1/templates/{id}/constraints` for the fixture template on the
 *  locked blank's front. */
const CONSTRAINTS_PATH = `/v1/templates/${encodeURIComponent(TEMPLATE)}/constraints?blankId=${FIXTURE.blank.tee}&placementId=${FIXTURE.placement.front}`;

test('WordPress reaches the fake on the server base', async ({
	request,
	fake,
}) => {
	const response = await request.post(
		restUrl('/pixonaut-e2e/v1/fake-request'),
		{ data: { path: CONSTRAINTS_PATH, key: MAIN.integrationKey } },
	);

	expect(response.ok()).toBe(true);
	const answered = await response.json();
	expect(answered.status).toBe(200);
	expect(answered.body).toMatchObject({
		templateId: TEMPLATE,
		placement: { blankId: FIXTURE.blank.tee },
	});
	expect(await fake.requests()).toMatchObject([
		{ operation: 'GET /v1/templates/{id}/constraints', status: 200 },
	]);
});

test('the browser reaches the fake on the public base', async ({
	page,
	fake,
}) => {
	await page.goto('/');

	const status = await page.evaluate(
		async ({ url, key }) =>
			(await fetch(url, { headers: { authorization: `Bearer ${key}` } }))
				.status,
		{ url: `${fake.baseURL}${CONSTRAINTS_PATH}`, key: MAIN.publishableKey },
	);

	expect(status).toBe(200);
});

test('the fixture template renders in the pinned SDK against the fake', async ({
	page,
	fake,
}) => {
	await page.goto('/');

	const version = await page.evaluate(
		async ({ sdk, apiBase, publishableKey, templateId, blank }) => {
			const script = document.createElement('script');
			script.src = sdk.url;
			script.integrity = sdk.integrity;
			script.crossOrigin = 'anonymous';
			await new Promise((resolve, reject) => {
				script.onload = resolve;
				script.onerror = reject;
				document.head.append(script);
			});
			const previewTarget = document.createElement('div');
			const formTarget = document.createElement('div');
			previewTarget.style.width = '400px';
			document.body.prepend(previewTarget, formTarget);

			// biome-ignore lint/suspicious/noExplicitAny: the SDK's IIFE global.
			const { Pixonaut } = window as any;
			await Pixonaut.mount({
				previewTarget,
				formTarget,
				publishableKey,
				apiBase,
				templateId,
				blankId: blank.id,
				placementId: blank.placement,
			});
			return Pixonaut.version;
		},
		{
			sdk: SDK_SCRIPT,
			apiBase: fake.baseURL,
			publishableKey: MAIN.publishableKey,
			templateId: TEMPLATE,
			blank: { id: FIXTURE.blank.tee, placement: FIXTURE.placement.front },
		},
	);

	expect(version).toBe(SDK_LOCK.version);
	const preview = page.getByTestId('pixonaut-preview');
	await expect
		.poll(() => preview.evaluate((img: HTMLImageElement) => img.naturalWidth))
		.toBeGreaterThan(0);
	const operations = (await fake.requests()).map((r) => r.operation);
	expect(operations).toEqual(
		expect.arrayContaining([
			'GET /v1/templates/{id}',
			'GET /v1/templates/{id}/constraints',
			'GET /f/{file}',
		]),
	);
});
