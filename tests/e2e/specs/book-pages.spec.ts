import type { Locator, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import { mcpSession } from '../mcp';
import { PERSONALISED } from '../personalised';

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** The page counts the tee's front is printed at in this test. */
const PRINTED = { min: 2, max: 8, step: 2 };

/** The counts the notebook is sold at: not every one it is printed at. */
const SOLD = [2, 6, 8];

const value = (count: number) => `${count} pages`;

const slugOf = (count: number) => `${count}-pages`;

/** The count the designer's own stepper shows. */
function shownCount(page: Page): Locator {
	return page.getByTestId('pixonaut-page-count-value');
}

function variations(page: Page, slug: string): Locator {
	return page.locator(`select[name="attribute_pa_${slug}"]`);
}

// The variation form's select is the classic form's.
test.skip(
	({ theme }) => theme !== 'storefront',
	'the block form is followed as the classic one is',
);

test('a notebook’s designer follows the page count chosen, and its own stepper chooses the variation', async ({
	connectedStore: page,
	request,
	fake,
}) => {
	const set = await fake.control.post(
		`/__fake/blanks/${FIXTURE.blank.tee}/pages`,
		{ data: { placement: FIXTURE.placement.front, ...PRINTED } },
	);
	expect(set.ok(), "the tee's front is printed as pages").toBe(true);
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Notebook ${Date.now()}`,
			pixonaut: { ...PERSONALISED, approvalMode: 'checkbox' },
			variations: SOLD.map(value),
			variant: FIXTURE.variant.teeWhiteS,
		},
	});
	expect(created.status()).toBe(201);
	const { id, permalink } = (await created.json()) as {
		id: number;
		permalink: string;
	};

	const mcp = await mcpSession(page);
	const slug = `e2e${String(Date.now()).slice(-SLUG_CLOCK_DIGITS)}`;
	const attribute = await mcp.run<{ id: number }>(
		ABILITY('create-product-attribute'),
		{ fields: { name: 'Pages', slug } },
	);
	try {
		for (const count of SOLD) {
			await mcp.run(ABILITY('create-attribute-term'), {
				attribute_id: attribute.id,
				fields: { name: value(count), slug: slugOf(count) },
			});
		}
		await mcp.run(ABILITY('move-product-attribute'), {
			from: 'size',
			to: `pa_${slug}`,
			product_ids: [id],
		});
		await mcp.run(ABILITY('set-attribute-blank-option'), {
			attribute_id: attribute.id,
			option: '@pages',
			values: [],
		});

		const proofs: { page?: number; pageCount?: number }[] = [];
		page.on('request', (sent) => {
			if (
				sent.method() === 'POST' &&
				new URL(sent.url()).pathname === '/v1/proofs'
			) {
				proofs.push(sent.postDataJSON());
			}
		});

		await page.goto(permalink);
		const select = variations(page, slug);
		// The designer draws the variation field in its form.
		await expect(
			page.locator('#pixonaut-form-front').locator(select),
		).toBeVisible();

		await select.selectOption(slugOf(6));
		await expect(shownCount(page)).toContainText('6');
		await select.selectOption(slugOf(2));
		await expect(shownCount(page)).toContainText('2');

		// A step towards pages nothing is sold at goes on to the next sold.
		await page.locator('[aria-label*="more" i]').first().click();
		await expect(select).toHaveValue(slugOf(6));
		await expect(shownCount(page)).toContainText('6');

		await page.locator('.pixonaut-approval__proof:visible').click();
		await expect.poll(() => proofs.length).toBe(6);
		expect(proofs.map((proof) => proof.page).sort()).toEqual([
			1, 2, 3, 4, 5, 6,
		]);
		expect(proofs.every((proof) => proof.pageCount === 6)).toBe(true);
	} finally {
		await mcp.run(ABILITY('delete-product-attribute'), {
			id: attribute.id,
			force: true,
		});
	}
});
