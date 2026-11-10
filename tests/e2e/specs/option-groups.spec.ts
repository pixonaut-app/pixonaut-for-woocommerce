import type { APIRequestContext, BrowserContext, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	expect,
	restUrl,
	settingsPath,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { mcpSession } from '../mcp';
import {
	addToCartButton,
	connectFixtureStore,
	PERSONALISED,
} from '../personalised';
import { openPixonautTab, openStoreSettings, productPanel } from '../products';

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** The tee's sizes, a global attribute once moved. */
const SIZES = ['Liten', 'Stor'];

/** The first configuration version with these choices. */
const SCHEMA_VERSION = 12;

/** The tee's template's groups, in its order. */
const TEMPLATE_STEPS = ['Ladda upp motiv', 'Text'];

/** The name a product's custom `size` attribute goes by, before it is moved. */
const ATTRIBUTE = 'size';

/** Most a printed part may stand from where the designer draws it. */
const SAME_PLACE_PX = 1.5;

const DESKTOP = { width: 1280, height: 800 };
const PHONE = { width: 390, height: 844 };

const FRONT = '#pixonaut-form-front';
const DESIGNER_FORM = `${FRONT} [data-testid="pixonaut-form"]`;
const PRINTED_FORM = `${FRONT} .pixonaut-form__placeholder`;
const PRINTED_HEADINGS = `${FRONT} .pixonaut-placeholder__step`;

/** Sets the store's product-form settings for this browser context alone. */
async function storeSettings(
	context: BrowserContext,
	baseURL: string,
	form: string,
): Promise<void> {
	await context.addCookies([
		{ name: 'pixonaut_e2e_product_form', value: form, url: baseURL },
	]);
}

/** A variable product on a global Storlek attribute, moved there as a shop's
 *  products are; its id, its page's link and the attribute's field name. */
async function storlekProduct(
	page: Page,
	request: APIRequestContext,
	name: string,
	config: object,
): Promise<{ id: number; permalink: string; field: string }> {
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `${name} ${Date.now()}`,
			pixonaut: { ...PERSONALISED, ...config, v: SCHEMA_VERSION },
			variations: SIZES,
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(created.status()).toBe(201);
	const { id, permalink } = (await created.json()) as {
		id: number;
		permalink: string;
	};
	const mcp = await mcpSession(page);
	const slug = `s${String(Date.now()).slice(-SLUG_CLOCK_DIGITS)}`;
	const attribute = await mcp.run<{ id: number }>(
		ABILITY('create-product-attribute'),
		{ fields: { name: 'Storlek', slug } },
	);
	for (const size of SIZES) {
		await mcp.run(ABILITY('create-attribute-term'), {
			attribute_id: attribute.id,
			fields: { name: size },
		});
	}
	const taxonomy = `pa_${slug}`;
	await mcp.run(ABILITY('move-product-attribute'), {
		from: 'size',
		to: taxonomy,
		product_ids: [id],
	});
	return { id, permalink, field: `attribute_${taxonomy}` };
}

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** Holds the SDK script back, so the page shows what the server printed
 *  until the returned function is called. */
async function holdSdk(page: Page): Promise<() => void> {
	let release = () => {};
	const held = new Promise<void>((resolve) => {
		release = resolve;
	});
	await page.route('https://cdn.pixonaut.app/**', async (route) => {
		await held;
		await route.fallback();
	});
	return release;
}

async function top(page: Page, selector: string): Promise<number> {
	const found = await page.locator(selector).first().boundingBox();
	expect(found, `${selector} is laid out`).not.toBeNull();
	return found?.y ?? 0;
}

const numbered = (steps: string[]) =>
	steps.map((step, index) => `${index + 1}. ${step}`);

/** The attribute's field, as a classic or a block theme's form draws it. */
const fieldOf = (field: string) =>
	`:is(select[name="${field}"], .wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute [role="radiogroup"])`;

for (const viewport of [DESKTOP, PHONE]) {
	test(`an option's group stands last, numbered, and nothing moves at takeover, at ${viewport.width}px`, async ({
		connectedStore: page,
		request,
		baseURL,
	}) => {
		const { permalink, field } = await storlekProduct(
			page,
			request,
			'Last',
			{},
		);
		await storeSettings(
			page.context(),
			baseURL ?? '',
			'as_groups=yes&place=last',
		);
		await page.setViewportSize(viewport);
		const release = await holdSdk(page);
		await page.goto(permalink, { waitUntil: 'domcontentloaded' });

		await expect(page.locator(PRINTED_HEADINGS)).toHaveText([
			...TEMPLATE_STEPS,
			'Storlek',
		]);
		const select = fieldOf(field);
		await expect(page.locator(`${PRINTED_FORM} ${select}`)).toBeVisible();
		const printed = await top(page, select);

		release();
		await expect(page.locator(`${DESIGNER_FORM} > h3`)).toHaveText(
			numbered([...TEMPLATE_STEPS, 'Storlek']),
		);
		await expect(page.locator(`${DESIGNER_FORM} ${select}`)).toBeVisible();
		expect(Math.abs((await top(page, select)) - printed)).toBeLessThanOrEqual(
			SAME_PLACE_PX,
		);
	});

	test(`with numbers off a page shows names alone, printed too, and nothing moves at takeover, at ${viewport.width}px`, async ({
		connectedStore: page,
		request,
		baseURL,
	}) => {
		const { permalink, field } = await storlekProduct(
			page,
			request,
			'No numbers',
			{},
		);
		await storeSettings(
			page.context(),
			baseURL ?? '',
			'as_groups=yes&numbers=off',
		);
		await page.setViewportSize(viewport);
		const release = await holdSdk(page);
		await page.goto(permalink, { waitUntil: 'domcontentloaded' });

		const first = page.locator(PRINTED_HEADINGS).first();
		await expect(first).toHaveText('Storlek');
		expect(
			await first.evaluate(
				(element) => getComputedStyle(element, '::before').content,
			),
		).toBe('none');
		const select = fieldOf(field);
		await expect(page.locator(`${PRINTED_FORM} ${select}`)).toBeVisible();
		const printed = await top(page, select);

		release();
		await expect(page.locator(`${DESIGNER_FORM} > h3`)).toHaveText([
			'Storlek',
			...TEMPLATE_STEPS,
		]);
		await expect(page.locator(`${DESIGNER_FORM} ${select}`)).toBeVisible();
		expect(Math.abs((await top(page, select)) - printed)).toBeLessThanOrEqual(
			SAME_PLACE_PX,
		);
	});
}

/** WooCommerce's own label of an attribute's field, classic or block. */
const LABEL_OF_FIELD =
	':is(th.label, .wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute-name)';

/** Most a label kept for assistive technology alone takes, each way. */
const HIDDEN_LABEL_PX = 1;

async function labelHidden(page: Page, form: string): Promise<void> {
	const label = page.locator(`${form} ${LABEL_OF_FIELD}`).first();
	await expect(label).toHaveCount(1);
	const box = await label.boundingBox();
	expect(box?.height ?? 0).toBeLessThanOrEqual(HIDDEN_LABEL_PX);
	expect(box?.width ?? 0).toBeLessThanOrEqual(HIDDEN_LABEL_PX);
}

test('an option in its own group shows no label above its field, printed or drawn, and its Clear link works', async ({
	connectedStore: page,
	request,
	baseURL,
}) => {
	const { permalink, field } = await storlekProduct(
		page,
		request,
		'Own label',
		{},
	);
	await storeSettings(page.context(), baseURL ?? '', 'as_groups=yes');
	const release = await holdSdk(page);
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });

	await expect(page.locator(PRINTED_HEADINGS).first()).toHaveText('Storlek');
	await labelHidden(page, PRINTED_FORM);

	release();
	await expect(page.locator(`${DESIGNER_FORM} > h3`).first()).toHaveText(
		'1. Storlek',
	);
	await labelHidden(page, DESIGNER_FORM);
	// The label still names the field.
	const select = page.locator(`${DESIGNER_FORM} select[name="${field}"]`);
	if ((await select.count()) > 0) {
		await expect(page.getByRole('combobox', { name: 'Storlek' })).toHaveCount(
			1,
		);
		await select.selectOption({ index: 1 });
		const clear = page.locator(`${DESIGNER_FORM} a.reset_variations`);
		await expect(clear).toBeVisible();
		await clear.click();
		await expect(select).toHaveValue('');
	}
});

