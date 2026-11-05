// The studio's consent screen, `GET /connect`: the fake applies the
// parameter rules of Pixonaut's "Connecting a store" guide and answers as a
// member would, by the state's `consent`, at once. A request that breaks a
// rule is a contract violation, like one to the API.

import { randomBytes } from 'node:crypto';
import type { Hono } from 'hono';
import type { Fake } from './app';
import type { Environment, State } from './state';
import type { Violation } from './validate';

export const CONNECT_PATH = '/connect';

/** How long a code may wait for its exchange. */
export const CONNECT_CODE_LIFETIME_MS = 60_000;

/** An S256 challenge: base64url of a SHA-256, 43 characters. */
const S256_CHALLENGE = /^[A-Za-z0-9_-]{43}$/;

const CLIENT = 'woocommerce';

const ENVIRONMENTS: readonly Environment[] = ['live', 'sandbox'];

/** Random bytes in a code, written as hex. */
const CODE_BYTES = 12;

const OPERATION = `GET ${CONNECT_PATH} (studio)`;

/** `value` as a URL a site can have: http or https, no query or fragment. */
function siteAddress(value: string | null): URL | null {
	if (value === null || !URL.canParse(value)) {
		return null;
	}
	const url = new URL(value);
	const web = url.protocol === 'http:' || url.protocol === 'https:';
	return web && url.search === '' && url.hash === '' ? url : null;
}

/** Whether `value` is a URL on `origin`. */
function onOrigin(value: string | null, origin: string): value is string {
	return (
		value !== null && URL.canParse(value) && new URL(value).origin === origin
	);
}

/** The rules the request breaks, as messages. */
function check(query: URLSearchParams): string[] {
	const broken: string[] = [];
	if (query.get('client') !== CLIENT) {
		broken.push(`client is not ${CLIENT}`);
	}
	const site = siteAddress(query.get('site_url'));
	if (site === null) {
		broken.push('site_url is not an http(s) address without query or fragment');
	} else {
		if (!onOrigin(query.get('redirect_uri'), site.origin)) {
			broken.push('redirect_uri is not on the origin of site_url');
		}
		const restUrl = query.get('rest_url');
		if (restUrl !== null && !onOrigin(restUrl, site.origin)) {
			broken.push('rest_url is not on the origin of site_url');
		}
	}
	if ((query.get('state') ?? '') === '') {
		broken.push('state is missing');
	}
	if (!S256_CHALLENGE.test(query.get('code_challenge') ?? '')) {
		broken.push('code_challenge is not an S256 challenge');
	}
	const method = query.get('code_challenge_method');
	if (method !== null && method !== 'S256') {
		broken.push('code_challenge_method is not S256');
	}
	const environment = query.get('environment');
	if (
		environment !== null &&
		!ENVIRONMENTS.includes(environment as Environment)
	) {
		broken.push('environment is neither live nor sandbox');
	}
	return broken;
}

/** The fixture tenant of a side, which a store connects to. */
function sideTenant(state: State, environment: Environment): string {
	const tenant = [...state.tenants.values()].find(
		(t) => t.environment === environment,
	);
	if (tenant === undefined) {
		throw new Error(`No fixture tenant has a ${environment} side.`);
	}
	return tenant.id;
}

/** The workspace of the store connected at `siteUrl`, which a member is
 *  linked to; undefined when none is. */
export function registerStudio(
	app: Hono,
	fake: Fake,
	record: (found: Violation[]) => void,
): void {
	app.get(CONNECT_PATH, (c) => {
		const query = new URL(c.req.url).searchParams;
		const broken = check(query);
		if (broken.length > 0) {
			record(
				broken.map((message) => ({
					operation: OPERATION,
					direction: 'request',
					at: 'query',
					message,
				})),
			);
			return c.text(
				`The connect request is not valid: ${broken.join('; ')}.`,
				400,
			);
		}

		const { state } = fake;
		const back = new URL(query.get('redirect_uri') ?? '');
		back.searchParams.set('state', query.get('state') ?? '');
		if (state.consent.decision === 'deny') {
			back.searchParams.set('error', 'access_denied');
			return c.redirect(back.toString(), 302);
		}

		const siteUrl = query.get('site_url') ?? '';
		const tenantId = sideTenant(
			state,
			(query.get('environment') as Environment | null) ??
				state.consent.environment,
		);
		const code = `c_${randomBytes(CODE_BYTES).toString('hex')}`;
		state.connectCodes.set(code, {
			code,
			member: state.consent.member,
			tenantId,
			siteUrl,
			siteName: query.get('site_name') ?? undefined,
			redirectUri: query.get('redirect_uri') ?? '',
			restUrl: query.get('rest_url') ?? undefined,
			codeChallenge: query.get('code_challenge') ?? '',
			expiresAt: fake.clock.now() + CONNECT_CODE_LIFETIME_MS,
		});
		back.searchParams.set('code', code);
		return c.redirect(back.toString(), 302);
	});
}
