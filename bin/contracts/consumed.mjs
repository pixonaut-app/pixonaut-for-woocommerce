/**
 * `contracts/pixonaut/consumed.json`: the Pixonaut operations and webhooks
 * the plugin uses, in the format of Pixonaut's consumer-contract check
 * (`contracts/consumers/<consumer>/consumed.json` there):
 *
 *   {
 *     "operations": ["POST /v1/designs", …],
 *     "webhooks": ["order.status_changed", …],
 *     "keys": { "POST /v1/designs": "integrationKey",
 *               "GET /v1/templates/{id}/constraints": ["integrationKey", "publishableKey", "shareLink"] },
 *     "pending": [{ "op": "POST /v1/things", "requirement": "R12" }]
 *   }
 *
 * `keys` names the key kind an operation is called with, as the spec's
 * `components.securitySchemes` names it; an operation that takes no key has
 * no entry. `operations`, `webhooks` and `keys` are what the code calls and
 * handles, written by `pnpm contracts:consumed` (`consumed-generate.mjs`).
 * `pending` is kept by hand: what the plugin plans to use and does not call
 * yet, whether or not the pinned spec declares it; Pixonaut's check ignores
 * it.
 */
import fs from 'node:fs';
import path from 'node:path';
import { overlaidOperations, overlaidWebhooks } from './overlay.mjs';

export const CONSUMED_FILE = path.join(
	import.meta.dirname,
	'..',
	'..',
	'contracts',
	'pixonaut',
	'consumed.json',
);

/** A pending entry names a webhook this way instead of `METHOD /path`. */
export const WEBHOOK_LABEL = 'WEBHOOK';

const OPERATION = /^(GET|PUT|POST|DELETE|PATCH|HEAD|OPTIONS|TRACE) (\/\S*)$/;
const PENDING_WEBHOOK = new RegExp(`^${WEBHOOK_LABEL} (\\S+)$`);

/**
 * @typedef {{ op: string, requirement: string }} Pending
 * @typedef {{
 *   operations: string[],
 *   webhooks: string[],
 *   keys: Record<string, string[]>,
 *   pending: Pending[],
 * }} Consumed
 *   `keys` holds every kind as a list, whichever form the file uses.
 * @typedef {{
 *   openapi?: string,
 *   info?: object,
 *   security?: Record<string, string[]>[],
 *   paths?: Record<string, Record<string, any>>,
 *   webhooks?: Record<string, Record<string, any>>,
 *   components?: Record<string, any>,
 * }} Spec
 */

/**
 * `{method, path}` of an operation written `POST /v1/designs`.
 *
 * @param {string} written
 */
export function parseOperation(written) {
	const match = OPERATION.exec(written);
	if (match === null) {
		throw new Error(
			`"${written}" is not an operation; write it as METHOD /path, the path as the spec declares it`,
		);
	}
	return { method: match[1].toLowerCase(), path: match[2] };
}

/** @param {unknown} value */
const isText = (value) => typeof value === 'string' && value.trim() !== '';

/** @param {unknown} value */
const isTextList = (value) => Array.isArray(value) && value.every(isText);

/**
 * `consumed.json`, checked for shape.
 *
 * @param {any} json
 * @param {string} where named in errors
 * @returns {Consumed}
 */
