// The credential an operation takes, as the reference's `security` declares
// it, and the rules Pixonaut applies to each kind.
import type { KeyKind, Operation } from './spec';
import type { ShareLink, State } from './state';

/** Who is calling, once their credential is accepted. */
export type Caller = {
	kind: KeyKind;
	tenantId: string;
	/** The store the key or link belongs to. */
	storeId: string;
	/** The share link, when the caller holds one. */
	link?: ShareLink;
	/** The member a member key acts as. */
};

export type Refusal = {
	status: 401 | 403;
	body: { error: string };
};

export type AuthResult =
	| { ok: true; caller: Caller | null }
	| { ok: false; refusal: Refusal };

const BEARER = /^Bearer\s+(\S+)$/i;

/** What a share link's token is sent behind: the link's own address holds
 *  the bare token, and the SDK adds this to tell it from a key. */
export const LINK_BEARER_PREFIX = 'link_';

const KEY_PREFIXES: Record<string, KeyKind> = {
	ik_: 'integrationKey',
	pk_: 'publishableKey',
	sk_: 'secretKey',
	[LINK_BEARER_PREFIX]: 'shareLink',
};

function refuse(status: Refusal['status'], error: string): AuthResult {
	return { ok: false, refusal: { status, body: { error } } };
}

function keyKind(token: string): KeyKind | undefined {
	const prefix = Object.keys(KEY_PREFIXES).find((p) => token.startsWith(p));
	return prefix === undefined ? undefined : KEY_PREFIXES[prefix];
}

/** The caller `token` names, or null when nothing in the state issued it. */
function callerOf(
	state: State,
	kind: KeyKind,
	token: string,
	now: number,
): Caller | null {
	if (kind === 'shareLink') {
		const link = state.shareLinks.get(token);
		if (link === undefined || link.expiresAt <= now) {
			return null;
		}
		return { kind, tenantId: link.tenantId, storeId: link.storeId, link };
	}
	if (kind === 'secretKey') {
		// No fixture tenant hands out a secret key: the plugin never holds one.
		return null;
	}
	if (kind === 'memberKey') {
		// The plugin never sends a member key.
		return null;
	}
	for (const store of state.stores.values()) {
		if (store[kind] === token) {
			return { kind, tenantId: store.tenantId, storeId: store.id };
		}
	}
	return null;
}

function storeOrigins(state: State, storeId: string): string[] {
	return state.stores.get(storeId)?.allowedOrigins ?? [];
}

/**
 * Accept or refuse the request's credential for `operation`.
 *
 * `selfOrigin` is the fake's own public origin, which stands in for
 * Pixonaut's hosted pages: a share link is taken from there, or from its
 * store's origins.
 */
export function authenticate(
	operation: Operation,
	headers: Headers,
	state: State,
	now: number,
	selfOrigin: string,
): AuthResult {
	if (
		operation.accepts.length === 0 ||
		(operation.keyOptional && !headers.has('authorization'))
	) {
		return { ok: true, caller: null };
	}
	const token = BEARER.exec(headers.get('authorization') ?? '')?.[1];
	const kind = token === undefined ? undefined : keyKind(token);
	if (token === undefined || kind === undefined) {
		return refuse(401, 'unauthorized');
	}
	const caller = callerOf(state, kind, token, now);
	if (caller === null) {
		return refuse(401, 'unauthorized');
	}
	if (!operation.accepts.includes(kind)) {
		const secretOnly =
			operation.accepts.length === 1 && operation.accepts[0] === 'secretKey';
		return refuse(403, secretOnly ? 'secret_key_required' : 'forbidden');
	}
	const origin = headers.get('origin');
	if (kind === 'publishableKey') {
		// A publishable key sits in a browser: it is taken only from the
		// origins its tenant allows, so a request with no origin is refused.
		if (
			origin === null ||
			!storeOrigins(state, caller.storeId).includes(origin)
		) {
			return refuse(403, 'origin_forbidden');
		}
	}
	if (kind === 'shareLink' && origin !== null) {
		const allowed = [selfOrigin, ...storeOrigins(state, caller.storeId)];
		if (!allowed.includes(origin)) {
			return refuse(403, 'origin_forbidden');
		}
	}
	return { ok: true, caller };
}

/** Whether `caller` may act on `subject`: a share link reaches only its own
 *  template or design, any other answered as not found. */
export function reaches(
	caller: Caller,
	subject: { kind: 'template' | 'design'; id: string },
): boolean {
	if (caller.link === undefined) {
		return true;
	}
	return (
		caller.link.subject.kind === subject.kind &&
		caller.link.subject.id === subject.id
	);
}
