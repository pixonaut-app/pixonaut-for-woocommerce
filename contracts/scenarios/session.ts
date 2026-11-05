// One run of the scenarios against a target: every request a scenario sends
// and every answer it gets is held to the pinned reference, schema only. A
// value is never compared: the fake and a live Pixonaut hold different data.
import {
	type Contract,
	loadContract,
	type Operation,
	type OperationKey,
} from '../../tests/fake-pixonaut/src/spec';
import {
	createValidator,
	type Validator,
	type Violation,
} from '../../tests/fake-pixonaut/src/validate';

/** The key kinds the plugin and the SDK call Pixonaut with. */
export type KeyKind = 'integrationKey' | 'publishableKey';

/** What `POST /v1/connect/token` gave the store. */
export type StoreKeys = {
	integrationKey: string;
	publishableKey: string;
	webhookEndpointId: string;
};

export type Target = {
	name: 'fake' | 'live';
	/** Sends one request to Pixonaut; `path` holds the path and query. */
	fetch(path: string, init: RequestInit): Promise<Response>;
	/** The store's site. Its origin is the one its publishable key accepts. */
	siteUrl: string;
	/**
	 * A connect code for the store at `siteUrl`, as a member's consent in
	 * the studio hands it to the store's `redirectUri`.
	 */
	connectCode(codeChallenge: string, redirectUri: string): Promise<string>;
	/**
	 * Holds and releases orders as Pixonaut does for a free workspace past
	 * its monthly order limit; absent for a target whose workspace cannot be
	 * put there.
	 */
	holds?: Holds;
};

export type Holds = {
	/** The workspace's next order is taken and held. */
	holdNextOrder(): Promise<void>;
	/** Releases a held order, as an upgrade or the month's reset does. */
	release(
		orderId: string,
		releasedBy: 'upgrade' | 'month_reset',
	): Promise<void>;
};

export type Call = {
	params?: Record<string, string>;
	query?: Record<string, string>;
	json?: unknown;
	/** The key sent; the operation's only one in `consumed.json` by default,
	 *  none when it lists none. */
	key?: KeyKind | null;
	/** A correction link's token, sent in place of a key as the SDK sends
	 *  it: behind `link_`, from the store's origin. */
	link?: string;
	idempotencyKey?: string;
	/** `x-pixonaut-session`, as the SDK sends it. */
	sdkSession?: string;
	/** More headers, such as MCP's `mcp-protocol-version`. */
	headers?: Record<string, string>;
	accept?: string;
	/** Statuses the scenario goes on from; any 2xx by default. */
	expect?: number[];
};

/** A request to a URL Pixonaut handed out, sent as is. */
export type LinkedCall = {
	method: string;
	headers?: Record<string, string>;
	body?: BodyInit;
	/** Statuses the scenario goes on from; any 2xx by default. */
	expect?: number[];
};

export type Answer = {
	status: number;
	contentType: string | null;
	/** Parsed JSON; `null` for an empty or non-JSON body. Read loosely: the
	 *  session has already held it to the reference. */
	// biome-ignore lint/suspicious/noExplicitAny: checked against the reference, not by TypeScript.
	body: any;
	/** The body of a `text/` answer, such as an event stream; empty otherwise. */
	text: string;
};

/** A step answered with a status the scenario cannot go on from. */
export class UnexpectedStatus extends Error {}

const JSON_TYPE = 'application/json';

/** What the SDK sends a share or correction link's token behind. */
const LINK_BEARER_PREFIX = 'link_';

/** Only resolves a linked path; never fetched. */
const LINK_BASE = 'http://link.invalid';

/** Statuses a call goes on from unless it says otherwise: any 2xx. */
const SUCCESS_MIN = 200;
const SUCCESS_MAX = 299;

function isJson(contentType: string | null): boolean {
	const type = contentType?.split(';')[0]?.trim().toLowerCase();
	return type === JSON_TYPE || (type?.endsWith('+json') ?? false);
}

function fillPath(path: string, params: Record<string, string>): string {
	return path.replace(/\{([^}]+)\}/g, (_, name: string) => {
		const value = params[name];
		if (value === undefined) {
			throw new Error(`no value for {${name}} in ${path}`);
		}
		return encodeURIComponent(value);
	});
}

export class Session {
	readonly contract: Contract;
	readonly violations: Violation[] = [];
	/** Every operation sent, whatever it answered. */
	readonly called = new Set<OperationKey>();
	keys: StoreKeys | null = null;
	private readonly validator: Validator;

	constructor(
		readonly target: Target,
		contract: Contract = loadContract(),
	) {
		this.contract = contract;
		this.validator = createValidator(contract);
	}

