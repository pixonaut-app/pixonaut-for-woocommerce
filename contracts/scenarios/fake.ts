// The fake Pixonaut as a target, in process, as the scenarios run in
// `pnpm test:js`.
import { createFake, type Fake } from '../../tests/fake-pixonaut/src/app';
import { CONNECT_PATH } from '../../tests/fake-pixonaut/src/studio';
import type { Target } from './session';

/** The fake's own address in `app.request`. */
const FAKE_BASE = 'http://fake.test';

/** The store's site; the fake allows any origin a store connects from. */
const SITE_URL = 'http://shop.test';

export function fakeTarget(fake: Fake = createFake()): Target & { fake: Fake } {
	const fetch = async (path: string, init: RequestInit) =>
		fake.app.request(`${FAKE_BASE}${path}`, init);
	return {
		name: 'fake',
		fake,
		fetch,
		siteUrl: SITE_URL,
		async connectCode(codeChallenge, redirectUri) {
			const query = new URLSearchParams({
				client: 'woocommerce',
				site_url: SITE_URL,
				redirect_uri: redirectUri,
				state: 'scenario',
				code_challenge: codeChallenge,
				code_challenge_method: 'S256',
			});
			const response = await fetch(`${CONNECT_PATH}?${query}`, {});
			const location = response.headers.get('location');
			const code =
				location === null ? null : new URL(location).searchParams.get('code');
			if (code === null) {
				throw new Error(`the consent screen answered ${response.status}`);
			}
			return code;
		},
		holds: {
			async holdNextOrder() {
				await control(fetch, '/__fake/orders/hold', {});
			},
			async release(orderId, releasedBy) {
				await control(fetch, '/__fake/orders/release', {
					orderId,
					releasedBy,
				});
			},
		},
	};
}

/** Sends a control request to the fake; a refusal fails the scenario. */
async function control(
	fetch: Target['fetch'],
	path: string,
	json: unknown,
): Promise<void> {
	const response = await fetch(path, {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify(json),
	});
	if (!response.ok) {
		throw new Error(`${path} answered ${response.status}`);
	}
}
