import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, send } from './support';

const fake = createFake();
const asStore = bearer(MAIN.integrationKey);

const FRONT = {
	templateId: FIXTURE.template.live,
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.front,
	widthMm: 200,
	heightMm: 250,
};

function standing(json: Record<string, unknown>, headers = asStore) {
	return send(fake, '/v1/designs/standing', { method: 'POST', json, headers });
}

function order(designIds: string[], key: string) {
	return send(fake, '/v1/orders', {
		method: 'POST',
		json: {
			designs: designIds.map((designId) => ({ designId })),
			variant: FIXTURE.variant.teeWhiteS,
		},
		headers: { ...asStore, 'idempotency-key': key },
	});
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /v1/designs/standing', () => {
	it('creates an approved standing design, then finds the same one', async () => {
		const created = await standing({ ...FRONT, approve: true });
		const found = await standing({ ...FRONT, approve: true });

		expect(created.status).toBe(201);
		expect(found.status).toBe(200);
		const first = await created.json();
		expect(first).toMatchObject({
			created: true,
			standing: true,
			status: 'approved',
			...FRONT,
			revision: 1,
		});
		expect(await found.json()).toMatchObject({
			designId: first.designId,
			created: false,
		});
		const read = await send(fake, `/v1/designs/${first.designId}`, {
			headers: asStore,
		});
		expect(await read.json()).toMatchObject({
			standing: true,
			status: 'approved',
		});
		expect(fake.violations).toEqual([]);
	});

	it('finds another design for another version, placement or size', async () => {
		const ids = new Set<string>();
		for (const request of [
			FRONT,
			{ ...FRONT, templateId: FIXTURE.template.previous },
			{ ...FRONT, placementId: FIXTURE.placement.back },
			{ ...FRONT, widthMm: 100, heightMm: 125 },
		]) {
			ids.add((await (await standing(request)).json()).designId);
		}

		expect(ids.size).toBe(4);
	});

	it('reads the version as a number and answers its canonical spelling', async () => {
		const [base, version] = FIXTURE.template.live.split('@');
		const created = await (await standing(FRONT)).json();
		const padded = await (
			await standing({ ...FRONT, templateId: `${base}@0${version}` })
		).json();

		expect(padded).toMatchObject({
			designId: created.designId,
			created: false,
			templateId: FIXTURE.template.live,
		});
	});

	it('finds the design of the default size when no size is sent', async () => {
		const unsized = await standing({
			templateId: FRONT.templateId,
			blankId: FRONT.blankId,
			placementId: FRONT.placementId,
		});
		const first = await unsized.json();
		const sized = await (
			await standing({
				...FRONT,
				widthMm: first.widthMm,
				heightMm: first.heightMm,
			})
		).json();

		expect(unsized.status).toBe(201);
		expect(first.widthMm).toEqual(expect.any(Number));
		expect(sized).toMatchObject({ designId: first.designId, created: false });
		expect(fake.violations).toEqual([]);
	});

	it("never finds another store's standing design", async () => {
		const mine = await (await standing(FRONT)).json();
		const theirs = await standing(
			FRONT,
			bearer(FIXTURE.store.other.integrationKey),
		);

		expect(theirs.status).toBe(201);
		expect((await theirs.json()).designId).not.toBe(mine.designId);
	});

	it('approves a found design only when asked to', async () => {
		const committed = await (await standing(FRONT)).json();
		const approved = await (await standing({ ...FRONT, approve: true })).json();

		expect(committed.status).toBe('committed');
		expect(approved).toMatchObject({
			designId: committed.designId,
			status: 'approved',
		});
	});

	it('refuses a base id without a version, and half a placement or size', async () => {
		const unversioned = await standing({ ...FRONT, templateId: 'mini-tee' });
		const halfPlacement = await standing({
			templateId: FIXTURE.template.live,
			blankId: FIXTURE.blank.tee,
		});
		const halfSize = await standing({ ...FRONT, heightMm: undefined });

		expect(unversioned.status).toBe(400);
		expect(await unversioned.json()).toEqual({
			error: 'template_version_required',
		});
		expect(halfPlacement.status).toBe(400);
		expect(halfSize.status).toBe(400);
	});

	it('refuses an unknown version and an unknown placement', async () => {
		const version = await standing({ ...FRONT, templateId: 'mini-tee@9' });
		const placement = await standing({ ...FRONT, placementId: 'sleeve' });

		expect(version.status).toBe(422);
		expect(await version.json()).toEqual({ error: 'constraint_violation' });
		expect(placement.status).toBe(422);
		expect(await placement.json()).toEqual({ error: 'unknown_placement' });
	});

	it('is ordered any number of times, never refused as already ordered', async () => {
		const { designId } = await (
			await standing({ ...FRONT, approve: true })
		).json();

		const first = await order([designId], 'o1');
		const second = await order([designId], 'o2');

		expect(first.status).toBe(201);
		expect(second.status).toBe(201);
		const [one, two] = [await first.json(), await second.json()];
		expect(one.id).not.toBe(two.id);
		// Each order reuses the print of the design's revision.
		expect(two.prints[0].id).toBe(one.prints[0].id);
	});

	it('refuses to order a standing design not approved', async () => {
		const { designId } = await (await standing(FRONT)).json();

		const placed = await order([designId], 'o1');

		expect(placed.status).toBe(409);
		expect(await placed.json()).toMatchObject({ error: 'design_not_approved' });
	});
});

