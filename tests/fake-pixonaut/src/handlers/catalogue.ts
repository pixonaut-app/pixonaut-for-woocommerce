// The catalogue a store browses with its integration key: the tenant's live
// templates, its blanks, the templates that fit a blank's placement, the
// print-size verdict for a template, and the printshops a blank prints at,
// as Pixonaut answers them. A correction link reads the blank its design was
// made on, as the designer correcting it draws the design there.
import { fieldKeysOf, offersProperties } from '../field-keys';
import {
	type Blank,
	CUSTOM_ADAPTER,
	type LowResolutionSetup,
	type Placement,
	type State,
	type TemplateVersion,
	type ValueAppearance,
	type View,
} from '../state';
import { AS_WRITTEN, type BlankNamer, namerFor } from './blank-names';
import { findDesign } from './designs-store';
import { imageUrl } from './images';
import { type Box, fittedPrintSize, sizeFits, usable } from './placements';
import { templatePreviewUrl, versionedId, visibleTemplate } from './templates';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

/** A list's page when the request names none (Pixonaut's paging bounds). */
const DEFAULT_PAGE = 1;
/** A list's page size when the request names none. */
const DEFAULT_PAGE_SIZE = 50;

/** How close two ratios must be to count as the same shape: Pixonaut's
 *  `RATIO_EQUALITY_EPSILON`, the float slack a tolerance of `0` compares
 *  with, so a template never refuses its own declared size. */
const RATIO_EQUALITY_EPSILON = 1e-9;

/** The one template status a key may list. */
const LIVE = 'live';
/** The fitting-templates filter that keeps every status. */
const ALL_STATUSES = 'all';

const NOT_FOUND: HandlerResult = refusal(404, 'not_found');

/** Pixonaut's wording when a body names one of `blankId` and `placementId`
 *  without the other. */
const BOTH_OR_NEITHER: HandlerResult = {
	status: 422,
	body: {
		error: 'invalid_body',
		reason: 'placementId: blankId and placementId must be given together',
	},
};

type Ratio = { w: number; h: number };
type Artwork = { ratio: Ratio; ratioTolerance: number };
type IllegalReason = 'too_large' | 'wrong_ratio';
type Verdict = { legal: true } | { legal: false; reason: IllegalReason };

/** Whether `size` strays from the declared ratio by no more than the
 *  tolerance, relative to the declared ratio and inclusive at the edge
 *  (Pixonaut's `sizeMatchesRatioValue`). */
function matchesRatio(artwork: Artwork, size: Box): boolean {
	const declared = artwork.ratio.w / artwork.ratio.h;
	const requested = size.widthMm / size.heightMm;
	if (artwork.ratioTolerance === 0) {
		return Math.abs(requested - declared) <= RATIO_EQUALITY_EPSILON;
	}
	return Math.abs(requested - declared) / declared <= artwork.ratioTolerance;
}

/** Why `size` may not be printed, or null when it may (Pixonaut's
 *  `printSizeIllegalReason`): beyond the placement's maximum box is
 *  `too_large`, which wins over a ratio outside the tolerance. */
function illegalReason(
	artwork: Artwork,
	size: Box,
	placement?: Placement,
): IllegalReason | null {
	if (
		placement !== undefined &&
		(size.widthMm > placement.maxWidthMm ||
			size.heightMm > placement.maxHeightMm)
	) {
		return 'too_large';
	}
	return matchesRatio(artwork, size) ? null : 'wrong_ratio';
}

function verdictOf(
	artwork: Artwork,
	size: Box,
	placement?: Placement,
): Verdict {
	const reason = illegalReason(artwork, size, placement);
	return reason === null ? { legal: true } : { legal: false, reason };
}

type Paging = { page: number; pageSize: number };

/** The page a list request asks for; the reference has bounded both. */
function pagingOf(query: URLSearchParams): Paging {
	const page = query.get('page');
	const pageSize = query.get('pageSize');
	return {
		page: page === null ? DEFAULT_PAGE : Number(page),
		pageSize: pageSize === null ? DEFAULT_PAGE_SIZE : Number(pageSize),
	};
}

function pageOf<T>(rows: T[], { page, pageSize }: Paging): T[] {
	const start = (page - 1) * pageSize;
	return rows.slice(start, start + pageSize);
}

