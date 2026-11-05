import type { Fake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { findDesign } from '../../fake-pixonaut/src/handlers/designs-store';
import {
	type PrintStatus,
	printDesigns,
	recordPrint,
} from '../../fake-pixonaut/src/handlers/prints';

/** The main store's site in these tests; its origin is the one its
 *  publishable key accepts. */
export const SITE_URL = 'http://shop.test';
export const SITE_ORIGIN = new URL(SITE_URL).origin;

/** An origin no fixture store allows. */
export const FOREIGN_ORIGIN = 'http://elsewhere.test';

/** The fake's own address in `app.request`; its origin stands in for
 *  Pixonaut's hosted pages. */
const FAKE_BASE = 'http://fake.test';

export const MAIN = FIXTURE.store.main;

export function bearer(token: string): Record<string, string> {
	return { authorization: `Bearer ${token}` };
}

/** Puts a print of the store's approved design `designId` into the fake's
 *  state, as an order naming the design would make it, without ordering it;
 *  `status` makes one still rendering. Answers its id. */
export function seedPrint(
	fake: Fake,
	designId: string,
	{
		storeId = MAIN.id,
		status = 'completed',
	}: { storeId?: string; status?: PrintStatus } = {},
): string {
	const design = findDesign(fake.state, designId, storeId);
	if (design === undefined) {
		throw new Error(`No design ${designId} to print.`);
	}
	const [print] = printDesigns(fake.state, [design], storeId, fake.clock.now());
	if (print === undefined) {
		throw new Error(`Design ${designId} made no print.`);
	}
	if (status !== 'completed') {
		print.status = status;
		print.completedAt = null;
	}
	recordPrint(fake.state, storeId, print);
	return print.id;
}

export type Sent = {
	method?: string;
	headers?: Record<string, string>;
	json?: unknown;
	/** A raw body, sent as given; `json` takes precedence. */
	body?: string | Uint8Array<ArrayBuffer>;
};

/** Sends a request to the fake in process, as a client would over HTTP. */
export function send(
	fake: Fake,
	path: string,
	{ method = 'GET', headers = {}, json, body }: Sent = {},
): Promise<Response> {
	const init: RequestInit = { method, headers: { ...headers }, body };
	if (json !== undefined) {
		init.body = JSON.stringify(json);
		init.headers = { 'content-type': 'application/json', ...headers };
	}
	return Promise.resolve(fake.app.request(`${FAKE_BASE}${path}`, init));
}

export function templatePath(id: string, suffix = ''): string {
	return `/v1/templates/${encodeURIComponent(id)}${suffix}`;
}

/** For a test that makes a fake of its own: the fake compiles the contract's
 *  schemas as it is made, which takes many seconds on a loaded machine. */
export const OWN_FAKE_TIMEOUT_MS = 60_000;
