// Named fixture sets `/__fake/reset` loads. Template documents come from
// `fixtures/templates/`, and the documents designs made from them store from
// `fixtures/designs/`.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { FIXTURE } from './fixture-ids';
import {
	type Blank,
	type BlankNames,
	CUSTOM_ADAPTER,
	emptyState,
	type Photo,
	type State,
	type TemplateStatus,
	type TemplateVersion,
	templateId,
	type Variant,
	type View,
} from './state';

export const FIXTURES_DIR =
	process.env.PIXONAUT_FAKE_FIXTURES_DIR ??
	join(import.meta.dirname, '..', 'fixtures');

/** Lifetime of the fixture share link; long enough never to lapse in a run. */
const FIXTURE_LINK_LIFETIME_MS = 365 * 24 * 60 * 60 * 1000;

export type FixtureOptions = {
	/** The main store's site, whose origin its publishable key accepts. */
	siteUrl?: string;
	now: number;
};

/** The default when a reset names no site. */
const DEFAULT_SITE_URL = 'http://localhost';

/** The plugin's webhook route, under the store's REST address. */
export const WEBHOOK_REST_ROUTE = 'pixonaut/v1/webhook';

/** Where Pixonaut registers a store's webhook endpoint when the store names
 *  no REST address of its own: under `<site_url>/wp-json/`. */
export function defaultWebhookUrl(siteUrl: string): string {
	return `${siteUrl.replace(/\/+$/, '')}/wp-json/${WEBHOOK_REST_ROUTE}`;
}

type Exported = Pick<TemplateVersion, 'template' | 'formSchema' | 'designDoc'>;

/** What a republished version changes of its source document. */
type DocumentChanges = Partial<
	Pick<TemplateVersion['template'], 'name' | 'ratio' | 'ratioTolerance'>
>;

function readFixture(dir: string, name: string): unknown {
	return JSON.parse(
		readFileSync(join(FIXTURES_DIR, dir, `${name}.json`), 'utf8'),
	);
}

function exportedTemplate(name: string): Exported {
	return {
		...(readFixture('templates', name) as Omit<Exported, 'designDoc'>),
		designDoc: readFixture('designs', name) as Exported['designDoc'],
	};
}

/** `source` republished as `baseId@version` of `tenantId`. Each version gets
 *  its own copy, so a test that changes one leaves every other as it was. */
function version(
	source: Exported,
	tenantId: string,
	baseId: string,
	number: number,
	status: TemplateStatus,
	changes: DocumentChanges = {},
): TemplateVersion {
	return {
		tenantId,
		baseId,
		version: number,
		status,
		template: {
			...structuredClone(source.template),
			...structuredClone(changes),
			id: baseId,
			version: number,
			tenantId,
		},
		formSchema: structuredClone(source.formSchema),
		showcases: [],
		designDoc: {
			...structuredClone(source.designDoc),
			templateId: templateId(baseId, number),
			tenantId,
		},
	};
}

/** `template` holding `field` (`<layerId>.<prop>`) to a change: its document
 *  says so, for the SDK to stop a design that leaves it as its default, and
 *  the fake refuses a proof that does. */
function requiringChange(
	template: TemplateVersion,
	field: string,
): TemplateVersion {
	const [layerId, prop] = field.split('.');
	const layers = template.template.layers as {
		id: string;
		props: Record<string, { constraints?: Record<string, unknown> }>;
	}[];
	const attribute = layers.find((l) => l.id === layerId)?.props[prop ?? ''];
	if (attribute === undefined) {
		throw new Error(`Template ${template.baseId} has no ${field}.`);
	}
	attribute.constraints = { ...attribute.constraints, requireChange: true };
	return { ...template, requireChange: [field] };
}

/** A landscape A-format poster, beside the portrait tee. */
const POSTER: DocumentChanges = {
	name: 'Poster, liggande',
	ratio: { w: 420, h: 297 },
	ratioTolerance: 0.05,
};

/** The field `mini-tee-change` holds to a change: the slogan's text. */
const REQUIRED_CHANGE = 'slogan.content';

/** The showcase sets the latest live tee is drawn with in a store's product images. */
export const TEE_SHOWCASES = [
	{ id: 'showcase-anna', name: 'Anna, class of 2026' },
	{ id: 'showcase-team', name: 'Team photo' },
];

/** Where the print sits on each fixture view's photos. */
const MOCKUP_PLACEMENT_RECT_PX = { x: 250, y: 200, w: 500, h: 600 };

/** The size of each fixture view's photos, which the rect is measured in. */
const MOCKUP_PHOTO_SIZE_PX = { widthPx: 1000, heightPx: 1000 };

/** A view of a fixture placement holding `photos`. */
function view(id: string, label: string, photos: Photo[]): View {
	return {
		id,
		label,
		photoSizePx: { ...MOCKUP_PHOTO_SIZE_PX },
		placementRectPx: { ...MOCKUP_PLACEMENT_RECT_PX },
		photos,
	};
}

