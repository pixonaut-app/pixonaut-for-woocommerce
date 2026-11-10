// `POST /__fake/templates/:id/field-key`: gives a field of a template version
// a field key, `{field: "<layerId>.<prop>", key}`, so a test can see a value
// follow its key into another template. The next reset forgets it.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';
import { isRecord } from './field-keys';

const NOT_FOUND_STATUS = 404;

export function registerTemplateFieldKeyControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/templates/:id/field-key`, async (c) => {
		const template = fake.state.templates.get(c.req.param('id'));
		if (template === undefined) {
			return c.json({ error: 'there is no such template' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { field, key } = body.value;
		if (typeof field !== 'string' || typeof key !== 'string' || key === '') {
			return refused('`field` and `key` are not strings').response;
		}
		const [layerId, prop] = field.split('.');
		const layer = (template.template.layers as unknown[] | undefined)?.find(
			(each) => isRecord(each) && each.id === layerId,
		);
		const spec =
			isRecord(layer) && isRecord(layer.props)
				? layer.props[prop ?? '']
				: undefined;
		if (!isRecord(spec)) {
			return c.json(
				{ error: 'the template has no such field' },
				NOT_FOUND_STATUS,
			);
		}
		spec.fieldKey = key;
		return c.json({ field, key });
	});
}
