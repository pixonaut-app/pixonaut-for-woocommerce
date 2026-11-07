import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { OTHER_SESSION, storefront, uploadPicture } from './storefront-support';
import { SITE_URL, send } from './support';

const fake = createFake();

/** A machine's threads, as a placement's stitch block lists them. */
const THREADS = {
	maxThreads: 6,
	threads: [
		{ id: '1801', name: '1801 White', hex: '#FFFFFF', cmyk: [0, 0, 0, 0] },
		{ id: '1839', name: '1839 Red', hex: '#BA0C2F', cmyk: [0, 95, 75, 20] },
	],
};

function threadCopy(assetId: string, headers = storefront()) {
	return send(fake, `/v1/assets/${assetId}/threads`, {
		method: 'POST',
		headers,
		json: THREADS,
	});
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('POST /v1/assets/{id}/threads', () => {
	it('reads every upload as a photo, of which no copy is made', async () => {
		const response = await threadCopy(await uploadPicture(fake));

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({
			colours: { kind: 'photo' },
			copy: null,
			threads: [],
		});
	});

	it("answers another session's upload not_found", async () => {
		const source = await uploadPicture(fake);

		const response = await threadCopy(source, storefront(OTHER_SESSION));

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});
