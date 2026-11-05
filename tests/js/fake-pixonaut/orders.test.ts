import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { findPrint } from '../../fake-pixonaut/src/handlers/prints';
import { bearer, MAIN, SITE_URL, seedPrint, send } from './support';

const fake = createFake();
const asStore = bearer(MAIN.integrationKey);
/** How many variants the fixture tee has, retired ones included. */
const TEE_VARIANTS = 7;
/** Every order status counted at none. */
const NO_ORDERS = {
	held: 0,
	awaiting_prints: 0,
	submitted: 0,
	queued: 0,
	printed: 0,
	shipped: 0,
	failed: 0,
	outcome_unknown: 0,
	cancelled: 0,
};

function post(path: string, json: unknown, key?: string) {
	return send(fake, path, {
		method: 'POST',
		json,
		headers:
			key === undefined ? asStore : { ...asStore, 'idempotency-key': key },
	});
}

function get(path: string) {
	return send(fake, path, { headers: asStore });
}

/** Seeds an approved design for the main store, or the one `extra` names. */
async function approvedDesign(id: string, extra: Record<string, unknown> = {}) {
	const response = await send(fake, '/__fake/designs', {
		method: 'POST',
		json: { id, status: 'approved', ...extra },
	});
	expect(response.status).toBe(200);
}

/** A print of the main store's approved design, seeded unordered. */
function print(designId: string): string {
	return seedPrint(fake, designId);
}

/** The orders of the main store. */
async function orderCount(): Promise<number> {
	return (await (await get('/v1/orders')).json()).total;
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /v1/orders with designs', () => {
	const tee = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
	};

	/** Orders the designs as one item of the tee's white S. */
	function orderDesigns(designIds: string[], key: string, extra = {}) {
		return post(
			'/v1/orders',
			{
				designs: designIds.map((designId) => ({ designId })),
				variant: FIXTURE.variant.teeWhiteS,
				...extra,
			},
			key,
		);
	}

	it('prints each design and orders the prints, lead first', async () => {
		await approvedDesign('dsn_front', tee);
		await approvedDesign('dsn_back', {
			...tee,
			placementId: FIXTURE.placement.back,
		});

		const response = await orderDesigns(['dsn_front', 'dsn_back'], 'o1');

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body).toMatchObject({
			designId: 'dsn_front',
			status: 'submitted',
			prints: [
				{ designId: 'dsn_front', designRevision: 1, status: 'completed' },
				{ designId: 'dsn_back', designRevision: 1, status: 'completed' },
			],
		});
		expect(body.printId).toBe(body.printIds[0]);
		const [front, back] = body.printIds.map((id: string) =>
			findPrint(fake.state, id, MAIN.id),
		);
		expect(front).toMatchObject({
			designId: 'dsn_front',
			orderCount: 1,
			jobTicket: { product: { tenantId: FIXTURE.tenant.live } },
		});
		expect(back).toMatchObject({ designId: 'dsn_back', orderCount: 1 });
		expect(front?.number).not.toBe(back?.number);
		expect(fake.violations).toEqual([]);
	});

	it.each([
		['both prints and designs', { prints: [{ printId: 'prt_1' }] }],
		['neither', { designs: undefined }],
	])('refuses an order naming %s', async (_, extra) => {
		await approvedDesign('dsn_1', tee);

		const response = await orderDesigns(['dsn_1'], 'o1', extra);

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({ error: 'bad_request' });
		expect(await orderCount()).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it.each([
		['an unknown design', 'dsn_unknown', {}, 404, 'design_not_found'],
		[
			"another store's design",
			'dsn_other',
			{ store: FIXTURE.store.other.id },
			404,
			'design_not_found',
		],
		[
			'a design not approved',
			'dsn_draft',
			{ status: 'committed' },
			409,
			'design_not_approved',
		],
	])(
		'refuses %s and writes nothing',
		async (_, designId, seed, status, error) => {
			await approvedDesign('dsn_1', tee);
			if (designId !== 'dsn_unknown') {
				await approvedDesign(designId, { ...tee, ...seed });
			}

			const response = await orderDesigns(['dsn_1', designId], 'o1');

			expect(response.status).toBe(status);
			expect(await response.json()).toEqual({ error });
			expect(await orderCount()).toBe(0);
			expect(fake.state.records.get('prints')?.size ?? 0).toBe(0);
			expect(fake.violations).toEqual([]);
		},
	);

	it('refuses a second order of a design with a live order, naming it, and places one asked for again', async () => {
		await approvedDesign('dsn_1', tee);
		const first = await (await orderDesigns(['dsn_1'], 'o1')).json();

		const again = await orderDesigns(['dsn_1'], 'o2');
		const ofPrint = await post(
			'/v1/orders',
			{
				prints: [{ printId: first.printId }],
				variant: FIXTURE.variant.teeWhiteS,
			},
			'o3',
		);
		const reordered = await orderDesigns(['dsn_1'], 'o4', { reorder: true });
		const newest = await orderDesigns(['dsn_1'], 'o5');

		expect(again.status).toBe(409);
		expect(await again.json()).toEqual({
			error: 'design_already_ordered',
			designId: 'dsn_1',
			orderId: first.id,
		});
		expect(ofPrint.status).toBe(409);
		expect(reordered.status).toBe(201);
		const { id } = await reordered.json();
		expect((await newest.json()).orderId).toBe(id);
		expect(await orderCount()).toBe(2);
		expect(fake.violations).toEqual([]);
	});

	it('orders a design again whose only order failed or was cancelled', async () => {
		await approvedDesign('dsn_failed', tee);
		await approvedDesign('dsn_cancelled', tee);
		await send(fake, '/__fake/orders/fail', {
			method: 'POST',
			json: { reason: 'Out of stock at the printshop.', times: 1 },
		});
		const failed = await orderDesigns(['dsn_failed'], 'o1');
		const { id } = await (await orderDesigns(['dsn_cancelled'], 'o2')).json();
		await post(`/v1/orders/${id}/cancel`, undefined);

		const afterFailed = await orderDesigns(['dsn_failed'], 'o3');
		const afterCancelled = await orderDesigns(['dsn_cancelled'], 'o4');

		expect((await failed.json()).status).toBe('failed');
		expect(afterFailed.status).toBe(201);
		expect(afterCancelled.status).toBe(201);
		expect(fake.violations).toEqual([]);
	});

	it('answers a repeat of its key with the first order, not design_already_ordered', async () => {
		await approvedDesign('dsn_1', tee);

		const first = await orderDesigns(['dsn_1'], 'o1');
		const replay = await orderDesigns(['dsn_1'], 'o1');

		expect(first.status).toBe(201);
		expect(replay.status).toBe(201);
		expect(replay.headers.get('Idempotent-Replayed')).toBe('true');
		expect((await replay.json()).id).toBe((await first.json()).id);
		expect(await orderCount()).toBe(1);
		expect(fake.state.records.get('prints')?.size).toBe(1);
		expect(fake.violations).toEqual([]);
	});
});

