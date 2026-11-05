// The store's connection: the code exchange that hands a store its keys,
// reading the connection (with its workspace's settings, read only), the
// origins its publishable key takes, and disconnecting.
import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { defaultWebhookUrl, WEBHOOK_REST_ROUTE } from '../fixtures';
import {
	allOwned,
	brandsOf,
	type ConnectCode,
	type Environment,
	type State,
	type Store,
} from '../state';
import { DESIGNER_LINKS, type DesignerLink } from './designer-links';
import { callerOf, type Handler, type HandlerResult, refusal } from './types';

/** Random bytes in a minted key or secret, written as hex. */
const KEY_BYTES = 12;

/** Key prefixes by side, as Pixonaut mints them. */
const KEY_SIDE: Record<Environment, string> = { live: 'live', sandbox: 'test' };

/** The workspace settings a connection reads: no branding, no saved
 *  delivery address, and Pixonaut's default print resolution and upload
 *  retention. The plugin reads none of them yet. */
/** Hours a customer upload nothing holds is kept: the platform's default. */
const ASSET_TTL_HOURS = 168;

type TokenRequest = {
	kind?: string;
	code: string;
	code_verifier?: string;
	redirect_uri?: string;
};

function token(prefix: string): string {
	return `${prefix}${randomBytes(KEY_BYTES).toString('hex')}`;
}

/** The S256 challenge of a PKCE verifier. */
export function s256(verifier: string): string {
	return createHash('sha256').update(verifier).digest('base64url');
}

/** The store `siteUrl` belongs to, matched as written. */
function storeOfSite(state: State, siteUrl: string): Store | undefined {
	return [...state.stores.values()].find((store) => store.siteUrl === siteUrl);
}

/** Revokes a store's keys, its webhook endpoint and the share links they
 *  handed out. */
export function revoke(state: State, store: Store): void {
	store.integrationKey = '';
	store.storeAccess = null;
	store.storeAccessRequest = null;
	store.webhook.active = false;
	store.publishableKey = '';
	store.allowedOrigins = [];
	for (const [key, link] of state.shareLinks) {
		if (link.storeId === store.id) {
			state.shareLinks.delete(key);
		}
	}
	const designerLinks = allOwned<DesignerLink>(state, DESIGNER_LINKS);
	for (const [token, link] of designerLinks) {
		if (link.storeId === store.id) {
			designerLinks.delete(token);
		}
	}
}

export const exchangeConnectCode: Handler = (context) => {
	const { state, clock } = context;
	const request = context.body as TokenRequest;
	const grant = state.connectCodes.get(request.code);
	// Any attempt uses the code up.
	state.connectCodes.delete(request.code);
	if (
		grant === undefined ||
		grant.expiresAt <= clock.now() ||
		request.redirect_uri !== grant.redirectUri ||
		request.code_verifier === undefined ||
		s256(request.code_verifier) !== grant.codeChallenge
	) {
		return refusal(400, 'invalid_grant');
	}
	const tenant = state.tenants.get(grant.tenantId);
	if (tenant === undefined) {
		throw new Error(`The connect code names no tenant ${grant.tenantId}.`);
	}

	const host = new URL(grant.siteUrl).host;
	const side = KEY_SIDE[tenant.environment];
	const existing = storeOfSite(state, grant.siteUrl);
	if (existing !== undefined) {
		// Connecting a connected site replaces its connection.
		revoke(state, existing);
	}
	const store: Store = {
		id: existing?.id ?? `store_${randomUUID()}`,
		tenantId: tenant.id,
		siteUrl: grant.siteUrl,
		connectionId: `sc_${randomUUID()}`,
		name: `WooCommerce · ${grant.siteName ?? host} (${host})`,
		integrationKey: token(`ik_${side}_`),
		publishableKey: token(`pk_${side}_`),
		allowedOrigins: [new URL(grant.siteUrl).origin],
		// The brand chosen for the site stays across reconnects to its workspace.
		brandId: existing?.tenantId === tenant.id ? existing.brandId : null,
		storeAccess: null,
		storeAccessRequest: null,
		webhook: {
			id: randomUUID(),
			url:
				grant.restUrl === undefined
					? defaultWebhookUrl(grant.siteUrl)
					: `${grant.restUrl}${WEBHOOK_REST_ROUTE}`,
			secret: token('whsec_'),
			active: true,
			reachable: true,
			eventTypes: [],
			createdAt: clock.now(),
		},
	};
	state.stores.set(store.id, store);

	return {
		status: 201,
		body: {
			tenantId: tenant.id,
			tenantName: tenant.name,
			tenantSlug: tenant.slug,
			environment: tenant.environment,
			connectionId: store.connectionId,
			integrationKey: {
				id: randomUUID(),
				key: store.integrationKey,
				label: store.name,
			},
			publishableKey: {
				id: randomUUID(),
				key: store.publishableKey,
				allowedOrigins: store.allowedOrigins,
			},
			webhook: {
				id: store.webhook.id,
				url: store.webhook.url,
				secret: store.webhook.secret,
			},
			// The fake never calls the store to test its address.
			reachable: store.webhook.reachable,
			// Pixonaut hands over the approving member's key; the plugin does not read it.
			memberKey: {
				key: token(`mk_${side}_`),
				...grant.member,
			},
		},
	};
};

