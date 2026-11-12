// `POST /__fake/templates/:id/without-layer`: takes a layer out of a template
// version, `{layerId}`, with the group and the form step that held it, as a
// store keeps a copy of a template with its photo dropped. Every other layer
// keeps its id, so a test can see a value follow it into the other template.
// The next reset forgets it.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';
import { isRecord } from './field-keys';

const NOT_FOUND_STATUS = 404;

type Grouped = { layerIds?: unknown };

/** The entries of `list` that are not the layer and hold nothing of it. */
function without(list: unknown, layerId: string): unknown[] {
	return (Array.isArray(list) ? list : []).filter(
		(each) =>
			!isRecord(each) ||
			(each.id !== layerId &&
				!(
					Array.isArray((each as Grouped).layerIds) &&
					((each as Grouped).layerIds as unknown[]).includes(layerId)
				)),
	);
}

export function registerTemplateWithoutLayerControl(
	app: Hono,
	fake: Fake,
): void {
	app.post(`${PREFIX}/templates/:id/without-layer`, async (c) => {
		const template = fake.state.templates.get(c.req.param('id'));
		if (template === undefined) {
			return c.json({ error: 'there is no such template' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { layerId } = body.value;
		if (typeof layerId !== 'string' || layerId === '') {
			return refused('`layerId` is not a string').response;
		}
		const layers = template.template.layers as unknown[] | undefined;
		if (!layers?.some((each) => isRecord(each) && each.id === layerId)) {
			return c.json(
				{ error: 'the template has no such layer' },
				NOT_FOUND_STATUS,
			);
		}
		const document = template.template as unknown as Record<string, unknown>;
		document.layers = without(document.layers, layerId);
		document.groups = without(document.groups, layerId);
		const doc = template.designDoc;
		doc.layers = without(doc.layers, layerId);
		doc.groups = without(doc.groups, layerId);
		const schema = template.formSchema as { steps?: unknown[] };
		if (Array.isArray(schema.steps)) {
			schema.steps = without(schema.steps, layerId).map((step, index) =>
				isRecord(step) ? { ...step, index } : step,
			);
		}
		return c.json({ layerId });
	});
}
