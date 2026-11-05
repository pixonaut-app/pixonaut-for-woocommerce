// `POST /v1/templates/{id}/designer-links`: a hosted designer link for the
// template's live version, one side at the top level or 2–10 under `sides`;
// more than `RECOMMENDED_SIDES` is made, and answered with a warning.
// A side's `initialVariant` must be one of its `variants` when it names any;
// the hosted designer shows the variant and views a side is shown in.
// The link is kept so the hosted designer (designer.ts) can make each side's
// proof and send the shopper back to `returnUrl`.
//
// `GET /v1/share-links`, `PATCH` and `DELETE /v1/share-links/{id}` list,
// rename and withdraw the store's designer links; proof page links are not
// listed. A withdrawal emits `share_link.revoked`. A link's name is unique among the tenant's working links, case
// aside.
import { randomUUID } from 'node:crypto';
import {
	allOwned,
	type HostedLocale,
	putOwned,
	type State,
	type TemplateVersion,
	withLang,
} from '../state';
import {
	SHARE_LINK_REVOKED,
	shareLinkEvent,
} from '../webhooks/share-link-events';
import { pageOf } from './paging';
import { type Box, defaultPrintSize, resolvePlacement } from './placements';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	invalidInput,
	refusal,
} from './types';

export const DESIGNER_LINKS = 'designer-links';

/** How long a link carrying `state` works. */
export const DESIGNER_LINK_LIFETIME_MS = 24 * 60 * 60 * 1000;

/** Most sides the designer is built for; a link with more carries a warning. */
export const RECOMMENDED_SIDES = 4;

/** The longest name a link takes. */
export const LINK_NAME_MAX_LENGTH = 80;

export const DESIGNER_KIND = 'designer';

/** The hosted designer a link opens, under the fake's own origin. */
export const DESIGNER_PATH = '/designer';

/** The fields of one side that may also stand at the top level. */
const SIDE_FIELDS = [
	'blankId',
	'placementId',
	'printSizes',
	'mockups',
	'selectedPrintSizeIndex',
	'selectedMockupIndex',
	'views',
	'variants',
	'initialView',
	'initialVariant',
] as const;

type SizeRequest = { widthMm: number; heightMm: number; label: string };

type SideRequest = {
	key?: string;
	mockups?: unknown[];
	selectedMockupIndex?: number;
	label?: string;
	templateId?: string;
	blankId?: string;
	placementId?: string;
	printSizes?: SizeRequest[];
	selectedPrintSizeIndex?: number;
	views?: string[];
	variants?: string[];
	initialView?: string;
	initialVariant?: string;
};

type LinkRequest = SideRequest & {
	sides?: SideRequest[];
	returnUrl?: string;
	state?: string;
	/** Checked against the spec's enum before the handler runs. */
	locale?: HostedLocale;
	name?: string;
	designLimit?: { count: number; period: string } | null;
	printAreaFrame?: boolean;
	placementFrame?: boolean;
	/** Checked against the spec's enum before the handler runs. */
	layout?: LinkLayout;
	/** Checked against the spec's enum before the handler runs. */
	afterApprove?: AfterApprove;
};

/** What the hosted page's button says; it only words the button. */
export type AfterApprove = 'return' | 'add_to_cart';

/** How a link lays out every side's form; absent follows each template. */
type LinkLayout = 'page' | 'steps';

/** One side as the hosted designer proofs it. */
export type LinkSide = {
	/** Null for a link without `sides`. */
	key: string | null;
	label: string | null;
	printSizes: SizeRequest[];
	/** `baseId@version`, the version live when the link was made. */
	templateId: string;
	blankId: string | null;
	placementId: string | null;
	printSize: Box;
	/** The views it is shown in; empty for all. */
	views: string[];
	/** The variants offered; empty for all. */
	variants: string[];
	/** The variant it starts on, or null. */
	initialVariant: string | null;
};

export type DesignerLink = {
	id: string;
	token: string;
	storeId: string;
	tenantId: string;
	/** The base id of the template the link was made for: its subject. */
	subjectId: string;
	name: string | null;
	designLimit: { count: number; period: string } | null;
	createdAt: number;
	sides: LinkSide[];
	returnUrl: string | null;
	state: string | null;
	/** Null: the workspace's default language. */
	locale: HostedLocale | null;
	expiresAt: number | null;
	/** Whether the hosted designer frames the print area. */
	printAreaFrame: boolean;
	/** Whether the hosted designer frames the shopper's picture at rest. */
	placementFrame: boolean;
	/** The form's layout the link fixed, or null for each template's own. */
	layout: LinkLayout | null;
	afterApprove: AfterApprove;
};

