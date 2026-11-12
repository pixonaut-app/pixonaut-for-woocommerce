// What the fake knows: tenants, the stores connected to them with their keys,
// and each tenant's catalogue. Everything a test changes lives here, and
// `/__fake/reset` replaces all of it with a named fixture set.
import { FIXTURE } from './fixture-ids';
import type { components } from './openapi';

type Schemas = components['schemas'];

export type Environment = 'live' | 'sandbox';

/** The form print files are made in. */
export type PrintFileForm = 'flat' | 'layered' | 'layered_live_text';

/** How far from its outline a print file's live text may lie. */
export type TextTolerance = { unit: 'px' | 'mm'; value: number };

export type Tenant = {
	id: string;
	name: string;
	/** The workspace's own slug, which every studio page sits under; never changes. */
	slug: string;
	environment: Environment;
	/** The tenant's default bleed and safe area. */
	printMargins: { bleedMm: number; safeAreaMm: number };
	/** Minimum print resolution a picture is judged against. */
	minPrintDpi: number;
	/** The settings a connected store reads, read only, on `GET /v1/connect`. */
	workspace: WorkspaceSettings;
};

/** A workspace brand's look. */
export type BrandLook = { logoUrl: string | null; accentColor: string | null };

/** A brand a workspace keeps besides its default. */
export type Brand = BrandLook & { id: string; name: string };

/** A workspace's settings a connected store is shown. */
export type WorkspaceSettings = {
	/** The look of the workspace's default brand, named after the tenant. */
	branding: BrandLook;
	/** The brands it keeps besides the default, which a store may choose. */
	brands: Brand[];
	proofWatermark: boolean;
	/** The language the hosted pages and the designer use when a link or
	 *  the SDK names none. */
	defaultLanguage: HostedLocale;
	/** The saved address every order ships to, or null for its customer. */
	deliverOrdersTo: { id: string; name: string } | null;
};

/** The id of a tenant's default brand. */
export function defaultBrandId(tenant: Tenant): string {
	return `br_${tenant.id}`;
}

/** A tenant's brands, the default first, then by name, as
 *  `GET /v1/connect` lists them. */
export function brandsOf(tenant: Tenant): (Brand & { isDefault: boolean })[] {
	return [
		{
			id: defaultBrandId(tenant),
			name: tenant.name,
			...tenant.workspace.branding,
			isDefault: true,
		},
		...[...tenant.workspace.brands]
			.sort((a, b) => a.name.localeCompare(b.name))
			.map((brand) => ({ ...brand, isDefault: false })),
	];
}

/** The webhook endpoint a store was given when it connected. */
export type WebhookEndpoint = {
	id: string;
	url: string;
	/** The signing secret every delivery to it is signed with. */
	secret: string;
	/** False once the store disconnected, or switched it off: nothing is
	 *  delivered to it. */
	active: boolean;
	/** False when the store's address did not answer: nothing is queued for
	 *  it, and the store reads its events by pulling. */
	reachable: boolean;
	/** The event types it receives; empty for every type. */
	eventTypes: string[];
	/** When it was registered, epoch ms. */
	createdAt: number;
};

/** A store: one site, with the keys and the webhook endpoint its connection
 *  was given. Its `id` stays with the site across reconnects, so what it made
 *  stays its own; disconnecting empties its keys. */
export type Store = {
	id: string;
	tenantId: string;
	siteUrl: string;
	/** Pixonaut's id for the current connection; new on every connect. */
	connectionId: string;
	/** The integration key's name. */
	name: string;
	integrationKey: string;
	publishableKey: string;
	/** Origins the publishable key is accepted from. */
	allowedOrigins: string[];
	/** The brand chosen for the store; null for its workspace's default. */
	brandId: string | null;
	/** The form chosen for the store's print files; null for its workspace's. */
	printFileForm: PrintFileForm | null;
	/** The live text tolerance chosen for the store; null for its workspace's. */
	printFileTextTolerance: TextTolerance | null;
	/** The credential the store handed over for calling its shop tools; null
	 *  while it holds none. */
	storeAccess: string | null;
	/** The studio's current request to turn store access on; null when none. */
	storeAccessRequest: string | null;
	webhook: WebhookEndpoint;
};

