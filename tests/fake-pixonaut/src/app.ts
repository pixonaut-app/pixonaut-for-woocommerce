// The fake Pixonaut: every request is matched to an operation of the pinned
// reference, validated, authenticated by the operation's own `security`, and
// answered by the operation's handler; every answer is validated before it
// leaves. A contract violation on either side is logged and answered `599`,
// so the test that caused it fails instead of reading an ordinary refusal.
import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { authenticate } from './auth';
import { type Clock, createClock } from './clock';
import { registerControl } from './control';
import { registerAreasControl } from './control-areas';
import { registerCatalogueImportControl } from './control-catalogue-imports';
import { registerDesignerPixelControl } from './control-designer-pixel';
import { registerTemplateBackgroundControl } from './control-template-background';
import { registerTemplateFieldKeyControl } from './control-template-field-key';
import { registerTemplateRatioControl } from './control-template-ratio';
import { registerValueLooksControl } from './control-value-looks';
import { registerCorrectionPage } from './correction-page';
import { registerHostedDesigner } from './designer';
import { createFaults, DEFAULT_TIMEOUT_FAULT_MS, type Faults } from './faults';
import { DEFAULT_FIXTURE_SET, FIXTURE_SETS } from './fixtures';
import { HANDLERS, LINKED, SERVED_WEBHOOKS } from './handlers';
import type { Handler, HandlerResult } from './handlers/types';
import {
	createIdempotencyStore,
	IDEMPOTENCY_IN_USE_RETRY_AFTER_S,
	IDEMPOTENCY_KEY_HEADER,
	IDEMPOTENT_REPLAYED_HEADER,
	type IdempotencyStore,
	RETRY_AFTER_HEADER,
} from './idempotency';
import { registerProofPages } from './proof-pages';
import { registerProofControl } from './proofs';
import {
	type Contract,
	loadContract,
	type Operation,
	type OperationKey,
} from './spec';
import type { State } from './state';
import { registerStudio } from './studio';
import { createValidator, type Validator, type Violation } from './validate';
import { registerCatalogueEvents } from './webhooks/catalogue-events';
import { registerWebhookControl } from './webhooks/control';
import { createWebhooks, type Webhooks } from './webhooks/deliveries';
import { registerDesignEvents } from './webhooks/design-events';
import { registerHoldOrder } from './webhooks/hold-order';
import { registerMoveOrder } from './webhooks/move-order';
import { registerShareLinkEvents } from './webhooks/share-link-events';

/** Answered for a contract violation: outside every status Pixonaut uses, so
 *  no client mistakes it for a refusal it handles. */
export const VIOLATION_STATUS = 599;
/** Answered for an operation the fake does not serve. */
export const NOT_SERVED_STATUS = 501;
/** Answered when the fake itself throws: logged as a violation as well, so
 *  a fake that breaks fails the test instead of passing it quietly. */
export const FAKE_FAILED_STATUS = 500;

/** Answered once a `timeout` fault has held a request: what a gateway in
 *  front of Pixonaut sends when it gives up. */
const TIMED_OUT_STATUS = 504;

const JSON_CONTENT_TYPE = 'application/json';

/** A body of this type is a file (an upload's bytes), read as bytes. */
const FILE_MEDIA_TYPE = /^image\//i;

/** Request headers a browser may send cross-origin, as Pixonaut lists them. */
const CORS_ALLOW_HEADERS = [
	'authorization',
	'content-type',
	'x-pixonaut-session',
	'x-pixonaut-sdk-version',
	IDEMPOTENCY_KEY_HEADER,
];
/** Response headers a browser may read, as Pixonaut lists them. */
const CORS_EXPOSE_HEADERS = [
	'x-original-width',
	'x-original-height',
	IDEMPOTENT_REPLAYED_HEADER,
	RETRY_AFTER_HEADER,
];

export type LoggedRequest = {
	at: number;
	method: string;
	path: string;
	query: string;
	operation: string | null;
	status: number;
	idempotencyKey?: string;
	replayed?: boolean;
};