/** The live version of `baseId` on `tenantId`, or undefined. */
function liveVersion(
	state: State,
	tenantId: string,
	baseId: string,
): TemplateVersion | undefined {
	let latest: TemplateVersion | undefined;
	for (const version of state.templates.values()) {
		if (
			version.tenantId === tenantId &&
			version.baseId === baseId &&
			(latest === undefined || version.version > latest.version)
		) {
			latest = version;
		}
	}
	return latest?.status === 'live' ? latest : undefined;
}

type SideRead =
	| { ok: true; side: LinkSide }
	| { ok: false; result: HandlerResult };

function readSide(
	state: State,
	tenantId: string,
	pathId: string,
	request: SideRequest,
	key: string | null,
): SideRead {
	if ((request.blankId === undefined) !== (request.placementId === undefined)) {
		return {
			ok: false,
			result: invalidInput(
				'placementId: blankId and placementId come together',
			),
		};
	}
	const sizes = request.printSizes ?? [];
	const selected = request.selectedPrintSizeIndex ?? 0;
	if (sizes.length > 0 && selected >= sizes.length) {
		return {
			ok: false,
			result: invalidInput(
				'selectedPrintSizeIndex: past the last of printSizes',
			),
		};
	}
	const variants = request.variants ?? [];
	if (
		request.initialVariant !== undefined &&
		variants.length > 0 &&
		!variants.includes(request.initialVariant)
	) {
		return {
			ok: false,
			result: invalidInput('initialVariant: not one of variants'),
		};
	}
	const template = liveVersion(state, tenantId, request.templateId ?? pathId);
	if (template === undefined) {
		return { ok: false, result: refusal(404, 'subject_not_found') };
	}
	const placement =
		request.blankId === undefined || request.placementId === undefined
			? undefined
			: resolvePlacement(state, tenantId, request.blankId, request.placementId);
	if (request.blankId !== undefined && placement === undefined) {
		return { ok: false, result: refusal(422, 'unknown_placement') };
	}
	const named = sizes[selected];
	let printSize: Box | undefined =
		named === undefined
			? undefined
			: { widthMm: named.widthMm, heightMm: named.heightMm };
	if (printSize === undefined && placement !== undefined) {
		printSize = defaultPrintSize(template.template.ratio, placement);
	}
	if (printSize === undefined) {
		// Nothing to size a template-only proof by.
		return {
			ok: false,
			result: invalidInput('printSizes: a side on no blank names a size'),
		};
	}
	return {
		ok: true,
		side: {
			key,
			label: request.label ?? null,
			printSizes: sizes,
			templateId: `${template.baseId}@${template.version}`,
			blankId: request.blankId ?? null,
			placementId: request.placementId ?? null,
			printSize,
			views: request.views ?? [],
			variants,
			initialVariant: request.initialVariant ?? null,
		},
	};
}

/** Whether `returnUrl` is on one of the store's origins. */
function onStore(state: State, storeId: string, returnUrl: string): boolean {
	if (!URL.canParse(returnUrl)) {
		return false;
	}
	const origins = state.stores.get(storeId)?.allowedOrigins ?? [];
	return origins.includes(new URL(returnUrl).origin);
}

