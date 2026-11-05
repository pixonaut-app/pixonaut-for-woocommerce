import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { storefront } from './storefront-support';
import { SITE_URL, send, templatePath } from './support';

const fake = createFake();

const TALL = { w: 1, h: 3 };

function setRatio(templateId: string, json: unknown) {
	return send(fake, `/__fake/templates/${templateId}/ratio`, {
		method: 'POST',
		json,
	});
}

async function readRatio(templateId: string) {
	const read = await send(fake, templatePath(templateId), {
		headers: storefront(),
	});
	return (await read.json()).template.ratio;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /__fake/templates/:id/ratio', () => {
	it('gives the template that shape until the next reset', async () => {
		const fixture = await readRatio(FIXTURE.template.landscape);

		const response = await setRatio(FIXTURE.template.landscape, TALL);

		expect(response.status).toBe(200);
		expect(await readRatio(FIXTURE.template.landscape)).toEqual(TALL);
		fake.reset('default', SITE_URL);
		expect(await readRatio(FIXTURE.template.landscape)).toEqual(fixture);
	});

	it('refuses a shape that is not one, and a template it lacks', async () => {
		const notAShape = await setRatio(FIXTURE.template.landscape, {
			w: 0,
			h: 3,
		});
		expect(notAShape.ok).toBe(false);

		const missing = await setRatio('no-such-template@1', TALL);
		expect(missing.status).toBe(404);
	});
});
