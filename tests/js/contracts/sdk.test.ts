import { describe, expect, it } from 'vitest';
import { readSdkLock } from '../../../bin/contracts/files.mjs';
import {
	SDK_SCRIPT,
	sdkDrift,
	sdkLockFrom,
} from '../../../bin/contracts/sdk.mjs';

const lock = readSdkLock();

/** A release record for the pinned version, as Pixonaut publishes it. */
const record = {
	version: lock.version,
	released: lock.released,
	files: {
		[SDK_SCRIPT]: lock.integrity,
		...lock.types,
		LICENSE: 'sha384-licence',
	},
};

describe('sdk.lock.json', () => {
	it('is what a pin writes from the release record', () => {
		expect(sdkLockFrom(record)).toEqual(lock);
	});

	it('has no drift from its release record', () => {
		expect(sdkDrift(lock, record)).toEqual([]);
	});

	it('reports a release record whose script SRI differs', () => {
		const replaced = {
			...record,
			files: { ...record.files, [SDK_SCRIPT]: 'sha384-other' },
		};

		expect(sdkDrift(lock, replaced)).toEqual([
			`${SDK_SCRIPT}: the release says sha384-other, sdk.lock.json says ${lock.integrity}`,
		]);
	});
});
