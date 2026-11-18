// `POST /__fake/templates/:id/require-change`: holds another field of a
// template version to a change, `{field: "<layerId>.<prop>"}`, such as its
// picture, which then must be uploaded. The next reset forgets it.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';
import { isRecord } from './field-keys';

const NOT_FOUND_STATUS = 404;

export function registerTemplateRequireChangeControl(
	app: Hono,
	fake: Fake,
): void {
	app.post(`${PREFIX}/templates/:id/require-change`, async (c) => {
		const template = fake.state.templates.get(c.req.param('id'));
		if (template === undefined) {
			return c.json({ error: 'there is no such template' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { field } = body.value;
		if (typeof field !== 'string') {
			return refused('`field` is not a string').response;
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
		spec.constraints = {
			...(isRecord(spec.constraints) ? spec.constraints : {}),
			requireChange: true,
		};
		template.requireChange = [
			...new Set([...(template.requireChange ?? []), field]),
		];
		return c.json({ field, requireChange: template.requireChange });
	});
}
