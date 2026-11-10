import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { storefront } from './storefront-support';
import { SITE_URL, send, templatePath } from './support';

const fake = createFake();

/** The tee's slogan text, a field every fixture tee offers. */
const SLOGAN = 'slogan.content';

const KEY = 'slogan';

function setFieldKey(templateId: string, json: unknown) {
	return send(fake, `/__fake/templates/${templateId}/field-key`, {
		method: 'POST',
		json,
	});
}

async function readFieldKey(templateId: string): Promise<unknown> {
	const read = await send(fake, templatePath(templateId), {
		headers: storefront(),
	});
	const { layers } = (await read.json()).template as {
		layers: Array<{ id: string; props: Record<string, { fieldKey?: string }> }>;
	};
	return layers.find((layer) => layer.id === 'slogan')?.props.content?.fieldKey;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /__fake/templates/:id/field-key', () => {
	it('keys the field until the next reset', async () => {
		const response = await setFieldKey(FIXTURE.template.landscape, {
			field: SLOGAN,
			key: KEY,
		});

		expect(response.status).toBe(200);
		expect(await readFieldKey(FIXTURE.template.landscape)).toBe(KEY);
		expect(await readFieldKey(FIXTURE.template.live)).toBeUndefined();
		fake.reset('default', SITE_URL);
		expect(await readFieldKey(FIXTURE.template.landscape)).toBeUndefined();
	});

	it('refuses a key that is not one, and a field or template it lacks', async () => {
		const noKey = await setFieldKey(FIXTURE.template.landscape, {
			field: SLOGAN,
			key: '',
		});
		expect(noKey.ok).toBe(false);

		const noField = await setFieldKey(FIXTURE.template.landscape, {
			field: 'nothing.here',
			key: KEY,
		});
		expect(noField.status).toBe(404);

		const missing = await setFieldKey('no-such-template@1', {
			field: SLOGAN,
			key: KEY,
		});
		expect(missing.status).toBe(404);
	});
});
