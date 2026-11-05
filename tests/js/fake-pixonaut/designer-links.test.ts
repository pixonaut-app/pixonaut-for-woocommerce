import { beforeEach, describe, expect, it } from 'vitest';
import { createFake, VIOLATION_STATUS } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	DESIGNER_LINK_LIFETIME_MS,
	RECOMMENDED_SIDES,
} from '../../fake-pixonaut/src/handlers/designer-links';
import {
	bearer,
	MAIN,
	OWN_FAKE_TIMEOUT_MS,
	SITE_URL,
	send,
	templatePath,
} from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };

const BASE_ID = 'mini-tee';
const OTHER_BASE_ID = 'mini-poster';
const RETURN_URL = `${SITE_URL}/?pixonaut_designer=return`;
/** Most sides one link takes (`maxItems` of `sides`). */
const MAX_SIDES = 10;
/** A side's print size where it names no blank to size it by. */
const COASTER_SIZE = { widthMm: 90, heightMm: 90, label: 'Coaster' };
const VISIT = 'visit-1';

const FRONT = {
	key: 'front',
	label: 'Front',
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.front,
};
const BACK = {
	key: 'back',
	label: 'Back',
	blankId: FIXTURE.blank.tee,
	placementId: FIXTURE.placement.back,
	templateId: OTHER_BASE_ID,
};

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

function createLink(json: Record<string, unknown>, baseId = BASE_ID) {
	return send(fake, templatePath(baseId, '/designer-links'), {
		method: 'POST',
		json,
		...asStore,
	});
}

async function linkUrl(json: Record<string, unknown>): Promise<string> {
	const response = await createLink(json);
	expect(response.status).toBe(201);
	return new URL(((await response.json()) as { url: string }).url).pathname;
}

/** Follows the hosted designer's `action` link to where it sends the shopper. */
async function act(path: string, action: 'approve' | 'cancel'): Promise<URL> {
	const response = await send(fake, `${path}/${action}`);
	expect(response.status).toBe(302);
	return new URL(response.headers.get('location') ?? '');
}

