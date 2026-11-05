import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import {
	OTHER_SESSION,
	PICTURE,
	PICTURE_HEIGHT_PX,
	PICTURE_WIDTH_PX,
	putUpload,
	storefront,
	uploadPicture,
} from './storefront-support';
import { SITE_URL, send } from './support';

const fake = createFake();

function cutOut(assetId: string, method: string, headers = storefront()) {
	return send(fake, `/v1/assets/${assetId}/cut-out`, { method, headers });
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('POST /v1/assets/{id}/cut-out', () => {
	it('cuts the picture out into an upload of its own, once', async () => {
		const source = await uploadPicture(fake);

		const first = await cutOut(source, 'POST');
		expect(first.status).toBe(202);
		const asked = await first.json();
		expect(asked).toEqual({
			status: 'done',
			attempts: 1,
			retryable: false,
			cutOut: {
				assetId: expect.stringMatching(/^up_/),
				widthPx: PICTURE_WIDTH_PX,
				heightPx: PICTURE_HEIGHT_PX,
				contentKey: expect.any(String),
			},
		});
		expect(asked.cutOut.assetId).not.toBe(source);

		const again = await cutOut(source, 'POST');
		expect(again.status).toBe(200);
		expect(await again.json()).toEqual(asked);

		const read = await cutOut(source, 'GET');
		expect(read.status).toBe(200);
		expect(await read.json()).toEqual(asked);

		const content = await send(
			fake,
			`/v1/assets/${asked.cutOut.assetId}/content`,
			{ headers: storefront() },
		);
		expect(content.status).toBe(200);
	});

	it('answers an upload made for no picture not_found', async () => {
		const declared = await send(fake, '/v1/assets', {
			method: 'POST',
			headers: storefront(),
			json: {
				filename: 'picture.png',
				mime: 'image/png',
				sizeBytes: PICTURE.length,
			},
		});
		const { assetId, uploadUrl } = await declared.json();
		expect((await putUpload(fake, uploadUrl)).status).toBe(200);

		const response = await cutOut(assetId, 'POST');

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});

	it("answers another session's upload not_found", async () => {
		const source = await uploadPicture(fake);

		const response = await cutOut(source, 'POST', storefront(OTHER_SESSION));

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});

describe('GET /v1/assets/{id}/cut-out', () => {
	it('answers not_found before a cut-out was asked', async () => {
		const response = await cutOut(await uploadPicture(fake), 'GET');

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});