export type Fake = {
	app: Hono;
	contract: Contract;
	clock: Clock;
	faults: Faults;
	idempotency: IdempotencyStore;
	webhooks: Webhooks;
	readonly state: State;
	requests: LoggedRequest[];
	violations: Violation[];
	reset(fixture?: string, siteUrl?: string): void;
};

export type FakeOptions = {
	contract?: Contract;
	realNow?: () => number;
	/** Handlers over the fake's own, for a test that scripts an operation. */
	handlers?: Partial<Record<OperationKey, Handler>>;
	/** The origin a browser reaches the fake at, for the keyless image links
	 *  and hosted designer pages it answers with, when a server reaches it at
	 *  another (WordPress inside Docker); by default each request's origin. */
	publicOrigin?: string;
};

type ParsedBody = { hasBody: boolean; body: unknown; parseError?: string };

async function parseBody(request: Request): Promise<ParsedBody> {
	const type = request.headers.get('content-type') ?? '';
	if (FILE_MEDIA_TYPE.test(type)) {
		const bytes = new Uint8Array(await request.arrayBuffer());
		return { hasBody: bytes.length > 0, body: bytes };
	}
	const text = await request.text();
	if (text === '') {
		return { hasBody: false, body: undefined };
	}
	if (!type.toLowerCase().includes('json')) {
		return { hasBody: true, body: text };
	}
	try {
		return { hasBody: true, body: JSON.parse(text) };
	} catch (error) {
		return { hasBody: true, body: text, parseError: String(error) };
	}
}

/** `value` with every object's keys sorted, at any depth; arrays keep their
 *  order. Pixonaut reads a body by meaning, not by how its keys are ordered. */
function canonical(value: unknown): unknown {
	if (Array.isArray(value)) {
		return value.map(canonical);
	}
	if (value !== null && typeof value === 'object') {
		return Object.fromEntries(
			Object.keys(value)
				.sort()
				.map((key) => [
					key,
					canonical((value as Record<string, unknown>)[key]),
				]),
		);
	}
	return value;
}

/** What makes two requests under one `Idempotency-Key` the same request. */
function fingerprint(
	operation: Operation,
	params: Record<string, string>,
	query: URLSearchParams,
	body: unknown,
): string {
	return JSON.stringify([
		operation.key,
		canonical(params),
		[...query],
		canonical(body),
	]);
}

