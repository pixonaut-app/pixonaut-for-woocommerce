// `POST /v1/share-links`: a store hands its customer a proof page about one
// of its designs, which `proof-pages.ts` serves under `PROOF_PAGE_PATH`. The
// link's token is the fake's own share-link credential, so its holder reaches
// the design through the API as any share link does.
//
// A store's grants hold proof pages only: every other kind is refused
// `403 forbidden`, as Pixonaut refuses a kind the caller may not hand out.
import { randomBytes, randomUUID } from 'node:crypto';
import {
	type ProofPage,
	type ProofPageLocale,
	type ShareLink,
	type State,
	withLang,
} from '../state';
import { findDesign } from './designs-store';
import { callerOf, type Handler, invalidInput, refusal } from './types';

export const PROOF_PAGE_KIND = 'proof-page';

/** Where a proof page link opens, under the fake's own origin. */
export const PROOF_PAGE_PATH = '/h/proof';

/** A proof page's lifetime when the store names none, and the longest it may
 *  ask for, as Pixonaut's `SHARE_LINK_LIFETIME_DAYS` sets them: a customer
 *  may sit on a proof for a few weeks before deciding. */
export const PROOF_PAGE_DEFAULT_DAYS = 30;
export const PROOF_PAGE_MAX_DAYS = 90;

export const MS_PER_DAY = 24 * 60 * 60 * 1000;

/** Prefixes a share link's token as a bearer credential. */
const LINK_TOKEN_PREFIX = 'link_';

/** Random bytes in a link's token, written as hex. */
const TOKEN_BYTES = 24;

const WEB_PROTOCOLS = ['http:', 'https:'];

type MintRequest = {
	kind: string;
	subjectId: string;
	expiresInDays?: number;
	locale?: ProofPageLocale;
	mayDecide?: boolean;
	returnUrl?: string;
	state?: string;
	name?: string;
};

/** Whether `value` is an absolute web address carrying no credentials, as
 *  Pixonaut reads a return url; anything else is invalid input. A plain http
 *  one is taken: the fake stands in for a non-production Pixonaut, which
 *  does not hold a return to https, and the test sites are served over http. */
export function webAddress(value: string): boolean {
	if (!URL.canParse(value)) {
		return false;
	}
	const url = new URL(value);
	return (
		WEB_PROTOCOLS.includes(url.protocol) &&
		url.username === '' &&
		url.password === ''
	);
}

/** The origins the tenant's publishable keys are allowed on, which a return
 *  url must be on. */
export function tenantOrigins(state: State, tenantId: string): string[] {
	return [...state.stores.values()]
		.filter((store) => store.tenantId === tenantId)
		.flatMap((store) => store.allowedOrigins);
}

/** The hosted page `token` opens, under `origin`. */
export function proofPageUrl(origin: string, token: string): string {
	return `${origin}${PROOF_PAGE_PATH}/${encodeURIComponent(token)}`;
}

export const createShareLink: Handler = (context) => {
	const { state, clock } = context;
	const caller = callerOf(context);
	const request = context.body as MintRequest;
	const { kind, returnUrl } = request;
	const isProofPage = kind === PROOF_PAGE_KIND;

	// Read as the body's own rules, before any rule of the kind.
	if (request.state !== undefined && returnUrl === undefined) {
		return invalidInput('state: needs a returnUrl');
	}
	if (returnUrl !== undefined && !webAddress(returnUrl)) {
		return invalidInput(
			'returnUrl: an absolute web address with no credentials',
		);
	}
	if (request.mayDecide === true && !isProofPage) {
		return refusal(400, 'decide_not_allowed');
	}
	if (returnUrl !== undefined && !isProofPage) {
		return refusal(400, 'return_url_not_allowed');
	}
	if (request.locale !== undefined && !isProofPage) {
		return refusal(400, 'locale_not_allowed');
	}
	if (!isProofPage) {
		return refusal(403, 'forbidden');
	}
	const days = request.expiresInDays ?? PROOF_PAGE_DEFAULT_DAYS;
	if (days > PROOF_PAGE_MAX_DAYS) {
		return {
			status: 400,
			body: { error: 'expiry_too_long', kind, maxDays: PROOF_PAGE_MAX_DAYS },
		};
	}
	if (
		returnUrl !== undefined &&
		!tenantOrigins(state, caller.tenantId).includes(new URL(returnUrl).origin)
	) {
		return refusal(400, 'return_url_off_store');
	}
	const design = findDesign(state, request.subjectId, caller.storeId);
	if (design === undefined) {
		return refusal(404, 'subject_not_found');
	}

	const createdAt = clock.now();
	const proofPage: ProofPage = {
		id: randomUUID(),
		mayDecide: request.mayDecide ?? false,
		returnUrl: returnUrl ?? null,
		state: request.state ?? null,
		locale: request.locale ?? null,
		createdAt,
	};
	const link: ShareLink = {
		token: `${LINK_TOKEN_PREFIX}${randomBytes(TOKEN_BYTES).toString('hex')}`,
		tenantId: caller.tenantId,
		storeId: caller.storeId,
		subject: { kind: 'design', id: design.id },
		expiresAt: createdAt + days * MS_PER_DAY,
		proofPage,
	};
	state.shareLinks.set(link.token, link);
	// `state` stays with the link for its page and is not echoed.
	return {
		status: 201,
		body: {
			id: proofPage.id,
			token: link.token,
			url: withLang(proofPageUrl(context.origin, link.token), proofPage.locale),
			locale: proofPage.locale,
			kind: PROOF_PAGE_KIND,
			subjectId: design.id,
			mayDecide: proofPage.mayDecide,
			returnUrl: proofPage.returnUrl,
			name: request.name ?? null,
			// The fake's workspaces have one brand, the default.
			brandId: null,
			// Only a designer link takes a design limit or draws a print area
			// or placement frame.
			designLimit: null,
			printAreaFrame: false,
			placementFrame: false,
			createdAt,
			expiresAt: link.expiresAt,
		},
	};
};