describe('orders', () => {
	const shipping = {
		recipient: { name: 'Ada Lovelace' },
		shippingAddress: { line1: 'Storgatan 1', city: 'Lund', country: 'SE' },
	};

	/** Orders the print as one item of `variant`, the tee's white S unless
	 *  named. */
	function order(
		printId: string,
		key = 'order-1',
		variant: string = FIXTURE.variant.teeWhiteS,
	) {
		return post(
			'/v1/orders',
			{ prints: [{ printId }], externalRef: 'wc-42', variant, ...shipping },
			key,
		);
	}

	/** Prints a design on the locked blank's front and orders it. */
	async function placed(): Promise<{ printId: string; id: string }> {
		await approvedDesign('dsn_1', {
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const printId = print('dsn_1');
		const response = await order(printId);
		expect(response.status).toBe(201);
		return { printId, id: (await response.json()).id };
	}

	it('places one order for a key, however often it is sent', async () => {
		await approvedDesign('dsn_1', {
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const printId = print('dsn_1');

		const first = await order(printId);
		const replay = await order(printId);

		expect(first.status).toBe(201);
		const body = await first.json();
		expect(body).toMatchObject({
			tenantId: FIXTURE.tenant.live,
			printId,
			designId: 'dsn_1',
			printIds: [printId],
			status: 'submitted',
			printshopConnectionId: FIXTURE.printshop.main,
			printshopName: 'Fixture Print AB',
			prints: [
				{ printId, designId: 'dsn_1', placementId: FIXTURE.placement.front },
			],
		});
		expect(replay.headers.get('Idempotent-Replayed')).toBe('true');
		expect((await replay.json()).id).toBe(body.id);
		expect((await (await get('/v1/orders')).json()).total).toBe(1);
		expect(fake.violations).toEqual([]);
	});

	it("ships to the customer's address, and knows no saved address", async () => {
		await approvedDesign('dsn_1', {
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const printId = print('dsn_1');
		const body = {
			prints: [{ printId }],
			variant: FIXTURE.variant.teeWhiteS,
			...shipping,
		};

		const saved = await post(
			'/v1/orders',
			{ ...body, shipToAddressId: 'adr_1' },
			'order-saved',
		);
		const customer = await post(
			'/v1/orders',
			{ ...body, shipToAddressId: null },
			'order-customer',
		);

		expect(saved.status).toBe(422);
		expect((await saved.json()).error).toBe('saved_address_not_found');
		expect(customer.status).toBe(201);
		expect(await customer.json()).toMatchObject({
			shipTo: null,
			delivery: null,
		});
		expect(fake.violations).toEqual([]);
	});

	it('finds the order by its external reference, design and print', async () => {
		const { printId, id } = await placed();

		for (const query of [
			'externalRef=wc-42',
			'designId=dsn_1',
			`printId=${printId}&externalRef=wc-42`,
		]) {
			const body = await (await get(`/v1/orders?${query}`)).json();
			expect(body.orders.map((o: { id: string }) => o.id)).toEqual([id]);
		}
		const other = await get('/v1/orders?externalRef=wc-1');
		expect((await other.json()).total).toBe(0);
		expect(findPrint(fake.state, printId, MAIN.id)?.printshops).toEqual([
			'Fixture Print AB',
		]);
		expect(fake.violations).toEqual([]);
	});

	it('reads one order with its history', async () => {
		const { id } = await placed();

		const response = await get(`/v1/orders/${id}`);

		expect(response.status).toBe(200);
		expect(await response.json()).toMatchObject({
			id,
			history: [{ seq: 1, type: 'order.submitted', status: 'submitted' }],
			retryable: false,
			canRetry: false,
			statusMoves: [],
		});
		expect(fake.violations).toEqual([]);
	});

	it('places the armed number of orders as failed, then submits again', async () => {
		const armed = await send(fake, '/__fake/orders/fail', {
			method: 'POST',
			json: { reason: 'Out of stock at the printshop.', times: 1 },
		});
		expect(armed.status).toBe(200);
		await approvedDesign('dsn_1', {
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const printId = print('dsn_1');

		const failed = await order(printId, 'order-1');
		const next = await order(printId, 'order-2');

		expect(failed.status).toBe(201);
		const body = await failed.json();
		expect(body).toMatchObject({
			status: 'failed',
			lastError: 'Out of stock at the printshop.',
		});
		const read = await (await get(`/v1/orders/${body.id}`)).json();
		expect(read.history).toMatchObject([
			{ type: 'order.failed', status: 'failed' },
		]);
		expect((await next.json()).status).toBe('submitted');
		expect(fake.violations).toEqual([]);
	});

	it('refuses an order failure armed with a count that is no count', async () => {
		const response = await send(fake, '/__fake/orders/fail', {
			method: 'POST',
			json: { times: 0 },
		});

		expect(response.status).toBe(400);
		expect(fake.state.armedOrderOutcome).toBeNull();
	});

	it("refuses another store's print and answers an unknown order", async () => {
		await approvedDesign('dsn_other', { store: FIXTURE.store.other.id });
		const printId = seedPrint(fake, 'dsn_other', {
			storeId: FIXTURE.store.other.id,
		});

		const refused = await post('/v1/orders', { prints: [{ printId }] }, 'k');
		const unknown = await get('/v1/orders/ord_unknown');

		expect(refused.status).toBe(404);
		expect(await refused.json()).toEqual({ error: 'print_not_found' });
		expect(unknown.status).toBe(404);
		expect(await unknown.json()).toEqual({ error: 'order_not_found' });
		expect(fake.violations).toEqual([]);
	});

	it("sends a print on a blank to the blank's printshop", async () => {
		await approvedDesign('dsn_1', {
			blankId: FIXTURE.blank.hoodie,
			placementId: FIXTURE.placement.front,
		});
		const printId = print('dsn_1');

		const response = await order(
			printId,
			'order-1',
			FIXTURE.variant.hoodieWhiteS,
		);

		expect(response.status).toBe(201);
		expect((await response.json()).printshopConnectionId).toBe(
			FIXTURE.printshop.other,
		);
		expect(fake.violations).toEqual([]);
	});

	it.each([
		[
			'another printshop',
			FIXTURE.printshop.other,
			422,
			'printshop_differs_from_blank',
		],
		["the blank's own printshop", FIXTURE.printshop.main, 400, 'bad_request'],
	])(
		'refuses a print on a blank naming %s',
		async (_, printshopId, status, error) => {
			await approvedDesign('dsn_1', {
				blankId: FIXTURE.blank.tee,
				placementId: FIXTURE.placement.front,
			});
			const printId = print('dsn_1');

			const response = await post(
				'/v1/orders',
				{
					prints: [{ printId }],
					printshopId,
					variant: FIXTURE.variant.teeWhiteS,
				},
				'k',
			);

			expect(response.status).toBe(status);
			expect((await response.json()).error).toBe(error);
			expect(fake.violations).toEqual([]);
		},
	);

	it("sends a print on no blank to the printshop named, else the tenant's default", async () => {
		await approvedDesign('dsn_1');
		const printId = print('dsn_1');

		const named = await post(
			'/v1/orders',
			{ prints: [{ printId }], printshopId: FIXTURE.printshop.other },
			'k1',
		);
		const fallback = await post(
			'/v1/orders',
			{ prints: [{ printId }], reorder: true },
			'k2',
		);

		expect((await named.json()).printshopConnectionId).toBe(
			FIXTURE.printshop.other,
		);
		expect((await fallback.json()).printshopConnectionId).toBe(
			FIXTURE.printshop.main,
		);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a print on no blank when the tenant has no default printshop', async () => {
		for (const printshop of fake.state.printshops.values()) {
			printshop.isDefault = false;
		}
		await approvedDesign('dsn_1');
		const printId = print('dsn_1');

		const response = await post('/v1/orders', { prints: [{ printId }] }, 'k');

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({ error: 'print_needs_printshop' });
		expect(fake.violations).toEqual([]);
	});

	it("places a print whose blank's printshop is disconnected as a failed order", async () => {
		const main = fake.state.printshops.get(FIXTURE.printshop.main);
		if (main === undefined) {
			throw new Error('No main printshop.');
		}
		main.active = false;
		await approvedDesign('dsn_1', {
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		const printId = print('dsn_1');

		const response = await order(printId);

		expect(response.status).toBe(201);
		expect(await response.json()).toMatchObject({
			status: 'failed',
			lastError: 'Fixture Print AB is disconnected.',
			printshopConnectionId: FIXTURE.printshop.main,
		});
		expect(fake.violations).toEqual([]);
	});

	it('refuses a print on no blank naming a disconnected printshop', async () => {
		const other = fake.state.printshops.get(FIXTURE.printshop.other);
		if (other === undefined) {
			throw new Error('No other printshop.');
		}
		other.active = false;
		await approvedDesign('dsn_1');
		const printId = print('dsn_1');

		const response = await post(
			'/v1/orders',
			{ prints: [{ printId }], printshopId: FIXTURE.printshop.other },
			'k',
		);

		expect(response.status).toBe(422);
		expect((await response.json()).error).toBe('printshop_disconnected');
		expect((await (await get('/v1/orders')).json()).total).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	/** Prints each design, seeded approved with its seed, orders the prints
	 *  as one item, checks nothing was written and answers the refusal. */
	async function refusedOrder(
		designs: Record<string, Record<string, unknown>>,
	): Promise<{ status: number; body: Record<string, unknown> }> {
		const printIds: string[] = [];
		for (const [id, extra] of Object.entries(designs)) {
			await approvedDesign(id, extra);
			printIds.push(print(id));
		}
		const response = await post(
			'/v1/orders',
			{ prints: printIds.map((printId) => ({ printId })), ...shipping },
			'order-refused',
		);
		expect((await (await get('/v1/orders')).json()).total).toBe(0);
		expect(fake.violations).toEqual([]);
		return { status: response.status, body: await response.json() };
	}

	const front = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
	};
	const back = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.back,
	};

	it('orders a front and a back on one blank as one item', async () => {
		await approvedDesign('dsn_front', front);
		await approvedDesign('dsn_back', back);
		const prints = [
			{ printId: print('dsn_front') },
			{ printId: print('dsn_back') },
		];

		const response = await post(
			'/v1/orders',
			{ prints, variant: FIXTURE.variant.teeBlackM, ...shipping },
			'k',
		);

		expect(response.status).toBe(201);
		expect((await response.json()).printIds).toEqual(
			prints.map((p) => p.printId),
		);
		expect(fake.violations).toEqual([]);
	});

	it('refuses one of several prints whose design has no blank', async () => {
		const { status, body } = await refusedOrder({
			dsn_front: front,
			dsn_1: {},
		});

		expect(status).toBe(422);
		expect(body).toEqual({
			error: 'print_without_blank',
			printId: expect.any(String),
		});
	});

	it('refuses one of several prints whose design has no placement', async () => {
		const { status, body } = await refusedOrder({
			dsn_front: front,
			dsn_1: { blankId: FIXTURE.blank.tee },
		});

		expect(status).toBe(422);
		expect(body).toEqual({
			error: 'print_without_placement',
			printId: expect.any(String),
		});
	});

	it('refuses prints on different blanks', async () => {
		const { status, body } = await refusedOrder({
			dsn_front: front,
			dsn_back: { ...back, blankId: FIXTURE.blank.hoodie },
		});

		expect(status).toBe(422);
		expect(body).toEqual({ error: 'mixed_blanks' });
	});

	it('refuses two prints in one placement', async () => {
		const { status, body } = await refusedOrder({
			dsn_1: front,
			dsn_2: front,
		});

		expect(status).toBe(422);
		expect(body).toEqual({
			error: 'duplicate_placement',
			placementId: FIXTURE.placement.front,
		});
	});

	it('refuses a print of a revision the design has moved past', async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');
		await approvedDesign('dsn_1', { ...front, revision: 2 });

		const response = await order(printId);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({ error: 'print_superseded' });
		expect((await (await get('/v1/orders')).json()).total).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it('keeps a copy of the variant ordered and names each print as the printshop knows it', async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const response = await order(printId, 'k', FIXTURE.variant.teeBlackM);

		expect(response.status).toBe(201);
		expect(await response.json()).toMatchObject({
			blankId: FIXTURE.blank.tee,
			blank: {
				name: 'Fixture tee',
				optionAxes: ['Color', 'Size'],
				variants: TEE_VARIANTS,
			},
			variantRef: FIXTURE.variant.teeBlackM,
			variant: {
				ref: FIXTURE.variant.teeBlackM,
				options: { Color: 'Black', Size: 'M' },
				fields: [],
			},
			printshopAsPlaced: { name: 'Fixture Print AB', adapter: 'custom' },
			prints: [
				{ printId, designRevision: 1, printedAs: 'Front', placementFields: [] },
			],
		});
		expect(fake.violations).toEqual([]);
	});

	it.each([
		['the merchant', FIXTURE.variant.teeRedM],
		['its printshop', FIXTURE.variant.teeRedS],
	])('refuses a variant retired by %s', async (_, variant) => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const response = await order(printId, 'k', variant);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'variant_retired',
			variant,
			reason: expect.any(String),
		});
		expect((await (await get('/v1/orders')).json()).total).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it.each([
		['of another blank', front, FIXTURE.variant.hoodieWhiteS],
		['for a print on no blank', {}, FIXTURE.variant.teeWhiteS],
	])('refuses a variant %s', async (_, seed, variant) => {
		await approvedDesign('dsn_1', seed);
		const printId = print('dsn_1');

		const response = await order(printId, 'k', variant);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'variant_not_of_blank',
			variant,
			reason: expect.any(String),
		});
		expect((await (await get('/v1/orders')).json()).total).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it('refuses an order naming no variant of a blank with several', async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const response = await post('/v1/orders', { prints: [{ printId }] }, 'k');

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'variant_required',
			variants: TEE_VARIANTS,
			reason: expect.any(String),
		});
		expect((await (await get('/v1/orders')).json()).total).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it("refuses a blank's only variant, retired, when none is named", async () => {
		const [only] = fake.state.blanks.get(FIXTURE.blank.bare)?.variants ?? [];
		if (only === undefined) {
			throw new Error('The bare blank has no variant.');
		}
		only.retired = { reason: 'retired' };
		await approvedDesign('dsn_tote', { blankId: FIXTURE.blank.bare });

		const response = await post(
			'/v1/orders',
			{ prints: [{ printId: print('dsn_tote') }] },
			'k',
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'variant_retired',
			variant: FIXTURE.variant.tote,
			reason: expect.any(String),
		});
		expect((await (await get('/v1/orders')).json()).total).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it("orders a blank's only variant when none is named, and none for a print on no blank", async () => {
		await approvedDesign('dsn_tote', { blankId: FIXTURE.blank.bare });
		await approvedDesign('dsn_1');

		const tote = await post(
			'/v1/orders',
			{ prints: [{ printId: print('dsn_tote') }] },
			'k1',
		);
		const unplaced = await post(
			'/v1/orders',
			{ prints: [{ printId: print('dsn_1') }] },
			'k2',
		);

		expect(await tote.json()).toMatchObject({
			blankId: FIXTURE.blank.bare,
			variantRef: FIXTURE.variant.tote,
			variant: { ref: FIXTURE.variant.tote, options: {}, fields: [] },
		});
		expect(await unplaced.json()).toMatchObject({
			blankId: null,
			blank: null,
			variantRef: null,
			variant: null,
		});
		expect(fake.violations).toEqual([]);
	});
});

describe('order moves', () => {
	const shipping = {
		recipient: { name: 'Ada Lovelace' },
		shippingAddress: { line1: 'Storgatan 1', city: 'Lund', country: 'SE' },
	};
	const failedReason = 'The printshop has no order under this number.';

	function patch(id: string, json: unknown) {
		return send(fake, `/v1/orders/${id}`, {
			method: 'PATCH',
			json,
			headers: asStore,
		});
	}

	function arm(outcome: 'fail' | 'outcome-unknown', times = 1) {
		return send(fake, `/__fake/orders/${outcome}`, {
			method: 'POST',
			json: { times },
		});
	}

	async function read(id: string) {
		const response = await get(`/v1/orders/${id}`);
		expect(response.status).toBe(200);
		return response.json();
	}

	/** Lets the main store's tenant move orders as a sandbox does. */
	function inSandbox(): void {
		const tenant = fake.state.tenants.get(FIXTURE.tenant.live);
		if (tenant === undefined) {
			throw new Error('The fixture set has no live tenant.');
		}
		tenant.environment = 'sandbox';
	}

	let printId: string;
	let orders = 0;

	/** Orders the print; answers the new order's id. */
	async function placed(): Promise<string> {
		orders += 1;
		const response = await post(
			'/v1/orders',
			{
				prints: [{ printId }],
				externalRef: 'wc-42',
				variant: FIXTURE.variant.teeWhiteS,
				...shipping,
				// Each test orders the one print as often as it needs.
				reorder: true,
			},
			`order-${orders}`,
		);
		expect(response.status).toBe(201);
		return (await response.json()).id;
	}

	beforeEach(async () => {
		await approvedDesign('dsn_1', {
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});
		printId = print('dsn_1');
	});

	describe('an outcome armed unknown', () => {
		it('places the armed number of orders outcome_unknown, then submits again', async () => {
			const armed = await arm('outcome-unknown', 2);
			expect(armed.status).toBe(200);
			expect(await armed.json()).toEqual({
				outcome: 'outcome_unknown',
				remaining: 2,
			});

			const first = await read(await placed());
			const second = await read(await placed());
			const third = await read(await placed());

			for (const unknown of [first, second]) {
				expect(unknown).toMatchObject({
					status: 'outcome_unknown',
					lastError: null,
					history: [{ type: 'order.submitted', status: 'outcome_unknown' }],
					canRetry: false,
					retryable: false,
					statusMoves: ['submitted', 'failed'],
				});
			}
			expect(third.status).toBe('submitted');
			expect(fake.violations).toEqual([]);
		});

		it('refuses a count that is no count', async () => {
			const response = await arm('outcome-unknown', 0);

			expect(response.status).toBe(400);
			expect(fake.state.armedOrderOutcome).toBeNull();
		});

		it('replaces an armed failure', async () => {
			await arm('fail');
			await arm('outcome-unknown');

			expect((await read(await placed())).status).toBe('outcome_unknown');
			expect((await read(await placed())).status).toBe('submitted');
		});
	});

	describe('PATCH /v1/orders/{id}', () => {
		it("settles an unknown outcome as submitted with the printshop's reference", async () => {
			await arm('outcome-unknown');
			const id = await placed();

			const response = await patch(id, {
				status: 'submitted',
				reason: 'The printshop has it.',
				externalOrderRef: 'PS-1001',
			});

			expect(response.status).toBe(200);
			expect(await response.json()).toMatchObject({
				id,
				status: 'submitted',
				externalOrderRef: 'PS-1001',
				lastError: null,
			});
			expect(await read(id)).toMatchObject({
				statusMoves: [],
				history: [
					{ seq: 1, status: 'outcome_unknown' },
					{
						seq: 2,
						type: 'order.status_changed',
						status: 'submitted',
						reason: 'The printshop has it.',
						via: 'store_key',
					},
				],
			});
			expect(fake.violations).toEqual([]);
		});

		it('settles an unknown outcome as failed, which can then be retried', async () => {
			await arm('outcome-unknown');
			const id = await placed();

			const response = await patch(id, {
				status: 'failed',
				reason: failedReason,
			});

			expect(response.status).toBe(200);
			expect(await read(id)).toMatchObject({
				status: 'failed',
				lastError: failedReason,
				canRetry: true,
				retryable: true,
				statusMoves: [],
				history: [
					{ seq: 1 },
					{ seq: 2, type: 'order.failed', status: 'failed', via: 'store_key' },
				],
			});
			expect(fake.violations).toEqual([]);
		});

		it('refuses to settle an unknown outcome without a reason', async () => {
			await arm('outcome-unknown');
			const id = await placed();

			const response = await patch(id, { status: 'submitted' });

			expect(response.status).toBe(422);
			expect(await response.json()).toEqual({
				error: 'order_status_reason_required',
				from: 'outcome_unknown',
			});
			expect((await read(id)).status).toBe('outcome_unknown');
			expect(fake.violations).toEqual([]);
		});

		it('refuses a reference another order of the tenant holds', async () => {
			await arm('outcome-unknown', 2);
			const first = await placed();
			const second = await placed();
			const settle = {
				status: 'submitted',
				reason: 'The printshop has it.',
				externalOrderRef: 'PS-1001',
			};
			expect((await patch(first, settle)).status).toBe(200);

			const response = await patch(second, settle);

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'order_reference_taken',
			});
			expect((await read(second)).externalOrderRef).toBeNull();
			expect(fake.violations).toEqual([]);
		});

		it('refuses any move on live once the outcome is known', async () => {
			const id = await placed();

			const response = await patch(id, { status: 'shipped' });

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'order_status_not_allowed',
				from: 'submitted',
				to: 'shipped',
			});
			expect(fake.violations).toEqual([]);
		});

		it('refuses tracking without shipped and a reference without submitted', async () => {
			await arm('outcome-unknown');
			const id = await placed();

			const tracking = await patch(id, {
				status: 'failed',
				reason: failedReason,
				tracking: { number: '0037' },
			});
			const reference = await patch(id, {
				status: 'failed',
				reason: failedReason,
				externalOrderRef: 'PS-1001',
			});

			for (const response of [tracking, reference]) {
				expect(response.status).toBe(400);
				expect(await response.json()).toEqual({ error: 'bad_request' });
			}
			expect((await read(id)).status).toBe('outcome_unknown');
			expect(fake.violations).toEqual([]);
		});

		it('answers an unknown order order_not_found', async () => {
			const response = await patch('ord_unknown', { status: 'failed' });

			expect(response.status).toBe(404);
			expect(await response.json()).toEqual({ error: 'order_not_found' });
			expect(fake.violations).toEqual([]);
		});

		describe('in a sandbox', () => {
			beforeEach(inSandbox);

			it('moves an order forward to shipped with its tracking', async () => {
				const id = await placed();
				expect((await read(id)).statusMoves).toEqual([
					'queued',
					'printed',
					'shipped',
					'failed',
				]);
				const tracking = {
					carrier: 'PostNord',
					number: '00370725001234567890',
				};

				const queued = await patch(id, { status: 'queued' });
				const shipped = await patch(id, { status: 'shipped', tracking });

				expect(queued.status).toBe(200);
				expect(shipped.status).toBe(200);
				expect(await read(id)).toMatchObject({
					status: 'shipped',
					tracking,
					statusMoves: [],
					history: [
						{ status: 'submitted' },
						{ type: 'order.status_changed', status: 'queued' },
						{ type: 'order.status_changed', status: 'shipped' },
					],
				});
				expect(fake.violations).toEqual([]);
			});

			it('refuses a move back, to cancelled, or out of a final status', async () => {
				const id = await placed();
				await patch(id, { status: 'printed' });

				const back = await patch(id, { status: 'queued' });
				const cancelled = await patch(id, { status: 'cancelled' });
				await patch(id, { status: 'failed' });
				const out = await patch(id, { status: 'shipped' });

				expect(await back.json()).toEqual({
					error: 'order_status_not_allowed',
					from: 'printed',
					to: 'queued',
				});
				expect(cancelled.status).toBe(409);
				expect(await out.json()).toEqual({
					error: 'order_status_not_allowed',
					from: 'failed',
					to: 'shipped',
				});
				expect(fake.violations).toEqual([]);
			});

			it('fails an order without a reason', async () => {
				const id = await placed();

				const response = await patch(id, { status: 'failed' });

				expect(response.status).toBe(200);
				expect(await response.json()).toMatchObject({
					status: 'failed',
					lastError: null,
				});
				expect(fake.violations).toEqual([]);
			});
		});
	});

	describe('POST /v1/orders/{id}/retry', () => {
		function retry(id: string, json?: unknown) {
			return send(fake, `/v1/orders/${id}/retry`, {
				method: 'POST',
				json,
				headers: asStore,
			});
		}

		it('places a new order for a failed one, and marks the failed one replaced', async () => {
			await arm('fail');
			const failed = await placed();

			const response = await retry(failed);

			expect(response.status).toBe(201);
			const body = await response.json();
			expect(body).toMatchObject({
				status: 'submitted',
				replacesOrderId: failed,
				printIds: [printId],
				quantity: 1,
				externalRef: 'wc-42',
				...shipping,
				printshopConnectionId: FIXTURE.printshop.main,
				variantRef: FIXTURE.variant.teeWhiteS,
			});
			expect(body.id).not.toBe(failed);
			expect(body.replacedByOrderId).toBeNull();
			expect(await read(failed)).toMatchObject({
				canRetry: false,
				retryable: false,
				replacedByOrderId: body.id,
			});
			const list = await (await get('/v1/orders')).json();
			expect(list.total).toBe(2);
			expect(list.orders).toEqual(
				expect.arrayContaining([
					expect.objectContaining({ id: failed, replacedByOrderId: body.id }),
					expect.objectContaining({ id: body.id, replacedByOrderId: null }),
				]),
			);
			expect(fake.violations).toEqual([]);
		});

		it('refuses an order already retried, naming the newer one', async () => {
			await arm('fail');
			const failed = await placed();
			const newer = (await (await retry(failed, {})).json()).id;

			const response = await retry(failed);

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'order_superseded',
				newerOrderId: newer,
			});
			expect(fake.violations).toEqual([]);
		});

		it('fails the retry too when a failure is armed', async () => {
			await arm('fail', 2);
			const failed = await placed();

			const response = await retry(failed);

			expect(response.status).toBe(201);
			const body = await response.json();
			expect(body).toMatchObject({ status: 'failed', replacesOrderId: failed });
			expect((await read(body.id)).canRetry).toBe(true);
			expect(fake.violations).toEqual([]);
		});

		it('draws a failed print again in place, and the new order waits for it', async () => {
			await arm('fail');
			const failed = await placed();
			const drawn = findPrint(fake.state, printId, MAIN.id);
			if (drawn === undefined) {
				throw new Error('No print.');
			}
			drawn.status = 'failed';

			const response = await retry(failed);

			expect(response.status).toBe(201);
			const body = await response.json();
			expect(body).toMatchObject({
				status: 'awaiting_prints',
				replacesOrderId: failed,
				printIds: [printId],
			});
			expect(drawn.status).toBe('created');
			expect(fake.violations).toEqual([]);
		});

		it('places a retry while a print is still being made, waiting for it', async () => {
			await arm('fail');
			const failed = await placed();
			const drawn = findPrint(fake.state, printId, MAIN.id);
			if (drawn === undefined) {
				throw new Error('No print.');
			}
			drawn.status = 'created';

			expect((await read(failed)).canRetry).toBe(true);
			const response = await retry(failed);

			expect(response.status).toBe(201);
			expect(await response.json()).toMatchObject({
				status: 'awaiting_prints',
				replacesOrderId: failed,
			});
			expect(drawn.status).toBe('created');
			expect(fake.violations).toEqual([]);
		});

		it('refuses an order that has not failed', async () => {
			const id = await placed();

			const response = await retry(id);

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'order_not_retryable',
				status: 'submitted',
			});
			expect(fake.violations).toEqual([]);
		});

		it('refuses a retry once the printshop the order went to is disconnected', async () => {
			await arm('fail');
			const failed = await placed();
			const main = fake.state.printshops.get(FIXTURE.printshop.main);
			if (main === undefined) {
				throw new Error('No main printshop.');
			}
			main.active = false;

			const response = await retry(failed);

			expect(response.status).toBe(422);
			expect((await response.json()).error).toBe('printshop_disconnected');
			expect((await read(failed)).canRetry).toBe(true);
			expect(fake.violations).toEqual([]);
		});

		it('refuses a retry of a print of a revision the design has moved past, and offers none', async () => {
			await arm('fail');
			const failed = await placed();
			await approvedDesign('dsn_1', {
				blankId: FIXTURE.blank.tee,
				placementId: FIXTURE.placement.front,
				revision: 2,
			});

			expect(await read(failed)).toMatchObject({
				canRetry: false,
				retryable: false,
			});
			const response = await retry(failed);

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({ error: 'print_superseded' });
			expect(fake.violations).toEqual([]);
		});

		it('answers an unknown order order_not_found', async () => {
			const response = await retry('ord_unknown');

			expect(response.status).toBe(404);
			expect(await response.json()).toEqual({ error: 'order_not_found' });
			expect(fake.violations).toEqual([]);
		});
	});

	describe('POST /v1/orders/{id}/cancel', () => {
		function cancel(id: string) {
			return send(fake, `/v1/orders/${id}/cancel`, {
				method: 'POST',
				headers: asStore,
			});
		}

		it('cancels a submitted order, then answers it unchanged', async () => {
			const id = await placed();

			const first = await cancel(id);
			const again = await cancel(id);

			expect(first.status).toBe(200);
			expect(await first.json()).toMatchObject({ id, status: 'cancelled' });
			expect(again.status).toBe(200);
			expect((await read(id)).history).toMatchObject([
				{ type: 'order.submitted' },
				{ type: 'order.cancelled', status: 'cancelled', via: 'store_key' },
			]);
			expect(fake.violations).toEqual([]);
		});

		it('cancels an order whose outcome is unknown', async () => {
			await arm('outcome-unknown');
			const id = await placed();

			const response = await cancel(id);

			expect(response.status).toBe(200);
			expect((await response.json()).status).toBe('cancelled');
			expect(fake.violations).toEqual([]);
		});

		it('refuses a failed order', async () => {
			await arm('fail');
			const id = await placed();

			const response = await cancel(id);

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'order_status_not_allowed',
				from: 'failed',
				to: 'cancelled',
			});
			expect(fake.violations).toEqual([]);
		});

		it('refuses an order the printshop has made', async () => {
			inSandbox();
			const id = await placed();
			await patch(id, { status: 'printed' });

			const response = await cancel(id);

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'order_at_printshop',
				cause: 'made',
			});
			expect((await read(id)).status).toBe('printed');
			expect(fake.violations).toEqual([]);
		});

		it('answers an unknown order order_not_found', async () => {
			const response = await cancel('ord_unknown');

			expect(response.status).toBe(404);
			expect(await response.json()).toEqual({ error: 'order_not_found' });
			expect(fake.violations).toEqual([]);
		});
	});

	describe('PATCH /v1/orders/{id}/delivery', () => {
		const moved = {
			recipient: { name: 'Ada Lovelace', email: 'ada@example.org' },
			shippingAddress: {
				line1: 'Kungsgatan 9',
				city: 'Göteborg',
				postalCode: '411 19',
				country: 'SE',
			},
		};

		function changeDelivery(id: string, key: string) {
			return send(fake, `/v1/orders/${id}/delivery`, {
				method: 'PATCH',
				json: moved,
				headers: { ...asStore, 'idempotency-key': key },
			});
		}

		it('gives a submitted order the new address, accepted, once for a key', async () => {
			const id = await placed();

			const first = await changeDelivery(id, 'delivery-1');
			const replay = await changeDelivery(id, 'delivery-1');

			expect(first.status).toBe(200);
			expect(await first.json()).toMatchObject({
				id,
				...moved,
				delivery: { state: 'accepted', words: null },
			});
			expect(replay.headers.get('Idempotent-Replayed')).toBe('true');
			const order = await read(id);
			expect(order.deliveryChanges).toMatchObject([
				{ added: false, by: 'store_key', outcome: 'accepted', words: null },
			]);
			expect(order.deliveryChangeRefusal).toBeNull();
			expect(order.history).toMatchObject([
				{ type: 'order.submitted' },
				{ type: 'order.delivery_changed', via: 'store_key' },
			]);
			expect(fake.violations).toEqual([]);
		});

		it('carries the new address with an order still waiting for its prints', async () => {
			await approvedDesign('dsn_waiting', {
				blankId: FIXTURE.blank.tee,
				placementId: FIXTURE.placement.front,
			});
			const printId = seedPrint(fake, 'dsn_waiting', { status: 'created' });
			const order = await post(
				'/v1/orders',
				{ prints: [{ printId }], variant: FIXTURE.variant.teeWhiteS },
				'order-waiting',
			);
			const { id } = await order.json();

			const response = await changeDelivery(id, 'delivery-1');

			expect(response.status).toBe(200);
			expect(await response.json()).toMatchObject({
				status: 'awaiting_prints',
				...moved,
				delivery: { state: 'carried', words: null },
			});
			expect((await read(id)).deliveryChangeRefusal).toBeNull();
			expect(fake.violations).toEqual([]);
		});

		it('refuses an order its printshop is making, naming its status', async () => {
			inSandbox();
			const id = await placed();
			await patch(id, { status: 'queued' });

			const response = await changeDelivery(id, 'delivery-1');

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'order_past_address_change',
				status: 'queued',
				words: null,
			});
			expect((await read(id)).deliveryChangeRefusal).toEqual({
				code: 'order_past_address_change',
				status: 'queued',
				unanswered: false,
			});
			expect(fake.violations).toEqual([]);
		});

		it('refuses a printshop that takes no address changes, naming it', async () => {
			const printshop = fake.state.printshops.get(FIXTURE.printshop.main);
			if (printshop === undefined) {
				throw new Error('The fixture set has no main printshop.');
			}
			printshop.acceptsAddressUpdates = false;
			const id = await placed();

			const response = await changeDelivery(id, 'delivery-1');

			expect(response.status).toBe(409);
			expect(await response.json()).toEqual({
				error: 'address_change_not_accepted',
				printshop: printshop.name,
			});
			expect((await read(id)).shippingAddress).toEqual(
				shipping.shippingAddress,
			);
			expect(fake.violations).toEqual([]);
		});

		it('answers an unknown order order_not_found', async () => {
			const response = await changeDelivery('ord_unknown', 'delivery-1');

			expect(response.status).toBe(404);
			expect(fake.violations).toEqual([]);
		});
	});
});