/** A workspace member, as the studio signs one in. */
export type Member = {
	memberId: string;
	memberName: string;
	memberEmail: string;
};

/** The member a consent is approved by unless a test names another. */
export const DEFAULT_MEMBER: Member = FIXTURE.member.owner;

/** A code the studio handed out on approval, waiting to be exchanged. */
export type ConnectCode = {
	code: string;
	/** Who approved. */
	member: Member;
	tenantId: string;
	siteUrl: string;
	siteName: string | undefined;
	redirectUri: string;
	/** The store's REST address, when it named one; the webhook endpoint
	 *  goes under it. */
	restUrl: string | undefined;
	codeChallenge: string;
	expiresAt: number;
};

/** How the studio's consent screen answers the next connect. */
export type Consent = {
	decision: 'approve' | 'deny';
	/** The side a member picks when the request names none. */
	environment: Environment;
	/** Who signs in and approves. */
	member: Member;
};

export type ShareLinkSubject = { kind: 'template' | 'design'; id: string };

/** The languages the hosted pages, designer links and the SDK speak. */
export const HOSTED_LOCALES = [
	'en',
	'sv',
	'de',
	'fr',
	'es',
	'nl',
	'da',
	'nb',
	'fi',
] as const;

export type HostedLocale = (typeof HOSTED_LOCALES)[number];

export type ProofPageLocale = HostedLocale;

export function isHostedLocale(value: unknown): value is HostedLocale {
	return HOSTED_LOCALES.includes(value as HostedLocale);
}

/** A hosted page's address, with `?lang=` when the link names a language. */
export function withLang(url: string, locale: HostedLocale | null): string {
	return locale === null ? url : `${url}?lang=${locale}`;
}

/** The language a hosted page is shown in: its `?lang=`, then the link's
 *  stored locale, then the workspace's default. An unknown `?lang=` is
 *  ignored. */
export function pageLanguage(
	state: State,
	tenantId: string,
	lang: string | undefined,
	stored: string | null,
): HostedLocale {
	if (isHostedLocale(lang)) {
		return lang;
	}
	if (isHostedLocale(stored)) {
		return stored;
	}
	return state.tenants.get(tenantId)?.workspace.defaultLanguage ?? 'en';
}

/** What a link minted as a proof page (`POST /v1/share-links`) opens: the
 *  hosted page about its design, kept for that page to read. */
export type ProofPage = {
	/** The link's id, as the mint answered it. */
	id: string;
	/** Whether its holder may approve or reject the design. */
	mayDecide: boolean;
	/** Where the page sends the customer once they asked for changes. */
	returnUrl: string | null;
	/** Carried back to `returnUrl` untouched. */
	state: string | null;
	/** Null: the workspace's default language. */
	locale: ProofPageLocale | null;
	createdAt: number;
};

export type ShareLink = {
	token: string;
	tenantId: string;
	/** The store that minted it, whose origins may also send it. */
	storeId: string;
	subject: ShareLinkSubject;
	expiresAt: number;
	/** Only on a link minted as a proof page. */
	proofPage?: ProofPage;
	/** Only on a correction link minted with a `returnUrl`. */
	correctionPage?: CorrectionPage;
	/** A correction link minted with `adjustLayout`: its proofs adjust a
	 *  standing design's layout, never correct it. */
	adjustsLayout?: boolean;
};

/** Where the hosted correction page a correction link opens sends the
 *  customer back to. */
export type CorrectionPage = {
	returnUrl: string;
	/** Carried back to `returnUrl` untouched. */
	state: string | null;
};

export type TemplateStatus = 'live' | 'paused';

