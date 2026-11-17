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
const TEE_LINE = '4–7 days';

/** The product's attributes table: WooCommerce's, or the Product
 *  Specifications block's. */
const TABLE =
	':is(table.woocommerce-product-attributes, .wp-block-woocommerce-product-specifications table)';

/** The places the chosen variation's description and delivery time show
 *  in, rows of the attributes table, and the text each shows now. */
const DESCRIPTION = `${TABLE} .pixonaut-variation-description .is-chosen`;
const DELIVERY = `${TABLE} .pixonaut-delivery-time .is-chosen`;

/** The tab the classic attributes table is in, closed as the page opens. */
const TABLE_TAB = '.additional_information_tab a';

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
	version?: number,
): Promise<Poster> {
	const v = version === undefined ? {} : { v: version };
	const response = await request.post(
		restUrl('/pixonaut-e2e/v1/dependent-poster'),
		{
			data: {
				key,
				lookup: 'one-by-one',
				descriptions: DESCRIPTIONS,
				times: true,
				pixonaut: { ...PERSONALISED, ...v },
				overrides: Object.fromEntries(
					FRAMED.map((framed) => [framed, { ...HOODIE, ...v }]),
				),
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

/** Each row of the attributes table: its label, and its top and height
 *  from the table's top. A switch must leave them as they are. */
async function tableRows(page: Page): Promise<string[]> {
	return page.locator(TABLE).evaluate((table) => {
		const top = table.getBoundingClientRect().top;
		return [...table.querySelectorAll('tr')].map((row) => {
			const box = row.getBoundingClientRect();
			return `${row.querySelector('th')?.textContent?.trim()} ${Math.round(box.top - top)} ${Math.round(box.height)}`;
		});
	});
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
	// The classic table is in a tab: open it, so its rows take their room.
	const tab = page.locator(TABLE_TAB);
	if ((await tab.count()) > 0) {
		await tab.click();
	}
	await expect(page.locator(TABLE)).toBeVisible();
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
	// A shift is noted only in view: the buy row the shopper is at. The
	// attributes table below is checked by its rows' places.
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
			await expect(
				page.locator(
					'form :is(.pixonaut-variation-description, .pixonaut-delivery-time)',
				),
				'the form holds neither',
			).toHaveCount(0);
			const rows = await tableRows(page);
			expect(rows.join('\n'), 'the table lists both rows').toMatch(
				/^Delivery \d+ \d+$[\s\S]*^Delivery time \d+ \d+$/m,
			);

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
					await tableRows(page),
					`switching to ${value} moves none of the table's rows`,
				).toEqual(rows);
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

/** The first configuration version with a place for the option groups. */
const OPTION_GROUPS_VERSION = 12;

/** The store's setting that draws each option as a group of the designer's
 *  own, after the template's. */
const OPTIONS_AS_GROUPS = 'as_groups=yes&place=last';

/** The designer's numbered group headings. */
const GROUP_HEADINGS =
	'#pixonaut-form-front [data-testid="pixonaut-form"] > h3';

/** How long the page stays idle before each switch: time for what the
 *  next blank needs to be read ahead. */
const IDLE_MS = 3000;

/** How long after a choice the page's own moves count as answering it,
 *  as the browser's layout shift score counts them. */
const INPUT_WINDOW_MS = 500;

/** Each switch, the group of the option it shows, and the description. */
const GROUPED_SWITCHES = [
	{ framing: 'ram', group: 'Ramfärg', variation: 'ram-svart' },
	{ framing: 'ingen', group: null, variation: 'ingen' },
	{ framing: 'ram', group: 'Ramfärg', variation: 'ram-svart' },
	{ framing: 'hangare', group: 'Hängarfärg', variation: 'hangare-ek' },
	{ framing: 'ingen', group: null, variation: 'ingen' },
];

/** The groups that come and go with the framing. */
const DEPENDENT_GROUPS = ['Ramfärg', 'Hängarfärg'];

type FormFrame = {
	time: number;
	groups: string[];
	description: string;
	held: boolean;
	spinner: boolean;
};

/** Starts noting, at every painted frame, the designer's groups, the
 *  variation's description and whether the preview is held; and when the
 *  framing was last chosen. */
async function watchForm(page: Page, framingField: string): Promise<void> {
	await page.evaluate(
		([headings, description, field]) => {
			const frames: FormFrame[] = [];
			const at = { chosen: 0 };
			Object.assign(window, { formFrames: frames, chosenAt: at });
			const shows = (element: Element) => element.getClientRects().length > 0;
			document.addEventListener(
				'change',
				(event) => {
					if ((event.target as HTMLSelectElement).name === field) {
						at.chosen = performance.now();
					}
				},
				true,
			);
			const note = (): void => {
				frames.push({
					time: performance.now(),
					groups: [...document.querySelectorAll(headings)]
						.filter(shows)
						.map((heading) => heading.textContent ?? ''),
					description: [...document.querySelectorAll(description)]
						.filter(shows)
						.map((text) => text.textContent?.trim() ?? '')
						.join(''),
					held: document.querySelector('[data-pixonaut-held]') !== null,
					spinner: document.querySelector('.is-waiting') !== null,
				});
				requestAnimationFrame(note);
			};
			requestAnimationFrame(note);
		},
		[GROUP_HEADINGS, DESCRIPTION, framingField] as const,
	);
}

for (const viewport of VIEWPORTS) {
	test(`with the options in the designer's groups, a switch to another blank changes the form in the frame it is made in and moves nothing later, at ${viewport.width}x${viewport.height}`, async ({
		page,
		request,
		baseURL,
	}) => {
		await page.context().addCookies([
			{
				name: 'pixonaut_e2e_product_form',
				value: OPTIONS_AS_GROUPS,
				url: baseURL ?? '',
			},
		]);
		await page.setViewportSize(viewport);
		const key = String(Date.now()).slice(-SLUG_CLOCK_DIGITS);
		const poster = await createPoster(request, key, OPTION_GROUPS_VERSION);
		try {
			await openPoster(page, poster);
			const field = `attribute_${poster.taxonomies.inramning?.taxonomy}`;
			await watchForm(page, field);

			for (const { framing: value, group, variation } of GROUPED_SWITCHES) {
				// Idle, as a shopper reading the page is.
				await page.waitForTimeout(IDLE_MS);
				await page
					.locator('form .single_add_to_cart_button')
					.evaluate((button) => button.scrollIntoView({ block: 'end' }));
				const since = await page.evaluate(() => performance.now());
				await framing(page, poster).selectOption(value);
				await expect(page.locator('form [name="variation_id"]')).toHaveValue(
					String(poster.variations[variation]),
				);
				await expect(page.locator('[data-pixonaut-held]')).toHaveCount(0, {
					timeout: DRAWN_TIMEOUT_MS,
				});
				const settled = (await switchFrames(page)).length;
				await expect
					.poll(async () => (await switchFrames(page)).length)
					.toBeGreaterThan(settled + SETTLED_FRAMES);
				const { frames, chosen } = await page.evaluate(() => ({
					frames: (window as unknown as { formFrames: FormFrame[] }).formFrames,
					chosen: (window as unknown as { chosenAt: { chosen: number } })
						.chosenAt.chosen,
				}));
				const after = frames.filter((frame) => frame.time >= chosen);
				const first = after[0];
				const shown = (frame: FormFrame | undefined) =>
					DEPENDENT_GROUPS.filter((name) =>
						frame?.groups.some((heading) => heading.endsWith(` ${name}`)),
					);

				expect(
					shown(first),
					`switching to ${value}, the next frame shows its option's group`,
				).toEqual(group === null ? [] : [group]);
				expect(
					first?.description,
					`switching to ${value}, the next frame shows its description`,
				).toBe(DESCRIPTIONS[variation] ?? '');
				expect(
					after.filter(
						(frame) =>
							shown(frame).join() !== (group ?? '') ||
							frame.description !== (DESCRIPTIONS[variation] ?? ''),
					),
					'and nothing changes them back while the side mounts',
				).toEqual([]);
				const lastHeld = after.filter((frame) => frame.held).at(-1);
				expect(
					(lastHeld?.time ?? chosen) - chosen,
					'a switch on an idle page settles under 800 ms',
				).toBeLessThan(SPINNER_AFTER_MS);
				expect(
					after.filter((frame) => frame.spinner),
					'with no spinner',
				).toEqual([]);
				const late = (await shiftsSince(page, since)).filter(
					(shift) =>
						shift.time > chosen + INPUT_WINDOW_MS &&
						shift.sources.some((source) => !source.inOverlay),
				);
				expect(late, `switching to ${value} moves nothing later`).toEqual([]);
			}
		} finally {
			await request.delete(restUrl('/pixonaut-e2e/v1/dependent-poster'), {
				params: { key },
			});
		}
	});
}