describe('POST /v1/templates/{id}/designer-links', () => {
	it('makes a 24-hour link for one side that returns its proof token', async () => {
		const before = fake.clock.now();
		const response = await createLink({
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
			printSizes: [{ widthMm: 200, heightMm: 257, label: 'Medium' }],
			selectedPrintSizeIndex: 0,
			returnUrl: RETURN_URL,
			state: VISIT,
			locale: 'en',
		});
		expect(response.status).toBe(201);
		const link = (await response.json()) as { url: string; expiresAt: number };
		expect(link.expiresAt - before).toBeGreaterThanOrEqual(
			DESIGNER_LINK_LIFETIME_MS,
		);
		expect(link.expiresAt - fake.clock.now()).toBeLessThanOrEqual(
			DESIGNER_LINK_LIFETIME_MS,
		);

		const back = await act(new URL(link.url).pathname, 'approve');

		expect(back.origin + back.pathname).toBe(`${new URL(RETURN_URL).origin}/`);
		expect(back.searchParams.get('pixonaut_designer')).toBe('return');
		expect(back.searchParams.get('state')).toBe(VISIT);
		const commit = await send(fake, '/v1/designs', {
			method: 'POST',
			headers: { ...asStore.headers, 'idempotency-key': 'commit:one' },
			json: {
				proofToken: back.searchParams.get('proofToken'),
				externalRef: 'wc:site:cart:1',
			},
		});
		expect(commit.status).toBe(201);
		const { designId } = (await commit.json()) as { designId: string };
		const design = await send(fake, `/v1/designs/${designId}`, asStore);
		expect(await design.json()).toMatchObject({
			templateId: FIXTURE.template.live,
			placementId: FIXTURE.placement.front,
			printSize: { widthMm: 200, heightMm: 257 },
		});
		expect(fake.violations).toEqual([]);
	});

	it("returns each side's proof under its key, each side on its own template", async () => {
		const path = await linkUrl({
			sides: [FRONT, BACK],
			returnUrl: RETURN_URL,
			state: VISIT,
		});

		const back = await act(path, 'approve');

		expect(back.searchParams.get('proofToken[front]')).toMatch(/^prf\./);
		expect(back.searchParams.get('proofToken[back]')).toMatch(/^prf\./);
		expect(back.searchParams.has('proofToken')).toBe(false);
		expect(fake.violations).toEqual([]);
	});

	it('sends a cancelling shopper back with the state alone', async () => {
		const path = await linkUrl({
			...FRONT,
			key: undefined,
			label: undefined,
			returnUrl: RETURN_URL,
			state: VISIT,
		});

		const back = await act(path, 'cancel');

		expect(back.searchParams.get('state')).toBe(VISIT);
		expect(back.searchParams.has('proofToken')).toBe(false);
	});

	it('stops working 24 hours after it was made', async () => {
		const path = await linkUrl({
			sides: [FRONT, BACK],
			returnUrl: RETURN_URL,
			state: VISIT,
		});
		fake.clock.advance(DESIGNER_LINK_LIFETIME_MS);

		expect((await send(fake, path)).status).toBe(410);
	});

	it('refuses a side without a label as a contract violation', async () => {
		const response = await createLink({
			sides: [FRONT, { ...BACK, label: undefined }],
			returnUrl: RETURN_URL,
			state: VISIT,
		});

		expect(response.status).toBeGreaterThanOrEqual(400);
		expect(fake.violations).not.toEqual([]);
	});

	it('makes a link past the recommended sides and warns of it', async () => {
		const sides = (count: number) =>
			Array.from({ length: count }, (_, index) => ({
				...FRONT,
				key: `side-${index}`,
				label: `Side ${index}`,
			}));

		const recommended = await createLink({
			sides: sides(RECOMMENDED_SIDES),
			returnUrl: RETURN_URL,
		});
		expect(recommended.status).toBe(201);
		expect(await recommended.json()).not.toHaveProperty('warnings');

		const most = await createLink({
			sides: sides(MAX_SIDES),
			returnUrl: RETURN_URL,
		});
		expect(most.status).toBe(201);
		expect(await most.json()).toMatchObject({
			warnings: [
				{
					code: 'sides_over_recommended',
					recommended: RECOMMENDED_SIDES,
					sides: MAX_SIDES,
				},
			],
		});
		expect(fake.violations).toEqual([]);

		const over = await createLink({
			sides: sides(MAX_SIDES + 1),
			returnUrl: RETURN_URL,
		});
		expect(over.status).toBeGreaterThanOrEqual(400);
		expect(fake.violations).not.toEqual([]);
	});

	it('makes a link whose sides are on no blank, each sized by its menu', async () => {
		const path = await linkUrl({
			sides: [
				{ key: 'top', label: 'Top', printSizes: [COASTER_SIZE] },
				{ key: 'bottom', label: 'Bottom', printSizes: [COASTER_SIZE] },
			],
			returnUrl: RETURN_URL,
			state: VISIT,
		});

		const back = await act(path, 'approve');

		expect(back.searchParams.get('proofToken[top]')).toMatch(/^prf\./);
		expect(back.searchParams.get('proofToken[bottom]')).toMatch(/^prf\./);
		expect(fake.violations).toEqual([]);
	});

	it('refuses a link with some sides on a blank and some not', async () => {
		const response = await createLink({
			sides: [
				FRONT,
				{ key: 'card', label: 'Card', printSizes: [COASTER_SIZE] },
			],
			returnUrl: RETURN_URL,
		});

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({
			error: 'invalid_input',
			reason: expect.any(String),
		});
	});

	it('refuses side fields at the top level beside sides', async () => {
		const response = await createLink({
			sides: [FRONT, BACK],
			blankId: FIXTURE.blank.tee,
			placementId: FIXTURE.placement.front,
		});

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({
			error: 'invalid_input',
			reason: expect.any(String),
		});
	});

	it('refuses a return address off the store', async () => {
		const response = await createLink({
			...FRONT,
			key: undefined,
			label: undefined,
			returnUrl: 'http://elsewhere.test/back',
			state: VISIT,
		});

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({ error: 'return_url_off_store' });
	});

	it('refuses a placement the blank does not have', async () => {
		const response = await createLink({
			sides: [FRONT, { ...BACK, placementId: 'sleeve' }],
		});

		expect(response.status).toBe(422);
		expect(await response.json()).toEqual({ error: 'unknown_placement' });
	});

	it('shows each side in the variant it starts on and the views it names', async () => {
		const path = await linkUrl({
			sides: [
				{
					...FRONT,
					views: [FIXTURE.mockup.front],
					variants: [FIXTURE.variant.teeBlackS],
					initialVariant: FIXTURE.variant.teeBlackS,
				},
				BACK,
			],
		});

		const page = await (await send(fake, path)).text();

		expect(page).toContain('<p>Front: Variant: Black, S</p>');
		expect(page).toContain(`<p>Front: Views: ${FIXTURE.mockup.front}</p>`);
		expect(page).toContain('<p>Back: Variant: any</p>');
		expect(page).toContain('<p>Back: Views: all</p>');
	});

	it("lays every side's form out as the link fixes it, else as the template does", async () => {
		const fixed = await linkUrl({ sides: [FRONT, BACK], layout: 'steps' });
		const unfixed = await linkUrl({ sides: [FRONT, BACK] });

		expect(await (await send(fake, fixed)).text()).toContain(
			'<p>Layout: steps</p>',
		);
		expect(await (await send(fake, unfixed)).text()).toContain(
			'<p>Layout: template</p>',
		);
	});

	it('refuses a layout other than page or steps as a contract violation', async () => {
		const response = await createLink({
			sides: [FRONT, BACK],
			layout: 'wizard',
		});

		expect(response.status).toBeGreaterThanOrEqual(400);
		expect(fake.violations).not.toEqual([]);
	});

	it('refuses a starting variant it does not offer', async () => {
		const response = await createLink({
			...FRONT,
			key: undefined,
			label: undefined,
			variants: [FIXTURE.variant.teeBlackS],
			initialVariant: FIXTURE.variant.teeWhiteS,
		});

		expect(response.status).toBe(400);
		expect(await response.json()).toEqual({
			error: 'invalid_input',
			reason: expect.any(String),
		});
	});

	it('refuses a template that is not live', async () => {
		const response = await createLink(
			{ blankId: FIXTURE.blank.tee, placementId: FIXTURE.placement.front },
			'mini-tee-paused',
		);

		expect(response.status).toBe(404);
		expect(await response.json()).toEqual({ error: 'subject_not_found' });
	});
});