export type TemplateVersion = {
	tenantId: string;
	baseId: string;
	version: number;
	status: TemplateStatus;
	/** The published document and its form, as exported from Pixonaut. */
	template: Schemas['ResolvedTemplate'] & {
		ratio: { w: number; h: number };
		ratioTolerance: number;
		fit: string;
	};
	formSchema: unknown;
	/** Its showcase sets: named sets of example values its pictures can be
	 *  drawn with (`?showcase=`), never the designer. */
	showcases: { id: string; name: string }[];
	/** The document a design made from this version stores: the template
	 *  resolved with its default values at the front placement's default size,
	 *  so a correction mount opens on something the SDK's core reads. The fake
	 *  resolves no values, so every design shows it. */
	designDoc: Record<string, unknown>;
	/** What Pixel does for a customer, where this version offers it in the
	 *  designer: writes a field, or adds a layer of the customer's own and
	 *  changes nothing else. Absent, it does not offer it. */
	designerPixel?:
		| { layerId: string; prop: string; value: string }
		| { addsLayer: 'text' };
	/** The fields whose default a customer must change before a proof is
	 *  made, as `<layerId>.<prop>`: a proof leaving one as its form's
	 *  default is refused 422 `constraint_violation`, rule `unchanged`. */
	requireChange?: string[];
	/** What `backgroundSeeThrough` answers; absent answers `null`, not known,
	 *  until `POST /__fake/templates/:id/background` says otherwise. */
	backgroundSeeThrough?: boolean | null;
	/** The workspace's pictures the version holds that a print size can
	 *  print too soft, each with its width in pixels, and what the
	 *  workspace does with such a size; absent, every image is sharp
	 *  enough at any size. Set by `POST /__fake/templates/:id/low-resolution`. */
	lowResolution?: LowResolutionSetup;
};

/** Images a print size judges against their recommended resolution. */
export type LowResolutionSetup = {
	/** `label`, when given, is answered beside `layerId`, as Pixonaut does
	 *  since it names its pictures (pixonaut#3517); left out, the answer is
	 *  an older one without it. */
	images: {
		layerId: string;
		assetId: string;
		widthPx: number;
		label?: string;
	}[];
	recommendedDpi: number;
	/** `warn` names them with a legal size; `refuse` refuses the size. */
	action: 'warn' | 'refuse';
};

/** A photo of the blank a mockup is rendered on; its id is the mockup id. */
export type Photo = {
	id: string;
	assetId: string;
	/** False while its upload has not finished, which leaves the photo
	 *  without a `mount`. */
	uploaded: boolean;
	/** The refs of the blank's variants the photo shows; empty on a blank
	 *  with no Color axis, where a photo shows every variant. */
	variantRefs: string[];
};

/** One way of photographing a placement, such as "Flat": every photo in it
 *  shares one size and one print rect. */
export type View = {
	id: string;
	label: string;
	/** The size of the view's photos; null while it holds none. */
	photoSizePx: { widthPx: number; heightPx: number } | null;
	/** Where the print sits on the view's photos, in their pixels; null
	 *  until it is marked. */
	placementRectPx: { x: number; y: number; w: number; h: number } | null;
	photos: Photo[];
};

/** A placement's print area on one row of the options its blank's print
 *  areas change with, such as one per size. */
export type PrintArea = {
	/** A value of each `areasVaryBy` option, by option name. */
	row: Record<string, string>;
	/** The largest print the row takes. */
	widthMm: number;
	heightMm: number;
	/** What a print on the row gets when none is chosen. */
	usualWidthMm: number;
	usualHeightMm: number;
};

export type Placement = {
	id: string;
	/** The studio's name for it. */
	name: string;
	/** What the printshop calls it; null when it follows `name`. */
	shopName: string | null;
	maxWidthMm: number;
	maxHeightMm: number;
	defaultWidthMm: number;
	defaultHeightMm: number;
	bleedMm: number;
	safeAreaMm: number;
	/** Left out when the blank names none, as Pixonaut stores it. */
	printMethod?: string;
	dpi?: number;
	views: View[];
	/** Its print area per row; left out when it has one area, its own. */
	areas?: PrintArea[];
};

/** Why a variant is no longer sold: the merchant `retired` it, or its
 *  printshop stopped making it (`no_longer_sold`). */
export type VariantRetirement = { reason: string };

/** One purchasable combination of a blank's options, such as White / M. */
export type Variant = {
	/** The id an order or render names it by. */
	ref: string;
	/** Axis to label; empty on a blank with no options. */
	options: Record<string, string>;
	retired: VariantRetirement | null;
};