/** The tee's print areas change with Size: a smaller front on S than on M,
 *  set through the control API. */
async function areasVaryBySize() {
	const set = await send(fake, `/__fake/blanks/${FIXTURE.blank.tee}/areas`, {
		method: 'POST',
		json: {
			areasVaryBy: ['Size'],
			areas: {
				[FIXTURE.placement.front]: [
					{
						row: { Size: 'S' },
						widthMm: 220,
						heightMm: 280,
						usualWidthMm: 160,
						usualHeightMm: 200,
					},
					{
						row: { Size: 'M' },
						widthMm: 260,
						heightMm: 320,
						usualWidthMm: 200,
						usualHeightMm: 250,
					},
				],
			},
		},
	});
	expect(set.status).toBe(200);
}

describe('POST /v1/designs/standing on a blank whose print areas vary', () => {
	beforeEach(areasVaryBySize);

	it('refuses a design that names no row, saying what the areas vary by', async () => {
		const refused = await standing(FRONT);

		expect(refused.status).toBe(422);
		expect(await refused.json()).toMatchObject({
			error: 'variant_required',
			areasVaryBy: ['Size'],
		});
		expect(fake.violations).toEqual([]);
	});

	it('keeps one design per row, found again by its row or a variant in it', async () => {
		const small = await (
			await standing({ ...FRONT, variantRow: { Size: 'S' } })
		).json();
		const medium = await (
			await standing({ ...FRONT, variantRow: { Size: 'M' } })
		).json();
		const again = await (
			await standing({ ...FRONT, variant: FIXTURE.variant.teeBlackS })
		).json();

		expect(small.designId).not.toBe(medium.designId);
		expect(again).toMatchObject({ designId: small.designId, created: false });
		const read = await send(fake, `/v1/designs/${small.designId}`, {
			headers: asStore,
		});
		expect(await read.json()).toMatchObject({ variantRow: { Size: 'S' } });
		expect(fake.violations).toEqual([]);
	});

	it("sizes a design naming no size at its row's usual size", async () => {
		const { widthMm, heightMm, ...unsized } = FRONT;

		const small = await standing({ ...unsized, variantRow: { Size: 'S' } });

		const body = await small.json();
		expect(body.heightMm).toBe(200);
		expect(body.widthMm).toBeLessThanOrEqual(160);
	});

	it('refuses a row that names another option, or a variant of another blank', async () => {
		const row = await standing({ ...FRONT, variantRow: { Color: 'White' } });
		const variant = await standing({
			...FRONT,
			variant: FIXTURE.variant.hoodieWhiteS,
		});

		expect(row.status).toBe(422);
		expect(await row.json()).toMatchObject({ error: 'variant_row_invalid' });
		expect(variant.status).toBe(422);
		expect(await variant.json()).toMatchObject({
			error: 'variant_not_of_blank',
		});
	});

	it('refuses an area whose row names another option', async () => {
		const refused = await send(
			fake,
			`/__fake/blanks/${FIXTURE.blank.tee}/areas`,
			{
				method: 'POST',
				json: {
					areasVaryBy: ['Size'],
					areas: {
						front: [
							{
								row: { Color: 'White' },
								widthMm: 1,
								heightMm: 1,
								usualWidthMm: 1,
								usualHeightMm: 1,
							},
						],
					},
				},
			},
		);

		expect(refused.status).toBe(400);
	});

	it("orders a row's design only with a variant in its row", async () => {
		const { designId } = await (
			await standing({ ...FRONT, variantRow: { Size: 'S' }, approve: true })
		).json();
		const orderAs = (variant: string, key: string) =>
			send(fake, '/v1/orders', {
				method: 'POST',
				json: { designs: [{ designId }], variant },
				headers: { ...asStore, 'idempotency-key': key },
			});

		const other = await orderAs(FIXTURE.variant.teeWhiteM, 'o-m');
		const same = await orderAs(FIXTURE.variant.teeBlackS, 'o-s');

		expect(other.status).toBe(422);
		expect(await other.json()).toMatchObject({
			error: 'variant_not_of_design_row',
			designId,
			row: { Size: 'S' },
		});
		expect(same.status).toBe(201);
		expect(fake.violations).toEqual([]);
	});

	it('finds a row named in any key order as one design', async () => {
		const tee = fake.state.blanks.get(FIXTURE.blank.tee);
		if (tee !== undefined) {
			tee.areasVaryBy = ['Color', 'Size'];
		}
		const first = await (
			await standing({ ...FRONT, variantRow: { Color: 'White', Size: 'S' } })
		).json();
		const reversed = await (
			await standing({ ...FRONT, variantRow: { Size: 'S', Color: 'White' } })
		).json();
		const byVariant = await (
			await standing({ ...FRONT, variant: FIXTURE.variant.teeWhiteS })
		).json();

		expect(reversed.designId).toBe(first.designId);
		expect(byVariant.designId).toBe(first.designId);
	});

	it('answers the areas with the blank and its variants', async () => {
		const blank = await (
			await send(fake, `/v1/blanks/${FIXTURE.blank.tee}`, { headers: asStore })
		).json();
		const variants = await (
			await send(fake, `/v1/blanks/${FIXTURE.blank.tee}/variants`, {
				headers: asStore,
			})
		).json();

		expect(blank.areasVaryBy).toEqual(['Size']);
		expect(
			blank.placements.find(
				(p: { id: string }) => p.id === FIXTURE.placement.front,
			).areas,
		).toHaveLength(2);
		expect(variants.areasVaryBy).toEqual(['Size']);
		expect(fake.violations).toEqual([]);
	});
});

