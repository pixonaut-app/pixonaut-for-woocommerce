import type { Caller } from '../auth';
import type { Clock } from '../clock';
import type { Answer } from '../idempotency';
import type { Contract } from '../spec';
import type { State } from '../state';
import type { Webhooks } from '../webhooks/deliveries';

export type HandlerContext = {
	state: State;
	clock: Clock;
	contract: Contract;
	/** Sends to the stores' webhook endpoints. */
	webhooks: Webhooks;
	/** Null only for an operation that takes no credential. */
	caller: Caller | null;
	params: Record<string, string>;
	/** The fake's own origin, which the links it answers with point at. */
	origin: string;
	/** Where a browser reaches the fake: what a keyless image link (`/i/`)
	 *  and a page the store sends the shopper to (a hosted designer) point
	 *  at, which a server may hand on to a browser. */
	publicOrigin: string;
	query: URLSearchParams;
	headers: Headers;
	/** Parsed JSON; a file's bytes for an image; else the raw text. */
	body: unknown;
};

export type HandlerResult = {
	status: number;
	/** JSON unless `contentType` says otherwise; absent for no body. */
	body?: unknown;
	contentType?: string;
	headers?: Record<string, string>;
	/** For an idempotent create: re-reads what it made, for a replay. */
	replay?: Answer['replay'];
};

export type Handler = (
	context: HandlerContext,
) => HandlerResult | Promise<HandlerResult>;

/** The caller of an operation that requires a credential. */
export function callerOf(context: HandlerContext): Caller {
	if (context.caller === null) {
		throw new Error(
			'This operation is served only to an authenticated caller.',
		);
	}
	return context.caller;
}

export function refusal(status: number, error: string): HandlerResult {
	return { status, body: { error } };
}

/** `400 invalid_input`, which Pixonaut always sends with a `reason` naming
 *  the field and what is wrong with it. */
export function invalidInput(reason: string): HandlerResult {
	return { status: 400, body: { error: 'invalid_input', reason } };
}
