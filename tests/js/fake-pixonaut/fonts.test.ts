import { describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { SITE_ORIGIN, send } from './support';

const fake = createFake();

/** The font files the fixture template names, by family. */
const FONTS: Record<string, { hash: string }[]> =
	fake.state.templates.get(FIXTURE.template.live)?.template.fonts ?? {};

describe('GET /f/{file}', () => {
	it('serves every font the fixture template names, keyless and to any page', async () => {
		const hashes = Object.values(FONTS).flatMap((faces) =>
			faces.map((face) => face.hash),
		);
		expect(hashes.length).toBeGreaterThan(0);

		for (const hash of hashes) {
			const response = await send(fake, `/f/${hash}.ttf`, {
				headers: { origin: SITE_ORIGIN },
			});

			expect(response.status).toBe(200);
			expect(response.headers.get('content-type')).toBe('font/ttf');
			expect(response.headers.get('access-control-allow-origin')).toBe('*');
			const bytes = new Uint8Array(await response.arrayBuffer());
			const digest = await crypto.subtle.digest('SHA-256', bytes);
			expect(Buffer.from(digest).toString('hex')).toBe(hash);
		}
		expect(fake.violations).toEqual([]);
	});

	it('answers a name it has no file for with a bare 404', async () => {
		const response = await send(fake, `/f/${'0'.repeat(64)}.ttf`);

		expect(response.status).toBe(404);
	});
});