/** Whether `name` holds `q`, case aside; no `q` keeps every name. */
export function holds(name: string, q: string | null): boolean {
	return q === null || name.toLowerCase().includes(q.toLowerCase());
}

/** By name, then id: the order every Pixonaut list is in. */
function byName<T>(key: (row: T) => { name: string; id: string }) {
	return (a: T, b: T): number => {
		const [x, y] = [key(a), key(b)];
		if (x.name !== y.name) {
			return x.name < y.name ? -1 : 1;
		}
		return x.id < y.id ? -1 : x.id > y.id ? 1 : 0;
	};
}

/** The tenant's templates, one per base id: its latest version, whose
 *  status is the template's and whose document holds its name and ratio. */
function templateBases(state: State, tenantId: string): TemplateVersion[] {
	const latest = new Map<string, TemplateVersion>();
	for (const version of state.templates.values()) {
		const seen = latest.get(version.baseId);
		if (
			version.tenantId === tenantId &&
			(seen === undefined || version.version > seen.version)
		) {
			latest.set(version.baseId, version);
		}
	}
	return [...latest.values()].sort(
		byName((t) => ({ name: t.template.name, id: t.baseId })),
	);
}

function artworkOf(version: TemplateVersion): Artwork {
	const { ratio, ratioTolerance } = version.template;
	return { ratio, ratioTolerance };
}

/** `GET /v1/templates`, as a key reads it: a page of live templates. */
export const listTemplates: Handler = (context) => {
	const { query } = context;
	const status = query.get('status');
	// A key may ask for live templates alone; any other status, known or
	// not, is refused alike.
	if (status !== null && status !== LIVE) {
		return refusal(400, 'invalid_status');
	}
	const paging = pagingOf(query);
	const q = query.get('q');
	const matching = templateBases(
		context.state,
		callerOf(context).tenantId,
	).filter((t) => t.status === LIVE && holds(t.template.name, q));
	const items = pageOf(matching, paging).map((t) => ({
		id: versionedId(t),
		baseId: t.baseId,
		name: t.template.name,
		latestVersion: t.version,
		...artworkOf(t),
		fieldKeys: fieldKeysOf(t.template),
		offersProperties: offersProperties(t.template),
		backgroundSeeThrough: t.backgroundSeeThrough ?? null,
		previewUrl: templatePreviewUrl(context, t),
	}));
	return { status: 200, body: { items, total: matching.length, ...paging } };
};

/** A value the blank or placement sets itself, else none: the fake has no
 *  categories or workspace defaults to inherit from. */
function own<T>(value: T | null | undefined) {
	return value === undefined || value === null
		? { value: null, source: 'none' }
		: { value, source: 'own' };
}

function placementBody(
	p: Placement,
	names: BlankNamer,
): Record<string, unknown> {
	return {
		id: p.id,
		name: p.name,
		shopName: p.shopName,
		maxWidthMm: p.maxWidthMm,
		maxHeightMm: p.maxHeightMm,
		defaultWidthMm: p.defaultWidthMm,
		defaultHeightMm: p.defaultHeightMm,
		bleedMm: p.bleedMm,
		safeAreaMm: p.safeAreaMm,
		...(p.printMethod === undefined ? {} : { printMethod: p.printMethod }),
		...(p.dpi === undefined ? {} : { dpi: p.dpi }),
		...(p.areas === undefined
			? {}
			: { areas: p.areas.map((area) => ({ ...area, row: { ...area.row } })) }),
		views: p.views.map((v) => ({
			...viewFrame(v, names),
			photos: v.photos.map((photo) => ({
				id: photo.id,
				assetId: photo.assetId,
				variantRefs: [...photo.variantRefs],
			})),
		})),
	};
}

/** A view's own fields, which both blank answers carry. */
function viewFrame(v: View, names: BlankNamer): Record<string, unknown> {
	return {
		id: v.id,
		label: names.view(v.id, v.label),
		photoSizePx: v.photoSizePx === null ? null : { ...v.photoSizePx },
		placementRectPx:
			v.placementRectPx === null ? null : { ...v.placementRectPx },
	};
}

/** Each inheritable field with where its value came from (Pixonaut's
 *  `resolveBlank`). A blank's printshop is always its own. */
