import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { COST_CURRENCY } from '../../fake-pixonaut/src/handlers/variant-costs';
import { bearer, MAIN, SITE_ORIGIN, SITE_URL, send } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };

const TEE = FIXTURE.blank.tee;
const WHITE_S = FIXTURE.variant.teeWhiteS;
const WHITE_M = FIXTURE.variant.teeWhiteM;

type Cost = {
	variant: string;
	product: { amount: string; currency: string } | null;
	shipping: { amount: string; currency: string } | null;
	region: string;
	readAt: string;
	changedAt: string | null;
};

async function costs(blank: string = TEE, query = '?region=SE') {
	const response = await send(
		fake,
		`/v1/blanks/${blank}/variants/costs${query}`,
		asStore,
	);
	const body = (await response.json()) as { costs?: Cost[]; error?: string };
	return { status: response.status, body };
}

function setCosts(json: unknown, blank: string = TEE) {
	return send(fake, `/__fake/blanks/${blank}/costs`, { method: 'POST', json });
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

afterEach(() => {
	expect(fake.violations).toEqual([]);
});

describe('GET /v1/blanks/{id}/variants/costs', () => {
	it("lists every variant's product price and shipping to the region", async () => {
		const { status, body } = await costs();

		expect(status).toBe(200);
		expect(body.costs?.map((cost) => cost.variant)).toEqual(
			fake.state.blanks.get(TEE)?.variants.map((variant) => variant.ref),
		);
		const first = body.costs?.find((cost) => cost.variant === WHITE_S);
		expect(first).toMatchObject({
			product: { amount: '120.00', currency: COST_CURRENCY },
			shipping: { amount: '4.90', currency: COST_CURRENCY },
			region: 'SE',
			changedAt: null,
		});
		// No two variants cost the same, so a test can tell them apart.
		const amounts = body.costs?.map((cost) => cost.product?.amount);
		expect(new Set(amounts).size).toBe(amounts?.length);
	});

	it('answers a cost a test changed, and when it changed', async () => {
		const set = await setCosts({ costs: { [WHITE_S]: { product: '200.00' } } });
		expect(set.status).toBe(200);

		const { body } = await costs();

		const changed = body.costs?.find((cost) => cost.variant === WHITE_S);
		expect(changed?.product).toEqual({
			amount: '200.00',
			currency: COST_CURRENCY,
		});
		expect(changed?.shipping?.amount).toBe('4.90');
		expect(changed?.changedAt).not.toBeNull();
		expect(
			body.costs?.find((cost) => cost.variant === WHITE_M)?.changedAt,
		).toBeNull();
	});

	it('lists a variant without a published price with no product and no shipping', async () => {
		await setCosts({ costs: { [WHITE_S]: { product: null } } });

		const { body } = await costs();

		expect(body.costs?.find((cost) => cost.variant === WHITE_S)).toMatchObject({
			product: null,
			shipping: null,
		});
	});

	it('charges in the currency a test names', async () => {
		await setCosts({
			costs: { [WHITE_S]: { product: '1200.00', currency: 'SEK' } },
		});

		const { body } = await costs();

		expect(
			body.costs?.find((cost) => cost.variant === WHITE_S)?.product,
		).toEqual({ amount: '1200.00', currency: 'SEK' });
	});

	it('refuses an unknown blank and an unknown region', async () => {
		expect((await costs('blank_nowhere')).status).toBe(404);
		const unknown = await costs(TEE, '?region=ZZ');
		expect(unknown.status).toBe(422);
		expect(unknown.body.error).toBe('unknown_region');
	});

	it('is read with the integration key only', async () => {
		const response = await send(
			fake,
			`/v1/blanks/${TEE}/variants/costs?region=SE`,
			{ headers: { ...bearer(MAIN.publishableKey), origin: SITE_ORIGIN } },
		);

		expect(response.status).toBe(403);
	});
});

describe('POST /__fake/blanks/:id/costs', () => {
	it('refuses a blank, a variant or a price it does not know', async () => {
		expect((await setCosts({ costs: {} }, 'blank_nowhere')).status).toBe(404);
		expect(
			(await setCosts({ costs: { v_nowhere: { product: '1.00' } } })).status,
		).toBe(400);
		expect(
			(await setCosts({ costs: { [WHITE_S]: { product: 'free' } } })).status,
		).toBe(400);
		expect((await setCosts({ costs: [] })).status).toBe(400);
	});
});
