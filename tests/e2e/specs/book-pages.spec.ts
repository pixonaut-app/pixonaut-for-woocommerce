import type { APIRequestContext, Locator, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import {
	expect,
	type FakePixonaut,
	restUrl,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { mcpSession } from '../mcp';
import { runJobs, statuses } from '../orders';
import { addToCartButton, PERSONALISED } from '../personalised';

const ABILITY = (name: string) => `pixonaut-for-woocommerce/${name}`;

/** Most digits of the clock a unique attribute slug takes: slugs are short. */
const SLUG_CLOCK_DIGITS = 8;

/** The page counts the tee's front is printed at in this test. */
const PRINTED = { min: 2, max: 8, step: 2 };

/** mini-poster's 420:297 ratio at A4. */
const A4_LANDSCAPE = { widthMm: 297, heightMm: 210, label: 'A4 landscape' };

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

type Book = { permalink: string; slug: string };

/** Prints the tee's front as pages, and sells a notebook on it with a
 *  variation per count, its page count attribute gone after `use`. */
async function sellBook(
	{
		page,
		request,
		fake,
	}: {
		page: Page;
		request: APIRequestContext;
		fake: FakePixonaut;
	},
	counts: number[],
	overrides: Record<string, object>,
	use: (book: Book) => Promise<void>,
): Promise<void> {
	const set = await fake.control.post(
		`/__fake/blanks/${FIXTURE.blank.tee}/pages`,
		{ data: { placement: FIXTURE.placement.front, ...PRINTED } },
	);
	expect(set.ok(), "the tee's front is printed as pages").toBe(true);
	const created = await request.post(restUrl('/pixonaut-e2e/v1/products'), {
		data: {
			name: `Notebook ${Date.now()}`,
			pixonaut: { ...PERSONALISED, approvalMode: 'checkbox' },
			variations: counts.map(value),
			overrides,
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
		for (const count of counts) {
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
		await use({ permalink, slug });
	} finally {
		await mcp.run(ABILITY('delete-product-attribute'), {
			id: attribute.id,
			force: true,
		});
	}
}

type SentProof = {
	page?: number;
	pageCount?: number;
	values?: Record<string, { content?: string }>;
};

/** The proofs the designer asks for from now on. */
function watchProofs(page: Page): SentProof[] {
	const proofs: SentProof[] = [];
	page.on('request', (sent) => {
		if (
			sent.method() === 'POST' &&
			new URL(sent.url()).pathname === '/v1/proofs'
		) {
			proofs.push(sent.postDataJSON());
		}
	});
	return proofs;
}

test('a notebook’s designer follows the page count chosen, and its own stepper chooses the variation', async ({
	connectedStore: page,
	request,
	fake,
}) => {
	await sellBook(
		{ page, request, fake },
		SOLD,
		{},
		async ({ permalink, slug }) => {
			const proofs = watchProofs(page);
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
		},
	);
});

test('a notebook keeps every page’s words when its designer mounts again or on another template, and is added with every page', async ({
	connectedStore: page,
	request,
	fake,
	baseURL,
}) => {
	// Sells the book, then draws every page of it on three designers.
	test.slow();
	// A form posted to a pretty address finds nothing on the tests site.
	await usePlainPermalinks(page, baseURL);
	// Both templates' slogans share a key, so the slogan's value follows it.
	for (const template of [FIXTURE.template.live, FIXTURE.template.landscape]) {
		const keyed = await fake.control.post(
			`/__fake/templates/${template}/field-key`,
			{ data: { field: 'slogan.content', key: 'slogan' } },
		);
		expect(keyed.ok(), 'the fake keys the slogan').toBe(true);
	}
	const overrides = {
		[value(6)]: {
			...PERSONALISED,
			v: 3,
			formLayout: 'steps',
			approvalMode: 'checkbox',
		},
		[value(8)]: {
			...PERSONALISED,
			approvalMode: 'checkbox',
			sides: [
				{
					...PERSONALISED.sides[0],
					template: { baseId: 'mini-poster', version: 1, name: 'Mini poster' },
					printSizes: [A4_LANDSCAPE],
				},
			],
		},
	};
	await sellBook(
		{ page, request, fake },
		[4, 6, 8],
		overrides,
		async ({ permalink, slug }) => {
			// The recheck reads what the book costs at each count, and sells it.
			await runJobs(request, { early: true });
			const proofs = watchProofs(page);
			await page.goto(permalink);
			const select = variations(page, slug);
			await select.selectOption(slugOf(4));
			const slogan = page
				.locator('#pixonaut-form-front')
				.getByRole('textbox', { name: /Slogan/ })
				.first();
			await slogan.fill('First page');
			await page.locator('[aria-label*="next page" i]').first().click();
			await expect(slogan).not.toHaveValue('First page');
			await slogan.fill('Second page');

			// Each page's words, as its proofs were asked for.
			const proofed = async (count: number) => {
				await expect.poll(() => proofs.length).toBe(count);
				const words = [...proofs]
					.sort((first, second) => (first.page ?? 0) - (second.page ?? 0))
					.map(({ values }) => values?.slogan?.content);
				proofs.length = 0;
				await page.keyboard.press('Escape');
				return words;
			};
			// In steps, the designer mounts again, on the page it showed.
			await select.selectOption(slugOf(6));
			await expect(shownCount(page)).toContainText('6');
			await expect(page.getByText('Page 2 of 6')).toBeVisible();

			// On one page again, the first variation's own designer.
			await select.selectOption(slugOf(4));
			await expect(shownCount(page)).toContainText('4');
			await page.locator('.pixonaut-approval__proof:visible').click();
			expect(await proofed(4)).toEqual([
				'First page',
				'Second page',
				undefined,
				undefined,
			]);

			// On another template.
			await select.selectOption(slugOf(8));
			await expect(shownCount(page)).toContainText('8');
			await page.locator('.pixonaut-approval__proof:visible').click();
			expect((await proofed(8)).slice(0, 3)).toEqual([
				'First page',
				'Second page',
				undefined,
			]);

			await page.locator('.pixonaut-approval__box').check();
			await addToCartButton(page).click();
			await page.waitForURL(/pixonaut_edit=/);
			expect(await statuses(fake, 'POST /v1/designs')).toEqual(
				Array.from({ length: 8 }, () => 201),
			);
		},
	);
});
