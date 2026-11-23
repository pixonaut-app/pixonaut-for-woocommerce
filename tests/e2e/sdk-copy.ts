import type { Route } from '@playwright/test';

/** A file of the pinned SDK as the CDN answered it. */
type SdkFile = {
	status: number;
	headers: Record<string, string>;
	body: Buffer;
};

/** Tries per file before a test sees the CDN's failure. */
const FETCH_ATTEMPTS = 4;

/** Wait before the first retry, doubled for each after it. */
const FIRST_RETRY_DELAY_MS = 500;

/** One try's limit: the wasm is a few megabytes, and a stalled connection
 *  should give way to a fresh one well before a test's 5 s expectations. */
const ATTEMPT_TIMEOUT_MS = 15_000;

/** Lowest HTTP status the CDN answers when it may do better on a retry. */
const FIRST_SERVER_ERROR = 500;

/** Headers that describe the transfer rather than the file: fetch has already
 *  decoded the body, so the browser must not see them again. */
const TRANSFER_HEADERS = new Set([
	'content-encoding',
	'content-length',
	'transfer-encoding',
	'connection',
	'keep-alive',
]);

/** Each SDK file fetched once per worker, by URL. */
const copies = new Map<string, Promise<SdkFile>>();

function sleep(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

async function fetchOnce(url: string): Promise<SdkFile> {
	const response = await fetch(url, {
		signal: AbortSignal.timeout(ATTEMPT_TIMEOUT_MS),
	});
	if (response.status >= FIRST_SERVER_ERROR) {
		throw new Error(`${url} answered ${response.status}`);
	}
	const headers: Record<string, string> = {};
	response.headers.forEach((value, name) => {
		if (!TRANSFER_HEADERS.has(name)) {
			headers[name] = value;
		}
	});
	return {
		status: response.status,
		headers,
		body: Buffer.from(await response.arrayBuffer()),
	};
}

async function fetchWithRetries(url: string): Promise<SdkFile> {
	let failure: unknown;
	for (let attempt = 0; attempt < FETCH_ATTEMPTS; attempt++) {
		if (attempt > 0) {
			await sleep(FIRST_RETRY_DELAY_MS * 2 ** (attempt - 1));
		}
		try {
			return await fetchOnce(url);
		} catch (error) {
			failure = error;
		}
	}
	throw failure;
}

function copyOf(url: string): Promise<SdkFile> {
	let copy = copies.get(url);
	if (!copy) {
		copy = fetchWithRetries(url);
		// A file that never arrived is tried afresh by the next request.
		copy.catch(() => copies.delete(url));
		copies.set(url, copy);
	}
	return copy;
}

/**
 * Answers a request for a pinned SDK file from this worker's copy of it.
 *
 * Every test loads the same few files from Pixonaut's CDN. Fetched once, with
 * retries, a dropped or stalled connection to the CDN no longer fails
 * whichever test happened to ask for the file at that moment.
 */
export async function fulfilFromSdkCopy(route: Route): Promise<void> {
	const request = route.request();
	if (request.method() !== 'GET') {
		return route.fallback();
	}
	let file: SdkFile;
	try {
		file = await copyOf(request.url());
	} catch {
		// Let the browser try the CDN itself and meet its failure there.
		return route.fallback();
	}
	return route.fulfill(file);
}
