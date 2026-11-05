import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { CONTRACTS_DIR, sha256, sri384 } from '../../bin/contracts/files.mjs';

function read(file: string): Buffer {
	return readFileSync(join(CONTRACTS_DIR, file));
}

describe('pinned Pixonaut contracts', () => {
	const spec = JSON.parse(read('spec.lock.json').toString());
	const sdk = JSON.parse(read('sdk.lock.json').toString());

	it('spec.lock.json matches openapi.json', () => {
		expect(sha256(read('openapi.json'))).toBe(spec.sha256);
	});

	it('spec.lock.json matches the webhook signature vectors', () => {
		expect(sha256(read('webhook-signature-vectors.json'))).toBe(
			spec.webhookSignatureVectors.sha256,
		);
	});

	it.each(Object.entries(sdk.types as Record<string, string>))(
		'sdk.lock.json matches %s',
		(file, integrity) => {
			expect(sri384(read(join('sdk', file)))).toBe(integrity);
		},
	);

	it('spec.lock.json names the API release by version', () => {
		expect(spec.baseUrl).toBe(`https://cdn.pixonaut.app/api/${spec.version}/`);
	});

	it('the SDK script URL names the pinned version', () => {
		expect(sdk.baseUrl).toBe(`https://cdn.pixonaut.app/sdk/${sdk.version}/`);
	});
});
