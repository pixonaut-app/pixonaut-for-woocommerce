// `POST /__fake/templates/:id/background`: says whether a template version's
// background shows through, `{seeThrough: true | false | null}`, so a test can
// see what a store makes of each answer. The next reset forgets it.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';

const NOT_FOUND_STATUS = 404;

export function registerTemplateBackgroundControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/templates/:id/background`, async (c) => {
		const template = fake.state.templates.get(c.req.param('id'));
		if (template === undefined) {
			return c.json({ error: 'there is no such template' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { seeThrough } = body.value;
		if (typeof seeThrough !== 'boolean' && seeThrough !== null) {
			return refused('`seeThrough` is not a boolean or null').response;
		}
		template.backgroundSeeThrough = seeThrough;
		return c.json({ seeThrough });
	});
}
