// `POST /__fake/templates/:id/pixel`: makes a template version offer Pixel in
// the designer, as a merchant turns it on in the studio, so a test can walk
// the SDK's control. Each request is then answered by writing `value` into
// the field, or with `{addsLayer: 'text'}` by adding a text layer of the
// customer's own, which the version then allows; faults make it slow or fail.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';
import type { TemplateVersion } from './state';

const NOT_FOUND_STATUS = 404;

type AddedTextPreset = NonNullable<
	NonNullable<TemplateVersion['template']['customerLayers']>['text']
>['layer'];

/** A layer's fields a preset never holds: the added layer gets its own. */
const PRESET_OMITTED_FIELDS = [
	'id',
	'name',
	'z',
	'anchorTo',
	'locked',
	'print',
	'deletable',
];
/** Room for each request of a test to add one. */
const ADDED_TEXT_MAX = 5;

export function registerDesignerPixelControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/templates/:id/pixel`, async (c) => {
		const template = fake.state.templates.get(c.req.param('id'));
		if (template === undefined) {
			return c.json({ error: 'there is no such template' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		if (body.value.addsLayer === 'text') {
			const layer = template.template.layers.find((l) => l.type === 'text');
			if (layer === undefined) {
				return refused('the template holds no text layer').response;
			}
			template.template.customerLayers = {
				text: {
					max: ADDED_TEXT_MAX,
					layer: Object.fromEntries(
						Object.entries(layer).filter(
							([field]) => !PRESET_OMITTED_FIELDS.includes(field),
						),
					) as AddedTextPreset,
				},
			};
			template.designerPixel = { addsLayer: 'text' };
			return c.json(template.designerPixel);
		}
		const { layerId, prop, value } = body.value;
		if (
			typeof layerId !== 'string' ||
			typeof prop !== 'string' ||
			typeof value !== 'string'
		) {
			return refused('`layerId`, `prop` and `value` are not strings').response;
		}
		template.designerPixel = { layerId, prop, value };
		return c.json(template.designerPixel);
	});
}
