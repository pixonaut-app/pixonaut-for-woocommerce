import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	countMounts,
	mounts,
	type Shift,
	type SwitchFrame,
	shiftsSince,
	switchFrames,
	watchShifts,
	watchSwitch,
} from '../designer-watch';
import { expect, type FakePixonaut, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

/** The widths the shopper switches at: a desktop and a phone. */
const VIEWPORTS = [
	{ width: 1280, height: 800 },
	{ width: 390, height: 844 },
];

/** The tee on the hoodie: another blank, so the side mounts again. */
const HOODIE = {
	...PERSONALISED,
	blank: { id: FIXTURE.blank.hoodie, name: 'Fixture hoodie' },
};

/** The framed poster's variations, by the support route's keys: on the
 *  hoodie, while the bare poster and the one on a hanger are on the tee. */
const FRAMED = ['ram-svart', 'ram-vit'];

/** Most digits of the clock a unique slug takes: attribute slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** A switch quicker than this shows no spinner. */
const SPINNER_AFTER_MS = 800;

/** How long the fake answers late in the slow switch: well past the
 *  spinner's wait. */
const SLOW_FAKE_MS = 1500;

/** How long the side may take to show its next designer: on the slow fake,
 *  several reads one after the other. */
const DRAWN_TIMEOUT_MS = 30_000;

/** Painted frames sampled after a switch settled, so a late move shows. */
const SETTLED_FRAMES = 30;

/** Layout rounds to whole pixels. */
const PIXEL = 1;

/** Each variation's description, of lengths far apart: none for the bare
 *  poster, a line for the hanger, a paragraph that wraps for the frame. */
const DESCRIPTIONS: Record<string, string> = {
	'hangare-ek': 'Hängare i ek, med en upphängningssnodd.',
	'ram-svart':
		'Ram i svart ek med passepartout. Levereras med glas som inte reflekterar, upphängningsbeslag och skyddande hörn, klar att hänga på väggen. Ramens djup är 2 cm och den väger under ett kilo.',
};

/** The line the tee's variations say: the fixture printshop's span. The
 *  frame, on the hoodie, says none: its printshop tells no time. */
const TEE_LINE = 'Delivered in 4–7 days';

/** The places the chosen variation's description and delivery time show
 *  in, and the text each shows now. */
const DESCRIPTION = '.pixonaut-variation-description .is-chosen';
const DELIVERY = '.pixonaut-delivery-time .is-chosen';

/** The switches, by Inramning value, with the variation each lands on: the
 *  colour of the frame or the hanger a switch shows again keeps the first
 *  value it was given while it was hidden. */
const SWITCHES = [
	{
		framing: 'ram',
		variation: 'ram-svart',
		mounted: 2,
		price: '20.00',
		delivery: null,
	},
	{
		framing: 'hangare',
		variation: 'hangare-ek',
		mounted: 3,
		price: '15.00',
		delivery: TEE_LINE,
	},
	// Back on the tee, as on a hanger: switched in place.
	{
		framing: 'ingen',
		variation: 'ingen',
		mounted: 3,
		price: '10.00',
		delivery: TEE_LINE,
	},
];

/** The bare poster's price, which the page opens on. */
const OPENING_PRICE = '10.00';

/** The top of the poster's range, the canvas's price: no switch here lands
 *  on it, so a price showing it is the range. */
const RANGE_TOP = '30.00';

type Poster = {
	permalink: string;
	taxonomies: Record<string, { taxonomy: string }>;
	variations: Record<string, number>;
};

test.beforeEach(async ({ request, baseURL, fake }) => {
	await connectFixtureStore(request, baseURL ?? '');
	// The frame's printshop tells no delivery time: its line is empty.
	const told = await fake.control.post(
		`/__fake/blanks/${FIXTURE.blank.hoodie}/costs`,
		{ data: { costs: { [FIXTURE.variant.hoodieWhiteS]: { delivery: null } } } },
	);
	expect(told.ok()).toBe(true);
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** A poster sold bare, on a hanger or framed, with more variations than
 *  the store gives the form up front, framed on another blank; each
 *  variation described as `DESCRIPTIONS` says, in a store that shows
 *  delivery times. */
async function createPoster(
	request: APIRequestContext,
	key: string,
): Promise<Poster> {
	const response = await request.post(
		restUrl('/pixonaut-e2e/v1/dependent-poster'),
		{
			data: {
				key,
				lookup: 'one-by-one',
				descriptions: DESCRIPTIONS,
				times: true,
				pixonaut: PERSONALISED,
				overrides: Object.fromEntries(FRAMED.map((framed) => [framed, HOODIE])),
				variants: Object.fromEntries(
					FRAMED.map((framed) => [framed, FIXTURE.variant.hoodieWhiteS]),
				),
			},
		},
	);
	expect(response.status()).toBe(201);
	return (await response.json()) as Poster;
}

function framing(page: Page, poster: Poster) {
	return page.locator(
		`form select[name="attribute_${poster.taxonomies.inramning?.taxonomy}"]`,
	);
}

/** The total height of the attribute rows shown now: what a step that
 *  appears or goes moves the page below it by. */
async function rowsHeight(page: Page): Promise<number> {
	return page.evaluate(() =>
		[...document.querySelectorAll('form.cart select[name^="attribute_"]')]
			.map((select) => select.closest('tr') ?? select)
			.filter((row) => row.getClientRects().length > 0)
			.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0),
	);
}

/** The nodes a switch moved that the steps appearing or going do not
 *  account for: one that moved other than straight down or up by the
 *  height they added or took away. The table the steps are rows of fits
 *  its columns to the labels of the rows it shows, so the steps that
 *  stay move along with those that appear or go. Rects are clipped to the
 *  viewport, so a node partly below it changes height as it moves, and one
 *  that came into view had none. The designer's overlay is left out: it
 *  follows the preview after a scroll (`Shift`). */
function unaccounted(shifts: Shift[], moved: number): Shift['sources'] {
	return shifts
		.flatMap((shift) => shift.sources)
		.filter(
			({ previous, current, inAttributes, inOverlay }) =>
				!inAttributes &&
				!inOverlay &&
				previous.height > 0 &&
				(Math.abs(current.x - previous.x) > PIXEL ||
					Math.abs(current.y - previous.y - moved) > PIXEL),
		);
}

/** Opens the poster on its bare variation, drawn, and starts watching. */
async function openPoster(page: Page, poster: Poster): Promise<void> {
	await countMounts(page);
	await page.goto(poster.permalink);
	await expect(framing(page, poster)).toHaveValue('ingen');
	await expect(page.locator('form [name="variation_id"]')).toHaveValue(
		String(poster.variations.ingen),
	);
	await expect(page.locator('.pixonaut-preview__frame').first()).toHaveClass(
		/is-drawn/,
	);
	await expect(page.locator('[data-pixonaut-held]')).toHaveCount(0);
	await watchSwitch(page, 'front');
	await watchShifts(page);
}

/** Switches Inramning to `value` and waits until the variation is shown,
 *  the page's designers `mounted` in all, the last one drawn, and settled;
 *  gives the frames painted since, when it began and how far the steps it
 *  showed or hid move what is below them. */
async function switchTo(
	page: Page,
	poster: Poster,
	value: string,
	variation: number,
	mounted: number,
): Promise<{ frames: SwitchFrame[]; since: number; moved: number }> {
	// A shift is noted only in view: the place the shopper reads the
	// variation's description and delivery time in, by the button.
	await page
		.locator('form .single_add_to_cart_button')
		.evaluate((button) => button.scrollIntoView({ block: 'end' }));
	const rowsBefore = await rowsHeight(page);
	const since = await page.evaluate(() => performance.now());
	await framing(page, poster).selectOption(value);
	await expect(page.locator('form [name="variation_id"]')).toHaveValue(
		String(variation),
	);
	await expect.poll(() => mounts(page)).toBe(mounted);
	await expect(page.locator('[data-pixonaut-held]')).toHaveCount(0, {
		timeout: DRAWN_TIMEOUT_MS,
	});
	const settled = (await switchFrames(page)).length;
	await expect
		.poll(async () => (await switchFrames(page)).length)
		.toBeGreaterThan(settled + SETTLED_FRAMES);
	const frames = (await switchFrames(page)).filter(
		(frame) => frame.time >= since,
	);
	return { frames, since, moved: (await rowsHeight(page)) - rowsBefore };
}

function changes(frames: SwitchFrame[]): string[] {
	return frames
		.map((frame) => frame.price)
		.filter((price, index, all) => price !== all[index - 1]);
}

for (const viewport of VIEWPORTS) {
	test(`switching to a variation on another blank at ${viewport.width}x${viewport.height} moves nothing the shopper did not ask for`, async ({
		page,
		request,
	}) => {
		await page.setViewportSize(viewport);
		const key = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
		const poster = await createPoster(request, key);
		try {
			await openPoster(page, poster);
			let before = (await switchFrames(page)).at(-1)?.price ?? '';
			expect(before, 'the bare poster’s price shows').toContain(OPENING_PRICE);
			await expect(page.locator(DESCRIPTION)).toHaveCount(0);
			await expect(page.locator(DELIVERY)).toHaveText(TEE_LINE);

			for (const {
				framing: value,
				variation,
				mounted,
				price,
				delivery,
			} of SWITCHES) {
				const id = poster.variations[variation] as number;
				const { frames, since, moved } = await switchTo(
					page,
					poster,
					value,
					id,
					mounted,
				);
				const after = frames.at(-1)?.price ?? '';
				expect(after, `${variation}'s price shows`).toContain(price);
				const description = DESCRIPTIONS[variation];
				if (description === undefined) {
					await expect(page.locator(DESCRIPTION)).toHaveCount(0);
				} else {
					await expect(page.locator(DESCRIPTION)).toHaveText(description);
				}
				if (delivery === null) {
					await expect(page.locator(DELIVERY)).toHaveCount(0);
				} else {
					await expect(page.locator(DELIVERY)).toHaveText(delivery);
				}

				expect(
					unaccounted(await shiftsSince(page, since), moved),
					`switching to ${value} shifts nothing but the steps it shows or hides`,
				).toEqual([]);
				expect(
					changes(frames),
					`the price changes once, to ${variation}'s`,
				).toEqual([before, after]);
				expect(
					frames.filter((frame) => frame.price.includes(RANGE_TOP)),
					'the range never shows',
				).toEqual([]);
				expect(
					frames.filter((frame) => frame.disabled || !frame.cart),
					'Add to cart stays enabled',
				).toEqual([]);
				expect(
					frames.filter(
						(frame) =>
							(frame.blocked || frame.spinner) &&
							frame.time < since + SPINNER_AFTER_MS,
					),
					'no spinner and no block shows within 800 ms',
				).toEqual([]);
				expect(
					frames.filter((frame) => frame.blocked),
					'the form is never blocked',
				).toEqual([]);
				before = after;
			}
		} finally {
			await request.delete(restUrl('/pixonaut-e2e/v1/dependent-poster'), {
				params: { key },
			});
		}
	});
}

test('a switch that takes longer than 800 ms shows the spinner over the held picture, and moves nothing', async ({
	page,
	request,
	fake,
}) => {
	const key = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
	const poster = await createPoster(request, key);
	try {
		await openPoster(page, poster);
		await slowFake(page, fake);

		const { frames, since, moved } = await switchTo(
			page,
			poster,
			'ram',
			poster.variations['ram-svart'] as number,
			2,
		);

		const spinning = frames.filter((frame) => frame.spinner);
		expect(spinning.length, 'the spinner shows').toBeGreaterThan(0);
		expect(
			spinning.filter((frame) => frame.time < since + SPINNER_AFTER_MS),
			'not before 800 ms',
		).toEqual([]);
		expect(
			spinning.filter((frame) => !frame.held),
			'only over the held picture',
		).toEqual([]);
		expect(frames.at(-1)?.spinner, 'gone once drawn').toBe(false);
		expect(unaccounted(await shiftsSince(page, since), moved)).toEqual([]);
	} finally {
		await request.delete(restUrl('/pixonaut-e2e/v1/dependent-poster'), {
			params: { key },
		});
	}
});

/** Has every request of the page to the fake answered late from now on. */
async function slowFake(page: Page, fake: FakePixonaut): Promise<void> {
	await page.route(
		(url) => url.href.startsWith(fake.baseURL),
		async (route) => {
			await new Promise((resolve) => setTimeout(resolve, SLOW_FAKE_MS));
			await route.fallback();
		},
	);
}
