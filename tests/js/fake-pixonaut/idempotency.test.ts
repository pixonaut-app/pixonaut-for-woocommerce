import { beforeEach, describe, expect, it } from 'vitest';
import { createFake, VIOLATION_STATUS } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import type { Handler } from '../../fake-pixonaut/src/handlers/types';
import {
	createIdempotencyStore,
	IDEMPOTENCY_IN_USE_RETRY_AFTER_S,
	IDEMPOTENCY_REPLAY_WINDOW_MS,
} from '../../fake-pixonaut/src/idempotency';
import { loadContract } from '../../fake-pixonaut/src/spec';
import { bearer, MAIN, SITE_URL, send } from './support';

const contract = loadContract();

/** The reference's own example of an order, so the scripted answer is
 *  valid. */
function orderExample(): { id: string } {
	const media = contract.operations.get('POST /v1/orders')?.definition
		.responses['201']?.content?.['application/json'] as
		| { examples?: { example?: { value?: { id: string } } } }
		| undefined;
	const value = media?.examples?.example?.value;
	if (value === undefined) {
		throw new Error('The reference has no example order.');
	}
	return value;
}

const ORDER_EXAMPLE = orderExample();

/** The design the scripted handler refuses, to show a refusal frees a key. */
const UNKNOWN_DESIGN = 'unknown';

let made = 0;
let gate: Promise<void> | null = null;

const createOrder: Handler = async (context) => {
	await gate;
	const { designs } = context.body as { designs: { designId: string }[] };
	if (designs.some((d) => d.designId === UNKNOWN_DESIGN)) {
		return { status: 404, body: { error: 'design_not_found' } };
	}
	made += 1;
	const id = `order-${made}`;
	return {
		status: 201,
		body: { ...ORDER_EXAMPLE, id },
		replay: () => ({
			status: 201,
			body: { ...ORDER_EXAMPLE, id, replayedAt: 'now' },
		}),
	};
};

const fake = createFake({
	contract,
	handlers: { 'POST /v1/orders': createOrder },
});

beforeEach(() => {
	fake.reset('default', SITE_URL);
	made = 0;
	gate = null;
});

function order(
	key: string,
	designId = 'd1',
	integrationKey: string = MAIN.integrationKey,
) {
	return send(fake, '/v1/orders', {
		method: 'POST',
		headers: { ...bearer(integrationKey), 'idempotency-key': key },
		json: { designs: [{ designId }], reorder: false },
	});
}

describe('Idempotency-Key', () => {
	it('answers a repeat with what the first made, and makes nothing', async () => {
		const first = await order('k1');
		const repeat = await order('k1');

		expect(first.status).toBe(201);
		expect(first.headers.get('Idempotent-Replayed')).toBeNull();
		expect(repeat.status).toBe(201);
		expect(repeat.headers.get('Idempotent-Replayed')).toBe('true');
		expect((await repeat.json()).id).toBe((await first.json()).id);
		expect(made).toBe(1);
	});

	it("reads the made record's current state on a replay", async () => {
		await order('k1');
		const repeat = await order('k1');

		expect(await repeat.json()).toMatchObject({ replayedAt: 'now' });
	});

	it('replays the same body with its keys in another order', async () => {
		const first = await order('k1');
		const reordered = await send(fake, '/v1/orders', {
			method: 'POST',
			headers: { ...bearer(MAIN.integrationKey), 'idempotency-key': 'k1' },
			json: { reorder: false, designs: [{ designId: 'd1' }] },
		});

		expect(first.status).toBe(201);
		expect(reordered.headers.get('Idempotent-Replayed')).toBe('true');
		expect(made).toBe(1);
	});

	it('refuses the same key with another body', async () => {
		await order('k1', 'd1');
		const reused = await order('k1', 'd2');

		expect(reused.status).toBe(422);
		expect(await reused.json()).toEqual({ error: 'idempotency_key_reused' });
	});

	it('refuses a repeat while the first is still being answered', async () => {
		let open: () => void = () => {};
		gate = new Promise((resolve) => {
			open = resolve;
		});
		const first = order('k1');
		const repeat = await order('k1');
		open();

		expect(repeat.status).toBe(409);
		expect(await repeat.json()).toEqual({ error: 'idempotency_key_in_use' });
		expect(repeat.headers.get('Retry-After')).toBe(
			String(IDEMPOTENCY_IN_USE_RETRY_AFTER_S),
		);
		expect((await first).status).toBe(201);
	});

	it('frees a key once its request was refused', async () => {
		const refused = await order('k1', UNKNOWN_DESIGN);
		const retried = await order('k1', 'd1');

		expect(refused.status).toBe(404);
		expect(retried.status).toBe(201);
		expect(retried.headers.get('Idempotent-Replayed')).toBeNull();
	});

	it('forgets a key after 24 hours', async () => {
		await order('k1');
		fake.clock.advance(IDEMPOTENCY_REPLAY_WINDOW_MS);
		const later = await order('k1');

		expect(later.headers.get('Idempotent-Replayed')).toBeNull();
		expect(made).toBe(2);
	});

	it('keeps one key space per store', async () => {
		await order('k1');
		const otherStore = await order(
			'k1',
			'd1',
			FIXTURE.store.other.integrationKey,
		);

		expect(otherStore.headers.get('Idempotent-Replayed')).toBeNull();
		expect(made).toBe(2);
	});

	it('fails a key outside the reference as a contract violation', async () => {
		const response = await order('key with spaces');

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([{ at: 'header.idempotency-key' }]);
	});
});

describe('idempotency store', () => {
	it('refuses a key that is not printable ASCII', () => {
		const claim = createIdempotencyStore().claim('s1', 'k\u00e9y', 'f', 0);

		expect(claim).toEqual({
			kind: 'refused',
			status: 422,
			error: 'invalid_idempotency_key',
		});
	});
});
