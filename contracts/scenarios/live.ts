// A Pixonaut running on this machine as a target, named in the environment
// (`bin/contracts/live.mjs`). Never a shared or production Pixonaut.
import { describeReport, type Report, runScenarios } from './run';
import type { Target } from './session';

export type LiveConfig = {
	apiUrl: string;
	tenant: string;
	member: { email: string; password: string };
};

/** What names the Pixonaut, in the order a run lists what it lacks. */
const LIVE_ENV = {
	apiUrl: 'PIXONAUT_LIVE_API_URL',
	tenant: 'PIXONAUT_LIVE_TENANT',
	email: 'PIXONAUT_LIVE_EMAIL',
	password: 'PIXONAUT_LIVE_PASSWORD',
} as const;

const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

/** The target the environment names, or what it lacks to name one. A host
 *  off this machine is refused: scenarios create and order for real. */
export function liveConfig(
	env: Record<string, string | undefined>,
): LiveConfig | { missing: string[] } {
	const missing = Object.values(LIVE_ENV).filter((name) => !env[name]);
	if (missing.length > 0) {
		return { missing };
	}
	const apiUrl = env[LIVE_ENV.apiUrl] as string;
	if (!LOOPBACK_HOSTS.has(new URL(apiUrl).hostname)) {
		throw new Error(
			`${LIVE_ENV.apiUrl}: only a Pixonaut on this machine may be used, not ${apiUrl}`,
		);
	}
	return {
		apiUrl,
		tenant: env[LIVE_ENV.tenant] as string,
		member: {
			email: env[LIVE_ENV.email] as string,
			password: env[LIVE_ENV.password] as string,
		},
	};
}

/** The store's site. Pixonaut never calls it but for the endpoint ping,
 *  which `.invalid` answers by failing. */
const SITE_URL = 'http://shop.invalid';

/** better-auth's session cookie carries this in its name. */
const SESSION_COOKIE_MARK = 'session_token';

const MS_PER_SECOND = 1000;
/** How long one request, its body included, may take. A proof or a print
 *  file renders within a few seconds; a stalled answer fails the run rather
 *  than holding it, the API and Postgres up for good. */
const REQUEST_TIMEOUT_MS = 60 * MS_PER_SECOND;

/** Statuses a `Response` may not be built with a body for. */
const NULL_BODY_STATUSES = new Set([101, 204, 205, 304]);

export function liveTarget({ apiUrl, tenant, member }: LiveConfig): Target {
	// The body is read here, under the same timeout, so a stall names the
	// request whichever part of it stalls.
	const fetchFromApi = async (path: string, init: RequestInit) => {
		try {
			const response = await fetch(new URL(path, apiUrl), {
				...init,
				redirect: 'manual',
				signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
			});
			const body = await response.arrayBuffer();
			return new Response(
				NULL_BODY_STATUSES.has(response.status) ? null : body,
				{
					status: response.status,
					statusText: response.statusText,
					headers: response.headers,
				},
			);
		} catch (error) {
			throw new Error(
				`${init.method ?? 'GET'} ${path}: ${error instanceof Error ? error.message : error}`,
			);
		}
	};
	return {
		name: 'live',
		fetch: fetchFromApi,
		siteUrl: SITE_URL,
		async connectCode(codeChallenge, redirectUri) {
			// The studio's consent screen, as the member who approves it.
			const signIn = await fetchFromApi('/api/auth/sign-in/email', {
				method: 'POST',
				headers: {
					'content-type': 'application/json',
					origin: new URL(apiUrl).origin,
				},
				body: JSON.stringify(member),
			});
			const cookie = signIn.headers
				.getSetCookie()
				.map((line) => line.split(';')[0] ?? '')
				.find((line) => line.includes(SESSION_COOKIE_MARK));
			if (!signIn.ok || cookie === undefined) {
				throw new Error(
					`signing in as the member answered ${signIn.status}: ${await signIn.text()}`,
				);
			}
			const approved = await fetchFromApi('/v1/connect/codes', {
				method: 'POST',
				headers: {
					'content-type': 'application/json',
					cookie,
					'x-tenant-id': tenant,
				},
				body: JSON.stringify({
					client: 'woocommerce',
					siteUrl: SITE_URL,
					redirectUri,
					codeChallenge,
					codeChallengeMethod: 'S256',
				}),
			});
			const body = await approved.json();
			if (!approved.ok) {
				throw new Error(
					`approving the connection answered ${approved.status}: ${JSON.stringify(body)}`,
				);
			}
			return body.code;
		},
	};
}

/** Runs the scenarios against the Pixonaut `config` names. */
export async function runLive(
	config: LiveConfig,
): Promise<{ report: Report; lines: string[] }> {
	const report = await runScenarios(liveTarget(config));
	return { report, lines: describeReport(report) };
}
