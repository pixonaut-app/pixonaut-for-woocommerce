// `POST /__fake/templates/:id/low-resolution`: gives a template version
// pictures a print size can print below their recommended resolution,
// `{images: [{layerId, assetId, widthPx}], recommendedDpi, action}`, `action`
// being what the workspace does with such a size: `warn` names them on a
// legal verdict, `refuse` refuses the size `low_resolution`. `{images: []}`
// makes every image sharp enough again, as an upscale the template now uses
// does. The next reset forgets it.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';
import type { LowResolutionSetup } from './state';

const NOT_FOUND_STATUS = 404;

/** What a workspace recommends when the test names nothing. */
const DEFAULT_RECOMMENDED_DPI = 300;

function isImage(
	value: unknown,
): value is LowResolutionSetup['images'][number] {
	if (typeof value !== 'object' || value === null) {
		return false;
	}
	const { layerId, assetId, widthPx } = value as Record<string, unknown>;
	return (
		typeof layerId === 'string' &&
		typeof assetId === 'string' &&
		typeof widthPx === 'number' &&
		widthPx > 0
	);
}

export function registerTemplateLowResolutionControl(
	app: Hono,
	fake: Fake,
): void {
	app.post(`${PREFIX}/templates/:id/low-resolution`, async (c) => {
		const template = fake.state.templates.get(c.req.param('id'));
		if (template === undefined) {
			return c.json({ error: 'there is no such template' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const {
			images,
			recommendedDpi = DEFAULT_RECOMMENDED_DPI,
			action = 'warn',
		} = body.value;
		if (!Array.isArray(images) || !images.every(isImage)) {
			return refused('`images` is not a list of {layerId, assetId, widthPx}')
				.response;
		}
		if (typeof recommendedDpi !== 'number' || recommendedDpi <= 0) {
			return refused('`recommendedDpi` is not a positive number').response;
		}
		if (action !== 'warn' && action !== 'refuse') {
			return refused('`action` is not warn or refuse').response;
		}
		template.lowResolution = { images, recommendedDpi, action };
		return c.json(template.lowResolution);
	});
}
