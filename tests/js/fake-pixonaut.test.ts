import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const FAKE_SCRIPT = join(process.cwd(), 'bin', 'fake-pixonaut.mjs');

/** Node's exit status for a top-level await the event loop left unsettled. */
const UNSETTLED_TOP_LEVEL_AWAIT = 13;

/** Short enough to keep the test quick, long enough to see a second attempt. */
const WAIT = { timeoutMs: 2_000, pollMs: 10, attemptMs: 50 };

/**
 * Runs the wait in a process of its own, as `env:start` does, with a `fetch`
 * that holds nothing on the event loop and settles only when aborted.
 *
 * @param answers Whether the attempt after the hung one is answered.
 */
function waitWithAHungRequest(answers: boolean) {
	const script = `
		let attempts = 0;
		globalThis.fetch = (url, { signal }) => {
			attempts += 1;
			if (attempts > 1 && ${answers}) {
				return Promise.resolve({ ok: true });
			}
			return new Promise((_, reject) => {
				signal.addEventListener('abort', () => reject(signal.reason));
			});
		};
		const { waitUntilReady } = await import(${JSON.stringify(`file://${FAKE_SCRIPT}`)});
		await waitUntilReady(1, ${JSON.stringify(WAIT)});
	`;
	return spawnSync(process.execPath, ['--input-type=module', '-e', script], {
		encoding: 'utf8',
	});
}

describe('waitUntilReady', () => {
	it('tries again after a request that never settles', () => {
		const { status, stderr } = waitWithAHungRequest(true);

		expect(stderr).toBe('');
		expect(status).toBe(0);
	});

	it('reports a fake that never answers rather than exiting on the await', () => {
		const { status, stderr } = waitWithAHungRequest(false);

		expect(status).not.toBe(UNSETTLED_TOP_LEVEL_AWAIT);
		expect(stderr).toContain('The fake did not answer on port 1.');
	});
});
