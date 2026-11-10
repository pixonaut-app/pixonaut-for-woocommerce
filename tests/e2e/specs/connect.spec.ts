import {
	connect,
	connectionCard,
	expect,
	restUrl,
	SETTINGS_PATH,
	TESTS_SITE_ON_NETWORK,
	test,
	WEBHOOK_ROUTE,
} from '../fixtures';

type Endpoint = { url: string; active: boolean };

/** The fake's webhook endpoint for the tests site. */
async function endpointOf(
	fake: { control: import('@playwright/test').APIRequestContext },
	baseURL: string | undefined,
): Promise<Endpoint | undefined> {
	const { endpoints } = (await (
		await fake.control.get('/__fake/webhooks/deliveries')
	).json()) as { endpoints: Endpoint[] };
	return endpoints.find((e) => e.url.startsWith(baseURL ?? ''));
}

// The settings screens look the same under every theme.
test.skip(
	({ theme }) => theme !== 'storefront',
	'wp-admin does not depend on the theme',
);

test('connects through the studio and shows the connection card', async ({
	connectedStore,
	fake,
	baseURL,
}) => {
	const card = connectionCard(connectedStore);

	await expect(card.locator('.pixonaut-connection-result')).toContainText(
		'The store is connected to Pixonaut.',
	);
	await expect(card.locator('.pixonaut-connection-workspace')).toHaveText(
		'Fixture Prints',
	);
	await expect(card.locator('.pixonaut-connection-environment')).toHaveText(
		'Live',
	);
	await expect(card.locator('.pixonaut-connection-site')).toHaveText(
		baseURL ?? '',
	);
	await expect(card.locator('.pixonaut-connection-name')).toContainText(
		'WooCommerce · ',
	);
	const operations = (await fake.requests()).map(
		(r) => `${r.operation} ${r.status}`,
	);
	expect(operations).toEqual(
		expect.arrayContaining([
			'POST /v1/connect/token 201',
			'GET /v1/connect 200',
		]),
	);
});

test('shows a sandbox connection as Sandbox', async ({ admin, fake }) => {
	await fake.control.post('/__fake/consent', {
		data: { environment: 'sandbox' },
	});

	await connect(admin);

	const card = connectionCard(admin);
	await expect(card).toHaveAttribute('data-state', 'connected');
	await expect(card.locator('.pixonaut-connection-environment')).toHaveText(
		'Sandbox',
	);
	// Connected, the card offers to disconnect, not to connect.
	await expect(card.getByRole('link', { name: 'Disconnect' })).toBeVisible();
	await expect(
		card.getByRole('link', { name: 'Open the Pixonaut studio' }),
	).toBeVisible();
	await expect(card.locator('.pixonaut-connect')).toHaveCount(0);
	await expect(card).not.toContainText('Connect a staging or test site');
});

test('a sandbox store Pixonaut cannot reach is told how to change the address', async ({
	admin,
	fake,
}) => {
	await fake.control.post('/__fake/consent', {
		data: { environment: 'sandbox' },
	});
	await connect(admin);
	const connected = connectionCard(admin);
	await expect(connected.locator('.pixonaut-webhook-unreachable')).toHaveCount(
		0,
	);
	const { endpoints } = (await (
		await fake.control.get('/__fake/webhooks/deliveries')
	).json()) as { endpoints: { storeId: string; url: string }[] };
	const endpoint = endpoints.find((e) => e.url.includes(WEBHOOK_ROUTE));
	await fake.control.post('/__fake/webhooks/endpoint', {
		data: { store: endpoint?.storeId, reachable: false },
	});

	await admin.reload();

	const card = connectionCard(admin);
	await expect(card.locator('.pixonaut-webhook-state')).toHaveAttribute(
		'data-state',
		'unreachable',
	);
	const notice = card.locator('.pixonaut-webhook-unreachable');
	await expect(notice).toContainText('under Webhooks in the Pixonaut studio');
	await expect(notice.locator('code')).toContainText(WEBHOOK_ROUTE);
	await expect(notice.getByRole('link')).toHaveAttribute(
		'href',
		/#develop-locally-with-a-tunnel$/,
	);
});