export const createDesignerLink: Handler = (context) => {
	const { state, clock } = context;
	const caller = callerOf(context);
	const request = (context.body ?? {}) as LinkRequest;
	const pathId = context.params.id ?? '';

	if (request.state !== undefined && request.returnUrl === undefined) {
		return invalidInput('state: needs a returnUrl');
	}
	if (request.afterApprove !== undefined && request.returnUrl === undefined) {
		// Any value, the default too: it only words the way back to a returnUrl.
		return invalidInput('afterApprove: needs a returnUrl');
	}
	if (
		request.returnUrl !== undefined &&
		!onStore(state, caller.storeId, request.returnUrl)
	) {
		return refusal(400, 'return_url_off_store');
	}

	const sides: LinkSide[] = [];
	if (request.sides !== undefined) {
		// A link is either one side at the top level or every side under `sides`.
		const keys = request.sides.map((side) => side.key);
		// Either every side is on a blank or none is.
		const onBlank = new Set(
			request.sides.map((side) => side.blankId !== undefined),
		);
		if (
			SIDE_FIELDS.some((field) => request[field] !== undefined) ||
			new Set(keys).size !== keys.length ||
			onBlank.size > 1
		) {
			return invalidInput(
				'sides: one side at the top level or every side under sides, each key once, all on a blank or none',
			);
		}
		for (const side of request.sides) {
			const read = readSide(
				state,
				caller.tenantId,
				pathId,
				side,
				side.key ?? '',
			);
			if (!read.ok) {
				return read.result;
			}
			sides.push(read.side);
		}
	} else {
		const read = readSide(state, caller.tenantId, pathId, request, null);
		if (!read.ok) {
			return read.result;
		}
		sides.push(read.side);
	}

	if (
		request.name !== undefined &&
		request.name.length > LINK_NAME_MAX_LENGTH
	) {
		return refusal(422, 'share_link_name_too_long');
	}
	const holder =
		request.name === undefined
			? undefined
			: holderOf(context, caller.tenantId, request.name);
	if (holder !== undefined) {
		return nameTaken(holder);
	}

	const token = `dl_${randomUUID().replaceAll('-', '')}`;
	const link: DesignerLink = {
		id: `shl_${randomUUID().replaceAll('-', '')}`,
		token,
		storeId: caller.storeId,
		tenantId: caller.tenantId,
		subjectId: pathId,
		name: request.name ?? null,
		designLimit: request.designLimit ?? null,
		createdAt: clock.now(),
		sides,
		returnUrl: request.returnUrl ?? null,
		state: request.state ?? null,
		locale: request.locale ?? null,
		expiresAt:
			request.state === undefined
				? null
				: clock.now() + DESIGNER_LINK_LIFETIME_MS,
		printAreaFrame: request.printAreaFrame ?? false,
		placementFrame: request.placementFrame ?? false,
		layout: request.layout ?? null,
		afterApprove: request.afterApprove ?? 'return',
	};
	putOwned(state, DESIGNER_LINKS, token, caller.storeId, link);
	return {
		status: 201,
		body: {
			id: link.id,
			token,
			url: withLang(
				`${context.publicOrigin}${DESIGNER_PATH}/${token}`,
				link.locale,
			),
			locale: link.locale,
			expiresAt: link.expiresAt,
			name: link.name,
			// The fake's workspaces have one brand, the default.
			brandId: null,
			designLimit: link.designLimit,
			printAreaFrame: link.printAreaFrame,
			placementFrame: link.placementFrame,
			...(sides.length > RECOMMENDED_SIDES && {
				warnings: [
					{
						code: 'sides_over_recommended',
						recommended: RECOMMENDED_SIDES,
						sides: sides.length,
					},
				],
			}),
		},
	};
};

/** Every designer link of `tenantId` that still works, oldest first. */
function workingLinks(
	context: HandlerContext,
	tenantId: string,
): DesignerLink[] {
	return [...allOwned<DesignerLink>(context.state, DESIGNER_LINKS).values()]
		.map((record) => record.value)
		.filter(
			(link) =>
				link.tenantId === tenantId &&
				(link.expiresAt === null || link.expiresAt > context.clock.now()),
		);
}

/** The working link holding `name`, case aside. */
function holderOf(
	context: HandlerContext,
	tenantId: string,
	name: string,
): DesignerLink | undefined {
	return workingLinks(context, tenantId).find(
		(link) => link.name?.toLowerCase() === name.toLowerCase(),
	);
}

function nameTaken(holder: DesignerLink) {
	return {
		status: 409,
		body: { error: 'share_link_name_taken', holderId: holder.id },
	};
}

/** The caller's working link `id`, or undefined, which is answered `404`. */
function callersLink(context: HandlerContext): DesignerLink | undefined {
	const caller = callerOf(context);
	return workingLinks(context, caller.tenantId).find(
		(link) => link.id === context.params.id && link.storeId === caller.storeId,
	);
}

