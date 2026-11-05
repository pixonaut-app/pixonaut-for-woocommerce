import type { Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test, usePlainPermalinks } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	createVariableProduct,
	PERSONALISED,
} from '../personalised';
import { openPixonautTab, productPanel } from '../products';

/** The tee in steps, its size placed in the template's Text group, renamed,
 *  before the slogan: a version 6 configuration, the first with an
 *  arrangement of its own. */
const ARRANGED = {
	...PERSONALISED,
	v: 6,
	formLayout: 'steps',
	sides: PERSONALISED.sides.map((side) => ({
		...side,
		formGroups: {
			groups: [
				{
					id: 'text-front',
					title: 'Your words',
					items: [
						{ attribute: 'size' },
						{ field: 'slogan:content', label: 'Name on the back' },
					],
				},
			],
		},
	})),
};

/** The designer's own form, which the steps are drawn in. */
function designerForm(page: Page) {
	return page.locator('#pixonaut-form-front');
}

test.describe('on the product page', () => {
	test.beforeEach(async ({ request, baseURL }) => {
		await connectFixtureStore(request, baseURL ?? '');
	});

	test.afterEach(async ({ request }) => {
		await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
	});

	test('the size sits where it was placed, and Next waits for it', async ({
		page,
		request,
		theme,
	}) => {
		const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
			data: {
				name: `Arranged ${theme} ${Date.now()}`,
				pixonaut: ARRANGED,
				variations: ['Tee', 'Dark'],
				variant: FIXTURE.variant.teeWhiteS,
				variants: { Dark: FIXTURE.variant.teeBlackS },
			},
		});
		expect(response.status()).toBe(201);
		await page.goto((await response.json()).permalink);

		// The first step is the renamed Text group, the size first in it.
		const form = designerForm(page);
		await expect(form.getByText('Your words').first()).toBeVisible();
		const size = form.locator('select[name="attribute_size"]');
		await expect(size).toBeVisible();
		await expect(page.locator('select[name="attribute_size"]')).toHaveCount(1);
		await expect(form.getByText('Name on the back').first()).toBeVisible();

		// Next waits for a size.
		const next = form.getByRole('button', { name: /next/i });
		await expect(next).toBeDisabled();
		await size.selectOption('Dark');
		await expect(next).toBeEnabled();
		while (await next.isVisible()) {
			await next.click();
		}

		await expect(addToCartButton(page)).toBeVisible();
		// WooCommerce enables the button a moment after it finds the variation.
		await expect(addToCartButton(page)).not.toHaveClass(/\bdisabled\b/);
		const proofMade = page.waitForRequest(
			(r) =>
				r.method() === 'POST' && new URL(r.url()).pathname === '/v1/proofs',
		);
		await addToCartButton(page).click();
		await proofMade;
		await expect(page.getByText(/has been added to your cart/)).toBeVisible();
	});

	test('a variation of its own print size keeps the product’s groups, and the variation form alone picks the size', async ({
		page,
		request,
		theme,
	}) => {
		/** The product's side with its arrangement dropped and one size,
		 *  stored as a variation's own: a variation has no Groups setting. */
		const sized = (size: object) => ({
			...PERSONALISED,
			v: 6,
			formLayout: 'steps',
			sides: PERSONALISED.sides.map((side) => ({
				...side,
				printSizes: [size],
				formGroups: null,
			})),
		});
		const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
			data: {
				name: `Arranged sizes ${theme} ${Date.now()}`,
				pixonaut: ARRANGED,
				variations: ['Tee', 'Dark'],
				variant: FIXTURE.variant.teeWhiteS,
				variants: { Dark: FIXTURE.variant.teeBlackS },
				overrides: {
					Tee: sized({ widthMm: 210, heightMm: 270, label: 'Small' }),
					Dark: sized(PERSONALISED.sides[0].printSizes[0]),
				},
			},
		});
		expect(response.status()).toBe(201);
		await page.goto((await response.json()).permalink);

		const form = designerForm(page);
		await expect(form.getByText('Your words').first()).toBeVisible();
		await form.locator('select[name="attribute_size"]').selectOption('Dark');
		// The designer shows the variation's variant once it has switched to
		// the variation, after any mount again.
		await expect(
			page.getByRole('button', { name: 'Front, dark' }),
		).toHaveAttribute('aria-pressed', 'true');

		await expect(form.getByText('Your words').first()).toBeVisible();
		await expect(form.locator('select[name="attribute_size"]')).toBeVisible();
		await expect(form.getByText('Name on the back').first()).toBeVisible();
		await expect(page.getByTestId('pixonaut-size-picker')).toHaveCount(0);
	});
});