describe("a designer link's afterApprove", () => {
	const onBlank = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
	};

	it.each([
		['return', 'Approve and go back'],
		['add_to_cart', 'Approve and add to cart'],
	])('%s words the button, and is not on the answer', async (value, words) => {
		const response = await createLink({
			...onBlank,
			returnUrl: RETURN_URL,
			afterApprove: value,
		});

		expect(response.status).toBe(201);
		const link = (await response.json()) as { url: string };
		expect(link).not.toHaveProperty('afterApprove');
		const page = await send(fake, new URL(link.url).pathname);
		expect(await page.text()).toContain(words);
	});

	it('words the button for going back when absent', async () => {
		const path = await linkUrl({ ...onBlank, returnUrl: RETURN_URL });

		expect(await (await send(fake, path)).text()).toContain(
			'Approve and go back',
		);
	});

	it.each(['return', 'add_to_cart'])(
		'refuses %s with no return address',
		async (value) => {
			const response = await createLink({ ...onBlank, afterApprove: value });

			expect(response.status).toBe(400);
			expect(await response.json()).toEqual({
				error: 'invalid_input',
				reason: expect.stringContaining('afterApprove'),
			});
		},
	);

	it.each(['cart', 'ADD_TO_CART', null])(
		'flags %s, outside the spec, as a contract violation',
		async (value) => {
			const response = await createLink({
				...onBlank,
				returnUrl: RETURN_URL,
				afterApprove: value,
			});

			expect(response.status).toBe(VIOLATION_STATUS);
			expect(fake.violations).not.toEqual([]);
		},
	);
});

