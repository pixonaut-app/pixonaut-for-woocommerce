import { describe, expect, it } from 'vitest';
import { isLasting, unavailableReason } from './errors';
import { SdkLoadError } from './sdk-loader';
import {
	fakeSdk,
	PlacementChangedError,
	requestError,
	UnreadableDocumentError,
	WasmBootError,
	WasmIntegrityError,
	WasmVersionMismatchError,
} from './test-support';

describe('unavailableReason', () => {
	const { sdk } = fakeSdk();

	it.each([
		[new WasmIntegrityError('x'), 'wasm_integrity'],
		[new WasmBootError('x'), 'wasm_boot'],
		[new WasmVersionMismatchError('x'), 'wasm_version_mismatch'],
		[requestError(422, 'unknown_placement'), 'unknown_placement'],
		[requestError(409, 'sdk_outdated'), 'sdk_outdated'],
		[new UnreadableDocumentError('x'), 'sdk_outdated'],
		[new SdkLoadError('x'), 'sdk_load'],
	])('maps %o to %s', (error, reason) => {
		expect(unavailableReason(error, sdk)).toBe(reason);
	});

	it.each([
		[requestError(422, 'constraint_violation')],
		[requestError(503, 'unavailable')],
		[new PlacementChangedError('x')],
		[new TypeError('Failed to fetch')],
		['a string'],
	])('reports nothing for %o', (error) => {
		expect(unavailableReason(error, sdk)).toBeNull();
	});

	it('reads a script that did not load before the SDK exists', () => {
		expect(unavailableReason(new SdkLoadError('x'), null)).toBe('sdk_load');
	});

	it('lets a script that did not load be tried again', () => {
		expect(isLasting('sdk_load')).toBe(false);
		expect(isLasting('wasm_boot')).toBe(true);
		expect(isLasting('unknown_placement')).toBe(true);
		expect(isLasting('sdk_outdated')).toBe(true);
	});

	it('reads a placement refusal before the SDK loaded', () => {
		expect(
			unavailableReason(requestError(422, 'unknown_placement'), null),
		).toBe('unknown_placement');
	});
});