export function parseConsumed(json, where) {
	const fail = (/** @type {string} */ why) => {
		throw new Error(`${where}: ${why}`);
	};
	if (typeof json !== 'object' || json === null || Array.isArray(json)) {
		fail('expected an object');
	}
	if (!isTextList(json.operations)) {
		fail('"operations" must be a list of "METHOD /path"');
	}
	if (!isTextList(json.webhooks)) {
		fail('"webhooks" must be a list of event types');
	}
	for (const operation of json.operations) {
		parseOperation(operation);
	}
	/** @type {Record<string, string[]>} */
	const keys = {};
	const declared = json.keys ?? {};
	if (typeof declared !== 'object' || Array.isArray(declared)) {
		fail('"keys" must map "METHOD /path" to a key kind or a list of them');
	}
	for (const [operation, kinds] of Object.entries(declared)) {
		if (!json.operations.includes(operation)) {
			fail(`keys names "${operation}", which is not in operations`);
		}
		const list = Array.isArray(kinds) ? kinds : [kinds];
		if (list.length === 0 || !list.every(isText)) {
			fail(
				`keys for "${operation}" must be a key kind or a non-empty list of them`,
			);
		}
		keys[operation] = list;
	}
	const pending = json.pending ?? [];
	if (
		!Array.isArray(pending) ||
		!pending.every(
			(entry) =>
				typeof entry === 'object' &&
				entry !== null &&
				isText(entry.op) &&
				isText(entry.requirement),
		)
	) {
		fail(
			'"pending" must be a list of {"op": "METHOD /path" or "WEBHOOK <type>", "requirement": "…"}',
		);
	}
	for (const { op } of pending) {
		if (!PENDING_WEBHOOK.test(op)) {
			parseOperation(op);
		}
	}
	return {
		operations: json.operations,
		webhooks: json.webhooks,
		keys,
		pending,
	};
}

/** The plugin's `consumed.json`. */
export function readConsumed(file = CONSUMED_FILE) {
	return parseConsumed(JSON.parse(fs.readFileSync(file, 'utf8')), file);
}

/**
 * @param {Spec} spec
 * @param {string} written `METHOD /path`
 */
export function specOperation(spec, written) {
	const { method, path: route } = parseOperation(written);
	return spec.paths?.[route]?.[method];
}

/**
 * @param {Spec} spec
 * @param {string} type
 */
export function specWebhook(spec, type) {
	return spec.webhooks?.[type]?.post;
}

/**
 * @param {Spec} spec
 * @param {string} op a pending entry's `op`
 */
function declares(spec, op) {
	const webhook = PENDING_WEBHOOK.exec(op);
	return webhook === null
		? specOperation(spec, op) !== undefined
		: specWebhook(spec, webhook[1]) !== undefined;
}

/**
 * The consumed names `spec` does not declare, except those `pending` waits
 * on. Webhooks are named `WEBHOOK <type>`.
 *
 * @param {Consumed} consumed
 * @param {Spec} spec
 */
export function missingFromSpec(consumed, spec) {
	const waiting = new Set(consumed.pending.map((entry) => entry.op));
	return [
		...consumed.operations.filter(
			(operation) => specOperation(spec, operation) === undefined,
		),
		...consumed.webhooks
			.filter((type) => specWebhook(spec, type) === undefined)
			.map((type) => `${WEBHOOK_LABEL} ${type}`),
	].filter((name) => !waiting.has(name));
}

/**
 * The pending entries `next` declares and `pinned` did not: what a new spec
 * brings that the plugin waits on.
 *
 * @param {Consumed} consumed
 * @param {Spec} pinned
 * @param {Spec} next
 */
export function arrivedPending(consumed, pinned, next) {
	return consumed.pending
		.filter((entry) => declares(next, entry.op) && !declares(pinned, entry.op))
		.map((entry) => entry.op);
}

/**
 * Whether one security requirement is met by `kind` alone. A requirement
 * naming several schemes needs all of them at once.
 *
 * @param {Record<string, string[]>} requirement
 * @param {string} kind
 */
function acceptsAlone(requirement, kind) {
	const schemes = Object.keys(requirement);
	return schemes.length === 1 && schemes[0] === kind;
}

/**
 * The declared key kinds `spec` does not let an operation be called with:
 * no requirement in its `security` (the document's when it has none) is met
 * by that scheme alone, or the spec declares no such scheme at all. oasdiff
 * reports no dropped scheme, so this is the check for it. An operation the
 * spec lacks is left to `missingFromSpec`.
 *
 * @param {Consumed} consumed
 * @param {Spec} spec
 */