/** A blank's names in one language, each by what it names as written:
 *  option names by axis, option values by axis and value, view labels by
 *  view id. One left out is read as written. */
export type BlankNames = {
	axes: Record<string, string>;
	values: Record<string, Record<string, string>>;
	views: Record<string, string>;
};

/** How a blank's option value looks to a shopper: a colour fill, a picture,
 *  or none, when it is drawn as its name. */
export type ValueAppearance =
	| { kind: 'fill'; colours: string[] }
	| { kind: 'image'; assetId: string; url: string; mime: string }
	| null;

export type Blank = {
	id: string;
	tenantId: string;
	name: string;
	categoryId: string | null;
	/** The printshop that makes it, fixed when the blank is created. */
	defaultPrintshopId: string;
	/** Bound to a partner's product. */
	partnerLocked: boolean;
	machineProfileId: string | null;
	placements: Placement[];
	/** The option axes, in the order the shopper sees them; empty for a
	 *  blank with one variant. */
	axes: string[];
	/** Every variant, retired ones included, in the blank's order. */
	variants: Variant[];
	/** The options its print areas change with, such as Size; left out when
	 *  each placement has one print area. */
	areasVaryBy?: string[];
	/** Its names by language code; a language left out has none. */
	names?: Record<string, BlankNames>;
	/** A look given a value by a test, by axis and value as written, over
	 *  the fixture's colour fills (`POST /__fake/blanks/:id/value-looks`). */
	valueLooks?: Record<string, Record<string, ValueAppearance>>;
	createdAt: number;
	updatedAt: number;
	/** When the blank was deleted, epoch ms; null while it is not. A
	 *  deleted blank is still read by id, and left out of the list. */
	deletedAt: number | null;
	/** `importing` while its import from a printshop's catalogue runs;
	 *  `ready` when left out. */
	status?: BlankStatus;
	/** The catalogue product it was imported from, through which connection. */
	importedFrom?: { connectionId: string; productId: string };
};

export type BlankStatus = 'importing' | 'ready' | 'failed';

/** A product of an integrated printshop's catalogue: what it lists, and the
 *  blank an import of it makes (without its ids, which the import gives). */
export type CatalogueProduct = {
	connectionId: string;
	productId: string;
	name: string;
	brand: string | null;
	variantCount: number | null;
	makes: (
		blankId: string,
	) => Pick<
		Blank,
		'categoryId' | 'placements' | 'axes' | 'variants' | 'areasVaryBy'
	>;
};

/** The adapter of a printshop the merchant runs themselves; every other
 *  adapter is a print partner's integration, which prints only on a blank. */
export const CUSTOM_ADAPTER = 'custom';

export type Printshop = {
	id: string;
	tenantId: string;
	name: string;
	adapter: string;
	active: boolean;
	/** The tenant's default printshop, used for a print on no blank. */
	isDefault: boolean;
	/** Whether it takes an order's address after the order was sent. */
	acceptsAddressUpdates: boolean;
};

/** Orders the printshop refuses as they are placed: each answers `201` with
 *  status `failed`, as Pixonaut does when its partner turns one down. */
export type OrderFailure = {
	outcome: 'failed';
	reason: string;
	remaining: number;
};

/** Orders whose submission's answer is lost: each answers `201` with status
 *  `outcome_unknown` until the store settles it. */
export type OrderOutcomeUnknown = {
	outcome: 'outcome_unknown';
	remaining: number;
};

/** Why a free workspace holds an order, as an order's `hold` reports it. */
export type Hold = { reason: string; limit: number; periodEnd: string };

/** Orders a free workspace past its monthly limit takes: each answers `201`
 *  with status `held` until the control API releases it. */
export type OrderHeld = {
	outcome: 'held';
	hold: Hold;
	remaining: number;
};

/** How the next orders placed, retries included, turn out. */
export type ArmedOrderOutcome = OrderFailure | OrderOutcomeUnknown | OrderHeld;

/** A record a store made (a design, a print, an order): another store's
 *  credential never finds it. */
export type Owned<T> = { storeId: string; value: T };