function row(context: HandlerContext, link: DesignerLink) {
	const returnUrl = link.returnUrl === null ? null : new URL(link.returnUrl);
	return {
		id: link.id,
		kind: DESIGNER_KIND,
		subjectId: link.subjectId,
		mayDecide: false,
		returnUrl: link.returnUrl,
		name: link.name,
		// The fake's workspaces have one brand, the default.
		brandId: null,
		designLimit:
			link.designLimit === null
				? null
				: { ...link.designLimit, used: 0, reopensAt: null },
		createdAt: link.createdAt,
		expiresAt: link.expiresAt,
		printAreaFrame: link.printAreaFrame,
		placementFrame: link.placementFrame,
		designs: 0,
		createdBy: null,
		createdByKey: { id: link.storeId, kind: 'ik', name: null },
		source: 'key',
		lastOpenedAt: null,
		subject: {
			label: null,
			meta: {
				type: 'template',
				blank:
					link.sides[0]?.blankId == null
						? null
						: { id: link.sides[0].blankId, name: null },
				sides: link.sides.map((side) => ({
					key: side.key,
					label: side.label,
					printSizes: side.printSizes,
				})),
				carriesVisit: link.state !== null,
				returnHost: returnUrl?.host ?? null,
			},
			warning: null,
		},
		url: withLang(
			`${context.publicOrigin}${DESIGNER_PATH}/${link.token}`,
			link.locale,
		),
		locale: link.locale,
	};
}

/** Whether `q` is in the link's id or name, case aside; Pixonaut also
 *  searches the subject's name and who handed the link out. */
function matchesText(link: DesignerLink, q: string | null): boolean {
	if (q === null) {
		return true;
	}
	const text = q.toLowerCase();
	return [link.id, link.name ?? ''].some((field) =>
		field.toLowerCase().includes(text),
	);
}

/** `GET /v1/share-links`: the tenant's working designer links, newest first,
 *  narrowed by `kind`, `subjectId`, `name`, `q` and `createdBy=me`. */
export const listShareLinks: Handler = (context) => {
	const caller = callerOf(context);
	const { query } = context;
	const kind = query.get('kind');
	const subjectId = query.get('subjectId');
	const name = query.get('name');
	const links = workingLinks(context, caller.tenantId)
		.filter(
			(link) =>
				(kind === null || kind === DESIGNER_KIND) &&
				(subjectId === null || link.subjectId === subjectId) &&
				(name === null || link.name?.toLowerCase() === name.toLowerCase()) &&
				(query.get('createdBy') === null || link.storeId === caller.storeId) &&
				matchesText(link, query.get('q')),
		)
		.reverse();
	const page = pageOf(links, query);
	return {
		status: 200,
		body: {
			links: page.rows.map((link) => row(context, link)),
			total: page.total,
			page: page.page,
			pageSize: page.pageSize,
			counts: {
				kinds: [{ kind: DESIGNER_KIND, count: links.length }],
				sources: [],
				carriesVisit: links.filter((link) => link.state !== null).length,
			},
		},
	};
};

/** `PATCH /v1/share-links/{id}`: renames a link or changes its design limit. */
export const updateShareLink: Handler = (context) => {
	const link = callersLink(context);
	if (link === undefined) {
		return refusal(404, 'not_found');
	}
	const request = (context.body ?? {}) as Pick<
		LinkRequest,
		'name' | 'designLimit'
	> & { name?: string | null };
	if (typeof request.name === 'string') {
		if (request.name.length > LINK_NAME_MAX_LENGTH) {
			return refusal(422, 'share_link_name_too_long');
		}
		const holder = holderOf(context, link.tenantId, request.name);
		if (holder !== undefined && holder.id !== link.id) {
			return nameTaken(holder);
		}
	}
	if (request.name !== undefined) {
		link.name = request.name;
	}
	if (request.designLimit !== undefined) {
		link.designLimit = request.designLimit;
	}
	return { status: 200, body: row(context, link) };
};

/** `DELETE /v1/share-links/{id}`: the link stops working, and
 *  `share_link.revoked` names it. */
export const withdrawShareLink: Handler = async (context) => {
	const link = callersLink(context);
	if (link === undefined) {
		return refusal(404, 'not_found');
	}
	allOwned<DesignerLink>(context.state, DESIGNER_LINKS).delete(link.token);
	await context.webhooks.emit(link.storeId, [
		shareLinkEvent(SHARE_LINK_REVOKED, link, 'key'),
	]);
	return { status: 204 };
};