/** `PUT /v1/connect/store-access`: holds the store's credential. A `requestId`
 *  must be the studio's current request; without one it is an opt-in in
 *  WordPress, which always wins and ends the request. */
export const setStoreAccess: Handler = (context) => {
	const store = callerStore(context);
	const { credential, requestId } = context.body as {
		credential: string;
		requestId?: string;
	};
	if (requestId === undefined) {
		store.storeAccessRequest = null;
	} else if (requestId !== store.storeAccessRequest) {
		return refusal(409, 'store_access_request_stale');
	}
	store.storeAccess = credential;
	return { status: 204 };
};

/** `DELETE /v1/connect/store-access`: deletes it and ends any request from the
 *  studio; a no-op when none is held. */
export const deleteStoreAccess: Handler = (context) => {
	const store = callerStore(context);
	store.storeAccess = null;
	store.storeAccessRequest = null;
	return { status: 204 };
};

export function callerStore(context: Parameters<Handler>[0]): Store {
	const store = context.state.stores.get(callerOf(context).storeId);
	if (store === undefined) {
		throw new Error('The caller has no store.');
	}
	return store;
}

/** The print file form of the fake's workspaces. */
const PRINT_FILE_FORM = 'flat';

/** How far from its outline a print file's live text may lie in the fake's
 *  workspaces. */
const PRINT_FILE_TEXT_TOLERANCE = { unit: 'px', value: 1 };

/** The connection as `GET /v1/connect` answers it. */
function connectionOf(store: Store, state: State): HandlerResult {
	const tenant = state.tenants.get(store.tenantId);
	if (tenant === undefined) {
		throw new Error(`Store ${store.id} names no tenant ${store.tenantId}.`);
	}
	const brands = brandsOf(tenant);
	const drawnIn =
		brands.find((brand) => brand.id === store.brandId) ?? brands[0];
	return {
		status: 200,
		body: {
			connectionId: store.connectionId,
			name: store.name,
			siteUrl: store.siteUrl,
			tenantId: tenant.id,
			tenantName: tenant.name,
			tenantSlug: tenant.slug,
			environment: tenant.environment,
			webhookEndpointId: store.webhook.id,
			webhook: {
				id: store.webhook.id,
				url: store.webhook.url,
				active: store.webhook.active,
				reachable: store.webhook.reachable,
			},
			allowedOrigins: store.allowedOrigins,
			proofWatermark: tenant.workspace.proofWatermark,
			defaultLanguage: tenant.workspace.defaultLanguage,
			deliverOrdersTo: tenant.workspace.deliverOrdersTo,
			branding: {
				brandId: drawnIn.id,
				logoUrl: drawnIn.logoUrl,
				accentColor: drawnIn.accentColor,
			},
			brandId: store.brandId,
			brands: brands.map(({ id, name, logoUrl, accentColor, isDefault }) => ({
				id,
				name,
				logoUrl,
				accentColor,
				isDefault,
			})),
			reachable: store.webhook.reachable,
			// The store chooses neither; the workspace's are what it prints with.
			printFileForm: null,
			printFileTextTolerance: null,
			printing: {
				minPrintDpi: tenant.minPrintDpi,
				defaultBleedMm: tenant.printMargins.bleedMm,
				defaultSafeAreaMm: tenant.printMargins.safeAreaMm,
				printFileForm: PRINT_FILE_FORM,
				printFileTextTolerance: PRINT_FILE_TEXT_TOLERANCE,
			},
			assetTtlHours: ASSET_TTL_HOURS,
		},
	};
}

