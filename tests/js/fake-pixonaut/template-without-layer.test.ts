import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { storefront } from './storefront-support';
import { SITE_URL, send, templatePath } from './support';

const fake = createFake();

/** The tee's photo, in a group and a form step of its own. */
const PHOTO = 'motiv';

function dropLayer(templateId: string, json: unknown) {
	return send(fake, `/__fake/templates/${templateId}/without-layer`, {
		method: 'POST',
		json,
	});
}

type Read = {
	template: { layers: { id: string }[]; groups: { layerIds: string[] }[] };
	formSchema: { steps: { index: number; layerIds: string[] }[] };
};

async function read(templateId: string): Promise<Read> {
	const response = await send(fake, templatePath(templateId), {
		headers: storefront(),
	});
	return (await response.json()) as Read;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /__fake/templates/:id/without-layer', () => {
	it('takes the layer, its group and its step out until the next reset', async () => {
		const response = await dropLayer(FIXTURE.template.landscape, {
			layerId: PHOTO,
		});

		expect(response.status).toBe(200);
		const dropped = await read(FIXTURE.template.landscape);
		expect(dropped.template.layers.map((layer) => layer.id)).toEqual([
			'slogan',
		]);
		expect(dropped.template.groups.flatMap((g) => g.layerIds)).toEqual([
			'slogan',
		]);
		expect(dropped.formSchema.steps).toMatchObject([
			{ index: 0, layerIds: ['slogan'] },
		]);
		// Another version of the tee keeps it.
		const kept = await read(FIXTURE.template.live);
		expect(kept.template.layers.map((layer) => layer.id)).toContain(PHOTO);

		fake.reset('default', SITE_URL);
		const reset = await read(FIXTURE.template.landscape);
		expect(reset.template.layers.map((layer) => layer.id)).toContain(PHOTO);
	});

	it('refuses a layer that is not one, and a layer or template it lacks', async () => {
		expect((await dropLayer(FIXTURE.template.landscape, {})).ok).toBe(false);
		expect(
			(await dropLayer(FIXTURE.template.landscape, { layerId: 'nothing' }))
				.status,
		).toBe(404);
		expect(
			(await dropLayer('no-such-template@1', { layerId: PHOTO })).status,
		).toBe(404);
	});
});