export function refusedKeyKinds(consumed, spec) {
	const schemes = Object.keys(spec.components?.securitySchemes ?? {});
	/** @type {string[]} */
	const problems = [];
	for (const [written, kinds] of Object.entries(consumed.keys)) {
		const operation = specOperation(spec, written);
		if (operation === undefined) {
			continue;
		}
		/** @type {Record<string, string[]>[]} */
		const accepted = operation.security ?? spec.security ?? [];
		for (const kind of kinds) {
			if (!schemes.includes(kind)) {
				problems.push(
					`${written} names ${kind}, which the spec declares no security scheme for`,
				);
			} else if (
				!accepted.some((requirement) => acceptsAlone(requirement, kind))
			) {
				problems.push(`${written} does not accept ${kind}`);
			}
		}
	}
	return problems;
}

/**
 * What the code calls and handles, as `consumed-data.php` prints it: each
 * operation with the key kinds it is called with (none for an open route),
 * and the webhooks the store receives.
 *
 * @typedef {{
 *   operations: Record<string, string[]>,
 *   webhooks: string[],
 * }} Used
 */

/**
 * The names the spec overlay stands in for until they are pinned, as
 * `pending` writes them: each operation as `METHOD /path`, each webhook as
 * `WEBHOOK <type>`.
 *
 * @param {Spec} spec
 * @param {import('./overlay.mjs').Overlay} overlay
 */
export function awaitedByOverlay(spec, overlay) {
	return new Set([
		...overlaidOperations(spec, overlay),
		...overlaidWebhooks(spec, overlay).map(
			(type) => `${WEBHOOK_LABEL} ${type}`,
		),
	]);
}

/**
 * `consumed.json` for what the code uses, keeping `previous`'s pending
 * entries but those the code now uses, unless the spec overlay stands in for
 * them until they are pinned (`awaited`).
 *
 * @param {Used} used
 * @param {Pick<Consumed, 'pending'>} previous
 * @param {Set<string>} [awaited] what the overlay adds to the pinned spec (`awaitedByOverlay`)
 * @returns {Consumed}
 */
export function buildConsumed(used, previous, awaited = new Set()) {
	const operations = Object.keys(used.operations).sort();
	const webhooks = [...new Set(used.webhooks)].sort();
	/** @type {Record<string, string[]>} */
	const keys = {};
	for (const operation of operations) {
		const kinds = [...new Set(used.operations[operation])].sort();
		if (kinds.length > 0) {
			keys[operation] = kinds;
		}
	}
	const usedNames = new Set([
		...operations,
		...webhooks.map((type) => `${WEBHOOK_LABEL} ${type}`),
	]);
	const pending = previous.pending
		.filter((entry) => !usedNames.has(entry.op) || awaited.has(entry.op))
		.sort((a, b) => (a.op < b.op ? -1 : a.op > b.op ? 1 : 0));
	return { operations, webhooks, keys, pending };
}

/**
 * `consumed` as the file is written: tabs, one entry per line, a single key
 * kind as a string rather than a list.
 *
 * @param {Consumed} consumed
 */
export function formatConsumed(consumed) {
	const indent = (/** @type {string[]} */ lines) =>
		lines.map((line) => `\t\t${line}`);
	const list = (/** @type {string[]} */ items) =>
		items.length === 0
			? '[]'
			: `[\n${indent(items.map((item) => JSON.stringify(item))).join(',\n')}\n\t]`;
	const keys = Object.entries(consumed.keys).map(
		([operation, kinds]) =>
			`${JSON.stringify(operation)}: ${
				kinds.length === 1
					? JSON.stringify(kinds[0])
					: `[${kinds.map((kind) => JSON.stringify(kind)).join(', ')}]`
			}`,
	);
	const pending = consumed.pending.map(
		({ op, requirement }) =>
			`{ "op": ${JSON.stringify(op)}, "requirement": ${JSON.stringify(requirement)} }`,
	);
	return `{
\t"operations": ${list(consumed.operations)},
\t"webhooks": ${list(consumed.webhooks)},
\t"keys": ${keys.length === 0 ? '{}' : `{\n${indent(keys).join(',\n')}\n\t}`},
\t"pending": ${pending.length === 0 ? '[]' : `[\n${indent(pending).join(',\n')}\n\t]`}
}
`;
}
