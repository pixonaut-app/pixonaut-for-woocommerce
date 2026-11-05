/**
 * `tests/fake-pixonaut/spec-overlay.json`: what Pixonaut agreed to add and
 * the pinned spec does not declare yet, so the plugin, the fake and the
 * contract checks can be built against it before it is pinned:
 *
 *   {
 *     "entries": [
 *       { "pointer": "/paths/~1v1~1things", "awaits": "pixonaut-app/pixonaut#12", "value": { "post": { … } } }
 *     ]
 *   }
 *
 * Each entry adds `value` at `pointer` (RFC 6901) only where the pinned spec
 * has the parent and lacks the key, so an entry stops applying once
 * `pnpm contracts:pin` brings what it awaits, and is then removed. Entries
 * are sorted by pointer and applied in that order, so one may add to what an
 * earlier one added: a security requirement on an operation the overlay
 * adds, say.
 */
import fs from 'node:fs';
import path from 'node:path';

export const OVERLAY_FILE = path.join(
	import.meta.dirname,
	'..',
	'..',
	'tests',
	'fake-pixonaut',
	'spec-overlay.json',
);

const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'patch'];

/**
 * @typedef {{ pointer: string, awaits: string, value: unknown }} OverlayEntry
 * @typedef {{ entries: OverlayEntry[] }} Overlay
 */

/** @param {string} token */
const unescapeToken = (token) =>
	token.replaceAll('~1', '/').replaceAll('~0', '~');

/**
 * The reference tokens of a JSON pointer.
 *
 * @param {string} pointer
 */
export function pointerTokens(pointer) {
	if (!pointer.startsWith('/')) {
		throw new Error(`"${pointer}" is not a JSON pointer`);
	}
	return pointer.slice(1).split('/').map(unescapeToken);
}

/**
 * The overlay, checked for shape.
 *
 * @param {any} json
 * @param {string} where named in errors
 * @returns {Overlay}
 */
export function parseOverlay(json, where) {
	const entries = json?.entries;
	if (!Array.isArray(entries)) {
		throw new Error(`${where}: "entries" must be a list`);
	}
	for (const entry of entries) {
		if (
			typeof entry?.pointer !== 'string' ||
			typeof entry.awaits !== 'string' ||
			entry.awaits === '' ||
			!('value' in entry)
		) {
			throw new Error(
				`${where}: each entry needs a "pointer", the "awaits" it waits on, and a "value"`,
			);
		}
		pointerTokens(entry.pointer);
	}
	return { entries };
}

/** @param {string} [file] */
export function readOverlay(file = OVERLAY_FILE) {
	return parseOverlay(JSON.parse(fs.readFileSync(file, 'utf8')), file);
}

/**
 * Applies the entries in order to a copy of `spec`, each where the copy so
 * far has its parent and lacks its key.
 *
 * @template {object} T
 * @param {T} spec
 * @param {Overlay} overlay
 * @returns {{ merged: T, applied: OverlayEntry[] }}
 */
function apply(spec, overlay) {
	const merged = structuredClone(spec);
	/** @type {OverlayEntry[]} */
	const applied = [];
	for (const entry of overlay.entries) {
		const tokens = pointerTokens(entry.pointer);
		const key = tokens.pop();
		/** @type {any} */
		let parent = merged;
		for (const token of tokens) {
			parent = parent?.[token];
		}
		if (
			typeof parent === 'object' &&
			parent !== null &&
			key !== undefined &&
			!Object.hasOwn(parent, key)
		) {
			parent[key] = structuredClone(entry.value);
			applied.push(entry);
		}
	}
	return { merged, applied };
}

/**
 * The entries `spec` lacks: its parent is there, or an earlier entry adds
 * it, and its key is not.
 *
 * @param {object} spec
 * @param {Overlay} overlay
 */
export function applicableEntries(spec, overlay) {
	return apply(spec, overlay).applied;
}

/**
 * A copy of `spec` with the entries it lacks added.
 *
 * @template {object} T
 * @param {T} spec
 * @param {Overlay} overlay
 * @returns {T}
 */
export function withOverlay(spec, overlay) {
	return apply(spec, overlay).merged;
}

/**
 * The operations, as `METHOD /path`, that the overlay adds to `spec`.
 *
 * @param {{ paths?: Record<string, Record<string, unknown>> }} spec
 * @param {Overlay} overlay
 */
export function overlaidOperations(spec, overlay) {
	const merged = withOverlay(spec, overlay);
	/** @type {string[]} */
	const added = [];
	for (const [route, item] of Object.entries(merged.paths ?? {})) {
		for (const method of HTTP_METHODS) {
			if (
				item[method] !== undefined &&
				spec.paths?.[route]?.[method] === undefined
			) {
				added.push(`${method.toUpperCase()} ${route}`);
			}
		}
	}
	return added.sort();
}

/**
 * The webhook event types the overlay adds to `spec`.
 *
 * @param {{ webhooks?: Record<string, unknown> }} spec
 * @param {Overlay} overlay
 */
export function overlaidWebhooks(spec, overlay) {
	const merged = withOverlay(spec, overlay);
	return Object.keys(merged.webhooks ?? {})
		.filter((type) => spec.webhooks?.[type] === undefined)
		.sort();
}