test.describe('after the template dropped a group', () => {
	test.beforeEach(async ({ request, baseURL }) => {
		await connectFixtureStore(request, baseURL ?? '');
	});

	test.afterEach(async ({ request }) => {
		await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
	});

	test('an option placed in it is offered first again', async ({
		page,
		request,
		theme,
	}) => {
		const dropped = {
			...ARRANGED,
			sides: ARRANGED.sides.map((side) => ({
				...side,
				formGroups: {
					groups: [{ id: 'dropped-group', items: [{ attribute: 'size' }] }],
				},
			})),
		};
		const response = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
			data: {
				name: `Dropped group ${theme} ${Date.now()}`,
				pixonaut: dropped,
				variations: ['Tee', 'Dark'],
				variant: FIXTURE.variant.teeWhiteS,
				variants: { Dark: FIXTURE.variant.teeBlackS },
			},
		});
		expect(response.status()).toBe(201);
		await page.goto((await response.json()).permalink);

		const form = designerForm(page);
		await expect(form.getByText('Options').first()).toBeVisible();
		await expect(form.locator('select[name="attribute_size"]')).toBeVisible();
	});
});

test.describe('in the product screen', () => {
	// The product screen looks the same under every theme.
	test.skip(
		({ theme }) => theme !== 'storefront',
		'wp-admin does not depend on the theme',
	);

	test('places an option in a group and renames the group', async ({
		connectedStore: page,
		request,
		baseURL,
	}) => {
		// The panel reads the template's groups from a REST route.
		await usePlainPermalinks(page, baseURL);
		const id = await createVariableProduct(
			request,
			`Arranged hoodie ${Date.now()}`,
			PERSONALISED,
			['S', 'L'],
		);
		await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
		await openPixonautTab(page);
		const groups = productPanel(page).locator('.pixonaut-groups');
		// Closed until opened, whenever the page opens.
		const expand = groups.getByRole('button', {
			name: 'Groups',
			exact: true,
		});
		await expect(expand).toHaveAttribute('aria-expanded', 'false');
		await expect(groups.locator('.pixonaut-groups__body')).toBeHidden();
		await expand.click();

		// The template's groups, and the product's size in the tray.
		const text = groups.locator('.pixonaut-groups__group', {
			has: page.locator('input[placeholder="Text"]'),
		});
		await expect(text).toBeVisible();
		const chip = groups.locator('.pixonaut-groups__chip', { hasText: 'size' });
		// The tray is a group of its own, closed until opened.
		const trayToggle = groups.getByRole('button', {
			name: 'Show or hide the product options',
		});
		await expect(trayToggle).toHaveAttribute('aria-expanded', 'false');
		await expect(chip).toHaveCount(0);
		await trayToggle.focus();
		await page.keyboard.press('Enter');
		await expect(chip).toBeVisible();
		// Drawn again, the toggle keeps the focus, the option next in line.
		await expect(trayToggle).toBeFocused();
		await page.keyboard.press('Tab');
		await expect(chip).toBeFocused();

		await text.locator('.pixonaut-groups__toggle').click();
		// A drag that has to scroll to its target never starts: show both first.
		await text.locator('.pixonaut-groups__items').scrollIntoViewIfNeeded();
		await chip.dragTo(text.locator('.pixonaut-groups__items'));
		await expect(chip).toHaveCount(0);
		await expect(
			text.locator('.pixonaut-groups__item.is-option', { hasText: 'size' }),
		).toBeVisible();
		// Back to the tray and in again, without dragging.
		await text
			.locator('.pixonaut-groups__item.is-option')
			.getByRole('button', { name: 'Back to Product options' })
			.click();
		await expect(chip).toBeVisible();
		await chip.click();
		await expect(chip).toHaveCount(0);
		// It went first in the first group; dragged on into Text.
		const option = '.pixonaut-groups__item.is-option';
		const placed = groups
			.locator('.pixonaut-groups__group')
			.first()
			.locator(option);
		await expect(placed).toBeVisible();
		await text.locator('.pixonaut-groups__items').scrollIntoViewIfNeeded();
		await placed
			.locator('.pixonaut-groups__handle')
			.dragTo(text.locator('.pixonaut-groups__items'));
		await expect(text.locator(option)).toBeVisible();
		// A group of the product's own starts with its name field focused.
		await groups.getByRole('button', { name: /New group/ }).click();
		await expect(
			groups.locator(
				'.pixonaut-groups__title[placeholder="Name customers see"]',
			),
		).toBeFocused();
		await text.getByLabel('Group name').fill('Your words');
		// The keyboard moves it first; its handle keeps the focus.
		await text
			.locator('.pixonaut-groups__head .pixonaut-groups__handle')
			.focus();
		await page.keyboard.press('Alt+ArrowUp');
		await expect(
			groups
				.locator('.pixonaut-groups__group')
				.first()
				.getByLabel('Group name'),
		).toHaveValue('Your words');
		await expect(
			text.locator('.pixonaut-groups__head .pixonaut-groups__handle'),
		).toBeFocused();

		await page.locator('#publish').click();
		await page.waitForURL(/[?&]message=/);
		await openPixonautTab(page);
		await expect(expand, 'closed again on the next page load').toHaveAttribute(
			'aria-expanded',
			'false',
		);
		const saved = JSON.parse(
			await groups.locator('.pixonaut-groups-value').inputValue(),
		);
		expect(saved.groups[0]).toEqual(
			expect.objectContaining({
				id: 'text-front',
				title: 'Your words',
				items: expect.arrayContaining([{ attribute: 'size' }]),
			}),
		);
		await expect(
			groups.locator('input[aria-label="Group name"][placeholder="Text"]'),
		).toHaveValue('Your words');
	});
});