function resolvedOf(blank: Blank): Record<string, unknown> {
	return {
		defaultPrintshopId: own(blank.defaultPrintshopId),
		machineProfileId: own(blank.machineProfileId),
		placements: blank.placements.map((p) => ({
			id: p.id,
			printedAs: p.shopName ?? p.name,
			printedAsLinked: p.shopName === null,
			printMethod: own(p.printMethod),
			dpi: own(p.dpi),
			bleedMm: own(p.bleedMm),
			safeAreaMm: own(p.safeAreaMm),
			mockups: p.views
				.flatMap((v) => v.photos)
				.map((m) => ({
					id: m.id,
					shadingStrength: own(null),
					warp: own(null),
					displacementStrength: own(null),
					textureStrength: own(null),
				})),
		})),
	};
}

/** The flat colour each fixture colour value is drawn in; any other value
 *  has no appearance and is drawn as its name. */
const COLOUR_FILLS: Record<string, string> = {
	White: '#ffffff',
	Black: '#000000',
	Red: '#cc0000',
};

type Tone = 'light' | 'dark';

/** The fixture colour values whose garment is dark; every other value is
 *  light, as Pixonaut answers a value with no tone of its own. */
const DARK_VALUES = new Set(['Black']);

/** Each option axis with its values, in the order the variants name them,
 *  and how each value looks, named by `names`. */
function optionsOf(
	blank: Blank,
	names: BlankNamer,
): {
	name: string;
	values: string[];
	appearances: Record<string, ValueAppearance>;
	tones: Record<string, Tone>;
}[] {
	return blank.axes.map((axis) => {
		const values = [
			...new Set(
				blank.variants.flatMap((v) => {
					const value = v.options[axis];
					return value === undefined ? [] : [value];
				}),
			),
		];
		const appearances = Object.fromEntries(
			values.map((value): [string, ValueAppearance] => {
				const set = blank.valueLooks?.[axis];
				const fill = COLOUR_FILLS[value];
				return [
					names.value(axis, value),
					set !== undefined && value in set
						? (set[value] ?? null)
						: fill === undefined
							? null
							: { kind: 'fill', colours: [fill] },
				];
			}),
		);
		const tones = Object.fromEntries(
			values.map((value): [string, Tone] => [
				names.value(axis, value),
				DARK_VALUES.has(value) ? 'dark' : 'light',
			]),
		);
		return {
			name: names.axis(axis),
			values: values.map((value) => names.value(axis, value)),
			appearances,
			tones,
		};
	});
}

/** The option a blank's photos are painted in: none, as the fake holds only
 *  a blank's own photos, which show as photographed. */
const PAINT_BY = null;

/** A blank as the authoring reads answer it: every name as written. */
export function blankBody(blank: Blank): Record<string, unknown> {
	return {
		id: blank.id,
		name: blank.name,
		categoryId: blank.categoryId,
		placements: blank.placements.map((p) => placementBody(p, AS_WRITTEN)),
		axes: [...blank.axes],
		copiedFrom: null,
		defaultPrintshopId: blank.defaultPrintshopId,
		// The fake holds no stock item.
		stockItemKey: null,
		partnerLocked: blank.partnerLocked,
		machineProfileId: blank.machineProfileId,
		resolved: resolvedOf(blank),
		createdAt: blank.createdAt,
		updatedAt: blank.updatedAt,
		// The fake keeps no member who deleted it.
		deleted:
			blank.deletedAt === null ? null : { at: blank.deletedAt, by: null },
		options: optionsOf(blank, AS_WRITTEN),
		optionsConfirmedAt: null,
		// The fake keeps one photo set for every variant.
		photosVaryBy: [],
		paintBy: PAINT_BY,
		areasVaryBy: [...(blank.areasVaryBy ?? [])],
		referenceRow: null,
		// The fake's blanks are one product for every variant, measured nowhere.
		modelVariesBy: [],
		productModels: [],
		status: blank.status ?? 'ready',
		importFailure: null,
		// The fake draws no blank with AI.
		generation: null,
	};
}

/** The caller's tenant's blank `id`, or undefined for another tenant's. */
export function tenantBlank(
	context: HandlerContext,
	id: string,
): Blank | undefined {
	const blank = context.state.blanks.get(id);
	return blank?.tenantId === callerOf(context).tenantId ? blank : undefined;
}

