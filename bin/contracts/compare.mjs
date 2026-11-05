/**
 * What a new Pixonaut spec changes for the plugin, as `contracts:pin` and
 * `contract:check` both print it.
 */
import {
	arrivedPending,
	missingFromSpec,
	refusedKeyKinds,
} from './consumed.mjs';
import { OASDIFF_FAILED, runOasdiff } from './oasdiff.mjs';
import { project } from './project.mjs';

/**
 * Compares `pinned` with `next`, each cut down to what `consumed` uses.
 *
 * `breaking` is true when oasdiff finds an `ERR` (after the downgrades in
 * `oasdiff-levels.txt`), or `next` stops declaring a consumed name or
 * stops accepting a key kind the plugin calls an operation with, which
 * oasdiff does not report.
 *
 * @param {import('./consumed.mjs').Spec} pinned
 * @param {import('./consumed.mjs').Spec} next
 * @param {import('./consumed.mjs').Consumed} consumed
 */
export function compareSpecs(pinned, next, consumed) {
	const before = project(pinned, consumed);
	const after = project(next, consumed);
	const changelog = runOasdiff('changelog', before, after).output.trim();
	const { status } = runOasdiff('breaking', before, after, [
		'--fail-on',
		'ERR',
	]);
	const missing = missingFromSpec(consumed, next);
	const refused = refusedKeyKinds(consumed, next);
	const arrived = arrivedPending(consumed, pinned, next);
	return {
		breaking:
			status === OASDIFF_FAILED || missing.length > 0 || refused.length > 0,
		changelog,
		missing,
		refused,
		arrived,
	};
}

/**
 * The comparison as lines to print.
 *
 * @param {ReturnType<typeof compareSpecs>} result
 */
export function describeComparison(result) {
	const lines = [
		result.changelog === ''
			? 'No change to the consumed operations and webhooks.'
			: result.changelog,
	];
	const list = (/** @type {string} */ title, /** @type {string[]} */ items) => {
		if (items.length > 0) {
			lines.push('', title, ...items.map((item) => `  ${item}`));
		}
	};
	list('Consumed, but not declared by the new spec:', result.missing);
	list('Key kinds the new spec no longer accepts:', result.refused);
	list(
		'Pending entries the new spec declares, which the pinned one did not:',
		result.arrived,
	);
	return lines;
}