export type State = {
	tenants: Map<string, Tenant>;
	stores: Map<string, Store>;
	connectCodes: Map<string, ConnectCode>;
	/** By key. */
	consent: Consent;
	/** How the next orders placed turn out; null while none is armed, and
	 *  they are submitted. */
	armedOrderOutcome: ArmedOrderOutcome | null;
	shareLinks: Map<string, ShareLink>;
	/** By template id, `baseId@version`; an old base id reads its template. */
	templates: TemplateVersions;
	blanks: Map<string, Blank>;
	printshops: Map<string, Printshop>;
	/** The integrated printshops' catalogues, by product id. */
	catalogue: Map<string, CatalogueProduct>;
	/** Whether an import stays `importing` until the control API finishes
	 *  it, rather than being whole at once. */
	catalogueImportsHeld: boolean;
	/** Store-made records by collection, then id. */
	records: Map<string, Map<string, Owned<unknown>>>;
};

export function emptyState(): State {
	return {
		tenants: new Map(),
		stores: new Map(),
		connectCodes: new Map(),
		consent: {
			decision: 'approve',
			environment: 'live',
			member: DEFAULT_MEMBER,
		},
		armedOrderOutcome: null,
		shareLinks: new Map(),
		templates: new TemplateVersions(),
		blanks: new Map(),
		printshops: new Map(),
		catalogue: new Map(),
		catalogueImportsHeld: false,
		records: new Map(),
	};
}

export function templateId(baseId: string, version: number): string {
	return `${baseId}@${version}`;
}

/** The published template versions, by `baseId@version`. A base id Pixonaut
 *  changed stays an alias: `<old id>@<version>` reads the template under its
 *  new id, as `GET /v1/templates/{id}` does after a rename. Listing them
 *  lists each version once, under its id now. */
export class TemplateVersions extends Map<string, TemplateVersion> {
	/** The base id each changed one became. */
	private readonly renamed = new Map<string, string>();

	override get(id: string): TemplateVersion | undefined {
		return super.get(id) ?? super.get(this.current(id));
	}

	/** Moves every version of `from` to `to`, keeping `from` as an alias. */
	rename(from: string, to: string): void {
		for (const [id, version] of [...super.entries()]) {
			if (version.baseId !== from) {
				continue;
			}
			super.delete(id);
			version.baseId = to;
			version.template.id = to;
			version.designDoc.templateId = templateId(to, version.version);
			super.set(templateId(to, version.version), version);
		}
		this.renamed.set(from, to);
		this.renamed.delete(to);
	}

	/** `id` with its base id followed through every change. */
	private current(id: string): string {
		const at = id.lastIndexOf('@');
		let base = at > 0 ? id.slice(0, at) : id;
		const seen = new Set<string>();
		for (
			let next = this.renamed.get(base);
			next !== undefined && !seen.has(base);
			next = this.renamed.get(base)
		) {
			seen.add(base);
			base = next;
		}
		return at > 0 ? `${base}${id.slice(at)}` : base;
	}
}

/** Store `value` as `storeId`'s record in `collection`. */
export function putOwned<T>(
	state: State,
	collection: string,
	id: string,
	storeId: string,
	value: T,
): void {
	allOwned<T>(state, collection).set(id, { storeId, value });
}

/** `storeId`'s record, or undefined when there is none or another store
 *  made it, which a handler answers `404` alike. */
export function findOwned<T>(
	state: State,
	collection: string,
	id: string,
	storeId: string,
): T | undefined {
	const record = state.records.get(collection)?.get(id);
	return record?.storeId === storeId ? (record.value as T) : undefined;
}

/** Every record `storeId` made in `collection`, oldest first. */
export function ownedBy<T>(
	state: State,
	collection: string,
	storeId: string,
): T[] {
	return [...(state.records.get(collection)?.values() ?? [])]
		.filter((record) => record.storeId === storeId)
		.map((record) => record.value as T);
}

/** Every store's records in `collection`, by id, for a handler that looks one
 *  up by something other than its id or its store. Deleting from it deletes
 *  the record. */
export function allOwned<T>(
	state: State,
	collection: string,
): Map<string, Owned<T>> {
	let records = state.records.get(collection);
	if (records === undefined) {
		records = new Map();
		state.records.set(collection, records);
	}
	return records as Map<string, Owned<T>>;
}
