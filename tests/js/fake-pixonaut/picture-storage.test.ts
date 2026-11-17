import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake, VIOLATION_STATUS } from '../../fake-pixonaut/src/app';
import { bearer, MAIN, SITE_ORIGIN, SITE_URL, send } from './support';

const NO_CONTENT = 204;
const FORBIDDEN = 403;
const UNAUTHORIZED = 401;
const PATH = '/v1/connect/picture-storage';

const fake = createFake();

const BYTES_PER_PICTURE = 400_000;
const REPORT = {
	pictureCount: 12,
	pictureBytes: 12 * BYTES_PER_PICTURE,
	variationPictureCount: 8,
	variationPictureBytes: 8 * BYTES_PER_PICTURE,
	freeBytes: null,
	variationPicturesOn: true,
	links: { variationSetting: null, cleanUp: null },
	products: [
		{
			storeProductId: '42',
			name: 'Tee',
			pictureCount: 12,
			pictureBytes: 12 * BYTES_PER_PICTURE,
			variationPictureCount: 8,
			variationPictureBytes: 8 * BYTES_PER_PICTURE,
			variationPicturesOn: true,
			editUrl: 'http://shop.test/wp-admin/post.php?post=42&action=edit',
		},
	],
};

/** Reports as the store does, with its integration key. */
function report(json: unknown, headers = bearer(MAIN.integrationKey)) {
	return send(fake, PATH, { method: 'PUT', headers, json });
}

/** What the control API shows the stores sent. */
async function reports() {
	const response = await send(fake, '/__fake/picture-storage');
	return ((await response.json()) as { reports: unknown[] }).reports;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	fake.violations.length = 0;
});

describe('PUT /v1/connect/picture-storage', () => {
	it('holds the report as sent and answers no content', async () => {
		const response = await report(REPORT);

		expect(response.status).toBe(NO_CONTENT);
		expect(fake.state.stores.get(MAIN.id)?.pictureStorage).toEqual(REPORT);
		expect(await reports()).toEqual([{ siteUrl: SITE_URL, report: REPORT }]);
		expect(fake.violations).toEqual([]);
	});

	it('replaces the last report with the next', async () => {
		await report(REPORT);
		const next = { ...REPORT, pictureCount: 0, products: [] };

		await report(next);

		expect(await reports()).toEqual([{ siteUrl: SITE_URL, report: next }]);
	});

	it('shows no report from a store that sent none', async () => {
		expect(await reports()).toEqual([]);
	});

	it('flags a report the contract does not allow and holds none', async () => {
		const response = await report({ ...REPORT, pictureCount: -1 });

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toHaveLength(1);
		expect(await reports()).toEqual([]);
	});

	it('refuses a key that is not the integration key', async () => {
		expect((await report(REPORT, {})).status).toBe(UNAUTHORIZED);
		const publishable = {
			...bearer(MAIN.publishableKey),
			origin: SITE_ORIGIN,
		};
		expect((await report(REPORT, publishable)).status).toBe(FORBIDDEN);
	});
});
