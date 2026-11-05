import {
	expect,
	type FakePixonaut,
	test,
	usePlainPermalinks,
} from '../fixtures';
import {
	type CheckoutUrls,
	type Line,
	openCheckouts,
	pay,
	readOrder,
	runJobs,
} from '../orders';
import { publishLinkedProduct } from '../products';

/** What the customer writes on each side's text layer. */
const FRONT_SLOGAN = 'Printed on the front';
const BACK_SLOGAN = 'Printed on the back';

/** The statuses the fake answered an operation with, in order. */
async function statuses(
	fake: FakePixonaut,
	operation: string,
): Promise<number[]> {
	return (await fake.requests())
		.filter((r) => r.operation === operation)
		.map((r) => r.status);
}

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

test('a front and a back go to the cart as one line and to the printshop as one order', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	await usePlainPermalinks(admin, baseURL);
	await publishLinkedProduct(
		admin,
		`Two-sided tee ${theme} ${Date.now()}`,
		'30',
		{
			back: true,
		},
	);
	const permalink = await admin
		.locator('#wp-admin-bar-view a, #view-post-btn a')
		.first()
		.getAttribute('href');
	expect(permalink).toBeTruthy();

	// The customer personalises the front, then the back under its tab.
	await customer.goto(permalink ?? '');
	const tabs = customer.getByRole('tablist', { name: 'Sides' });
	const front = tabs.getByRole('tab', { name: 'Front' });
	const back = tabs.getByRole('tab', { name: 'Back' });
	await expect(front).toHaveAttribute('aria-selected', 'true');
	await expect(
		customer.locator('#pixonaut-preview-front *').first(),
	).toBeVisible();
	await expect(customer.locator('#pixonaut-preview-back')).toBeHidden();
	await customer.getByRole('textbox', { name: /Slogan/ }).fill(FRONT_SLOGAN);

	// The arrow key moves to the back's tab and selects it.
	await front.focus();
	await customer.keyboard.press('ArrowRight');
	await expect(back).toBeFocused();
	await expect(back).toHaveAttribute('aria-selected', 'true');
	await expect(customer.locator('#pixonaut-form-front')).toBeHidden();
	await expect(
		customer.locator('#pixonaut-preview-back *').first(),
	).toBeVisible();
	await customer.getByRole('textbox', { name: /Slogan/ }).fill(BACK_SLOGAN);

	const proofs: string[] = [];
	customer.on('request', (r) => {
		if (r.method() === 'POST' && new URL(r.url()).pathname === '/v1/proofs') {
			proofs.push(r.postData() ?? '');
		}
	});
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	expect(proofs).toHaveLength(2);
	expect(proofs[0]).toContain(FRONT_SLOGAN);
	expect(proofs[1]).toContain(BACK_SLOGAN);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201, 201]);

	const orderId = await pay(customer, checkout, checkoutUrls);
	const placed = await readOrder(request, orderId);
	expect(placed.lines).toHaveLength(1);

	await runJobs(request);
	const ordered = await readOrder(request, orderId);
	const [line] = ordered.lines as [Line];
	expect(line.state).toBe('ordered');
	expect(Object.keys(line.sides)).toEqual(['front', 'back']);
	expect(await statuses(fake, 'POST /v1/designs/{id}/approve')).toEqual([
		200, 200,
	]);
	// Both designs travel in one printshop order, which Pixonaut prints.
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);
	expect(line.refs.orderId).toBeTruthy();
});
