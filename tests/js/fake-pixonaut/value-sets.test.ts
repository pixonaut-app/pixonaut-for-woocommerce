import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, send, templatePath } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };

const FARMOR = { slogan: { content: 'Världens bästa farmor' } };

async function made(values: object = FARMOR): Promise<Response> {
	return send(fake, templatePath(FIXTURE.template.live, '/value-sets'), {
		...asStore,
		method: 'POST',
		json: { values },
	});
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /v1/templates/{id}/value-sets', () => {
	it('makes a set of the values, and answers the same set for the same values', async () => {
		const first = await made();
		const again = await made();

		expect(first.status).toBe(201);
		const body = await first.json();
		expect(body).toEqual({
			valueSetId: expect.stringMatching(/^vs_[0-9a-f]{24}$/),
			templateId: FIXTURE.template.live,
			values: FARMOR,
		});
		expect(again.status).toBe(200);
		expect((await again.json()).valueSetId).toBe(body.valueSetId);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a layer or prop the template does not have, naming each', async () => {
		const response = await made({
			ghost: { content: 'x' },
			slogan: { colour: '#f00' },
		});

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'value_set_invalid',
			issues: [
				{ layerId: 'ghost', prop: '', rule: 'unknown_layer' },
				{ layerId: 'slogan', prop: 'colour', rule: 'unknown_prop' },
			],
		});
		expect(fake.violations).toEqual([]);
	});
});

describe('a template read with `valueSet`', () => {
	it('writes the set’s values into the layers, and names the set', async () => {
		const { valueSetId } = await (await made()).json();

		const response = await send(
			fake,
			templatePath(FIXTURE.template.live, `?valueSet=${valueSetId}`),
			asStore,
		);

		expect(response.status).toBe(200);
		const body = await response.json();
		expect(body.valueSetId).toBe(valueSetId);
		const slogan = body.template.layers.find(
			(layer: { id: string }) => layer.id === 'slogan',
		);
		expect(slogan.props.content.value).toBe(FARMOR.slogan.content);
		const field = body.formSchema.steps
			.flatMap((step: { fields: object[] }) => step.fields)
			.find(
				(f: { layerId: string; prop: string }) =>
					f.layerId === 'slogan' && f.prop === 'content',
			);
		expect(field.default).toBe(FARMOR.slogan.content);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a set the template holds none by with 422 value_set_not_found', async () => {
		const response = await send(
			fake,
			templatePath(
				FIXTURE.template.live,
				'?valueSet=vs_000000000000000000000000',
			),
			asStore,
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'value_set_not_found' });
		expect(fake.violations).toEqual([]);
	});
});
