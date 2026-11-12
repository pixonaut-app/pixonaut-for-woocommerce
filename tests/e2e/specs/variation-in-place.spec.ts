import type { APIRequestContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	countMounts,
	mounts,
	type Sample,
	samples,
	watchBoxes,
} from '../designer-watch';
import { expect, type FakePixonaut, restUrl, test } from '../fixtures';
import { connectFixtureStore, PERSONALISED } from '../personalised';

/** mini-poster's 420:297 ratio at A4. */
const A4_LANDSCAPE = { widthMm: 297, heightMm: 210, label: 'A4 landscape' };

/** The tee's photo, which the text-only copy of the template leaves out. */
const PHOTO_LAYER = 'motiv';

/** The heading of the tee's photo group. */
const PHOTO_GROUP = 'Ladda upp motiv';

/** The heading the arrangement gives the tee's text group. */
const WORDS_GROUP = 'Your words';

/** The label the arrangement gives the slogan. */
const NAME_LABEL = 'Name on the shirt';

/** The tee arranged: its photo first, then its text group renamed with
 *  the slogan in it under a label of the store's: a version 6
 *  configuration, the first with an arrangement of its own. */
const ARRANGED = {
	...PERSONALISED,
	v: 6,
	sides: PERSONALISED.sides.map((side) => ({
		...side,
		formGroups: {
			groups: [
				{ id: 'ladda-upp-motiv', items: [{ field: 'motiv:src' }] },
				{
					id: 'text-front',
					title: WORDS_GROUP,
					items: [{ field: 'slogan:content', label: NAME_LABEL }],
				},
			],
		},
	})),
};

/** The text-only variation: the poster, with the tee's photo taken out, and
 *  no arrangement of its own, as a variation has no Groups setting. */
const TEXT_ONLY = {
	...ARRANGED,
	sides: ARRANGED.sides.map((side) => ({
		...side,
		template: { baseId: 'mini-poster', version: 1, name: 'Mini poster' },
		printSizes: [A4_LANDSCAPE],
		formGroups: null,
	})),
};

/** The tee on the hoodie: another blank, so the side mounts again. */
const HOODIE = {
	...PERSONALISED,
	blank: { id: FIXTURE.blank.hoodie, name: 'Fixture hoodie' },
};

/** The boxes no switch may move. */
const BOXES = {
	preview: '#pixonaut-preview-front',
	form: '#pixonaut-form-front',
};

/** Painted frames sampled after a switch: well past the fake's reads and
 *  the new designer's drawing. */
const SWITCH_FRAMES = 120;

/** A difference in height too small to see: layout rounds to whole
 *  pixels. */
const SAME_HEIGHT_PX = 1;

/** What the shopper types before switching. */
const TYPED = 'Ada Lovelace';

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/** Takes the photo out of the poster, and connects again, which clears the
 *  template reads the store keeps. */
async function textOnlyPoster(
	fake: FakePixonaut,
	request: APIRequestContext,
	baseURL: string | undefined,
): Promise<void> {
	const dropped = await fake.control.post(
		`/__fake/templates/${FIXTURE.template.landscape}/without-layer`,
		{ data: { layerId: PHOTO_LAYER } },
	);
	expect(dropped.ok(), 'the fake drops the photo').toBe(true);
	await connectFixtureStore(request, baseURL ?? '');
}

async function createProduct(
	request: APIRequestContext,
	data: object,
): Promise<string> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { variant: FIXTURE.variant.teeWhiteS, ...data },
	});
	expect(response.status()).toBe(201);
	return (await response.json()).permalink;
}

function sizes(page: Page) {
	return page.locator('select[name="attribute_size"]');
}

function nameField(page: Page) {
	return page
		.locator(BOXES.form)
		.getByRole('textbox', { name: NAME_LABEL })
		.first();
}

/** Starts sampling the boxes, and gives the last frame before the switch. */
async function beforeSwitch(page: Page): Promise<Sample<typeof BOXES>> {
	await watchBoxes(page, BOXES);
	await expect
		.poll(async () => (await samples(page, BOXES)).length)
		.toBeGreaterThan(0);
	const before = (await samples(page, BOXES)).at(-1) as Sample<typeof BOXES>;
	expect(before.preview, 'the preview is laid out').toBeGreaterThan(0);
	expect(before.form, 'the form is laid out').toBeGreaterThan(0);
	return before;
}

/** The frames painted since `beforeSwitch()` once enough have been. */
async function afterSwitch(page: Page): Promise<Sample<typeof BOXES>[]> {
	await expect
		.poll(async () => (await samples(page, BOXES)).length)
		.toBeGreaterThan(SWITCH_FRAMES);
	return samples(page, BOXES);
}