describe("a trip's state on the hosted designer address", () => {
	const KEPT = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
		returnUrl: `${RETURN_URL}&pixonaut_product=12`,
	};
	/** The longest state the hosted designer echoes. */
	const MAX_STATE_LENGTH = 200;
	const TRIP = 'Trip-1._~';

	/** Opens the hosted page with a trip's state and follows its `action` link. */
	async function travel(
		path: string,
		state: string,
		action: 'approve' | 'cancel',
	): Promise<URL> {
		const query = `?${new URLSearchParams({ state })}`;
		const html = await (await send(fake, `${path}${query}`)).text();
		const href = html.match(new RegExp(`href="([^"]*/${action}[^"]*)"`))?.[1];
		expect(href).toBeDefined();
		const response = await send(fake, href ?? '');
		expect(response.status).toBe(302);
		return new URL(response.headers.get('location') ?? '');
	}

	it.each(['approve', 'cancel'] as const)(
		'is returned unchanged on %s, keeping the store query',
		async (action) => {
			const path = await linkUrl(KEPT);

			const back = await travel(path, TRIP, action);

			expect(back.searchParams.get('state')).toBe(TRIP);
			expect(back.searchParams.get('pixonaut_product')).toBe('12');
			expect(back.searchParams.has('proofToken')).toBe(action === 'approve');
		},
	);

	it("replaces the link's own state", async () => {
		const path = await linkUrl({ ...KEPT, state: VISIT });

		expect(
			(await travel(path, TRIP, 'approve')).searchParams.get('state'),
		).toBe(TRIP);
	});

	it.each([
		['too long', 'a'.repeat(MAX_STATE_LENGTH + 1)],
		['a reserved character', 'trip/1'],
		['a space', 'trip 1'],
	])(
		"is never echoed when %s: the link's own state or none comes back",
		async (_, state) => {
			const bare = await linkUrl(KEPT);
			const stated = await linkUrl({ ...KEPT, state: VISIT });

			expect(
				(await travel(bare, state, 'approve')).searchParams.has('state'),
			).toBe(false);
			expect(
				(await travel(stated, state, 'cancel')).searchParams.get('state'),
			).toBe(VISIT);
		},
	);

	it("treats a repeated state as missing: the link's own comes back", async () => {
		const path = await linkUrl({ ...KEPT, state: VISIT });
		const page = await send(fake, `${path}?state=trip-a&state=trip-b`);

		expect(await page.text()).not.toContain('trip-a');
		expect((await act(path, 'approve')).searchParams.get('state')).toBe(VISIT);
		const back = await send(fake, `${path}/approve?state=trip-a&state=trip-b`);
		expect(
			new URL(back.headers.get('location') ?? '').searchParams.get('state'),
		).toBe(VISIT);
	});

	it('takes the longest allowed state', async () => {
		const longest = 'a'.repeat(MAX_STATE_LENGTH);
		const path = await linkUrl(KEPT);

		expect(
			(await travel(path, longest, 'approve')).searchParams.get('state'),
		).toBe(longest);
	});
});

describe('the hosted designer address', () => {
	it(
		'is on the public origin the fake is given, where the browser reaches it',
		async () => {
			const publicFake = createFake({ publicOrigin: 'http://localhost:8924' });
			publicFake.reset('default', SITE_URL);

			const response = await send(
				publicFake,
				templatePath(BASE_ID, '/designer-links'),
				{
					method: 'POST',
					json: {
						blankId: FIXTURE.blank.tee,
						placementId: FIXTURE.placement.front,
					},
					...asStore,
				},
			);

			expect(response.status).toBe(201);
			const { url } = (await response.json()) as { url: string };
			expect(url).toMatch(/^http:\/\/localhost:8924\/designer\/dl_/);
		},
		OWN_FAKE_TIMEOUT_MS,
	);
});

