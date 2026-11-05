// Template reads: the published document the SDK mounts, and the print-size
// constraints a store and the SDK read for a template on a placement.
import { reaches } from '../auth';
import { fieldKeysOf, offersProperties } from '../field-keys';
import { isHostedLocale, type TemplateVersion } from '../state';
import { designTemplateId, findDesign } from './designs-store';
import { imageUrl } from './images';
import { defaultPrintSize, resolvePlacement, sizeFits } from './placements';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

/** The caller's tenant's template `id`, or undefined when another tenant
 *  owns it, there is none, or a share link does not reach it. */
export function visibleTemplate(
	context: HandlerContext,
	id: string,
): TemplateVersion | undefined {
	const caller = callerOf(context);
	const template = context.state.templates.get(id);
	if (
		template === undefined ||
		template.tenantId !== caller.tenantId ||
		!reaches(caller, { kind: 'template', id })
	) {
		return undefined;
	}
	return template;
}

/** The template version `id` a correction link's design was made from, when
 *  the query names the blank and placement the design was fitted to: the box
 *  the page correcting it draws the design in. Undefined otherwise. */
function correctedTemplate(
	context: HandlerContext,
	id: string,
): TemplateVersion | undefined {
	const caller = callerOf(context);
	const subject = caller.link?.subject;
	if (subject?.kind !== 'design') {
		return undefined;
	}
	const design = findDesign(context.state, subject.id, caller.storeId);
	const { blankId, placementId } = design?.proof ?? {};
	if (
		design === undefined ||
		blankId === null ||
		designTemplateId(design) !== id ||
		context.query.get('blankId') !== blankId ||
		context.query.get('placementId') !== placementId
	) {
		return undefined;
	}
	return context.state.templates.get(id);
}

/** `baseId@version`, the id a template version is read and opened by. */
export function versionedId(template: TemplateVersion): string {
	return `${template.baseId}@${template.version}`;
}

/** The link a template version's preview opens at. */
export function templatePreviewUrl(
	context: HandlerContext,
	template: TemplateVersion,
	showcase?: string,
): string {
	return imageUrl(context.publicOrigin, {
		kind: 'preview',
		templateId: versionedId(template),
		...(showcase === undefined ? {} : { showcase }),
	});
}

/** The bundled output profile a placement's colours are drawn through on screen. */
const CMYK_PREVIEW_PROFILE = 'ISOcoated_v2_eci';

/** `GET /v1/templates/{id}` answers a missing template with a bare 404. */
const NOT_FOUND: HandlerResult = { status: 404 };

/** `formSchema` with each step naming the layers its customer may delete:
 *  none, for a fixture exported before templates offered Delete. */
function withDeletableLayers(formSchema: unknown): unknown {
	const schema = formSchema as { steps?: Record<string, unknown>[] };
	if (!Array.isArray(schema?.steps)) {
		return formSchema;
	}
	return {
		...schema,
		steps: schema.steps.map((step) => ({
			...step,
			deletableLayerIds: step.deletableLayerIds ?? [],
		})),
	};
}

/** What `GET /v1/templates/{id}` answers about `template`; the designer
 *  route answers a design's template version the same way. */
export function templateRead(
	context: HandlerContext,
	template: TemplateVersion,
): Record<string, unknown> {
	const tenant = context.state.tenants.get(template.tenantId);
	if (tenant === undefined) {
		throw new Error(`Template ${template.baseId} has no tenant.`);
	}
	const lang = context.query.get('lang');
	return {
		template: template.template,
		formSchema: withDeletableLayers(template.formSchema),
		printMargins: tenant.printMargins,
		printFloorDpi: tenant.minPrintDpi,
		fieldKeys: fieldKeysOf(template.template),
		offersProperties: offersProperties(template.template),
		backgroundSeeThrough: template.backgroundSeeThrough ?? null,
		previewUrl: templatePreviewUrl(context, template),
		showcases: template.showcases.map((s) => ({
			...s,
			previewUrl: templatePreviewUrl(context, template, s.id),
		})),
		status: template.status,
		// The fake's workspaces have no plan that hides the mark.
		poweredBy: true,
		// Off until `POST /__fake/templates/:id/pixel` turns it on.
		pixel: template.designerPixel !== undefined,
		// The SDK speaks it when its `locale` names none it has.
		workspaceLanguage: tenant.workspace.defaultLanguage,
		// The fake holds no translated templates: every language reads the original.
		language: isHostedLocale(lang) ? lang : tenant.workspace.defaultLanguage,
	};
}

export const readTemplate: Handler = (context) => {
	const template = visibleTemplate(context, context.params.id ?? '');
	if (template === undefined) {
		return NOT_FOUND;
	}
	return { status: 200, body: templateRead(context, template) };
};

export const readTemplateConstraints: Handler = (context) => {
	const id = context.params.id ?? '';
	const template =
		visibleTemplate(context, id) ?? correctedTemplate(context, id);
	if (template === undefined) {
		return refusal(404, 'not_found');
	}
	const { ratio, ratioTolerance, fit } = template.template;
	const body: Record<string, unknown> = {
		templateId: id,
		ratio,
		ratioTolerance,
		fit,
	};
	const blankId = context.query.get('blankId');
	const placementId = context.query.get('placementId');
	if ((blankId === null) !== (placementId === null)) {
		// Both or neither.
		return refusal(422, 'invalid_query');
	}
	if (blankId !== null && placementId !== null) {
		const placement = resolvePlacement(
			context.state,
			template.tenantId,
			blankId,
			placementId,
		);
		if (placement === undefined) {
			return refusal(422, 'unknown_placement');
		}
		const printed = defaultPrintSize(ratio, placement);
		body.placement = {
			blankId,
			placementId,
			maxWidthMm: placement.maxWidthMm,
			maxHeightMm: placement.maxHeightMm,
			defaultWidthMm: placement.defaultWidthMm,
			defaultHeightMm: placement.defaultHeightMm,
			// A print is placed inside the fake's placements.
			coverage: 'within',
			sizes: sizeFits(),
			printWidthMm: printed.widthMm,
			printHeightMm: printed.heightMm,
			bleedMm: placement.bleedMm,
			safeAreaMm: placement.safeAreaMm,
			// The fake keeps no workspace output profile: the bundled one draws.
			cmykPreviewProfile: CMYK_PREVIEW_PROFILE,
		};
	}
	return { status: 200, body };
};
