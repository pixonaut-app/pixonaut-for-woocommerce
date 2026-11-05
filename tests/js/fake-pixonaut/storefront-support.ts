import { expect } from 'vitest';
import type { Fake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_ORIGIN, send } from './support';

export const SESSION = 'ds_session_one';
export const OTHER_SESSION = 'ds_session_two';

/** The main store's storefront: its publishable key, from its own site, in
 *  one designer session. */
export function storefront(session = SESSION): Record<string, string> {
	return {
		...bearer(MAIN.publishableKey),
		origin: SITE_ORIGIN,
		'x-pixonaut-session': session,
	};
}

/** A 4×2 PNG. */
export const PICTURE = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAAABAAAAAQBPJcTWAAAAEElEQVR4nGP4y8AARwzIHABuygfpbgaHdgAAAABJRU5ErkJggg==',
	'base64',
);
export const PICTURE_WIDTH_PX = 4;
export const PICTURE_HEIGHT_PX = 2;

export function picture(): Uint8Array<ArrayBuffer> {
	return new Uint8Array(PICTURE);
}

/** Declares a PNG upload, as the SDK does before it puts the bytes. */
export async function declareUpload(
	fake: Fake,
	headers = storefront(),
): Promise<{ assetId: string; uploadUrl: string }> {
	const response = await send(fake, '/v1/assets', {
		method: 'POST',
		headers,
		json: {
			filename: 'picture.png',
			mime: 'image/png',
			sizeBytes: PICTURE.length,
			templateId: FIXTURE.template.live,
			layerId: 'photo',
		},
	});
	expect(response.status).toBe(201);
	return response.json();
}

export function putUpload(
	fake: Fake,
	uploadUrl: string,
	body: Uint8Array<ArrayBuffer> = picture(),
) {
	return send(fake, uploadUrl, {
		method: 'PUT',
		headers: { 'content-type': 'image/png', origin: SITE_ORIGIN },
		body,
	});
}

/** Declares and uploads a PNG; answers its id. */
export async function uploadPicture(
	fake: Fake,
	headers = storefront(),
): Promise<string> {
	const { assetId, uploadUrl } = await declareUpload(fake, headers);
	expect((await putUpload(fake, uploadUrl)).status).toBe(200);
	return assetId;
}