test('a connect cancelled in the studio leaves the store disconnected', async ({
	admin,
	fake,
}) => {
	await fake.control.post('/__fake/consent', { data: { decision: 'deny' } });

	await connect(admin);

	const card = connectionCard(admin);
	await expect(card).toHaveAttribute('data-state', 'disconnected');
	await expect(card).toContainText('The connect was cancelled in Pixonaut.');
});

test('disconnect revokes the keys in Pixonaut', async ({
	connectedStore,
	fake,
}) => {
	await connectedStore.getByRole('link', { name: 'Disconnect' }).click();

	const card = connectionCard(connectedStore);
	await expect(card).toHaveAttribute('data-state', 'disconnected');
	await expect(card).toContainText('The store is disconnected from Pixonaut');
	expect(
		(await fake.requests()).map((r) => `${r.operation} ${r.status}`),
	).toContain('DELETE /v1/connect 204');
});

test('a revoked key pauses the connection', async ({
	connectedStore,
	fake,
}) => {
	await fake.control.post('/__fake/faults', {
		data: {
			operation: 'GET /v1/connect',
			fault: { kind: 'status', status: 401, error: 'unauthorized' },
		},
	});

	await connectedStore.goto(SETTINGS_PATH);

	const card = connectionCard(connectedStore);
	await expect(card).toHaveAttribute('data-state', 'paused');
	await expect(
		card.getByRole('button', { name: 'Connect again' }),
	).toBeVisible();
	// Admin notices print before the card's call paused it: the next screen
	// carries it, and every one after it until the store connects again.
	await connectedStore.goto('/wp-admin/');
	await expect(
		connectedStore.locator('.pixonaut-notice').first(),
	).toContainText('Pixonaut no longer accepts this store');
});

test('connects on plain permalinks, with the webhook under ?rest_route=', async ({
	admin,
	context,
	fake,
	baseURL,
}) => {
	await context.clearCookies({ name: 'pixonaut_e2e_permalinks' });

	await connect(admin);

	await expect(connectionCard(admin)).toHaveAttribute(
		'data-state',
		'connected',
	);
	expect((await endpointOf(fake, baseURL))?.url).toBe(
		`${baseURL}/index.php?rest_route=${WEBHOOK_ROUTE}`,
	);
});

test('offers only the sandbox when the admin picks it', async ({ admin }) => {
	// The fake's member would pick Live when offered both.
	await connect(admin, 'Sandbox');

	const card = connectionCard(admin);
	await expect(card).toHaveAttribute('data-state', 'connected');
	await expect(card.locator('.pixonaut-connection-environment')).toHaveText(
		'Sandbox',
	);
	await expect(card).toContainText('never reach a printshop');
});

test("allows the site's www. counterpart on connecting, takes it away and allows it again", async ({
	connectedStore,
	fake,
	baseURL,
}) => {
	const www = new URL(baseURL ?? '');
	www.hostname = `www.${www.hostname}`;
	const row = connectionCard(connectedStore).locator(
		'.pixonaut-connection-www',
	);
	await expect(row).toContainText(www.origin);
	await expect(row.locator('.pixonaut-www-allowed')).toBeVisible();

	await row.getByRole('link', { name: 'Take away' }).click();

	await expect(connectionCard(connectedStore)).toContainText(
		"load on the site's own address only",
	);
	await row.getByRole('link', { name: 'Allow' }).click();

	await expect(connectionCard(connectedStore)).toContainText(
		'now also load on the www. address',
	);
	await expect(row.locator('.pixonaut-www-allowed')).toBeVisible();
	expect(
		(await fake.requests())
			.map((r) => `${r.operation} ${r.status}`)
			.filter((r) => r.startsWith('PATCH /v1/connect')),
	).toEqual([
		'PATCH /v1/connect 200',
		'PATCH /v1/connect 200',
		'PATCH /v1/connect 200',
	]);
});

