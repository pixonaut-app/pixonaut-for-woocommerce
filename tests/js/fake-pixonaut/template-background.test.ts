import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { storefront } from './storefront-support';
import { SITE_URL, send, templatePath } from './support';

const fake = createFake();

function setBackground(templateId: string, json: unknown) {
	return send(fake, `/__fake/templates/${templateId}/background`, {
		method: 'POST',
		json,
	});
}

async function readBackground(templateId: string) {
	const read = await send(fake, templatePath(templateId), {
		headers: storefront(),
	});
	return (await read.json()).backgroundSeeThrough;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /__fake/templates/:id/background', () => {
	it('answers what it was told until the next reset, and null before', async () => {
		expect(await readBackground(FIXTURE.template.landscape)).toBeNull();

		const response = await setBackground(FIXTURE.template.landscape, {
			seeThrough: true,
		});

		expect(response.status).toBe(200);
		expect(await readBackground(FIXTURE.template.landscape)).toBe(true);
		fake.reset('default', SITE_URL);
		expect(await readBackground(FIXTURE.template.landscape)).toBeNull();
	});

	it('refuses what is no answer, and a template it lacks', async () => {
		const notAnAnswer = await setBackground(FIXTURE.template.landscape, {
			seeThrough: 'yes',
		});
		expect(notAnAnswer.ok).toBe(false);

		const missing = await setBackground('no-such-template@1', {
			seeThrough: true,
		});
		expect(missing.status).toBe(404);
	});
});