describe('GET /v1/orders', () => {
	/** How far apart the orders of a test are placed. */
	const STEP_MS = 1000;
	const tee = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
	};

	/** Orders a print of a design seeded approved as `seed`, with `extra` in
	 *  the request; answers the order's id. */
	async function placeOrder(
		designId: string,
		seed: Record<string, unknown>,
		extra: Record<string, unknown> = {},
	): Promise<string> {
		await approvedDesign(designId, seed);
		const printId = print(designId);
		const response = await post(
			'/v1/orders',
			{ prints: [{ printId }], ...extra },
			`o-${designId}`,
		);
		expect(response.status).toBe(201);
		return (await response.json()).id;
	}

	async function ids(query: string): Promise<string[]> {
		const response = await get(`/v1/orders?${query}`);
		expect(response.status).toBe(200);
		return (await response.json()).orders.map((o: { id: string }) => o.id);
	}

	/** Three orders a second apart: a white tee for Ada, a black tee for
	 *  Grace referenced wc-7, and a print on no blank, cancelled. */
	async function three(): Promise<{
		white: string;
		black: string;
		loose: string;
	}> {
		const white = await placeOrder('dsn_white', tee, {
			variant: FIXTURE.variant.teeWhiteS,
			externalRef: 'wc-1',
			recipient: { name: 'Ada Lovelace' },
		});
		fake.clock.advance(STEP_MS);
		const black = await placeOrder('dsn_black', tee, {
			variant: FIXTURE.variant.teeBlackM,
			externalRef: 'wc-7',
			recipient: { name: 'Grace Hopper' },
		});
		fake.clock.advance(STEP_MS);
		const loose = await placeOrder('dsn_loose', {});
		const cancelled = await send(fake, `/v1/orders/${loose}/cancel`, {
			method: 'POST',
			headers: asStore,
		});
		expect(cancelled.status).toBe(200);
		return { white, black, loose };
	}

	it('lists the latest submitted first, or as sort and order ask', async () => {
		const { white, black, loose } = await three();
		fake.clock.advance(STEP_MS);
		const moved = await send(fake, `/v1/orders/${white}/cancel`, {
			method: 'POST',
			headers: asStore,
		});
		expect(moved.status).toBe(200);

		expect(await ids('')).toEqual([loose, black, white]);
		expect(await ids('order=asc')).toEqual([white, black, loose]);
		expect(await ids('sort=updated')).toEqual([white, loose, black]);
		expect(await ids('sort=updated&order=asc')).toEqual([black, loose, white]);
		expect(fake.violations).toEqual([]);
	});

	it('keeps the orders in any status named, counting each under the other filters', async () => {
		const { white, black, loose } = await three();

		const comma = await (
			await get('/v1/orders?status=cancelled,failed')
		).json();
		const repeated = await ids('status=submitted&status=cancelled');
		const onTee = await (
			await get(`/v1/orders?status=cancelled&blankId=${FIXTURE.blank.tee}`)
		).json();

		expect(comma.orders.map((o: { id: string }) => o.id)).toEqual([loose]);
		expect(comma.total).toBe(1);
		expect(comma.counts).toEqual({ ...NO_ORDERS, submitted: 2, cancelled: 1 });
		expect(repeated).toEqual([loose, black, white]);
		expect(onTee.total).toBe(0);
		expect(onTee.counts).toEqual({ ...NO_ORDERS, submitted: 2 });
		expect(fake.violations).toEqual([]);
	});

	it('refuses a status no order can be in', async () => {
		const response = await get('/v1/orders?status=submitted,lost');

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'invalid_query' });
		expect(fake.violations).toEqual([]);
	});

	it('narrows by blank, variant, printshop and when the order was submitted', async () => {
		const { white, black, loose } = await three();
		const submittedAt = async (id: string): Promise<number> =>
			(await (await get(`/v1/orders/${id}`)).json()).submittedAt;
		const whiteAt = await submittedAt(white);
		const blackAt = await submittedAt(black);

		expect(await ids(`blankId=${FIXTURE.blank.tee}`)).toEqual([black, white]);
		expect(await ids(`variant=${FIXTURE.variant.teeBlackM}`)).toEqual([black]);
		expect(await ids(`printshop=${FIXTURE.printshop.main}`)).toEqual([
			loose,
			black,
			white,
		]);
		expect(await ids(`printshop=${FIXTURE.printshop.other}`)).toEqual([]);
		expect(await ids(`submittedFrom=${blackAt}`)).toEqual([loose, black]);
		expect(
			await ids(`submittedFrom=${whiteAt}&submittedTo=${blackAt}`),
		).toEqual([black, white]);
		expect(fake.violations).toEqual([]);
	});

	it("finds an order by the start of its reference, id or recipient's name, case aside", async () => {
		const { white, black } = await three();

		expect(await ids('q=WC-7')).toEqual([black]);
		expect(await ids('q=ada')).toEqual([white]);
		expect(await ids(`q=${white.slice(0, white.length - 1)}`)).toEqual([white]);
		expect(await ids('q=lovelace')).toEqual([]);
		expect(fake.violations).toEqual([]);
	});

	it('reads the blank as it is now and the revision each print was made from', async () => {
		await approvedDesign('dsn_1', { ...tee, revision: 2 });
		const printId = print('dsn_1');
		const response = await post(
			'/v1/orders',
			{ prints: [{ printId }], variant: FIXTURE.variant.teeWhiteM },
			'o1',
		);
		const { id } = await response.json();
		const blank = fake.state.blanks.get(FIXTURE.blank.tee);
		if (blank === undefined) {
			throw new Error('The fixture set has no tee.');
		}
		blank.name = 'Renamed tee';

		const [listed] = (await (await get('/v1/orders')).json()).orders;
		fake.state.blanks.delete(FIXTURE.blank.tee);
		const gone = await (await get(`/v1/orders/${id}`)).json();

		expect(listed).toMatchObject({
			id,
			blankId: FIXTURE.blank.tee,
			blank: { name: 'Renamed tee', variants: TEE_VARIANTS },
			prints: [{ printId, designId: 'dsn_1', designRevision: 2 }],
		});
		expect(gone).toMatchObject({ blankId: FIXTURE.blank.tee, blank: null });
		expect(fake.violations).toEqual([]);
	});
});
