// `GET /t/{id}/mockup.png` and `GET /t/{id}/preview.png`: a published
// template drawn on a blank's photo, or alone, with its defaults or one of its
// showcase sets. The fake checks every id the request names as Pixonaut does,
// and draws one white picture for all.
import type { Photo, TemplateVersion } from '../state';
import { tenantBlank } from './catalogue';
import { visibleTemplate } from './templates';
import { type Handler, refusal } from './types';

/** An 8×8 white PNG: every picture the fake draws. */
export const WHITE_PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAD0lEQVR4nGP4jwMwDC0JALoev0Ewkwr8AAAAAElFTkSuQmCC',
	'base64',
);

/** A published version never changes, so neither does its picture. */
const IMMUTABLE = 'public, max-age=31536000, immutable';

/** The `size` query: a whole number of pixels. */
const SIZE = /^\d+$/;

/** Pixonaut answers every id it cannot find with its router's plain-text
 *  404, naming none. Only a showcase set the template does not have carries
 *  a code. */
const NOT_FOUND = {
	status: 404,
	body: '404 Not Found',
	contentType: 'text/plain; charset=UTF-8',
} as const;

/** The picture, unless `showcase` names a set the template does not have. */
function drawn(template: TemplateVersion, showcase: string | null) {
	return showcase === null || template.showcases.some((s) => s.id === showcase)
		? picture()
		: refusal(404, 'showcase_not_found');
}

function picture() {
	return {
		status: 200,
		body: new Uint8Array(WHITE_PNG),
		contentType: 'image/png',
		headers: { 'cache-control': IMMUTABLE },
	};
}

/** Whether a photo shows the variant: one on a blank with no Color axis
 *  shows every variant. */
function shows(photo: Photo, ref: string): boolean {
	return photo.variantRefs.length === 0 || photo.variantRefs.includes(ref);
}

export const readTemplateMockup: Handler = (context) => {
	const { query } = context;
	const size = query.get('size');
	const mockupId = query.get('mockupId');
	const viewId = query.get('viewId');
	const ref = query.get('variant');
	if (
		(size !== null && !SIZE.test(size)) ||
		(mockupId === null) === (viewId === null) ||
		(ref !== null && viewId === null)
	) {
		return refusal(422, 'invalid_query');
	}
	if (query.get('bleed') !== null) {
		return refusal(400, 'bleed_not_compositable');
	}
	const template = visibleTemplate(context, context.params.id ?? '');
	const blank = tenantBlank(context, query.get('blankId') ?? '');
	const placement = blank?.placements.find(
		(p) => p.id === query.get('placementId'),
	);
	if (
		template === undefined ||
		blank === undefined ||
		placement === undefined
	) {
		return NOT_FOUND;
	}
	if (mockupId !== null) {
		return placement.views.some((v) => v.photos.some((m) => m.id === mockupId))
			? drawn(template, query.get('showcase'))
			: NOT_FOUND;
	}
	const view = placement.views.find((v) => v.id === viewId);
	if (view === undefined || view.photos.length === 0) {
		return NOT_FOUND;
	}
	if (ref === null) {
		return drawn(template, query.get('showcase'));
	}
	const variant = blank.variants.find((v) => v.ref === ref);
	if (variant === undefined) {
		return {
			status: 422,
			body: {
				error: 'unknown_variant',
				reason: `This blank has no variant ${ref}.`,
				ref,
			},
		};
	}
	if (variant.retired !== null) {
		return {
			status: 422,
			body: {
				error: 'variant_retired',
				reason: `Variant ${ref} is retired.`,
				variant: ref,
			},
		};
	}
	if (!view.photos.some((m) => shows(m, ref))) {
		return {
			status: 422,
			body: {
				error: 'variant_has_no_photo',
				reason: `${view.label} has no photo of variant ${ref}.`,
				viewId: view.id,
				view: view.label,
				variant: ref,
			},
		};
	}
	return drawn(template, query.get('showcase'));
};

export const readTemplatePreview: Handler = (context) => {
	const size = context.query.get('size');
	if (size !== null && !SIZE.test(size)) {
		return refusal(422, 'invalid_query');
	}
	const template = visibleTemplate(context, context.params.id ?? '');
	return template === undefined
		? NOT_FOUND
		: drawn(template, context.query.get('showcase'));
};
