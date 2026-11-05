import {
	expect,
	type FakePixonaut,
	test,
	usePlainPermalinks,
} from '../fixtures';
import { type Line, openCheckouts, pay, readOrder, runJobs } from '../orders';
import { publishLinkedProduct } from '../products';

/** What the customer writes on the fixture tee's text layer. */
const SLOGAN = 'Ticked and approved';

/** The checkbox approval mode's name in the product panel. */
const CHECKBOX_MODE = 'Customer ticks "I approve the preview"';

/** The error under the box, from the label `approval_required`. */
const APPROVAL_REQUIRED =
	'Please tick "I approve the preview" before adding this product to your cart.';

/** Answers to `POST /v1/proofs` the customer's browser received. */
async function proofsMade(fake: FakePixonaut): Promise<number> {
	return (await fake.requests()).filter(
		(r) => r.method === 'POST' && r.path === '/v1/proofs',
	).length;
}

test('a customer ticks "I approve the preview" and the order is approved with that consent', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const checkoutUrls = await openCheckouts(request);

	// The merchant links a product that asks for the checkbox.
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(
		admin,
		`Checkbox tee ${theme} ${Date.now()}`,
		'25',
		{
			approval: CHECKBOX_MODE,
		},
	);
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();

	// The customer personalises it, with the note under the preview …
	await customer.goto(permalink ?? '');
	await expect(customer.locator('.pixonaut-preview *').first()).toBeVisible();
	await expect(customer.locator('.pixonaut-preview__note')).toHaveText(
		'The preview updates as you type',
	);
	await customer.getByRole('textbox', { name: /Slogan/ }).fill(SLOGAN);

	// … and adds to the cart without the box: the error under it says why.
	const addToCart = customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i });
	const approval = customer.locator('.pixonaut-approval');
	const error = approval.getByRole('alert');
	await addToCart.click();
	await expect(error).toHaveText(APPROVAL_REQUIRED);
	await expect(approval).toHaveClass(/\bis-invalid\b/);
	await expect(customer.getByLabel('I approve the preview')).toHaveAttribute(
		'aria-invalid',
		'true',
	);
	expect(await proofsMade(fake)).toBe(0);

	// … opens the proof from the label's "preview": a dialog over the page,
	// on the same address, with no tab and no Pixonaut address in sight …
	const pageUrl = customer.url();
	const tabs: unknown[] = [];
	customer.context().on('page', (page) => tabs.push(page));
	const preview = approval.getByRole('button', {
		name: 'preview',
		exact: true,
	});
	const dialog = customer.getByRole('dialog', { name: 'Your design' });
	await preview.click();
	await expect(dialog).toBeVisible();
	const proof = dialog.getByRole('img', { name: 'Your design' });
	await expect(proof).toBeVisible();
	expect(await proof.getAttribute('src')).toMatch(/^blob:/);
	expect(
		await proof.evaluate((image: HTMLImageElement) => image.naturalWidth),
	).toBeGreaterThan(0);
	expect(customer.url()).toBe(pageUrl);
	expect(tabs).toHaveLength(0);
	expect(await customer.content()).not.toContain(
		`${new URL(fake.baseURL).origin}/p/`,
	);

	// … which closes with Escape, its button and a click outside it, each
	// time handing focus back to "preview", and leaves the box as it was …
	await customer.keyboard.press('Escape');
	await expect(dialog).toBeHidden();
	await expect(preview).toBeFocused();
	await preview.click();
	await expect(proof).toBeVisible();
	await dialog.getByRole('button', { name: 'Close' }).click();
	await expect(dialog).toBeHidden();
	await expect(preview).toBeFocused();
	await preview.click();
	await expect(proof).toBeVisible();
	await customer.mouse.click(2, 2);
	await expect(dialog).toBeHidden();
	await expect(preview).toBeFocused();
	await expect(customer.getByLabel('I approve the preview')).not.toBeChecked();
	await expect(
		approval.getByRole('button', { name: 'Open proof' }),
	).toHaveCount(0);

	// … ticks the box and adds it to the cart, with the proof they opened.
	await customer.getByLabel('I approve the preview').check();
	await expect(error).toBeHidden();
	await expect(approval).not.toHaveClass(/\bis-invalid\b/);
	await addToCart.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	expect(await proofsMade(fake)).toBe(1);

	// … and pays; the store approves the design with the box as consent.
	const orderId = await pay(customer, checkout, checkoutUrls);
	await runJobs(request);
	const [line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line.refs.approvalMode).toBe('checkbox');
	const [side] = Object.values(line.sides);
	const design = await fake.control.get(`/__fake/designs/${side?.designId}`);
	expect(design.ok()).toBe(true);
	expect(await design.json()).toMatchObject({
		status: 'approved',
		consent: { kind: 'checkbox', at: expect.any(Number) },
	});
});

test('a design records whether its preview was opened before the box was ticked', async ({
	connectedStore: admin,
	customer,
	fake,
	baseURL,
	theme,
}) => {
	test.slow();
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(
		admin,
		`Previewed tee ${theme} ${Date.now()}`,
		'25',
		{ approval: CHECKBOX_MODE },
	);
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();
	const addToCart = customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i });
	const approval = customer.locator('.pixonaut-approval');
	const box = customer.getByLabel('I approve the preview');

	/** What the design the last add to cart committed records: the plugin
	 *  reads a design right after committing it. */
	const recorded = async () => {
		const read = (await fake.requests())
			.filter(
				(r) => r.method === 'GET' && /^\/v1\/designs\/[^/]+$/.test(r.path),
			)
			.at(-1);
		const design = await fake.control.get(
			`/__fake/designs/${read?.path.split('/').pop()}`,
		);
		return ((await design.json()) as { previewOpened?: boolean }).previewOpened;
	};

	// Approved blind, it records false.
	await customer.goto(permalink ?? '');
	await customer.getByRole('textbox', { name: /Slogan/ }).fill('Blind');
	await box.check();
	await addToCart.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	expect(await recorded()).toBe(false);

	// Approved after looking, it records true.
	await customer.goto(permalink ?? '');
	await customer.getByRole('textbox', { name: /Slogan/ }).fill('Looked');
	await approval.getByRole('button', { name: 'preview', exact: true }).click();
	const dialog = customer.getByRole('dialog', { name: 'Your design' });
	await expect(dialog.getByRole('img', { name: 'Your design' })).toBeVisible();
	await dialog.getByRole('button', { name: 'Close' }).click();
	await box.check();
	await addToCart.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	expect(await recorded()).toBe(true);
});
