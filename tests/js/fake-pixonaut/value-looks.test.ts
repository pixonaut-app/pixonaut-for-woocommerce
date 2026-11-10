import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { storefront } from './storefront-support';
import { SITE_URL, send } from './support';

const fake = createFake();

function setLook(blankId: string, json: unknown) {
	return send(fake, `/__fake/blanks/${blankId}/value-looks`, {
		method: 'POST',
		json,
	});
}

async function sizeLooks(): Promise<Record<string, unknown>> {
	const read = await send(fake, `/v1/blanks/${FIXTURE.blank.tee}/variants`, {
		headers: storefront(),
	});
	expect(read.status).toBe(200);
	return (await read.json()).options[1].appearances;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /__fake/blanks/:id/value-looks', () => {
	it('gives a value a picture the fake serves, or a fill, until the next reset', async () => {
		expect(
			(
				await setLook(FIXTURE.blank.tee, {
					axis: 'Size',
					value: 'S',
					look: 'svg',
				})
			).status,
		).toBe(200);
		await setLook(FIXTURE.blank.tee, {
			axis: 'Size',
			value: 'M',
			look: { colours: ['#112233', '#445566'] },
		});

		const looks = await sizeLooks();
		expect(looks.M).toEqual({ kind: 'fill', colours: ['#112233', '#445566'] });
		const picture = looks.S as { kind: string; url: string };
		expect(picture.kind).toBe('image');
		const served = await send(fake, new URL(picture.url).pathname);
		expect(served.headers.get('content-type')).toBe('image/svg+xml');
		expect(await served.text()).toContain('<svg');

		fake.reset('default', SITE_URL);
		expect((await sizeLooks()).S).toBeNull();
	});

	it('serves a PNG picture', async () => {
		const set = await setLook(FIXTURE.blank.tee, {
			axis: 'Size',
			value: 'S',
			look: 'png',
		});
		const served = await send(fake, new URL((await set.json()).url).pathname);

		expect(served.headers.get('content-type')).toBe('image/png');
	});

	it('refuses an axis the blank lacks, an unknown look, and a blank it lacks', async () => {
		expect(
			(
				await setLook(FIXTURE.blank.tee, {
					axis: 'Frame',
					value: 'S',
					look: 'png',
				})
			).ok,
		).toBe(false);
		expect(
			(
				await setLook(FIXTURE.blank.tee, {
					axis: 'Size',
					value: 'S',
					look: 'gif',
				})
			).ok,
		).toBe(false);
		expect(
			(await setLook('blank_none', { axis: 'Size', value: 'S', look: null }))
				.status,
		).toBe(404);
	});
});
