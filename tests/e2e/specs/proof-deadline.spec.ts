import type { APIRequestContext, Page } from '@playwright/test';
import {
	expect,
	type FakePixonaut,
	restUrl,
	settingsPath,
	test,
} from '../fixtures';
import {
	type CheckoutUrls,
	type Line,
	openCheckouts,
	pay,
	readOrder,
	runJobs,
	statuses,
} from '../orders';
import { createLinkedProduct } from '../products';

/** What the customer writes on the fixture tee's text layer. */
const SLOGAN = 'Waiting for me';

/** A proof page's path on the fake, which stands in for Pixonaut's. */
const PROOF_PAGE = /\/h\/proof\/[A-Za-z0-9_-]+/;

/** The plugin's Action Scheduler hooks for a proof's reminders and deadline. */
const REMINDER_HOOK = 'pixonaut_proof_reminder';
const DEADLINE_HOOK = 'pixonaut_proof_deadline';

/** The proof deadline settings, by their field label, and their defaults. */
const NO_ANSWER = 'When no answer by the deadline';
const ON_DENY = 'On deny';
const REMINDERS = 'Proof reminders at most';
const DEFAULTS = {
	[NO_ANSWER]: 'deny',
	[ON_DENY]: 'needs_attention',
	[REMINDERS]: '2',
};

type Mail = { to: string[]; subject: string; message: string };

/** The emails the tests site sent whose subject names the order. */
async function mailsFor(
	request: APIRequestContext,
	orderId: string,
): Promise<Mail[]> {
	const response = await request.get(restUrl('/pixonaut-e2e/v1/mails'));
	expect(response.ok()).toBe(true);
	return ((await response.json()) as Mail[]).filter((mail) =>
		mail.subject.includes(orderId),
	);
}

/** Saves the proof deadline settings on the settings page's Approval tab. */
async function saveSettings(
	admin: Page,
	values: Partial<Record<keyof typeof DEFAULTS, string>>,
): Promise<void> {
	await admin.goto(settingsPath('approval'));
	for (const [label, value] of Object.entries(values)) {
		const field = admin.getByLabel(label, { exact: true });
		if (label === REMINDERS) {
			await field.fill(value);
		} else {
			await field.selectOption(value);
		}
	}
	await admin.getByRole('button', { name: 'Save changes' }).click();
	await expect(admin.getByText('Your settings have been saved.')).toBeVisible();
}

/** The customer buys a tee approved by proof email; answers the order's id
 *  and its line once the proof was sent. */
async function buyProofTee(
	admin: Page,
	customer: Page,
	request: APIRequestContext,
	{
		baseURL,
		checkout,
		checkoutUrls,
		name,
	}: {
		baseURL: string | undefined;
		checkout: 'classic' | 'block';
		checkoutUrls: CheckoutUrls;
		name: string;
	},
): Promise<{ orderId: string; line: Line }> {
	const permalink = await createLinkedProduct(admin, baseURL, name, {
		approval: 'proof_email',
	});

	await customer.goto(permalink);
	await expect(customer.locator('.pixonaut-preview *').first()).toBeVisible();
	await customer.getByRole('textbox', { name: /Slogan/ }).fill(SLOGAN);
	await customer
		.locator('form.cart, form[data-wp-interactive]')
		.getByRole('button', { name: /add to cart/i })
		.click();
	await expect(customer.getByText(/has been added to your cart/)).toBeVisible();
	const orderId = await pay(customer, checkout, checkoutUrls);
	await runJobs(request);

	const [line] = (await readOrder(request, orderId)).lines as [Line];
	expect(line.state).toBe('awaiting_customer');
	return { orderId, line };
}

/** The design of the line's only side, as the fake holds it. */
async function designOf(fake: FakePixonaut, line: Line): Promise<unknown> {
	const [side] = Object.values(line.sides);
	const design = await fake.control.get(`/__fake/designs/${side?.designId}`);
	expect(design.ok()).toBe(true);
	return design.json();
}

test('an unanswered proof is reminded, then approved at the deadline and ordered', async ({
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
	await saveSettings(admin, { [NO_ANSWER]: 'approve', [REMINDERS]: '1' });
	try {
		const { orderId, line } = await buyProofTee(admin, customer, request, {
			baseURL,
			checkout,
			checkoutUrls,
			name: `Deadline tee ${theme} ${Date.now()}`,
		});

		// The reminder falls due: the customer gets the proof again, and this
		// last reminder says what the deadline does.
		await runJobs(request, { hook: REMINDER_HOOK, item: line.itemId });
		const [reminder] = (await mailsFor(request, orderId)).filter((mail) =>
			mail.subject.startsWith('Reminder:'),
		);
		expect(reminder?.to).toContain('ada@example.com');
		expect(reminder?.message).toMatch(PROOF_PAGE);
		expect(reminder?.message).toContain(
			'we will print your design as the proof shows it',
		);

		// The deadline passes without an answer: the store approves it.
		await runJobs(request, { hook: DEADLINE_HOOK, item: line.itemId });
		expect(await statuses(fake, 'POST /v1/designs/{id}/approve')).toEqual([
			200,
		]);
		await runJobs(request);
		const [ordered] = (await readOrder(request, orderId)).lines as [Line];
		expect(ordered.state).toBe('ordered');
		expect(await statuses(fake, 'POST /v1/orders')).toEqual([201]);
		expect(await designOf(fake, line)).toMatchObject({
			status: 'approved',
			consent: { kind: 'deadline' },
		});
	} finally {
		await saveSettings(admin, DEFAULTS);
	}
});

test('an unanswered proof is denied at the deadline and the item cancelled', async ({
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
	await saveSettings(admin, { [ON_DENY]: 'cancel' });
	try {
		const { orderId, line } = await buyProofTee(admin, customer, request, {
			baseURL,
			checkout,
			checkoutUrls,
			name: `Denied tee ${theme} ${Date.now()}`,
		});

		await runJobs(request, { hook: DEADLINE_HOOK, item: line.itemId });
		// The cancellation runs as its own job.
		await runJobs(request);

		const [cancelled] = (await readOrder(request, orderId)).lines as [Line];
		expect(cancelled.state).toBe('cancelled');
		expect(await designOf(fake, line)).toMatchObject({ status: 'rejected' });
		expect(await statuses(fake, 'POST /v1/orders')).toEqual([]);
		const told = (await mailsFor(request, orderId)).filter((mail) =>
			mail.subject.includes('was cancelled'),
		);
		expect(told).toHaveLength(1);
		expect(told[0]?.to).toContain('ada@example.com');
	} finally {
		await saveSettings(admin, DEFAULTS);
	}
});