/** Whether the caller may read the blank `id` and, when named, its placement
 *  `placementId`: a correction link reaches only the pair its design was made
 *  on, and Pixonaut answers any other as not found. */
function linkReaches(
	context: HandlerContext,
	id: string,
	placementId?: string,
): boolean {
	const caller = callerOf(context);
	const subject = caller.link?.subject;
	if (subject?.kind !== 'design') {
		return true;
	}
	const design = findDesign(context.state, subject.id, caller.storeId);
	return (
		design !== undefined &&
		design.proof.blankId === id &&
		(placementId === undefined || design.proof.placementId === placementId)
	);
}

/** `GET /v1/blanks`: a page of the tenant's blanks by name. */
export const listBlanks: Handler = (context) => {
	const { query } = context;
	const paging = pagingOf(query);
	const q = query.get('q');
	const withPlacements = query.get('hasPlacements') === 'true';
	const { tenantId } = callerOf(context);
	const matching = [...context.state.blanks.values()]
		.filter(
			(b) =>
				b.tenantId === tenantId &&
				b.deletedAt === null &&
				holds(b.name, q) &&
				(!withPlacements || b.placements.length > 0),
		)
		.sort(byName((b) => b));
	return {
		status: 200,
		body: {
			blanks: pageOf(matching, paging).map((b) => ({
				...blankBody(b),
				thumbnailUrl: firstPhotoUrl(context, b),
			})),
			total: matching.length,
			...paging,
			// Ranking by a template's orders needs order history the fake
			// does not keep, so the list is by name alone.
			orderedFirst: false,
		},
	};
};

/** The link to a blank's first view photo, by placement then view order, as
 *  the blank list answers it; null for a blank with no photo. */
function firstPhotoUrl(context: HandlerContext, blank: Blank): string | null {
	for (const placement of blank.placements) {
		for (const view of placement.views) {
			const photo = view.photos[0];
			if (photo !== undefined) {
				return imageUrl(context.publicOrigin, {
					kind: 'thumbnail',
					blankId: blank.id,
					placementId: placement.id,
					mockupId: photo.id,
				});
			}
		}
	}
	return null;
}

/** `GET /v1/blanks/{id}`. */
export const readBlank: Handler = (context) => {
	const blank = tenantBlank(context, context.params.id ?? '');
	return blank === undefined
		? NOT_FOUND
		: { status: 200, body: blankBody(blank) };
};

/** `GET /v1/blanks/{id}/mockups`: each usable placement's resolved bounds
 *  and its views, each photo with a short-lived thumbnail link and the
 *  `mount` a storefront passes to the designer; null while the photo's
 *  upload has not finished or its view has no print rect. The fake keeps no tenant default margins, so a placement's own
 *  bleed and safe area are the resolved ones. */
export const listBlankMockups: Handler = (context) => {
	const id = context.params.id ?? '';
	const blank = tenantBlank(context, id);
	if (blank === undefined || !linkReaches(context, id)) {
		return NOT_FOUND;
	}
	const names = namerFor(context, blank);
	const placements = blank.placements.filter(usable).map((p) => ({
		id: p.id,
		name: p.name,
		maxWidthMm: p.maxWidthMm,
		maxHeightMm: p.maxHeightMm,
		defaultWidthMm: p.defaultWidthMm,
		defaultHeightMm: p.defaultHeightMm,
		bleedMm: p.bleedMm,
		safeAreaMm: p.safeAreaMm,
		...(p.printMethod === undefined ? {} : { printMethod: p.printMethod }),
		// A print is placed inside the area.
		areas: (p.areas ?? []).map((area) => ({
			row: { ...area.row },
			widthMm: area.widthMm,
			heightMm: area.heightMm,
			usualWidthMm: area.usualWidthMm,
			usualHeightMm: area.usualHeightMm,
		})),
		coverage: 'within',
		views: p.views.map((v) => ({
			...viewFrame(v, names),
			photos: v.photos.map((m) => {
				const photo = { blankId: blank.id, placementId: p.id, mockupId: m.id };
				const { photoSizePx: size, placementRectPx: rect } = v;
				// A photo is measured once its upload has finished.
				const measured = m.uploaded && size !== null ? size : null;
				return {
					id: m.id,
					width: measured?.widthPx ?? null,
					height: measured?.heightPx ?? null,
					variantRefs: [...m.variantRefs],
					thumbnailUrl: imageUrl(context.publicOrigin, {
						kind: 'thumbnail',
						...photo,
					}),
					// Pixonaut mounts a photo once it is uploaded and its view
					// has a size and a print rect.
					mount:
						!m.uploaded || size === null || rect === null
							? null
							: {
									url: imageUrl(context.publicOrigin, {
										kind: 'mount',
										...photo,
									}),
									placementRectPx: { ...rect },
									photoSizePx: { w: size.widthPx, h: size.heightPx },
								},
				};
			}),
		})),
	}));
	// No view of the fake's shows two placements on one photo.
	return { status: 200, body: { placements, views: [] } };
};

