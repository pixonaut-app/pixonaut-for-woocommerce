import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { createFake, NOT_SERVED_STATUS } from '../../fake-pixonaut/src/app';
import {
	HANDLERS,
	PINGED_WEBHOOK,
	SERVED_WEBHOOKS,
} from '../../fake-pixonaut/src/handlers';
import { CONTRACTS_DIR, loadContract } from '../../fake-pixonaut/src/spec';
import { bearer, MAIN, send } from './support';

/**
 * Compiling the pinned spec, for a fake or for types, takes a few seconds
 * alone and more on a loaded machine.
 */
const SPEC_COMPILE_TIMEOUT_MS = 30_000;

const contract = loadContract();
const { consumed } = contract;
const served = Object.keys(HANDLERS);

describe('fake coverage of consumed.json', {
	timeout: SPEC_COMPILE_TIMEOUT_MS,
}, () => {
	it('serves exactly the consumed operations', () => {
		expect([...served].sort()).toEqual([...consumed.operations].sort());
	});

	it('delivers exactly the consumed webhooks', () => {
		expect([...SERVED_WEBHOOKS, PINGED_WEBHOOK].sort()).toEqual(
			[...consumed.webhooks].sort(),
		);
	});

	it('answers 501 for an operation not in consumed.json', async () => {
		const fake = createFake({ contract });

		const response = await send(fake, '/v1/storage', {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(NOT_SERVED_STATUS);
		expect(fake.violations).toMatchObject([
			{ operation: 'GET /v1/storage', at: 'operation' },
		]);
	});
});

describe('generated types', { timeout: SPEC_COMPILE_TIMEOUT_MS }, () => {
	it('match the pinned reference (run `pnpm fake:types` after a re-pin)', () => {
		const scratch = mkdtempSync(join(tmpdir(), 'fake-types-'));
		const regenerated = join(scratch, 'openapi.d.ts');
		try {
			execFileSync(
				join('node_modules', '.bin', 'openapi-typescript'),
				[join(CONTRACTS_DIR, 'openapi.json'), '--output', regenerated],
				{ stdio: 'ignore' },
			);

			expect(readFileSync(regenerated, 'utf8')).toBe(
				readFileSync(
					join(import.meta.dirname, '../../fake-pixonaut/src/openapi.d.ts'),
					'utf8',
				),
			);
		} finally {
			rmSync(scratch, { recursive: true, force: true });
		}
	});
});