test('with numbers off a form in steps has no counter, printed or drawn', async ({
	connectedStore: page,
	request,
}) => {
	const { permalink } = await storlekProduct(page, request, 'Steps', {
		formLayout: 'steps',
		optionGroups: 'first',
		groupNumbers: 'off',
	});
	await page.setViewportSize(DESKTOP);
	const release = await holdSdk(page);
	await page.goto(permalink, { waitUntil: 'domcontentloaded' });

	const printed = page.locator(PRINTED_FORM);
	const printedTitle = `${PRINTED_FORM} .pixonaut-placeholder__step-title`;
	await expect(page.locator(printedTitle)).toHaveText('Storlek');
	await expect(printed.getByText(/^Step 1 of /)).toBeHidden();
	const title = await top(page, printedTitle);

	release();
	const drawnTitle = `${FRONT} [data-testid="form-step-label"]`;
	await expect(page.locator(drawnTitle)).toHaveText('Storlek');
	await expect(
		page.locator(`${FRONT} [data-testid="form-step-counter"]`),
	).toBeHidden();
	expect(Math.abs((await top(page, drawnTitle)) - title)).toBeLessThanOrEqual(
		SAME_PLACE_PX,
	);
});

test('a product follows the store until it chooses, and one arranged by hand keeps its groups', async ({
	connectedStore: page,
	request,
	baseURL,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'worked out on the server');
	const following = await storlekProduct(page, request, 'Following', {});
	const chosen = await storlekProduct(page, request, 'Chosen', {
		optionGroups: 'on_top',
	});
	const arranged = await storlekProduct(page, request, 'Arranged', {
		sides: PERSONALISED.sides.map((side) => ({
			...side,
			formGroups: {
				groups: [
					{ id: 'wc-mine', title: 'Mine', items: [{ attribute: 'size' }] },
				],
			},
		})),
	});
	const headings = page.locator(`${DESIGNER_FORM} > h3`);

	await page.goto(following.permalink);
	await expect(headings).toHaveText(numbered(TEMPLATE_STEPS));

	await storeSettings(page.context(), baseURL ?? '', 'as_groups=yes');
	await page.goto(following.permalink);
	await expect(headings).toHaveText(numbered(['Storlek', ...TEMPLATE_STEPS]));

	await page.goto(chosen.permalink);
	await expect(headings).toHaveText(numbered(TEMPLATE_STEPS));

	await page.goto(arranged.permalink);
	await expect(headings).toHaveText(numbered(['Mine', ...TEMPLATE_STEPS]));
});