/** Whether `view` has a photo of the variant `ref`: a photo naming no
 *  variant, as on a blank with no Color axis, shows every one. */
function showsVariant(view: View, ref: string): boolean {
	return view.photos.some(
		(photo) =>
			photo.variantRefs.length === 0 || photo.variantRefs.includes(ref),
	);
}

/** `GET /v1/blanks/{id}/variants`: every variant, retired ones included,
 *  with the views it has a photo in. */
export const listBlankVariants: Handler = (context) => {
	const id = context.params.id ?? '';
	const blank = tenantBlank(context, id);
	if (blank === undefined || !linkReaches(context, id)) {
		return NOT_FOUND;
	}
	const names = namerFor(context, blank);
	const variants = blank.variants.map((variant) => ({
		ref: variant.ref,
		options: Object.fromEntries(
			Object.entries(variant.options).map(([axis, value]) => [
				names.axis(axis),
				names.value(axis, value),
			]),
		),
		retired: variant.retired === null ? null : { ...variant.retired },
		views: blank.placements.flatMap((p) =>
			p.views
				.filter((v) => showsVariant(v, variant.ref))
				.map((v) => ({
					placementId: p.id,
					viewId: v.id,
					label: names.view(v.id, v.label),
				})),
		),
	}));
	return {
		status: 200,
		body: {
			axes: blank.axes.map((axis) => names.axis(axis)),
			options: optionsOf(blank, names),
			areasVaryBy: (blank.areasVaryBy ?? []).map((axis) => names.axis(axis)),
			paintBy: PAINT_BY,
			variants,
		},
	};
};

/** `GET /v1/blanks/{id}/placements/{placementId}/preview-tables`: refused
 *  with `no_preview_tables`, as every placement of the fake's draws its
 *  colours through a bundled profile; not found for a placement the caller
 *  cannot read. */
export const readPreviewTables: Handler = (context) => {
	const { id = '', placementId = '' } = context.params;
	const placement = tenantBlank(context, id)?.placements.find(
		(p) => p.id === placementId,
	);
	return placement === undefined || !linkReaches(context, id, placementId)
		? NOT_FOUND
		: refusal(404, 'no_preview_tables');
};

/** `GET /v1/blanks/{id}/placements/{placementId}/fitting-templates`: each
 *  template with its verdict at the size it prints at on the placement. */
export const listFittingTemplates: Handler = (context) => {
	const { query, params } = context;
	const blank = tenantBlank(context, params.id ?? '');
	const placement = blank?.placements.find((p) => p.id === params.placementId);
	// A placement the rule cannot judge has no answer for any template.
	if (placement === undefined || !usable(placement)) {
		return NOT_FOUND;
	}
	const status = query.get('status') ?? LIVE;
	const q = query.get('q');
	const paging = pagingOf(query);
	const matching = templateBases(
		context.state,
		callerOf(context).tenantId,
	).filter(
		(t) =>
			(status === ALL_STATUSES || t.status === status) &&
			holds(t.template.name, q),
	);
	const defaultBox = {
		widthMm: placement.defaultWidthMm,
		heightMm: placement.defaultHeightMm,
	};
	const items = pageOf(matching, paging).map((t) => {
		const artwork = artworkOf(t);
		const size = fittedPrintSize(artwork.ratio, defaultBox);
		return {
			templateId: versionedId(t),
			name: t.template.name,
			status: t.status,
			...artwork,
			printWidthMm: size.widthMm,
			printHeightMm: size.heightMm,
			verdict: verdictOf(artwork, size, placement),
			lowResolution: lowResolutionAt(size, t.lowResolution),
			sizes: sizeFits(),
			// Every template the fake keeps is a published version.
			previewUrl: templatePreviewUrl(context, t),
		};
	});
	return { status: 200, body: { items, total: matching.length, ...paging } };
};