export const readConnection: Handler = (context) =>
	connectionOf(callerStore(context), context.state);

type UpdateRequest = { additionalOrigins?: string[]; brandId?: string | null };

/** Most origins a store adds besides the site's own. */
export const MAX_ADDITIONAL_ORIGINS = 10;

/** The labels of a registrable domain. The fake takes a host's last two;
 *  Pixonaut reads the Public Suffix List, so `shop.example.co.uk` differs. */
const REGISTRABLE_LABELS = 2;

function registrableDomain(host: string): string {
	return host.split('.').slice(-REGISTRABLE_LABELS).join('.');
}

/** Whether two hosts share a domain: one is a subdomain of the other (a
 *  single-label site such as `localhost` and its `www.`), or both end in
 *  the same registrable domain. */
function sameDomain(a: string, b: string): boolean {
	return (
		a.endsWith(`.${b}`) ||
		b.endsWith(`.${a}`) ||
		registrableDomain(a) === registrableDomain(b)
	);
}

/** Whether `origin` is a host of the site's own domain, with the site's
 *  scheme and port. */
function onSiteDomain(siteUrl: string, origin: string): boolean {
	if (!URL.canParse(origin)) {
		return false;
	}
	const site = new URL(siteUrl);
	const other = new URL(origin);
	return (
		other.origin === origin &&
		other.protocol === site.protocol &&
		other.port === site.port &&
		sameDomain(other.hostname, site.hostname)
	);
}

/** Sets the origins the publishable key takes besides the site's own (any
 *  host of the site's domain, up to `MAX_ADDITIONAL_ORIGINS`), and the
 *  store's brand: one of its workspace's, or null for the default. Nothing
 *  changes when either is refused. */
export const updateConnection: Handler = (context) => {
	const store = callerStore(context);
	const { additionalOrigins, brandId } = context.body as UpdateRequest;
	const tenant = context.state.tenants.get(store.tenantId);
	if (
		typeof brandId === 'string' &&
		(tenant === undefined ||
			!brandsOf(tenant).some((brand) => brand.id === brandId))
	) {
		return refusal(404, 'brand_not_found');
	}
	const origins = allowedOrigins(store, additionalOrigins);
	if (!Array.isArray(origins)) {
		return origins;
	}
	store.allowedOrigins = origins;
	if (brandId !== undefined) {
		store.brandId = brandId;
	}
	return connectionOf(store, context.state);
};

/** The origins the publishable key takes with `additionalOrigins` besides
 *  the site's own, or the refusal; as they are when none are given. */
function allowedOrigins(
	store: Store,
	additionalOrigins: string[] | undefined,
): string[] | HandlerResult {
	if (additionalOrigins === undefined) {
		return store.allowedOrigins;
	}
	const refused = additionalOrigins.find(
		(origin) => !onSiteDomain(store.siteUrl, origin),
	);
	if (refused !== undefined) {
		return { status: 400, body: { error: 'invalid_origin', origin: refused } };
	}
	const own = new URL(store.siteUrl).origin;
	const added = [...new Set(additionalOrigins)].filter(
		(origin) => origin !== own,
	);
	if (added.length > MAX_ADDITIONAL_ORIGINS) {
		return {
			status: 400,
			body: { error: 'too_many_origins', max: MAX_ADDITIONAL_ORIGINS },
		};
	}
	return [own, ...added];
}

export const disconnect: Handler = (context) => {
	revoke(context.state, callerStore(context));
	return { status: 204 };
};
