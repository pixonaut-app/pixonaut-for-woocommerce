import type { APIRequestContext } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test, usePlainPermalinks } from '../fixtures';
import { runJobs } from '../orders';
import { connectFixtureStore, PERSONALISED } from '../personalised';
import { openPixonautTab, productPanel, publish } from '../products';

/** The job that draws a product's picture. */
const PICTURE_HOOK = 'pixonaut_wc_catalogue_picture';

/** The job that queues the drawings of a variable product's variations. */
const VARIATIONS_HOOK = 'pixonaut_wc_catalogue_pictures_variations';

/** A product's picture is saved as `pixonaut-<product id>-<key>.png`. */
function pictureOf(id: number): RegExp {
	return new RegExp(`/pixonaut-${id}-[0-9a-f]+(-\\d+x\\d+)?\\.png`);
}

/** The images the Store API answers for a product or variation. */
async function storeImages(
	request: APIRequestContext,
	id: number,
): Promise<string[]> {
	const response = await request.get(restUrl(`/wc/store/v1/products/${id}`));
	expect(response.ok()).toBe(true);
	return ((await response.json()).images as { src: string }[]).map(
		(i) => i.src,
	);
}

/** A personalised product selling the tee's white S; its id. */
async function createPersonalised(
	request: APIRequestContext,
	name: string,
): Promise<number> {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: { name, pixonaut: PERSONALISED, variant: FIXTURE.variant.teeWhiteS },
	});
	expect(response.status()).toBe(201);
	return ((await response.json()) as { id: number }).id;
}

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

test('a personalised product shows its Pixonaut picture in the product lists once it is drawn', async ({
	page,
	request,
	theme,
}) => {
	const name = `Pictured ${theme} ${Date.now()}`;
	const id = await createPersonalised(request, `${name} one`);
	// A second match keeps the search on its results: one alone opens the product.
	await createPersonalised(request, `${name} two`);
	const results = `/?s=${encodeURIComponent(name)}&post_type=product`;
	const image = page
		.locator('main')
		.locator('li', { hasText: `${name} one` })
		.locator('img')
		.first();

	// Until its picture is drawn, the product keeps its own image.
	await page.goto(results);
	await expect(page.locator('main').getByText(name).first()).toBeVisible();
	await expect(image).not.toHaveAttribute('src', pictureOf(id));
	await runJobs(request, { hook: PICTURE_HOOK });

	await page.goto(results);
	await expect(image).toHaveAttribute('src', pictureOf(id));
	expect(await storeImages(request, id)).toEqual([
		expect.stringMatching(pictureOf(id)),
	]);
});

test('each variation shows its own picture', async ({ request, theme }) => {
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Pictured variations ${theme} ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: ['S', 'M'],
			variants: { S: FIXTURE.variant.teeWhiteS, M: FIXTURE.variant.teeBlackM },
		},
	});
	expect(response.status()).toBe(201);
	const { variations } = (await response.json()) as {
		variations: Record<string, number>;
	};
	const ids = Object.values(variations);
	await runJobs(request, { hook: PICTURE_HOOK });

	for (const id of ids) {
		expect(await storeImages(request, id)).toEqual([
			expect.stringMatching(pictureOf(id)),
		]);
	}
});