export function createFake(options: FakeOptions = {}): Fake {
	const contract = options.contract ?? loadContract();
	const validator: Validator = createValidator(contract);
	const consumed = new Set<string>(contract.consumed.operations);
	const handlers = { ...HANDLERS, ...options.handlers };
	validator.warm(
		[...consumed].flatMap((key) => {
			const operation = contract.operations.get(key as Operation['key']);
			return operation === undefined ? [] : [operation];
		}),
		SERVED_WEBHOOKS,
	);

	const clock = createClock(options.realNow);
	const faults = createFaults();
	const idempotency = createIdempotencyStore();
	const requests: LoggedRequest[] = [];
	const violations: Violation[] = [];
	let state = FIXTURE_SETS[DEFAULT_FIXTURE_SET]({ now: clock.now() });
	// What the fake sends is held to the reference like what it answers.
	const webhooks = createWebhooks(
		() => state,
		clock,
		(envelope) =>
			validator.webhook(envelope.type, envelope).map((found) => found.message),
	);

	const fake: Fake = {
		app: new Hono(),
		contract,
		clock,
		faults,
		idempotency,
		webhooks,
		get state() {
			return state;
		},
		requests,
		violations,
		reset(fixture = DEFAULT_FIXTURE_SET, siteUrl) {
			const build = FIXTURE_SETS[fixture];
			if (build === undefined) {
				throw new Error(`No fixture set named ${fixture}.`);
			}
			clock.reset();
			faults.clear();
			idempotency.reset();
			webhooks.reset();
			requests.length = 0;
			violations.length = 0;
			state = build({ siteUrl, now: clock.now() });
		},
	};
	const { app } = fake;

	// Pixonaut reflects any origin on /v1 and leaves the key to decide;
	// catalogue images and font files are open to every page.
	app.use(
		'/v1/*',
		cors({
			origin: (origin) => origin,
			allowHeaders: CORS_ALLOW_HEADERS,
			exposeHeaders: CORS_EXPOSE_HEADERS,
		}),
	);
	app.use('/i/*', cors({ origin: '*' }));
	app.use('/f/*', cors({ origin: '*' }));
	// A proof's image and an upload's target, as Pixonaut opens them to the
	// page that was handed the link.
	app.use('/p/*', cors({ origin: (origin) => origin }));
	app.use(
		'/u/*',
		cors({ origin: (origin) => origin, allowHeaders: ['content-type'] }),
	);

	registerControl(app, fake);
	registerAreasControl(app, fake);
	registerCatalogueImportControl(app, fake);
	registerDesignerPixelControl(app, fake);
	registerTemplateRatioControl(app, fake);
	registerValueLooksControl(app, fake);
	registerTemplateBackgroundControl(app, fake);
	registerTemplateFieldKeyControl(app, fake);
	registerWebhookControl(app, fake);
	registerMoveOrder(app, fake);
	registerHoldOrder(app, fake);
	registerDesignEvents(app, fake);
	registerCatalogueEvents(app, fake);
	registerShareLinkEvents(app, fake);
	registerProofControl(app, fake);
	registerProofPages(app, fake);

	/** Logs `found`, to the violation log and to the console. */
	function record(found: Violation[]): void {
		violations.push(...found);
		for (const violation of found) {
			console.error(
				`[fake-pixonaut] ${violation.direction} violation in ${violation.operation} at ${violation.at}: ${violation.message}`,
			);
		}
	}

	registerStudio(app, fake, record);
	registerHostedDesigner(app, fake);
	registerCorrectionPage(app, fake);

	function violated(found: Violation[], status = VIOLATION_STATUS): Response {
		record(found);
		return Response.json(
			{ error: 'contract_violation', violations: found },
			{ status },
		);
	}

	function answer(result: HandlerResult): Response {
		const headers = new Headers(result.headers);
		if (result.body === undefined) {
			return new Response(null, { status: result.status, headers });
		}
		const contentType = result.contentType ?? JSON_CONTENT_TYPE;
		headers.set('content-type', contentType);
		const payload =
			contentType === JSON_CONTENT_TYPE
				? JSON.stringify(result.body)
				: (result.body as BodyInit);
		return new Response(payload, { status: result.status, headers });
	}

	app.all('*', async (c) => {
		const url = new URL(c.req.url);
		const entry: LoggedRequest = {
			at: clock.now(),
			method: c.req.method,
			path: url.pathname,
			query: url.search,
			operation: null,
			status: 0,
		};
		requests.push(entry);
		const respond = (response: Response): Response => {
			entry.status = response.status;
			return response;
		};
		try {
			return await serve(c.req.raw, url, entry, respond);
		} catch (error) {
			return respond(
				violated(
					[
						{
							operation: entry.operation ?? `${c.req.method} ${url.pathname}`,
							direction: 'response',
							at: 'handler',
							message: `the fake failed: ${String(error)}`,
						},
					],
					FAKE_FAILED_STATUS,
				),
			);
		}
	});

	/** Matches, validates, authenticates and answers one request. */
	async function serve(
		request: Request,
		url: URL,
		entry: LoggedRequest,
		respond: (response: Response) => Response,
	): Promise<Response> {
		let matched: ReturnType<Contract['match']>;
		try {
			matched = contract.match(request.method, url.pathname);
		} catch (error) {
			if (!(error instanceof URIError)) {
				throw error;
			}
			return respond(
				violated([
					{
						operation: `${request.method} ${url.pathname}`,
						direction: 'request',
						at: 'path',
						message: 'the path has a malformed percent-escape',
					},
				]),
			);
		}
		if (matched === null) {
			return respond(
				violated([
					{
						operation: `${request.method} ${url.pathname}`,
						direction: 'request',
						at: 'path',
						message: 'no operation in the reference has this method and path',
					},
				]),
			);
		}
		const { operation, params } = matched;
		entry.operation = operation.key;

		const linked = LINKED[operation.key];
		if (!consumed.has(operation.key) && linked === undefined) {
			record([
				{
					operation: operation.key,
					direction: 'request',
					at: 'operation',
					message: 'the operation is not in consumed.json',
				},
			]);
			return respond(
				Response.json({ error: 'not_consumed' }, { status: NOT_SERVED_STATUS }),
			);
		}
		const handler = linked ?? handlers[operation.key];
		if (handler === undefined) {
			return respond(
				Response.json({ error: 'not_served' }, { status: NOT_SERVED_STATUS }),
			);
		}

		const parsed = await parseBody(request);
		// A link's request is the token the fake minted and, for an upload, the
		// file, which the reference does not declare as a body: only what the
		// fake answers through one is held to it.
		const requestViolations =
			linked === undefined
				? validator.request(operation, {
						params,
						query: url.searchParams,
						headers: request.headers,
						contentType: request.headers.get('content-type'),
						body: parsed.body,
						hasBody: parsed.hasBody,
					})
				: [];
		if (parsed.parseError !== undefined) {
			requestViolations.push({
				operation: operation.key,
				direction: 'request',
				at: 'body',
				message: `body is not JSON: ${parsed.parseError}`,
			});
		}
		if (requestViolations.length > 0) {
			return respond(violated(requestViolations));
		}

		// Every answer from here on is checked against the reference.
		const send = (result: HandlerResult): Response => {
			const found = validator.response(operation, {
				status: result.status,
				contentType:
					result.body === undefined
						? null
						: (result.contentType ?? JSON_CONTENT_TYPE),
				body: result.body,
			});
			return respond(found.length > 0 ? violated(found) : answer(result));
		};

		const auth = authenticate(
			operation,
			request.headers,
			state,
			clock.now(),
			url.origin,
		);
		if (!auth.ok) {
			return send(auth.refusal);
		}

		// An injected fault is deliberate, so it is not held to the reference.
		const fault = faults.take(operation.key);
		if (fault?.kind === 'timeout') {
			await new Promise((resolve) =>
				setTimeout(resolve, fault.ms ?? DEFAULT_TIMEOUT_FAULT_MS),
			);
			return respond(new Response(null, { status: TIMED_OUT_STATUS }));
		}
		if (fault?.kind === 'status') {
			return respond(
				answer({
					status: fault.status,
					body:
						fault.error === undefined
							? undefined
							: { ...fault.fields, error: fault.error },
					headers: fault.headers,
				}),
			);
		}

		const context = {
			state,
			clock,
			contract,
			webhooks,
			caller: auth.caller,
			params,
			origin: url.origin,
			publicOrigin: options.publicOrigin ?? url.origin,
			query: url.searchParams,
			headers: request.headers,
			body: parsed.body,
		};
		const key = request.headers.get(IDEMPOTENCY_KEY_HEADER) ?? undefined;
		const { caller } = auth;
		const takesKey = (operation.definition.parameters ?? []).some(
			(p) =>
				p.in === 'header' && p.name.toLowerCase() === IDEMPOTENCY_KEY_HEADER,
		);
		if (key === undefined || caller === null || !takesKey) {
			return send(await handler(context));
		}

		entry.idempotencyKey = key;
		const claim = idempotency.claim(
			caller.storeId,
			key,
			fingerprint(operation, params, url.searchParams, parsed.body),
			clock.now(),
		);
		if (claim.kind === 'refused') {
			return send({
				status: claim.status,
				body: { error: claim.error },
				headers:
					claim.status === 409
						? { [RETRY_AFTER_HEADER]: String(IDEMPOTENCY_IN_USE_RETRY_AFTER_S) }
						: undefined,
			});
		}
		if (claim.kind === 'replay') {
			entry.replayed = true;
			return send({
				status: claim.status,
				body: claim.body,
				headers: { [IDEMPOTENT_REPLAYED_HEADER]: 'true' },
			});
		}
		try {
			const result = await handler(context);
			const succeeded = result.status >= 200 && result.status < 300;
			if (succeeded) {
				claim.settle({
					status: result.status,
					body: result.body,
					replay: result.replay,
				});
			} else {
				// A refused request made nothing, so its key may be sent again.
				claim.release();
			}
			return send(result);
		} catch (error) {
			claim.release();
			throw error;
		}
	}

	return fake;
}