describe('offersProperties', () => {
	it('says whether a template offers its customer a field', async () => {
		const read = await send(fake, `/v1/templates/${FIXTURE.template.live}`, {
			headers: asStore,
		});
		const listed = await send(fake, '/v1/templates?status=live', {
			headers: asStore,
		});

		expect((await read.json()).offersProperties).toBe(true);
		const items: { id: string; offersProperties: boolean }[] = (
			await listed.json()
		).items;
		expect(items.find((t) => t.id === FIXTURE.template.live)).toMatchObject({
			offersProperties: true,
		});
		expect(fake.violations).toEqual([]);
	});
});

describe('POST /v1/designs/standing with a key or a layout adjustment', () => {
	const RETURN_URL = `${SITE_URL}/wp-admin/admin-post.php?action=back`;

	/** Mints an `adjustLayout` correction link for `designId` and approves
	 *  its hosted page, answering the proof token it sends back. */
	async function adjust(designId: string): Promise<string> {
		const minted = await send(
			fake,
			`/v1/designs/${designId}/correction-links`,
			{
				method: 'POST',
				headers: asStore,
				json: { adjustLayout: true, returnUrl: RETURN_URL, state: 'trip' },
			},
		);
		expect(minted.status).toBe(201);
		const page = new URL((await minted.json()).url).pathname;
		const approved = await send(fake, `${page}/approve`);
		const back = new URL(approved.headers.get('location') ?? '');
		expect(back.searchParams.get('state')).toBe('trip');
		return back.searchParams.get('proofToken') ?? '';
	}

	it('keeps a design per key, found only by that key', async () => {
		const shared = await (await standing(FRONT)).json();
		const keyed = await (await standing({ ...FRONT, key: 'product-1' })).json();
		const again = await (await standing({ ...FRONT, key: 'product-1' })).json();

		expect(keyed.designId).not.toBe(shared.designId);
		expect(again.designId).toBe(keyed.designId);
		expect(shared).toMatchObject({ key: null, adjusted: false });
		expect(keyed).toMatchObject({ key: 'product-1', adjusted: false });
		expect(fake.violations).toEqual([]);
	});

	it('makes a standing design of its own from a layout proof, and finds it again by that proof', async () => {
		const source = await (await standing({ ...FRONT, approve: true })).json();
		const proofToken = await adjust(source.designId);

		const made = await standing({ ...FRONT, approve: true, proofToken });
		const again = await standing({ ...FRONT, approve: true, proofToken });
		const plain = await (await standing({ ...FRONT, approve: true })).json();

		expect(made.status).toBe(201);
		const adjusted = await made.json();
		expect(adjusted).toMatchObject({
			adjusted: true,
			status: 'approved',
			...FRONT,
		});
		expect(adjusted.designId).not.toBe(source.designId);
		expect((await again.json()).designId).toBe(adjusted.designId);
		expect(plain.designId).toBe(source.designId);
		// An adjusted design is adjusted again by naming what it was made for.
		const readjusted = await standing({
			...FRONT,
			proofToken: await adjust(adjusted.designId),
		});
		expect(readjusted.status).toBe(200);
		expect((await readjusted.json()).designId).toBe(adjusted.designId);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a proof that is no layout proof, one of another size or row, and an unknown one', async () => {
		const source = await (await standing(FRONT)).json();
		const proofToken = await adjust(source.designId);
		const plainLink = await send(
			fake,
			`/v1/designs/${source.designId}/correction-links`,
			{
				method: 'POST',
				headers: asStore,
				json: { returnUrl: RETURN_URL },
			},
		);
		const page = new URL((await plainLink.json()).url).pathname;
		const corrected = new URL(
			(await send(fake, `${page}/approve`)).headers.get('location') ?? '',
		);

		const notLayout = await standing({
			...FRONT,
			proofToken: corrected.searchParams.get('proofToken'),
		});
		const otherSize = await standing({
			...FRONT,
			widthMm: 100,
			heightMm: 125,
			proofToken,
		});
		const unknown = await standing({ ...FRONT, proofToken: 'prf_unknown' });

		expect(notLayout.status).toBe(422);
		expect(await notLayout.json()).toEqual({ error: 'not_a_layout_proof' });
		expect(otherSize.status).toBe(422);
		expect(await otherSize.json()).toEqual({ error: 'layout_proof_mismatch' });
		expect(unknown.status).toBe(404);
		expect(await unknown.json()).toEqual({ error: 'unknown_proof' });
	});

	it('opens only a standing design for its layout, and never redeems a layout proof with PATCH', async () => {
		const source = await (await standing(FRONT)).json();
		const proofToken = await adjust(source.designId);

		const patched = await send(fake, `/v1/designs/${source.designId}`, {
			method: 'PATCH',
			headers: asStore,
			json: { proofToken },
		});

		expect(patched.status).toBe(409);
		expect(await patched.json()).toEqual({ error: 'proof_adjusts_layout' });
		const customer = await send(fake, '/__fake/designs', {
			method: 'POST',
			json: {},
		});
		const { id } = (await customer.json()) as { id: string };
		const refused = await send(fake, `/v1/designs/${id}/correction-links`, {
			method: 'POST',
			headers: asStore,
			json: { adjustLayout: true },
		});
		expect(refused.status).toBe(409);
		expect(await refused.json()).toMatchObject({ error: 'not_standing' });
	});
});
