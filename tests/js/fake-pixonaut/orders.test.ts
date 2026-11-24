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
	open: 0,
	partly_shipped: 0,
	shipped: 0,
	needs_attention: 0,
	cancelled: 0,
	done: 0,
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

/** An order request of the one item, with `top` beside it. */
function oneItem(
	item: Record<string, unknown>,
	top: Record<string, unknown> = {},
) {
	return { ...top, items: [item] };
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

const front = {
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.front,
};
const back = {
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.back,
};

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /v1/orders with designs', () => {
	/** Orders the designs as one item of the tee's white S. */
	function orderDesigns(designIds: string[], key: string, extra = {}) {
		return post(
			'/v1/orders',
			oneItem({
				designs: designIds.map((designId) => ({ designId })),
				variant: FIXTURE.variant.teeWhiteS,
				...extra,
			}),
			key,
		);
	}

	it('prints each design and orders the prints as one item, lead first', async () => {
		await approvedDesign('dsn_front', front);
		await approvedDesign('dsn_back', back);

		const response = await orderDesigns(['dsn_front', 'dsn_back'], 'o1');

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body).toMatchObject({
			status: 'open',
			items: [
				{
					designId: 'dsn_front',
					status: 'submitted',
					prints: [
						{ designId: 'dsn_front', designRevision: 1, status: 'completed' },
						{ designId: 'dsn_back', designRevision: 1, status: 'completed' },
					],
				},
			],
		});
		const [front_, back_] = body.items[0].printIds.map((id: string) =>
			findPrint(fake.state, id, MAIN.id),
		);
		expect(front_).toMatchObject({
			designId: 'dsn_front',
			orderCount: 1,
			jobTicket: { product: { tenantId: FIXTURE.tenant.live } },
		});
		expect(back_).toMatchObject({ designId: 'dsn_back', orderCount: 1 });
		expect(front_?.number).not.toBe(back_?.number);
		expect(fake.violations).toEqual([]);
	});

	it.each([
		['both prints and designs', { prints: [{ printId: 'prt_1' }] }],
		['neither', { designs: undefined }],
	])('refuses an item naming %s', async (_, extra) => {
		await approvedDesign('dsn_1', front);

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
		'refuses %s, naming its item, and writes nothing',
		async (_, designId, seed, status, error) => {
			await approvedDesign('dsn_1', front);
			if (designId !== 'dsn_unknown') {
				await approvedDesign(designId, { ...front, ...seed });
			}

			const response = await post(
				'/v1/orders',
				{
					items: [
						{
							designs: [{ designId: 'dsn_1' }],
							variant: FIXTURE.variant.teeWhiteS,
						},
						{ designs: [{ designId }], variant: FIXTURE.variant.teeWhiteS },
					],
				},
				'o1',
			);

			expect(response.status).toBe(status);
			expect(await response.json()).toEqual({ error, itemIndex: 1 });
			expect(await orderCount()).toBe(0);
			expect(fake.state.records.get('prints')?.size ?? 0).toBe(0);
			expect(fake.violations).toEqual([]);
		},
	);

	it('refuses a second order of a design with a live item, naming it, and places one asked for again', async () => {
		await approvedDesign('dsn_1', front);
		const first = await (await orderDesigns(['dsn_1'], 'o1')).json();
		const [firstItem] = first.items;

		const again = await orderDesigns(['dsn_1'], 'o2');
		const ofPrint = await post(
			'/v1/orders',
			oneItem({
				prints: [{ printId: firstItem.printIds[0] }],
				variant: FIXTURE.variant.teeWhiteS,
			}),
			'o3',
		);
		const reordered = await orderDesigns(['dsn_1'], 'o4', { reorder: true });
		const newest = await orderDesigns(['dsn_1'], 'o5');

		expect(again.status).toBe(409);
		expect(await again.json()).toEqual({
			error: 'design_already_ordered',
			designId: 'dsn_1',
			orderId: first.id,
			itemId: firstItem.id,
			itemIndex: 0,
		});
		expect(ofPrint.status).toBe(409);
		expect(reordered.status).toBe(201);
		const { id } = await reordered.json();
		expect((await newest.json()).orderId).toBe(id);
		expect(await orderCount()).toBe(2);
		expect(fake.violations).toEqual([]);
	});

	it('orders a design again whose only item failed or was cancelled', async () => {
		await approvedDesign('dsn_failed', front);
		await approvedDesign('dsn_cancelled', front);
		await send(fake, '/__fake/orders/fail', {
			method: 'POST',
			json: { reason: 'Out of stock at the printshop.', times: 1 },
		});
		const failed = await orderDesigns(['dsn_failed'], 'o1');
		const { id } = await (await orderDesigns(['dsn_cancelled'], 'o2')).json();
		await post(`/v1/orders/${id}/cancel`, undefined);

		const afterFailed = await orderDesigns(['dsn_failed'], 'o3');
		const afterCancelled = await orderDesigns(['dsn_cancelled'], 'o4');

		expect((await failed.json()).items[0].status).toBe('failed');
		expect(afterFailed.status).toBe(201);
		expect(afterCancelled.status).toBe(201);
		expect(fake.violations).toEqual([]);
	});

	it('answers a repeat of its key with the first order, not design_already_ordered', async () => {
		await approvedDesign('dsn_1', front);

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
			oneItem(
				{ prints: [{ printId }], variant, externalRef: 'wc-42-1' },
				{ externalRef: 'wc-42', ...shipping },
			),
			key,
		);
	}

	/** Prints a design on the tee's front and orders it. */
	async function placed(): Promise<{ printId: string; id: string }> {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');
		const response = await order(printId);
		expect(response.status).toBe(201);
		return { printId, id: (await response.json()).id };
	}

	it('places one order for a key, however often it is sent', async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const first = await order(printId);
		const replay = await order(printId);

		expect(first.status).toBe(201);
		const body = await first.json();
		expect(body).toMatchObject({
			tenantId: FIXTURE.tenant.live,
			externalRef: 'wc-42',
			status: 'open',
			hold: null,
			parcels: [],
			items: [
				{
					externalRef: 'wc-42-1',
					designId: 'dsn_1',
					printIds: [printId],
					status: 'submitted',
					quantity: 1,
					shippedQuantity: 0,
					printshopConnectionId: FIXTURE.printshop.main,
					printshopName: 'Fixture Print AB',
					prints: [
						{
							printId,
							designId: 'dsn_1',
							placementId: FIXTURE.placement.front,
						},
					],
				},
			],
		});
		const [item] = body.items;
		expect(body.printshopOrders).toMatchObject([
			{
				printshopConnectionId: FIXTURE.printshop.main,
				externalOrderRef: null,
				itemIds: [item.id],
			},
		]);
		expect(item.submissions).toMatchObject([
			{
				printshopOrderId: body.printshopOrders[0].id,
				status: 'submitted',
				struck: false,
			},
		]);
		expect(replay.headers.get('Idempotent-Replayed')).toBe('true');
		expect((await replay.json()).id).toBe(body.id);
		expect(await orderCount()).toBe(1);
		expect(fake.violations).toEqual([]);
	});

	it('places one item per line, in the order named, sent together to one printshop', async () => {
		await approvedDesign('dsn_1', front);
		await approvedDesign('dsn_2', front);

		const response = await post(
			'/v1/orders',
			{
				externalRef: 'wc-42',
				items: [
					{
						externalRef: 'wc-42-1',
						prints: [{ printId: print('dsn_1') }],
						variant: FIXTURE.variant.teeWhiteS,
					},
					{
						externalRef: 'wc-42-2',
						designs: [{ designId: 'dsn_2' }],
						variant: FIXTURE.variant.teeBlackM,
						quantity: 3,
					},
				],
			},
			'o1',
		);

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body.items).toMatchObject([
			{ externalRef: 'wc-42-1', designId: 'dsn_1', quantity: 1 },
			{ externalRef: 'wc-42-2', designId: 'dsn_2', quantity: 3 },
		]);
		expect(body.printshopOrders).toHaveLength(1);
		expect(body.printshopOrders[0].itemIds).toEqual(
			body.items.map((item: { id: string }) => item.id),
		);
		const [row] = (await (await get('/v1/orders?externalRef=wc-42')).json())
			.orders;
		expect(row).toMatchObject({
			id: body.id,
			itemCount: 2,
			copies: 4,
			parcelCount: 0,
			parcels: { preparing: 0, shipped: 0, delivered: 0 },
			printshopNames: ['Fixture Print AB'],
			itemsPreview: [
				{ designId: 'dsn_1', quantity: 1, status: 'submitted' },
				{ designId: 'dsn_2', quantity: 3, status: 'submitted' },
			],
		});
		expect(fake.violations).toEqual([]);
	});

	it("ships to the customer's address, and knows no saved address", async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');
		const item = { prints: [{ printId }], variant: FIXTURE.variant.teeWhiteS };

		const saved = await post(
			'/v1/orders',
			oneItem(item, { ...shipping, shipToAddressId: 'adr_1' }),
			'order-saved',
		);
		const customer = await post(
			'/v1/orders',
			oneItem(item, { ...shipping, shipToAddressId: null }),
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

	it('reads one order with its history, each step of an item', async () => {
		const { id } = await placed();

		const response = await get(`/v1/orders/${id}`);

		expect(response.status).toBe(200);
		const body = await response.json();
		const [item] = body.items;
		expect(body).toMatchObject({
			id,
			history: [
				{ seq: 1, type: 'order.created', status: 'submitted', itemId: item.id },
				{ seq: 2, type: 'item.submitted', status: 'submitted' },
			],
			items: [{ retryable: false, canRetry: false, statusMoves: [] }],
		});
		expect(fake.violations).toEqual([]);
	});

	it('places the armed number of items as failed, then submits again', async () => {
		const armed = await send(fake, '/__fake/orders/fail', {
			method: 'POST',
			json: { reason: 'Out of stock at the printshop.', times: 1 },
		});
		expect(armed.status).toBe(200);
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const failed = await order(printId, 'order-1');
		const next = await order(printId, 'order-2');

		expect(failed.status).toBe(201);
		const body = await failed.json();
		expect(body).toMatchObject({
			status: 'needs_attention',
			items: [
				{ status: 'failed', lastError: 'Out of stock at the printshop.' },
			],
		});
		expect(body.history).toMatchObject([
			{ type: 'order.created', status: 'failed' },
			{ type: 'item.failed', status: 'failed' },
		]);
		expect((await next.json()).items[0].status).toBe('submitted');
		expect(fake.violations).toEqual([]);
	});

	it('fails only the item an armed failure names, in a printshop order of its own', async () => {
		await send(fake, '/__fake/orders/fail', {
			method: 'POST',
			json: { itemExternalRef: 'wc-42-2' },
		});
		await approvedDesign('dsn_1', front);
		await approvedDesign('dsn_2', front);

		const response = await post(
			'/v1/orders',
			{
				items: ['dsn_1', 'dsn_2'].map((designId, index) => ({
					externalRef: `wc-42-${index + 1}`,
					designs: [{ designId }],
					variant: FIXTURE.variant.teeWhiteS,
				})),
			},
			'o1',
		);

		const body = await response.json();
		expect(body.items.map((i: { status: string }) => i.status)).toEqual([
			'submitted',
			'failed',
		]);
		expect(body.status).toBe('needs_attention');
		expect(body.printshopOrders).toHaveLength(2);
		expect(fake.state.armedOrderOutcome).toBeNull();
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

		const refused = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId }] }),
			'k',
		);
		const unknown = await get('/v1/orders/ord_unknown');

		expect(refused.status).toBe(404);
		expect(await refused.json()).toEqual({
			error: 'print_not_found',
			itemIndex: 0,
		});
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
		expect((await response.json()).items[0].printshopConnectionId).toBe(
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
			await approvedDesign('dsn_1', front);
			const printId = print('dsn_1');

			const response = await post(
				'/v1/orders',
				oneItem({
					prints: [{ printId }],
					printshopId,
					variant: FIXTURE.variant.teeWhiteS,
				}),
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
			oneItem(
				{ prints: [{ printId }] },
				{ printshopId: FIXTURE.printshop.other },
			),
			'k1',
		);
		const fallback = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId }], reorder: true }),
			'k2',
		);

		expect((await named.json()).items[0].printshopConnectionId).toBe(
			FIXTURE.printshop.other,
		);
		expect((await fallback.json()).items[0].printshopConnectionId).toBe(
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

		const response = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId }] }),
			'k',
		);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'print_needs_printshop',
			itemIndex: 0,
		});
		expect(fake.violations).toEqual([]);
	});

	it("places a print whose blank's printshop is disconnected as a failed item, sent nowhere", async () => {
		const main = fake.state.printshops.get(FIXTURE.printshop.main);
		if (main === undefined) {
			throw new Error('No main printshop.');
		}
		main.active = false;
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const response = await order(printId);

		expect(response.status).toBe(201);
		const body = await response.json();
		expect(body).toMatchObject({
			status: 'needs_attention',
			printshopOrders: [],
			items: [
				{
					status: 'failed',
					lastError: 'Fixture Print AB is disconnected.',
					printshopConnectionId: FIXTURE.printshop.main,
					submissions: [{ printshopOrderId: null }],
				},
			],
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
			oneItem({ prints: [{ printId }], printshopId: FIXTURE.printshop.other }),
			'k',
		);

		expect(response.status).toBe(422);
		expect((await response.json()).error).toBe('printshop_disconnected');
		expect(await orderCount()).toBe(0);
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
			oneItem(
				{ prints: printIds.map((printId) => ({ printId })) },
				{ ...shipping },
			),
			'order-refused',
		);
		expect(await orderCount()).toBe(0);
		expect(fake.violations).toEqual([]);
		return { status: response.status, body: await response.json() };
	}

	it('orders a front and a back on one blank as one item', async () => {
		await approvedDesign('dsn_front', front);
		await approvedDesign('dsn_back', back);
		const prints = [
			{ printId: print('dsn_front') },
			{ printId: print('dsn_back') },
		];

		const response = await post(
			'/v1/orders',
			oneItem({ prints, variant: FIXTURE.variant.teeBlackM }, shipping),
			'k',
		);

		expect(response.status).toBe(201);
		expect((await response.json()).items[0].printIds).toEqual(
			prints.map((p) => p.printId),
		);
		expect(fake.violations).toEqual([]);
	});

	it.each([
		[
			'whose design has no blank',
			{ dsn_front: front, dsn_1: {} },
			{ error: 'print_without_blank', printId: expect.any(String) },
		],
		[
			'whose design has no placement',
			{ dsn_front: front, dsn_1: { blankId: FIXTURE.blank.tee } },
			{ error: 'print_without_placement', printId: expect.any(String) },
		],
		[
			'on different blanks',
			{
				dsn_front: front,
				dsn_back: { ...back, blankId: FIXTURE.blank.hoodie },
			},
			{ error: 'mixed_blanks' },
		],
		[
			'in one placement',
			{ dsn_1: front, dsn_2: front },
			{ error: 'duplicate_placement', placementId: FIXTURE.placement.front },
		],
	])('refuses several prints %s', async (_, designs, refusal) => {
		const { status, body } = await refusedOrder(designs);

		expect(status).toBe(422);
		expect(body).toEqual({ ...refusal, itemIndex: 0 });
	});

	it('refuses a print of a revision the design has moved past', async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');
		await approvedDesign('dsn_1', { ...front, revision: 2 });

		const response = await order(printId);

		expect(response.status).toBe(409);
		expect(await response.json()).toEqual({
			error: 'print_superseded',
			itemIndex: 0,
		});
		expect(await orderCount()).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it('keeps a copy of the variant ordered and names each print as the printshop knows it', async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const response = await order(printId, 'k', FIXTURE.variant.teeBlackM);

		expect(response.status).toBe(201);
		expect((await response.json()).items[0]).toMatchObject({
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
		[
			'retired by the merchant',
			front,
			FIXTURE.variant.teeRedM,
			'variant_retired',
		],
		[
			'retired by its printshop',
			front,
			FIXTURE.variant.teeRedS,
			'variant_retired',
		],
		[
			'of another blank',
			front,
			FIXTURE.variant.hoodieWhiteS,
			'variant_not_of_blank',
		],
		[
			'for a print on no blank',
			{},
			FIXTURE.variant.teeWhiteS,
			'variant_not_of_blank',
		],
	])('refuses a variant %s', async (_, seed, variant, error) => {
		await approvedDesign('dsn_1', seed);
		const printId = print('dsn_1');

		const response = await order(printId, 'k', variant);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error,
			variant,
			reason: expect.any(String),
			itemIndex: 0,
		});
		expect(await orderCount()).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it('refuses an item naming no variant of a blank with several', async () => {
		await approvedDesign('dsn_1', front);
		const printId = print('dsn_1');

		const response = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId }] }),
			'k',
		);

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({
			error: 'variant_required',
			variants: TEE_VARIANTS,
			reason: expect.any(String),
			itemIndex: 0,
		});
		expect(await orderCount()).toBe(0);
		expect(fake.violations).toEqual([]);
	});

	it("orders a blank's only variant when none is named, and none for a print on no blank", async () => {
		await approvedDesign('dsn_tote', { blankId: FIXTURE.blank.bare });
		await approvedDesign('dsn_1');

		const tote = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId: print('dsn_tote') }] }),
			'k1',
		);
		const unplaced = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId: print('dsn_1') }] }),
			'k2',
		);

		expect((await tote.json()).items[0]).toMatchObject({
			blankId: FIXTURE.blank.bare,
			variantRef: FIXTURE.variant.tote,
			variant: { ref: FIXTURE.variant.tote, options: {}, fields: [] },
		});
		expect((await unplaced.json()).items[0]).toMatchObject({
			blankId: null,
			blank: null,
			variantRef: null,
			variant: null,
		});
		expect(fake.violations).toEqual([]);
	});
});