/** The refs each fixture colourway's photos show. */
type Colourways = { light: string[]; dark: string[] };

/** Front and back placements, as a t-shirt-sized blank has them, each with
 *  one view of one mockup photo; the front also has a view of a dark
 *  colourway, and the back's view a second photo whose upload never
 *  finished. The light front photo shows the light variants, the dark one
 *  the dark variants, and the back photos both. */
function frontAndBack(
	blankId: string,
	{ light, dark }: Colourways,
): Blank['placements'] {
	const side = {
		maxWidthMm: 330,
		maxHeightMm: 420,
		defaultWidthMm: 300,
		defaultHeightMm: 360,
		bleedMm: 3,
		safeAreaMm: 5,
		printMethod: 'dtg',
	};
	const photo = (
		id: string,
		asset: string,
		variantRefs: string[],
		uploaded = true,
	): Photo => ({
		id,
		assetId: `asset_${blankId}_${asset}`,
		uploaded,
		variantRefs,
	});
	const both = [...light, ...dark];
	return [
		{
			id: FIXTURE.placement.front,
			name: 'Front',
			shopName: 'Front',
			...side,
			views: [
				view('view_front', 'Front', [
					photo(FIXTURE.mockup.front, FIXTURE.placement.front, light),
				]),
				view('view_front_dark', 'Front, dark', [
					photo(
						FIXTURE.mockup.frontDark,
						`${FIXTURE.placement.front}_dark`,
						dark,
					),
				]),
			],
		},
		{
			id: FIXTURE.placement.back,
			name: 'Back',
			shopName: 'Back',
			...side,
			views: [
				view('view_back', 'Back', [
					photo(FIXTURE.mockup.back, FIXTURE.placement.back, both),
					photo(
						FIXTURE.mockup.uploading,
						`${FIXTURE.placement.back}_uploading`,
						both,
						false,
					),
				]),
			],
		},
	];
}

/** A Color × Size variant, sold unless `retired` names why not. */
function colourSize(
	ref: string,
	color: string,
	size: string,
	retired: string | null = null,
): Variant {
	return {
		ref,
		options: { Color: color, Size: size },
		retired: retired === null ? null : { reason: retired },
	};
}

const COLOUR_SIZE_AXES = ['Color', 'Size'];

/** The tee's variants: white and black in two sizes, a red retired for
 *  each reason, and a heather no photo shows. */
function teeVariants(): Variant[] {
	const v = FIXTURE.variant;
	return [
		colourSize(v.teeWhiteS, 'White', 'S'),
		colourSize(v.teeWhiteM, 'White', 'M'),
		colourSize(v.teeBlackS, 'Black', 'S'),
		colourSize(v.teeBlackM, 'Black', 'M'),
		colourSize(v.teeRedS, 'Red', 'S', 'no_longer_sold'),
		colourSize(v.teeRedM, 'Red', 'M', 'retired'),
		colourSize(v.teeHeatherS, 'Heather', 'S'),
	];
}

/** The tee's Swedish names: every option name, the colours but Heather,
 *  and the front and back views. Its sizes and the dark front view have
 *  none, so they read as written. */
const TEE_SWEDISH: BlankNames = {
	axes: { Color: 'Färg', Size: 'Storlek' },
	values: { Color: { White: 'Vit', Black: 'Svart', Red: 'Röd' } },
	views: { view_front: 'Framsida', view_back: 'Baksida' },
};

