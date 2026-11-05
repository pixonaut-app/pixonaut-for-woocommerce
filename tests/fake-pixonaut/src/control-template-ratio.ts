// `POST /__fake/templates/:id/ratio`: gives a template version another shape,
// `{w, h}`, so a test can see how a page lays out a design of that shape. The
// next reset brings the fixture's shape back.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';

const NOT_FOUND_STATUS = 404;

export function registerTemplateRatioControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/templates/:id/ratio`, async (c) => {
		const template = fake.state.templates.get(c.req.param('id'));
		if (template === undefined) {
			return c.json({ error: 'there is no such template' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { w, h } = body.value;
		if (typeof w !== 'number' || typeof h !== 'number' || w <= 0 || h <= 0) {
			return refused('`w` and `h` are not positive numbers').response;
		}
		template.template.ratio = { w, h };
		return c.json(template.template.ratio);
	});
}
