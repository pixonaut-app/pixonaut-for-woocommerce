// `POST /v1/pixel/designer`: Pixel for the customer in the designer, which
// the SDK asks only where the template read offers it (`pixel`). A template
// offers it once `POST /__fake/templates/:id/pixel` says what Pixel does;
// any other request it can read is answered as Pixonaut answers a template
// with Pixel off: `503 pixel_unavailable`, with no reason.
import { sessionOf } from './session';
import { visibleTemplate } from './templates';
import { type Handler, refusal } from './types';

type DesignerPixelRequest = {
	templateId: string;
	prompt: string;
	values?: Record<string, unknown>;
};

/** The key of a design's values listing the kinds of the customer's own
 *  layers, each of which has the id `$added.<its place>`. */
const ADDED_LAYERS_KEY = '$added';

export const askDesignerPixel: Handler = (context) => {
	// Its limits are counted per designer session.
	if (sessionOf(context.headers) === null) {
		return refusal(400, 'no_session');
	}
	const { templateId, values } = context.body as DesignerPixelRequest;
	const template = visibleTemplate(context, templateId);
	if (template === undefined) {
		return refusal(404, 'not_found');
	}
	if (template.designerPixel === undefined) {
		return refusal(503, 'pixel_unavailable');
	}
	if ('addsLayer' in template.designerPixel) {
		const kind = template.designerPixel.addsLayer;
		const added = (values?.[ADDED_LAYERS_KEY] ?? []) as string[];
		return {
			status: 200,
			body: {
				// An empty reply: the layer alone says Pixel did something.
				reply: '',
				changed: [],
				cutOuts: [],
				layers: [
					{
						action: 'added',
						layerId: `${ADDED_LAYERS_KEY}.${added.length}`,
						kind,
					},
				],
				values: { ...values, [ADDED_LAYERS_KEY]: [...added, kind] },
			},
		};
	}
	const { layerId, prop, value } = template.designerPixel;
	const held = values?.[layerId] as Record<string, unknown> | undefined;
	return {
		status: 200,
		body: {
			reply: 'Done.',
			changed: [{ layerId, prop }],
			cutOuts: [],
			layers: [],
			values: {
				...values,
				[layerId]: { ...held, [prop]: value },
			},
		},
	};
};