describe('GET /v1/orders', () => {
	/** How far apart the orders of a test are placed. */
	const STEP_MS = 1000;

	/** Orders a print of a design seeded approved as `seed`, with `item` in
	 *  the item and `top` beside it; answers the order's id. */
	async function placeOrder(
		designId: string,
		seed: Record<string, unknown>,
		item: Record<string, unknown> = {},
		top: Record<string, unknown> = {},
	): Promise<string> {
		await approvedDesign(designId, seed);
		const printId = print(designId);
		const response = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId }], ...item }, top),
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
		const white = await placeOrder(
			'dsn_white',
			front,
			{ variant: FIXTURE.variant.teeWhiteS },
			{ externalRef: 'wc-1', recipient: { name: 'Ada Lovelace' } },
		);
		fake.clock.advance(STEP_MS);
		const black = await placeOrder(
			'dsn_black',
			front,
			{ variant: FIXTURE.variant.teeBlackM },
			{ externalRef: 'wc-7', recipient: { name: 'Grace Hopper' } },
		);
		fake.clock.advance(STEP_MS);
		const loose = await placeOrder('dsn_loose', {});
		const cancelled = await post(`/v1/orders/${loose}/cancel`, undefined);
		expect(cancelled.status).toBe(200);
		return { white, black, loose };
	}

	it('lists the latest submitted first, or as sort and order ask', async () => {
		const { white, black, loose } = await three();
		fake.clock.advance(STEP_MS);
		const moved = await post(`/v1/orders/${white}/cancel`, undefined);
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
			await get('/v1/orders?status=cancelled,needs_attention')
		).json();
		const repeated = await ids('status=open&status=cancelled');
		const onTee = await (
			await get(`/v1/orders?status=cancelled&blankId=${FIXTURE.blank.tee}`)
		).json();

		expect(comma.orders.map((o: { id: string }) => o.id)).toEqual([loose]);
		expect(comma.total).toBe(1);
		expect(comma.counts).toEqual({ ...NO_ORDERS, open: 2, cancelled: 1 });
		expect(repeated).toEqual([loose, black, white]);
		expect(onTee.total).toBe(0);
		expect(onTee.counts).toEqual({ ...NO_ORDERS, open: 2 });
		expect(fake.violations).toEqual([]);
	});

	it('refuses a status no order can be in', async () => {
		const response = await get('/v1/orders?status=open,submitted');

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'invalid_query' });
		expect(fake.violations).toEqual([]);
	});

	it('narrows by blank, variant, printshop and when the order was submitted', async () => {
		const { white, black, loose } = await three();
		const createdAt = async (id: string): Promise<number> =>
			(await (await get(`/v1/orders/${id}`)).json()).createdAt;
		const whiteAt = await createdAt(white);
		const blackAt = await createdAt(black);

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
		await approvedDesign('dsn_1', { ...front, revision: 2 });
		const printId = print('dsn_1');
		const response = await post(
			'/v1/orders',
			oneItem({ prints: [{ printId }], variant: FIXTURE.variant.teeWhiteM }),
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
			items: [
				{
					blankId: FIXTURE.blank.tee,
					blank: { name: 'Renamed tee', variants: TEE_VARIANTS },
					printIds: [printId],
				},
			],
		});
		expect(gone.items[0]).toMatchObject({
			blankId: FIXTURE.blank.tee,
			blank: null,
			prints: [{ printId, designId: 'dsn_1', designRevision: 2 }],
		});
		expect(fake.violations).toEqual([]);
	});
});