test('a variation on another template keeps the options in their groups, first and last', async ({
	connectedStore: page,
	request,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'worked out on the server');
	const headings = page.locator(`${DESIGNER_FORM} > h3`);
	for (const choice of ['first', 'last'] as const) {
		const config = {
			...PERSONALISED,
			optionGroups: choice,
			v: SCHEMA_VERSION,
		};
		const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
			data: {
				name: `Other template ${choice} ${Date.now()}`,
				pixonaut: config,
				variations: SIZES,
				variant: FIXTURE.variant.teeWhiteS,
				overrides: {
					Stor: {
						...config,
						sides: [
							{
								...PERSONALISED.sides[0],
								template: {
									baseId: 'mini-poster',
									version: 1,
									name: 'Mini poster',
								},
								printSizes: [
									{ widthMm: 297, heightMm: 210, label: 'A4 landscape' },
								],
							},
						],
					},
				},
			},
		});
		expect(created.status()).toBe(201);
		await page.goto((await created.json()).permalink);
		await expect(headings).toHaveText(
			numbered(
				choice === 'first'
					? [ATTRIBUTE, ...TEMPLATE_STEPS]
					: [...TEMPLATE_STEPS, ATTRIBUTE],
			),
		);

		await page.locator('select[name="attribute_size"]').selectOption('Stor');
		await expect
			.poll(async () => {
				const texts = await headings.allTextContents();
				return (
					texts.at(choice === 'first' ? 0 : -1)?.endsWith(ATTRIBUTE) &&
					texts.join() !== numbered(TEMPLATE_STEPS).join()
				);
			})
			.toBe(true);
		await expect(headings.filter({ hasText: ATTRIBUTE })).toHaveCount(1);
	}
});