test('the product images show the template’s showcase sets the merchant ticks', async ({
	admin: page,
	request,
	theme,
	baseURL,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	// Signing in forgets the connection the hook made.
	await connectFixtureStore(request, baseURL ?? '');
	const id = await createPersonalised(request, `Showcased ${Date.now()}`);
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	const images = productPanel(page).locator('.pixonaut-showcase-images');

	await images.getByRole('checkbox', { name: 'Anna, class of 2026' }).check();
	await images.getByRole('checkbox', { name: 'Team photo' }).check();
	const teamPhoto = images.getByRole('radiogroup', {
		name: 'How Team photo is drawn',
	});
	// Each way it can be drawn shows a thumbnail of what it is drawn from:
	// the photo the product page starts on, each photo of the front, flat.
	await expect(teamPhoto.locator('img')).toHaveCount(4);
	await teamPhoto.getByRole('radio', { name: /Flat design/ }).check();
	const annaPhoto = images.getByRole('radiogroup', {
		name: 'How Anna, class of 2026 is drawn',
	});
	await annaPhoto.getByRole('radio', { name: 'Front, dark' }).check();
	await publish(page);
	await runJobs(request, { hook: PICTURE_HOOK });

	// The first set is the product image, the second leads its gallery.
	expect(await storeImages(request, id)).toEqual([
		expect.stringMatching(pictureOf(id)),
		expect.stringMatching(pictureOf(id)),
	]);
	await openPixonautTab(page);
	await expect(
		images.getByRole('checkbox', { name: 'Anna, class of 2026' }),
	).toBeChecked();
	await expect(
		teamPhoto.getByRole('radio', { name: /Flat design/ }),
	).toBeChecked();
	await expect(
		annaPhoto.getByRole('radio', { name: 'Front, dark' }),
	).toBeChecked();
});

test('Regenerate in background draws the product’s picture again and says when it is done', async ({
	admin: page,
	request,
	theme,
	baseURL,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await connectFixtureStore(request, baseURL ?? '');
	const id = await createPersonalised(request, `Regenerated ${Date.now()}`);
	await runJobs(request, { hook: PICTURE_HOOK });
	const [drawn] = await storeImages(request, id);
	expect(drawn).toMatch(pictureOf(id));
	// The tests site has no .htaccess, so the button's `/wp-json/` route needs plain permalinks.
	await usePlainPermalinks(page, baseURL);
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	const regenerate = productPanel(page).locator('.pixonaut-regenerate');

	await regenerate
		.getByRole('button', { name: 'Regenerate in background' })
		.click();
	// The line says so once the route has queued the drawing; jobs run before that find none.
	await expect(regenerate.getByRole('status')).toContainText(
		'Regenerating the product image',
	);

	await runJobs(request, { hook: PICTURE_HOOK });
	await expect(regenerate.getByRole('status')).toContainText(
		'The product image is regenerated',
	);
	const [redrawn] = await storeImages(request, id);
	expect(redrawn).toMatch(pictureOf(id));
	expect(redrawn).not.toBe(drawn);
});

/** How long Regenerate in background may take from the press until it says
 *  every picture is drawn. Waiting for WP-Cron, as the drawing did before
 *  the button started it, takes a minute or more. */
const DONE_WITHIN_MS = 30_000;

test('Regenerate in background draws a variable product’s pictures at once and says when every one is drawn', async ({
	admin: page,
	request,
	theme,
	baseURL,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await connectFixtureStore(request, baseURL ?? '');
	const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Regenerated variations ${Date.now()}`,
			pixonaut: PERSONALISED,
			variations: ['S', 'M', 'L'],
			variants: {
				S: FIXTURE.variant.teeWhiteS,
				M: FIXTURE.variant.teeBlackM,
				L: FIXTURE.variant.teeWhiteM,
			},
		},
	});
	expect(response.status()).toBe(201);
	const { id, variations } = (await response.json()) as {
		id: number;
		variations: Record<string, number>;
	};
	const pictured = [id, ...Object.values(variations)];
	await usePlainPermalinks(page, baseURL);
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	// Two showcase sets: the product image, and a gallery picture.
	const images = productPanel(page).locator('.pixonaut-showcase-images');
	await images.getByRole('checkbox', { name: 'Anna, class of 2026' }).check();
	await images.getByRole('checkbox', { name: 'Team photo' }).check();
	await publish(page);
	await runJobs(request, { hook: VARIATIONS_HOOK });
	await runJobs(request, { hook: PICTURE_HOOK });
	// The product's new image queues the check of its variations again; it finds them drawn.
	await runJobs(request, { hook: VARIATIONS_HOOK });
	const drawn = await Promise.all(pictured.map((p) => storeImages(request, p)));
	expect(drawn[0]).toHaveLength(2);
	await page.reload();
	await openPixonautTab(page);
	const regenerate = productPanel(page).locator('.pixonaut-regenerate');
	const spinner = regenerate.locator('.pixonaut-regenerate__spinner');

	const pressed = Date.now();
	await regenerate
		.getByRole('button', { name: 'Regenerate in background' })
		.click();
	await expect(spinner).toHaveClass(/is-active/);
	// No job is run by the test: the button starts the drawing itself.
	await expect(regenerate.getByRole('status')).toContainText(
		'The product image is regenerated',
		{ timeout: DONE_WITHIN_MS },
	);
	const took = Date.now() - pressed;
	test.info().annotations.push({
		type: 'press to done',
		description: `${took} ms for a product with a gallery picture and ${pictured.length - 1} variations`,
	});

	expect(took).toBeLessThan(DONE_WITHIN_MS);
	await expect(spinner).not.toHaveClass(/is-active/);
	const redrawn = await Promise.all(
		pictured.map((p) => storeImages(request, p)),
	);
	for (const [index, images] of redrawn.entries()) {
		expect(images).toHaveLength(drawn[index]?.length ?? 0);
		for (const [at, src] of images.entries()) {
			expect(src).toMatch(pictureOf(pictured[index] ?? 0));
			expect(src).not.toBe(drawn[index]?.[at]);
		}
	}
});

test('the products list regenerates the product images of the selected products in the background', async ({
	admin: page,
	request,
	theme,
	baseURL,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await connectFixtureStore(request, baseURL ?? '');
	const name = `Bulk regenerated ${Date.now()}`;
	const id = await createPersonalised(request, name);
	await runJobs(request, { hook: PICTURE_HOOK });
	const [drawn] = await storeImages(request, id);
	await page.goto(
		`/wp-admin/edit.php?post_type=product&s=${encodeURIComponent(name)}`,
	);

	await page.locator(`#cb-select-${id}`).check();
	await page.locator('#bulk-action-selector-top').selectOption({
		label: 'Regenerate in background',
	});
	await page.locator('#doaction').click();

	await expect(page.locator('.notice-success')).toContainText(
		'The product image of 1 product is being regenerated',
	);
	await runJobs(request, { hook: PICTURE_HOOK });
	const [redrawn] = await storeImages(request, id);
	expect(redrawn).toMatch(pictureOf(id));
	expect(redrawn).not.toBe(drawn);
});

/** The sizes Regenerate now's dialog is shown at: desktop and phone. */
const SHOT_SIZES = [
	{ width: 1280, height: 800 },
	{ width: 390, height: 844 },
];

/** Regenerate now's draw route, under plain permalinks too. */
function isDrawRequest(url: URL): boolean {
	return decodeURIComponent(url.href).includes('/pixonaut/v1/pictures/draw');
}

test('Regenerate now on the products list asks first, then draws the selected products’ pictures in the page', async ({
	admin: page,
	request,
	theme,
	baseURL,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await connectFixtureStore(request, baseURL ?? '');
	const name = `Regenerated now ${Date.now()}`;
	const ids = [
		await createPersonalised(request, `${name} one`),
		await createPersonalised(request, `${name} two`),
	];
	await runJobs(request, { hook: PICTURE_HOOK });
	const drawn = await Promise.all(ids.map((id) => storeImages(request, id)));
	await usePlainPermalinks(page, baseURL);
	await page.goto(
		`/wp-admin/edit.php?post_type=product&s=${encodeURIComponent(name)}`,
	);
	for (const id of ids) {
		await page.locator(`#cb-select-${id}`).check();
	}
	await page
		.locator('#bulk-action-selector-top')
		.selectOption({ label: 'Regenerate now' });
	await page.locator('#doaction').click();

	const dialog = page.getByRole('dialog', { name: 'Regenerate now?' });
	await expect(dialog).toContainText('The site is busier while it runs.');
	await expect(dialog).toContainText(
		'Keep this tab open until it finishes. Closing it or pressing Stop leaves the rest to the background queue.',
	);
	await expect(dialog.getByRole('button', { name: 'Stop' })).toBeHidden();
	await expect(dialog.getByRole('button', { name: 'Close' })).toBeHidden();
	for (const size of SHOT_SIZES) {
		await page.setViewportSize(size);
		await test.info().attach(`confirmation ${size.width}x${size.height}`, {
			body: await page.screenshot(),
			contentType: 'image/png',
		});
	}
	// The second product's draw waits until the progress was shot.
	let draws = 0;
	let release: () => void = () => {};
	const held = new Promise<void>((resolve) => {
		release = resolve;
	});
	await page.route(isDrawRequest, async (route) => {
		draws += 1;
		if (draws === 2) {
			await held;
		}
		await route.continue();
	});

	await dialog.getByRole('button', { name: 'Regenerate now' }).click();

	const progress = page.getByRole('dialog', {
		name: 'Regenerating product images',
	});
	await expect(progress.getByRole('status')).toHaveText('1 of 2 products done');
	// The products are drawn in the products list's order, newest first unless created in the same second.
	const lines = progress.getByRole('listitem');
	await expect(lines).toHaveCount(2);
	await expect(lines.first()).toHaveText(/(one|two)\s*Done$/);
	await expect(lines.last()).toHaveText(/(one|two)\s*0 of 1 pictures$/);
	await expect(progress.getByRole('button', { name: 'Stop' })).toBeVisible();
	await expect(
		progress.getByRole('button', { name: 'Regenerate now' }),
	).toBeHidden();
	for (const size of SHOT_SIZES) {
		await page.setViewportSize(size);
		await test.info().attach(`progress ${size.width}x${size.height}`, {
			body: await page.screenshot(),
			contentType: 'image/png',
		});
	}
	await page.setViewportSize(SHOT_SIZES[0] ?? { width: 1280, height: 800 });
	release();
	// No job is run by the test: each picture is drawn while its request runs.
	await expect(progress.getByRole('status')).toHaveText(
		'The product images are regenerated. Close this to see them.',
	);
	const redrawn = await Promise.all(ids.map((id) => storeImages(request, id)));
	for (const [index, images] of redrawn.entries()) {
		expect(images[0]).toMatch(pictureOf(ids[index] ?? 0));
		expect(images[0]).not.toBe(drawn[index]?.[0]);
	}
	await progress.getByRole('button', { name: 'Close' }).click();
	await expect(progress).toBeHidden();
});

test('Regenerate now is a product row’s action, and Cancel draws nothing', async ({
	admin: page,
	request,
	theme,
	baseURL,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await connectFixtureStore(request, baseURL ?? '');
	const name = `Row regenerated ${Date.now()}`;
	const id = await createPersonalised(request, name);
	await runJobs(request, { hook: PICTURE_HOOK });
	await usePlainPermalinks(page, baseURL);
	await page.goto(
		`/wp-admin/edit.php?post_type=product&s=${encodeURIComponent(name)}`,
	);
	const row = page.locator(`#post-${id}`);
	await row.hover();
	await expect(
		row.getByRole('link', { name: 'Regenerate in background' }),
	).toBeVisible();
	let draws = 0;
	await page.route(isDrawRequest, async (route) => {
		draws += 1;
		await route.continue();
	});

	await row.getByRole('link', { name: 'Regenerate now' }).click();
	const dialog = page.getByRole('dialog', { name: 'Regenerate now?' });
	await dialog.getByRole('button', { name: 'Cancel' }).click();

	await expect(dialog).toBeHidden();
	expect(draws).toBe(0);
	await expect(page).toHaveURL(/edit\.php\?post_type=product&s=/);
});

test('Regenerate now on the product’s Pixonaut tab draws its pictures and says when it is done', async ({
	admin: page,
	request,
	theme,
	baseURL,
}) => {
	test.skip(theme !== 'storefront', 'wp-admin does not depend on the theme');
	await connectFixtureStore(request, baseURL ?? '');
	const id = await createPersonalised(request, `Tab regenerated ${Date.now()}`);
	await runJobs(request, { hook: PICTURE_HOOK });
	const [drawn] = await storeImages(request, id);
	await usePlainPermalinks(page, baseURL);
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	const regenerate = productPanel(page).locator('.pixonaut-regenerate');

	await regenerate.getByRole('button', { name: 'Regenerate now' }).click();
	await page
		.getByRole('dialog', { name: 'Regenerate now?' })
		.getByRole('button', { name: 'Regenerate now' })
		.click();
	const progress = page.getByRole('dialog', {
		name: 'Regenerating product images',
	});
	await expect(progress.getByRole('listitem')).toHaveText([/Done/]);
	await progress.getByRole('button', { name: 'Close' }).click();

	await expect(regenerate.getByRole('status')).toContainText(
		'The product image is regenerated',
	);
	const [redrawn] = await storeImages(request, id);
	expect(redrawn).toMatch(pictureOf(id));
	expect(redrawn).not.toBe(drawn);
});