	get origin(): string {
		return new URL(this.target.siteUrl).origin;
	}

	private keyFor(operation: OperationKey, call: Call): string | null {
		const kinds = this.contract.consumed.keys[operation];
		const listed = kinds === undefined ? [] : [kinds].flat();
		const kind =
			call.key === undefined ? (listed[0] as KeyKind | undefined) : call.key;
		if (kind === undefined || kind === null) {
			return null;
		}
		if (this.keys === null) {
			throw new Error(`${operation} needs the store's keys: connect first`);
		}
		return this.keys[kind];
	}

	/** Sends a consumed operation and returns its answer once it is held
	 *  to the reference. */
	async call(key: OperationKey, call: Call = {}): Promise<Answer> {
		const operation = this.contract.operations.get(key);
		if (operation === undefined) {
			throw new Error(`the pinned reference has no ${key}`);
		}
		if (!this.contract.consumed.operations.includes(key)) {
			throw new Error(`${key} is not in consumed.json`);
		}
		this.called.add(key);
		const params = call.params ?? {};
		const query = new URLSearchParams(call.query ?? {});
		const headers = new Headers({ accept: call.accept ?? JSON_TYPE });
		const token =
			call.link === undefined
				? this.keyFor(key, call)
				: `${LINK_BEARER_PREFIX}${call.link}`;
		if (token !== null) {
			headers.set('authorization', `Bearer ${token}`);
		}
		if (token?.startsWith('pk_') || call.link !== undefined) {
			headers.set('origin', this.origin);
		}
		if (call.idempotencyKey !== undefined) {
			headers.set('idempotency-key', call.idempotencyKey);
		}
		if (call.sdkSession !== undefined) {
			headers.set('x-pixonaut-session', call.sdkSession);
		}
		for (const [name, value] of Object.entries(call.headers ?? {})) {
			headers.set(name, value);
		}
		const hasBody = call.json !== undefined;
		if (hasBody) {
			headers.set('content-type', JSON_TYPE);
		}
		this.violations.push(
			...this.validator.request(operation, {
				params,
				query,
				headers,
				contentType: hasBody ? JSON_TYPE : null,
				body: call.json,
				hasBody,
			}),
		);

		const search = query.size > 0 ? `?${query}` : '';
		const response = await this.target.fetch(
			`${fillPath(operation.path, params)}${search}`,
			{
				method: operation.method.toUpperCase(),
				headers,
				body: hasBody ? JSON.stringify(call.json) : undefined,
			},
		);
		return this.answer(operation, response, call.expect);
	}

	/**
	 * Sends a request to `path`, a URL Pixonaut handed out (an asset's
	 * `uploadUrl`), and holds the answer to the operation the reference
	 * declares for it. Such an operation is linked, not consumed: the plugin
	 * never builds its URL, so `consumed.json` does not list it. The request
	 * is Pixonaut's own URL with a body the reference describes in prose, so
	 * only the answer is checked.
	 */
	async linked(path: string, call: LinkedCall): Promise<Answer> {
		const pathname = new URL(path, LINK_BASE).pathname;
		const matched = this.contract.match(call.method, pathname);
		if (matched === null) {
			throw new Error(
				`the pinned reference has no operation for ${call.method} ${pathname}`,
			);
		}
		const response = await this.target.fetch(path, {
			method: call.method,
			headers: call.headers,
			body: call.body,
		});
		return this.answer(matched.operation, response, call.expect);
	}

	/** Reads an answer, holds it to `operation`, and throws when the
	 *  scenario cannot go on from its status. */
	private async answer(
		operation: Operation,
		response: Response,
		expected: number[] | undefined,
	): Promise<Answer> {
		const contentType = response.headers.get('content-type');
		const raw = new Uint8Array(await response.arrayBuffer());
		let body: unknown = null;
		if (isJson(contentType) && raw.length > 0) {
			body = JSON.parse(new TextDecoder().decode(raw));
		}
		const text = contentType?.trim().toLowerCase().startsWith('text/')
			? new TextDecoder().decode(raw)
			: '';
		this.violations.push(
			...this.validator.response(operation, {
				status: response.status,
				contentType: raw.length > 0 ? contentType : null,
				body: text === '' ? body : text,
			}),
		);

		const goesOn =
			expected === undefined
				? response.status >= SUCCESS_MIN && response.status <= SUCCESS_MAX
				: expected.includes(response.status);
		if (!goesOn) {
			throw new UnexpectedStatus(
				`${operation.key} answered ${response.status}: ${JSON.stringify(body)}`,
			);
		}
		return { status: response.status, contentType, body, text };
	}
}
