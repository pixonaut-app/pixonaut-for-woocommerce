import { expect, type FakePixonaut, restUrl, test } from '../fixtures';
import {
	type CheckoutUrls,
	type Line,
	linkProduct,
	openCheckouts,
	pay,
	personaliseIntoCart,
	readOrder,
	runJobs,
	statuses,
} from '../orders';

/** The tests site as the fake reaches it: by its service name on wp-env's
 *  Docker network. */
const TESTS_SITE_ON_NETWORK = 'http://tests-wordpress';

/** The plugin's webhook receiver. */
const WEBHOOK_ROUTE = '/pixonaut/v1/webhook';

/** The order note once every personalised item of a mixed order shipped. */
const ALL_SHIPPED_NOTE = 'All personalised items shipped';

const FIRST_TRACKING = { carrier: 'PostNord', number: 'PN000000011SE' };
const SECOND_TRACKING = { carrier: 'DHL', number: 'DHL000000022' };

/** Each checkout's address, once the tests site takes orders. */
let checkoutUrls: CheckoutUrls;

test.beforeEach(async ({ request }) => {
	checkoutUrls = await openCheckouts(request);
});

/** The printshop ships a line's Pixonaut order, and Pixonaut tells the store
 *  about each step. */
async function ship(
	fake: FakePixonaut,
	line: Line,
	tracking: { carrier: string; number: string },
): Promise<void> {
	const moved = await fake.control.post('/__fake/orders/move', {
		data: {
			orderId: line.refs.orderId,
			externalRef: line.refs.externalRef,
			tracking,
		},
	});
	expect(moved.ok()).toBe(true);
	const { attempts } = (await moved.json()) as {
		attempts: { status: number }[];
	};
	expect(attempts.map((a) => a.status)).toEqual([200, 200, 200]);
}

test('a mixed order ships its personalised items at different times and is never completed', async ({
	connectedStore: admin,
	customer,
	fake,
	request,
	baseURL,
	checkout,
	theme,
}) => {
	test.slow();
	const endpoint = await fake.control.post('/__fake/webhooks/endpoint', {
		data: { url: `${TESTS_SITE_ON_NETWORK}${restUrl(WEBHOOK_ROUTE)}` },
	});
	expect(endpoint.ok()).toBe(true);

	// Two personalised products and one the plugin does not fulfil.
	const stamp = `${theme} ${Date.now()}`;
	const first = await linkProduct(admin, baseURL, `First tee ${stamp}`);
	const second = await linkProduct(admin, baseURL, `Second tee ${stamp}`);
	await personaliseIntoCart(customer, first);
	await personaliseIntoCart(customer, second);
	const plain = await request.post(restUrl('/pixonaut-e2e/v1/plain-product'));
	expect(plain.ok()).toBe(true);
	const { id: plainId } = (await plain.json()) as { id: number };
	await customer.goto(`/?add-to-cart=${plainId}`);
	const orderId = await pay(customer, checkout, checkoutUrls);
	const received = customer.url();

	await runJobs(request);
	const placed = await readOrder(request, orderId);
	expect(placed.lines.map((l) => l.state)).toEqual(['ordered', 'ordered']);
	expect(await statuses(fake, 'POST /v1/orders')).toEqual([201, 201]);
	const [firstLine, secondLine] = placed.lines as [Line, Line];

	// The first ships; the order stays open.
	await ship(fake, firstLine, FIRST_TRACKING);
	await runJobs(request);
	const partly = await readOrder(request, orderId);
	expect(partly.status).toBe('processing');
	expect(partly.lines.map((l) => l.state)).toEqual(['shipped', 'ordered']);
	expect(partly.notes.join('\n')).not.toContain(ALL_SHIPPED_NOTE);

	// The customer sees the shipped item's tracking, and no item's state.
	await customer.goto(received);
	const firstRow = customer.getByRole('row', { name: /^First tee/ });
	await expect(firstRow).toHaveAccessibleName(
		new RegExp(`Tracking: PostNord ${FIRST_TRACKING.number}`),
	);
	await expect(firstRow).not.toHaveAccessibleName(/Status:/);
	const secondRow = customer.getByRole('row', { name: /^Second tee/ });
	await expect(secondRow).toBeVisible();
	await expect(secondRow).not.toHaveAccessibleName(/Status:|Tracking:/);

	// The second ships; the plain item is the store's to ship.
	await ship(fake, secondLine, SECOND_TRACKING);
	await runJobs(request);
	const shipped = await readOrder(request, orderId);
	expect(shipped.status).toBe('processing');
	expect(shipped.lines.map((l) => l.state)).toEqual(['shipped', 'shipped']);
	expect(
		shipped.notes.filter((n) => n.includes(ALL_SHIPPED_NOTE)),
	).toHaveLength(1);
});