/** Millimetres in an inch, which a resolution in dots per inch is measured along. */
const MM_PER_INCH = 25.4;

/** What an image is called when a test gave it no label: Pixonaut's word
 *  for a picture the merchant named nowhere. */
const UNNAMED_IMAGE_LABEL = 'Picture';

/** The images `size` prints below their recommended resolution, each
 *  judged along its width alone, which the fake's images fill. */
function lowResolutionAt(size: Box, setup: LowResolutionSetup | undefined) {
	return (setup?.images ?? [])
		.map((image) => ({
			layerId: image.layerId,
			assetId: image.assetId,
			effectiveDpi: image.widthPx / (size.widthMm / MM_PER_INCH),
			recommendedDpi: setup?.recommendedDpi ?? 0,
			label: image.label ?? UNNAMED_IMAGE_LABEL,
		}))
		.filter((image) => image.effectiveDpi < image.recommendedDpi);
}

/** A verdict with the images the size prints below their recommended
 *  resolution, which Pixonaut names on every verdict; under `refuse` a
 *  size that is otherwise legal is refused for them. */
function withLowResolution(
	verdict: Verdict,
	size: Box,
	setup: LowResolutionSetup | undefined,
) {
	const lowResolution = lowResolutionAt(size, setup);
	if (verdict.legal && setup?.action === 'refuse' && lowResolution.length > 0) {
		return { legal: false, reason: 'low_resolution', lowResolution };
	}
	return { ...verdict, lowResolution };
}

type NamedPlacement = { blankId?: string; placementId?: string };
type PrintSizeBody = NamedPlacement & (Box | { sizes: Box[] });

/** `POST /v1/templates/{id}/print-size`: one size's verdict, or one per
 *  size in `sizes`, in the order asked. */
export const judgePrintSize: Handler = (context) => {
	const template = visibleTemplate(context, context.params.id ?? '');
	if (template === undefined) {
		return NOT_FOUND;
	}
	// The reference has checked the body's shape.
	const body = context.body as PrintSizeBody;
	const { blankId, placementId } = body;
	if ((blankId === undefined) !== (placementId === undefined)) {
		return BOTH_OR_NEITHER;
	}
	let placement: Placement | undefined;
	if (blankId !== undefined) {
		const blank = context.state.blanks.get(blankId);
		placement =
			blank?.tenantId === template.tenantId
				? blank.placements.find((p) => p.id === placementId)
				: undefined;
		// Refused rather than judged without the placement's bounds.
		if (placement === undefined) {
			return refusal(422, 'unknown_placement');
		}
	}
	const artwork = artworkOf(template);
	const judge = (size: Box) =>
		withLowResolution(
			verdictOf(artwork, size, placement),
			size,
			template.lowResolution,
		);
	if ('sizes' in body) {
		return { status: 200, body: { verdicts: body.sizes.map(judge) } };
	}
	return { status: 200, body: judge(body) };
};

/** `GET /v1/printshop-connections`: the tenant's printshops, active first,
 *  in the shape a store's key reads them. */
export const listPrintshopConnections: Handler = (context) => {
	const q = context.query.get('q');
	const { tenantId } = callerOf(context);
	const connections = [...context.state.printshops.values()]
		.filter((p) => p.tenantId === tenantId && holds(p.name, q))
		.sort(
			(a, b) =>
				Number(b.active) - Number(a.active) || byName((p: typeof a) => p)(a, b),
		)
		.map((p) => ({
			id: p.id,
			name: p.name,
			adapter: p.adapter,
			active: p.active,
			isDefault: p.isDefault,
			// A print partner's integration has a catalogue; the merchant's own printshop has none.
			catalogue: p.adapter !== CUSTOM_ADAPTER,
			asksOfDesigns: {
				commitKeys: [],
				needsBlank: p.adapter !== CUSTOM_ADAPTER,
			},
		}));
	return { status: 200, body: { connections } };
};