test("a product's groups apply to a variation on another template", async ({
	page,
	request,
	baseURL,
	theme,
	fake,
}) => {
	test.skip(theme !== 'storefront', 'worked out on the server');
	// Both templates' slogans share a key, so the slogan keeps its place.
	for (const template of [FIXTURE.template.live, FIXTURE.template.landscape]) {
		const keyed = await fake.control.post(
			`/__fake/templates/${template}/field-key`,
			{ data: { field: 'slogan.content', key: 'slogan' } },
		);
		expect(keyed.ok(), 'the fake keys the slogan').toBe(true);
	}
	// Connecting again clears the template reads the store keeps.
	await connectFixtureStore(request, baseURL ?? '');
	const side = {
		...PERSONALISED.sides[0],
		formGroups: {
			groups: [
				{
					id: 'wc-mine',
					title: 'Personalisering & Storlek',
					items: [
						{ attribute: ATTRIBUTE },
						{ field: 'slogan:content', label: 'Ditt namn' },
					],
				},
				{ id: 'ladda-upp-motiv', title: 'Foto', items: [] },
				{ id: 'text-front', items: [{ field: 'slogan:fontFamily' }] },
			],
		},
	};
	const config = { ...PERSONALISED, sides: [side], v: SCHEMA_VERSION };
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Groups on another template ${Date.now()}`,
			pixonaut: config,
			variations: SIZES,
			default: SIZES[0],
			variant: FIXTURE.variant.teeWhiteS,
			overrides: {
				[SIZES[1]]: {
					...config,
					// Only the product arranges the form: the variation names no groups.
					sides: [
						{
							...PERSONALISED.sides[0],
							template: {
								baseId: 'mini-poster',
								version: 1,
								name: 'Mini poster',
							},
							printSizes: [
								{ widthMm: 297, heightMm: 210, label: 'A4 landscape' },
							],
						},
					],
				},
			},
		},
	});
	expect(created.status()).toBe(201);
	const headings = page.locator(`${DESIGNER_FORM} > h3`);
	const groups = numbered(['Personalisering & Storlek', 'Foto', 'Text']);
	const named = page
		.locator(DESIGNER_FORM)
		.getByRole('textbox', { name: 'Ditt namn' });
	const sizes = page.locator(`select[name="attribute_${ATTRIBUTE}"]`);

	await page.goto((await created.json()).permalink);
	await expect(headings).toHaveText(groups);
	await expect(named).toBeVisible();

	for (const size of [SIZES[1], SIZES[0], SIZES[1]]) {
		await sizes.selectOption(size);
		await expect(headings).toHaveText(groups);
		await expect(named).toBeVisible();
	}
});

test('a cart edit keeps the options in their groups', async ({
	connectedStore: admin,
	customer,
	request,
	baseURL,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'worked out on the server');
	await usePlainPermalinks(admin, baseURL);
	// A guest, so the lines stay out of the administrator's persistent cart,
	// which later tests would load and drop.
	for (const [choice, expected] of [
		['first', [ATTRIBUTE, ...TEMPLATE_STEPS]],
		['last', [...TEMPLATE_STEPS, ATTRIBUTE]],
	] as const) {
		const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
			data: {
				name: `Edit ${choice} ${Date.now()}`,
				pixonaut: { ...PERSONALISED, optionGroups: choice, v: SCHEMA_VERSION },
				variations: SIZES,
				default: SIZES[0],
				variant: FIXTURE.variant.teeWhiteS,
			},
		});
		expect(created.status()).toBe(201);
		await customer.goto((await created.json()).permalink);
		const headings = customer.locator(`${DESIGNER_FORM} > h3`);
		await expect(headings).toHaveText(numbered([...expected]));
		// WooCommerce enables the button a moment after it finds the variation.
		await expect(addToCartButton(customer)).not.toHaveClass(/disabled/);
		await addToCartButton(customer).click();

		await expect(customer).toHaveURL(/[?&]pixonaut_edit=[0-9a-f]+/);
		await expect(
			customer
				.locator('form.cart, form[data-wp-interactive]')
				.getByRole('button', { name: 'Update design' }),
		).toBeVisible();
		await expect(headings).toHaveText(numbered([...expected]));
	}
});

test('the product screen offers the store default, and the settings page shows the place only with the groups on', async ({
	connectedStore: page,
	request,
	theme,
}) => {
	test.skip(theme !== 'storefront', 'the admin screens are the same');
	const { id } = await storlekProduct(page, request, 'Screen', {});
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	await openPixonautTab(page);
	const panel = productPanel(page);
	await openStoreSettings(page);
	await expect(
		panel.getByLabel('Options in the form').locator('option'),
	).toHaveText([
		/^Store setting: /,
		'On top, unnumbered',
		'Own groups, first',
		'Own groups, last',
	]);
	await expect(panel.getByLabel('Group numbers').locator('option')).toHaveText([
		'Store setting: On',
		'On',
		'Off',
	]);

	await page.goto(settingsPath('storefront'));
	await expect(
		page.locator('[id="pixonaut_wc_settings[group_numbers]"]'),
	).toBeChecked();
	const box = page.locator('[id="pixonaut_wc_settings[options_as_groups]"]');
	const place = page.locator(
		'[id="pixonaut_wc_settings[option_groups_position]"]',
	);
	await box.uncheck();
	await expect(place).toBeHidden();
	await box.check();
	await expect(place).toBeVisible();
});