function defaultSet({
	siteUrl = DEFAULT_SITE_URL,
	now,
}: FixtureOptions): State {
	const state = emptyState();
	const { tenant, store } = FIXTURE;
	/** Each tenant's own margins, so changing one leaves the other alone. */
	const margins = () => ({ bleedMm: 3, safeAreaMm: 5 });
	const minPrintDpi = 150;
	/** Each tenant's own settings, so changing one leaves the other alone. */
	const workspace = () => ({
		branding: { logoUrl: null, accentColor: null },
		brands: [],
		proofWatermark: false,
		deliverOrdersTo: null,
		defaultLanguage: 'en' as const,
	});
	state.tenants.set(tenant.live, {
		id: tenant.live,
		name: 'Fixture Prints',
		slug: FIXTURE.tenantSlug.live,
		environment: 'live',
		printMargins: margins(),
		minPrintDpi,
		workspace: workspace(),
	});
	state.tenants.set(tenant.sandbox, {
		id: tenant.sandbox,
		name: 'Fixture Prints (sandbox)',
		slug: FIXTURE.tenantSlug.sandbox,
		environment: 'sandbox',
		printMargins: margins(),
		minPrintDpi,
		workspace: workspace(),
	});

	for (const [fixture, tenantId, url] of [
		[store.main, tenant.live, siteUrl],
		[store.other, tenant.live, 'http://other-store.test'],
		[store.sandbox, tenant.sandbox, siteUrl],
	] as const) {
		const s = fixture;
		state.stores.set(s.id, {
			id: s.id,
			integrationKey: s.integrationKey,
			publishableKey: s.publishableKey,
			tenantId,
			siteUrl: url,
			connectionId: s.id,
			name: `WooCommerce · ${new URL(url).host}`,
			allowedOrigins: [new URL(url).origin],
			brandId: null,
			printFileForm: null,
			printFileTextTolerance: null,
			storeAccess: null,
			storeAccessRequest: null,
			webhook: {
				...s.webhook,
				url: defaultWebhookUrl(url),
				active: true,
				reachable: true,
				eventTypes: [],
				createdAt: now,
			},
		});
	}

	const tee = exportedTemplate('mini-tee@1');
	for (const t of [
		version(tee, tenant.live, 'mini-tee', 1, 'live'),
		{
			...version(tee, tenant.live, 'mini-tee', 2, 'live'),
			showcases: structuredClone(TEE_SHOWCASES),
		},
		version(tee, tenant.live, 'mini-tee-paused', 1, 'paused'),
		version(tee, tenant.sandbox, 'sandbox-tee', 1, 'live'),
		version(tee, tenant.live, 'mini-poster', 1, 'live', POSTER),
		requiringChange(
			version(tee, tenant.live, 'mini-tee-change', 1, 'live'),
			REQUIRED_CHANGE,
		),
	]) {
		state.templates.set(templateId(t.baseId, t.version), t);
	}

	// The default printshop takes address changes after an order is sent; the
	// others do not. The integrated one prints only on a blank.
	for (const [id, name, adapter, isDefault] of [
		[FIXTURE.printshop.main, 'Fixture Print AB', CUSTOM_ADAPTER, true],
		[FIXTURE.printshop.other, 'Other Print AB', CUSTOM_ADAPTER, false],
		[FIXTURE.printshop.integrated, 'Gelato', 'gelato', false],
	] as const) {
		state.printshops.set(id, {
			id,
			tenantId: tenant.live,
			name,
			adapter,
			active: true,
			isDefault,
			acceptsAddressUpdates: isDefault,
		});
	}
	const stamped = {
		machineProfileId: null,
		createdAt: now,
		updatedAt: now,
		deletedAt: null,
	};
	state.blanks.set(FIXTURE.blank.tee, {
		id: FIXTURE.blank.tee,
		tenantId: tenant.live,
		name: 'Fixture tee',
		categoryId: 'cat_apparel',
		defaultPrintshopId: FIXTURE.printshop.main,
		partnerLocked: false,
		placements: frontAndBack(FIXTURE.blank.tee, {
			light: [FIXTURE.variant.teeWhiteS, FIXTURE.variant.teeWhiteM],
			dark: [FIXTURE.variant.teeBlackS, FIXTURE.variant.teeBlackM],
		}),
		axes: [...COLOUR_SIZE_AXES],
		variants: teeVariants(),
		names: { sv: TEE_SWEDISH },
		...stamped,
	});
	state.blanks.set(FIXTURE.blank.hoodie, {
		id: FIXTURE.blank.hoodie,
		tenantId: tenant.live,
		name: 'Fixture hoodie',
		categoryId: 'cat_apparel',
		defaultPrintshopId: FIXTURE.printshop.other,
		partnerLocked: false,
		placements: frontAndBack(FIXTURE.blank.hoodie, {
			light: [FIXTURE.variant.hoodieWhiteS],
			dark: [FIXTURE.variant.hoodieBlackS],
		}),
		axes: [...COLOUR_SIZE_AXES],
		variants: [
			colourSize(FIXTURE.variant.hoodieWhiteS, 'White', 'S'),
			colourSize(FIXTURE.variant.hoodieBlackS, 'Black', 'S'),
		],
		...stamped,
	});
	state.blanks.set(FIXTURE.blank.bare, {
		id: FIXTURE.blank.bare,
		tenantId: tenant.live,
		name: 'Fixture tote',
		categoryId: null,
		defaultPrintshopId: FIXTURE.printshop.main,
		partnerLocked: false,
		placements: [],
		axes: [],
		variants: [{ ref: FIXTURE.variant.tote, options: {}, retired: null }],
		...stamped,
	});

	state.shareLinks.set(FIXTURE.shareLink, {
		token: FIXTURE.shareLink,
		tenantId: tenant.live,
		storeId: store.main.id,
		subject: { kind: 'template', id: FIXTURE.template.live },
		expiresAt: now + FIXTURE_LINK_LIFETIME_MS,
	});
	return state;
}

export const FIXTURE_SETS: Record<string, (options: FixtureOptions) => State> =
	{
		default: defaultSet,
	};

export const DEFAULT_FIXTURE_SET = 'default';
