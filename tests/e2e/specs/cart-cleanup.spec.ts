import { expect, restUrl, signIn, test } from '../fixtures';
import {
	addPersonalisedProduct,
	type CheckoutUrls,
	type Line,
	openCheckouts,
	pay,
	readOrder,
	runJobs,
	statuses,
} from '../orders';

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

test('a guest who logs in before paying orders the design they added, once', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	await addPersonalisedProduct({
		admin,
		customer,
		fake,
		baseURL,
		name: `Merge tee ${theme} ${Date.now()}`,
	});

	// The guest logs in; WooCommerce merges their cart into the account's.
	const account = await request.post(restUrl('/pixonaut-e2e/v1/customers'));
	expect(account.status()).toBe(201);
	await signIn(customer, baseURL ?? '', await account.json());

	const orderId = await pay(customer, checkout, checkoutUrls);
	await runJobs(request);

	const [line] = (await readOrder(request, orderId)).lines as [Line];
	const ref = line.refs.externalRef;
	expect(ref).toMatch(new RegExp(`:order:${orderId}:item:${line.itemId}$`));
	expect(line.refs.externalRefMoved).toBe(true);
	expect(await statuses(fake, 'POST /v1/designs')).toEqual([201]);
	expect(await statuses(fake, 'DELETE /v1/designs/{id}')).toEqual([]);

	const [side] = Object.values(line.sides);
	const design = await fake.control.get(`/__fake/designs/${side?.designId}`);
	expect(design.ok()).toBe(true);
	expect(await design.json()).toMatchObject({ externalRef: ref });
});

test('a line removed from the cart has its design deleted', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	await addPersonalisedProduct({
		admin,
		customer,
		fake,
		baseURL,
		name: `Removed tee ${theme} ${Date.now()}`,
	});
	const carts = await request.post(restUrl('/pixonaut-e2e/v1/cart'));
	expect(carts.ok(), 'the tests site has both carts').toBe(true);
	const cartUrls: Record<'classic' | 'block', string> = await carts.json();

	await customer.goto(cartUrls[checkout]);
	await customer.getByRole('button', { name: /Remove .* from cart/ }).click();
	await expect(
		customer.getByText(/Your cart is (currently )?empty/),
	).toBeVisible();
	// The removal can be undone for a while; the delete waits for it.
	await runJobs(request);
	expect(await statuses(fake, 'DELETE /v1/designs/{id}')).toEqual([]);

	await runJobs(request, { early: true });
	const deletes = (await fake.requests()).filter(
		(r) => r.operation === 'DELETE /v1/designs/{id}',
	);
	expect(deletes.map((r) => r.status)).toEqual([204]);
	const designId = deletes[0]?.path.split('/').pop();
	expect((await fake.control.get(`/__fake/designs/${designId}`)).status()).toBe(
		404,
	);
});