test("chooses the store's brand, and shows one chosen in the studio", async ({
	connectedStore,
	fake,
}) => {
	await fake.control.post('/__fake/workspace', {
		data: {
			brands: [{ id: 'br_pets', name: 'Fixture Pets', accentColor: '#2e7d32' }],
		},
	});
	await connectedStore.reload();
	const brand = connectionCard(connectedStore).getByLabel('Brand', {
		exact: true,
	});
	await expect(brand).toHaveValue('');
	await expect(brand.locator('option')).toHaveText([
		'Fixture Prints (workspace default)',
		'Fixture Pets',
	]);

	await brand.selectOption({ label: 'Fixture Pets' });
	await connectionCard(connectedStore)
		.getByRole('button', { name: 'Save brand' })
		.click();

	await expect(
		connectionCard(connectedStore).locator('.pixonaut-connection-result'),
	).toContainText("The store's brand is saved.");
	await expect(brand).toHaveValue('br_pets');
	await expect(
		connectionCard(connectedStore).locator('.pixonaut-workspace-accent'),
	).toContainText('#2e7d32');

	// A member puts the store back on the default in the studio.
	await fake.control.post('/__fake/workspace', { data: { brandId: null } });
	await connectedStore.reload();
	await expect(brand).toHaveValue('');
});

test("chooses the store's print file form and tolerance, which survive a reload", async ({
	connectedStore,
}) => {
	const card = () => connectionCard(connectedStore);
	const form = card().getByLabel('Print files', { exact: true });
	const tolerance = card().getByLabel('Live text tolerance');
	const unit = card().getByLabel('Unit', { exact: true });
	const save = async () => {
		await card().getByRole('button', { name: 'Save print files' }).click();
		await expect(card().locator('.pixonaut-connection-result')).toContainText(
			"The store's print file settings are saved.",
		);
		await connectedStore.reload();
	};

	// Following the workspace shows what it sets.
	await expect(form).toHaveValue('');
	await expect(form.locator('option').first()).toHaveText(
		'Follow the workspace (Flat)',
	);
	await expect(card().locator('.pixonaut-prints-with')).toContainText(
		'Print files are made as: Flat.',
	);
	await expect(tolerance).toBeHidden();

	for (const choice of ['flat', 'layered']) {
		await form.selectOption(choice);
		await save();
		await expect(form).toHaveValue(choice);
		await expect(tolerance).toBeHidden();
	}

	await form.selectOption('layered_live_text');
	await expect(tolerance).toBeVisible();
	await tolerance.fill('2.5');
	await save();
	await expect(form).toHaveValue('layered_live_text');
	await expect(tolerance).toHaveValue('2.5');
	await expect(unit).toHaveValue('px');
	await expect(card().locator('.pixonaut-prints-with')).toContainText(
		'Layers with live text, within 2.5 px at text edges.',
	);

	await tolerance.fill('0.25');
	await unit.selectOption('mm');
	await save();
	await expect(tolerance).toHaveValue('0.25');
	await expect(unit).toHaveValue('mm');

	// Out of its unit's limits, nothing is sent.
	await tolerance.fill('6');
	await card().getByRole('button', { name: 'Save print files' }).click();
	await expect(card().locator('.pixonaut-connection-result')).toContainText(
		'The live text tolerance is 1 to 100 px, or 0.01 to 5 mm',
	);
	await expect(tolerance).toHaveValue('0.25');

	await form.selectOption('');
	await save();
	await expect(form).toHaveValue('');
	await expect(card().locator('.pixonaut-prints-with')).toContainText(
		'Print files are made as: Flat.',
	);
});

test('narrows and tests the webhook endpoint after connecting, and tests it again on demand', async ({
	connectedStore,
	fake,
}) => {
	const card = connectionCard(connectedStore);
	const operations = (await fake.requests()).map(
		(r) => `${r.operation} ${r.status}`,
	);
	expect(operations).toEqual(
		expect.arrayContaining([
			'PATCH /v1/webhooks/{id} 200',
			'POST /v1/webhooks/{id}/ping 200',
			'GET /v1/webhooks 200',
		]),
	);
	await expect(card.locator('.pixonaut-webhook-state')).toHaveAttribute(
		'data-state',
		'active',
	);
	// The fake reaches the tests site only by its network name, not at the
	// address it was registered under.
	await expect(card.locator('.pixonaut-webhook-ping')).toHaveAttribute(
		'data-delivered',
		'false',
	);
	const endpoint = await fake.control.post('/__fake/webhooks/endpoint', {
		data: { url: `${TESTS_SITE_ON_NETWORK}${restUrl(WEBHOOK_ROUTE)}` },
	});
	expect(endpoint.ok()).toBe(true);

	await card.getByRole('link', { name: 'Send test request' }).click();

	await expect(
		connectionCard(connectedStore).locator('.pixonaut-webhook-ping'),
	).toHaveAttribute('data-delivered', 'true');
});