describe("a designer link's language", () => {
	const ONE_SIDE = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
		returnUrl: RETURN_URL,
	};

	it('answers its locale, and ?lang= with one, on the link and its list row', async () => {
		const finnish = await createLink({ ...ONE_SIDE, locale: 'fi' });
		const none = await createLink(ONE_SIDE);

		expect(finnish.status).toBe(201);
		const made = (await finnish.json()) as { id: string; url: string };
		expect(made).toMatchObject({
			url: expect.stringMatching(/\?lang=fi$/),
			locale: 'fi',
		});
		const plain = (await none.json()) as { url: string; locale: unknown };
		expect(plain.url).not.toContain('lang=');
		expect(plain.locale).toBeNull();
		const { links } = (await (
			await send(fake, `/v1/share-links?subjectId=${BASE_ID}`, asStore)
		).json()) as { links: { id: string; url: string; locale: unknown }[] };
		expect(links.find((row) => row.id === made.id)).toMatchObject({
			url: made.url,
			locale: 'fi',
		});
	});

	it('flags a locale outside the spec as a contract violation', async () => {
		const response = await createLink({ ...ONE_SIDE, locale: 'xx' });

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).not.toEqual([]);
	});

	it('opens in the workspace default without one', async () => {
		const tenant = fake.state.tenants.get(FIXTURE.tenant.live);
		if (tenant === undefined) {
			throw new Error('The fixture has no live tenant.');
		}
		tenant.workspace.defaultLanguage = 'nl';
		const response = await createLink(ONE_SIDE);
		const { url } = (await response.json()) as { url: string };

		const page = await (await send(fake, new URL(url).pathname)).text();

		expect(page).toContain('<html lang="nl">');
	});
});

describe('a kept designer link', () => {
	const KEPT = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
		returnUrl: `${RETURN_URL}&pixonaut_product=12&pixonaut_variation=0`,
		locale: 'en',
	};

	async function mint(name?: string) {
		const response = await createLink({ ...KEPT, ...(name && { name }) });
		expect(response.status).toBe(201);
		return (await response.json()) as {
			id: string;
			url: string;
			expiresAt: number | null;
		};
	}

	function list(query = 'kind=designer&createdBy=me') {
		return send(fake, `/v1/share-links?${query}`, asStore);
	}

	it('works until withdrawn and sends the shopper back keeping the store query', async () => {
		const link = await mint();
		expect(link.expiresAt).toBeNull();

		const back = await act(new URL(link.url).pathname, 'approve');

		expect(back.searchParams.get('pixonaut_product')).toBe('12');
		expect(back.searchParams.get('pixonaut_variation')).toBe('0');
		expect(back.searchParams.has('state')).toBe(false);
		expect(back.searchParams.get('proofToken')).toBeTruthy();
	});

	it('is listed by its template until it is withdrawn, then stops opening', async () => {
		const link = await mint('Mug (#12)');

		const listed = (await (
			await list(`kind=designer&subjectId=${BASE_ID}&createdBy=me`)
		).json()) as { links: { id: string; name: string; url: string }[] };
		expect(listed.links.map((row) => [row.id, row.name, row.url])).toEqual([
			[link.id, 'Mug (#12)', link.url],
		]);

		const withdrawn = await send(fake, `/v1/share-links/${link.id}`, {
			method: 'DELETE',
			...asStore,
		});
		expect(withdrawn.status).toBe(204);
		expect(
			((await (await list()).json()) as { links: unknown[] }).links,
		).toEqual([]);
		expect((await send(fake, new URL(link.url).pathname)).status).toBe(410);
		expect(
			(
				await send(fake, `/v1/share-links/${link.id}`, {
					method: 'DELETE',
					...asStore,
				})
			).status,
		).toBe(404);
	});

	it('holds its name alone among working links, case aside, until renamed', async () => {
		const first = await mint('Mug (#12)');

		const taken = await createLink({ ...KEPT, name: 'MUG (#12)' });
		expect(taken.status).toBe(409);
		expect(await taken.json()).toEqual({
			error: 'share_link_name_taken',
			holderId: first.id,
		});

		const renamed = await send(fake, `/v1/share-links/${first.id}`, {
			method: 'PATCH',
			json: { name: null },
			...asStore,
		});
		expect(renamed.status).toBe(200);
		expect(((await renamed.json()) as { name: unknown }).name).toBeNull();
		await mint('Mug (#12)');
	});

	it('is withdrawn when the store disconnects', async () => {
		const link = await mint();

		await send(fake, '/v1/connect', { method: 'DELETE', ...asStore });

		expect((await send(fake, new URL(link.url).pathname)).status).toBe(410);
	});
});