function movedFrom(
	before: Sample<typeof BOXES>,
	seen: Sample<typeof BOXES>[],
	box: keyof typeof BOXES,
): Sample<typeof BOXES>[] {
	return seen.filter(
		(sample) => Math.abs(sample[box] - before[box]) > SAME_HEIGHT_PX,
	);
}

test('a variation whose template lacks a group switches in place and keeps what the form shares', async ({
	page,
	request,
	baseURL,
	theme,
	fake,
}) => {
	await textOnlyPoster(fake, request, baseURL);
	await countMounts(page);
	await page.goto(
		await createProduct(request, {
			name: `Photo or text ${theme} ${Date.now()}`,
			pixonaut: ARRANGED,
			variations: ['Photo', 'Text'],
			overrides: { Text: TEXT_ONLY },
		}),
	);
	await sizes(page).selectOption('Photo');
	await nameField(page).fill(TYPED);
	await expect(page.locator('.pixonaut-preview__frame')).toHaveClass(
		/is-drawn/,
	);
	const form = page.locator(BOXES.form);
	await expect(form.getByText(PHOTO_GROUP).first()).toBeVisible();
	const mounted = await mounts(page);
	expect(mounted).toBe(1);
	const before = await beforeSwitch(page);

	await sizes(page).selectOption('Text');
	const seen = await afterSwitch(page);

	expect(await mounts(page), 'the designer is not mounted again').toBe(mounted);
	await expect(form.getByText(PHOTO_GROUP)).toHaveCount(0);
	await expect(form.getByText(WORDS_GROUP).first()).toBeVisible();
	await expect(nameField(page)).toHaveValue(TYPED);
	const collapsed = seen.filter((sample) => sample.preview === 0);
	expect(collapsed, 'the preview keeps its size').toEqual([]);
});

test('a variation on another blank mounts again without moving the page', async ({
	page,
	request,
	theme,
}) => {
	await countMounts(page);
	await page.goto(
		await createProduct(request, {
			name: `Tee or hoodie ${theme} ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: ['Tee', 'Hoodie'],
			overrides: { Hoodie: HOODIE },
			variants: { Hoodie: FIXTURE.variant.hoodieWhiteS },
		}),
	);
	await sizes(page).selectOption('Tee');
	await expect(page.locator('.pixonaut-preview__frame')).toHaveClass(
		/is-drawn/,
	);
	expect(await mounts(page)).toBe(1);
	const before = await beforeSwitch(page);

	await sizes(page).selectOption('Hoodie');
	await expect.poll(() => mounts(page)).toBe(2);
	const seen = await afterSwitch(page);

	expect(movedFrom(before, seen, 'preview'), 'the preview stays').toEqual([]);
	expect(movedFrom(before, seen, 'form'), 'the form stays').toEqual([]);
});

test('a page opened on a variation prints its template, and the designer takes it over without mounting again', async ({
	page,
	request,
	baseURL,
	theme,
	fake,
}) => {
	await textOnlyPoster(fake, request, baseURL);
	const permalink = await createProduct(request, {
		name: `Opens on text ${theme} ${Date.now()}`,
		pixonaut: ARRANGED,
		variations: ['Photo', 'Text'],
		overrides: { Text: TEXT_ONLY },
	});
	const opened = new URL(permalink);
	opened.searchParams.set('attribute_size', 'Text');

	// As printed, before any script: the text-only template's form.
	const printed = await (await request.get(opened.toString())).text();
	const mountsJson = /data-pixonaut-mounts="([^"]*)"/.exec(printed)?.[1] ?? '';
	expect(
		mountsJson.replaceAll('&quot;', '"'),
		'the side mounts with the variation’s template',
	).toContain(`"templateId":"${FIXTURE.template.landscape}"`);
	const placeholder =
		/class="pixonaut-form__placeholder[\s\S]*?<\/form>|class="pixonaut-form__placeholder[\s\S]*$/.exec(
			printed,
		)?.[0] ?? '';
	expect(placeholder).toContain(WORDS_GROUP);
	expect(placeholder).not.toContain(PHOTO_GROUP);

	await countMounts(page);
	await page.goto(opened.toString());
	await expect(sizes(page)).toHaveValue('Text');
	await expect(page.locator('.pixonaut-preview__frame')).toHaveClass(
		/is-drawn/,
	);
	await expect(nameField(page)).toBeVisible();
	const before = await beforeSwitch(page);
	// Well past the variation form settling on the chosen variation.
	const seen = await afterSwitch(page);

	expect(await mounts(page), 'mounted once, never again').toBe(1);
	await expect(page.locator(BOXES.form).getByText(PHOTO_GROUP)).toHaveCount(0);
	expect(movedFrom(before, seen, 'preview'), 'the preview stays').toEqual([]);
});
