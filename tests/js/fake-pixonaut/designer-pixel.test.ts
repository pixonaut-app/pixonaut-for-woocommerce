import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { storefront } from './storefront-support';
import { SITE_URL, send, templatePath } from './support';

const fake = createFake();

function ask(templateId: string, headers = storefront()) {
	return send(fake, '/v1/pixel/designer', {
		method: 'POST',
		headers,
		json: { templateId, prompt: 'Write my name in gold', values: {} },
	});
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('POST /v1/pixel/designer', () => {
	it('answers pixel_unavailable, as the template read offers no Pixel', async () => {
		const read = await send(fake, templatePath(FIXTURE.template.live), {
			headers: storefront(),
		});
		expect((await read.json()).pixel).toBe(false);

		const response = await ask(FIXTURE.template.live);

		expect(response.status).toBe(503);
		expect(await response.json()).toEqual({ error: 'pixel_unavailable' });
	});

	it('offers Pixel and writes the field once a test turns it on', async () => {
		const pixel = { layerId: 'slogan', prop: 'content', value: 'Alva' };
		const on = await send(
			fake,
			`/__fake/templates/${FIXTURE.template.live}/pixel`,
			{ method: 'POST', json: pixel },
		);
		expect(on.status).toBe(200);
		const read = await send(fake, templatePath(FIXTURE.template.live), {
			headers: storefront(),
		});
		expect((await read.json()).pixel).toBe(true);

		const response = await ask(FIXTURE.template.live);

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			changed: [{ layerId: 'slogan', prop: 'content' }],
			values: { slogan: { content: 'Alva' } },
		});
	});

	it("adds a layer of the customer's own and nothing else once a test asks", async () => {
		const on = await send(
			fake,
			`/__fake/templates/${FIXTURE.template.live}/pixel`,
			{ method: 'POST', json: { addsLayer: 'text' } },
		);
		expect(on.status).toBe(200);
		const read = await send(fake, templatePath(FIXTURE.template.live), {
			headers: storefront(),
		});
		const { pixel, template } = await read.json();
		expect(pixel).toBe(true);
		expect(template.customerLayers.text.layer.type).toBe('text');

		const response = await ask(FIXTURE.template.live);

		expect(response.status).toBe(200);
		expect(await response.json()).toEqual({
			reply: '',
			changed: [],
			cutOuts: [],
			layers: [{ action: 'added', layerId: '$added.0', kind: 'text' }],
			values: { $added: ['text'] },
		});
	});

	it('refuses a command that names no field, and a template it lacks', async () => {
		const path = `/__fake/templates/${FIXTURE.template.live}/pixel`;
		const incomplete = await send(fake, path, {
			method: 'POST',
			json: { layerId: 'slogan' },
		});
		expect(incomplete.status).toBe(400);
		const missing = await send(fake, '/__fake/templates/nope@1/pixel', {
			method: 'POST',
			json: { layerId: 'slogan', prop: 'content', value: 'Alva' },
		});
		expect(missing.status).toBe(404);
	});

	it('refuses a request with no designer session', async () => {
		const { 'x-pixonaut-session': _session, ...keyed } = storefront();

		const response = await ask(FIXTURE.template.live, keyed);

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({ error: 'no_session' });
	});

	it('answers a template the store cannot read not_found', async () => {
		const response = await ask(FIXTURE.template.sandbox);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'not_found' });
	});
});