describe('share-link events', () => {
	const KEPT = {
		blankId: FIXTURE.blank.tee,
		placementId: FIXTURE.placement.front,
		returnUrl: `${RETURN_URL}&pixonaut_product=12&pixonaut_variation=0`,
	};

	beforeEach(async () => {
		// The stream is the subject here, not the deliveries: none is sent.
		await control('webhooks/drop', { store: MAIN.id, drop: true });
	});

	function control(path: string, json: unknown) {
		return send(fake, `/__fake/${path}`, { method: 'POST', json });
	}

	async function mint(): Promise<{ id: string; url: string }> {
		const response = await createLink({ ...KEPT, name: 'Mug (#12)' });
		expect(response.status).toBe(201);
		return response.json();
	}

	async function pulled(): Promise<{ type: string; payload: unknown }[]> {
		const response = await send(fake, '/v1/events', asStore);
		expect(response.status).toBe(200);
		return (await response.json()).events;
	}

	async function listedUrl(id: string): Promise<string | undefined> {
		const response = await send(
			fake,
			`/v1/share-links?kind=designer&subjectId=${BASE_ID}&createdBy=me`,
			asStore,
		);
		const { links } = (await response.json()) as {
			links: { id: string; url: string }[];
		};
		return links.find((row) => row.id === id)?.url;
	}

	const event = (type: string, id: string, by: string) =>
		expect.objectContaining({
			type,
			payload: {
				linkId: id,
				kind: 'designer',
				subjectId: BASE_ID,
				name: 'Mug (#12)',
				by,
			},
		});

	it('names a link the store withdraws', async () => {
		const link = await mint();

		await send(fake, `/v1/share-links/${link.id}`, {
			method: 'DELETE',
			...asStore,
		});

		expect(await pulled()).toEqual([
			event('share_link.revoked', link.id, 'key'),
		]);
	});

	it('names a link a member withdraws in the studio, which stops opening', async () => {
		const link = await mint();

		const response = await control('share-links/revoke', { link: link.id });

		expect(response.status, await response.clone().text()).toBe(200);
		expect(await pulled()).toEqual([
			event('share_link.revoked', link.id, 'member'),
		]);
		expect(await listedUrl(link.id)).toBeUndefined();
		expect((await send(fake, new URL(link.url).pathname)).status).toBe(410);
	});

	it('names a regenerated link, which keeps its id under a new address', async () => {
		const link = await mint();

		const response = await control('share-links/regenerate', {
			link: link.id,
		});

		expect(response.status, await response.clone().text()).toBe(200);
		expect(await pulled()).toEqual([
			event('share_link.regenerated', link.id, 'member'),
		]);
		const url = await listedUrl(link.id);
		expect(url).toBeDefined();
		expect(url).not.toBe(link.url);
		expect((await send(fake, new URL(link.url).pathname)).status).toBe(410);
		expect((await send(fake, new URL(url ?? '').pathname)).status).toBe(200);
	});

	it('refuses a link the store does not have', async () => {
		const response = await control('share-links/revoke', { link: 'shl_none' });

		expect(response.status).toBe(400);
	});
});
