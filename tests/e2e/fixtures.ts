import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
	type APIRequestContext,
	type BrowserContext,
	test as base,
	expect,
	type Page,
} from '@playwright/test';

/** Read by the e2e support mu-plugin: the theme for this browser context. */
const THEME_COOKIE = 'pixonaut_e2e_theme';

/** Read by tests/compat/switch.php: the theme and page builder for this
 *  browser context. */
const COMBINATION_COOKIE = 'pixonaut_compat';

/** Read by the e2e support mu-plugin: the block theme's single product
 *  blocks for this browser context. */
const BLOCKS_COOKIE = 'pixonaut_e2e_blocks';

/** Read by the e2e orders mu-plugin: the checkout for this browser context. */
const CHECKOUT_COOKIE = 'pixonaut_e2e_checkout';

/** Read by the e2e support mu-plugin: pretty permalinks for this browser
 *  context, which connecting the store needs. */
const PERMALINKS_COOKIE = 'pixonaut_e2e_permalinks';

/** The tests site as the fake reaches it: by its service name on wp-env's
 *  Docker network. An extra e2e site (bin/e2e-sites.mjs) says its own. */
export const TESTS_SITE_ON_NETWORK =
	process.env.PIXONAUT_E2E_SITE_ON_NETWORK ?? 'http://tests-wordpress';

/** The plugin's webhook receiver. */
export const WEBHOOK_ROUTE = '/pixonaut/v1/webhook';

/**
 * Sent by every API request context: a new connection per request. Apache on
 * the tests site and the fake both drop an idle keep-alive connection after 5
 * seconds, and a request that reuses one as it closes fails with ECONNRESET.
 */
const NO_KEEP_ALIVE = { connection: 'close' };

/** wp-env's administrator. */
const ADMIN = { username: 'admin', password: 'password' };

/** A tab of the Pixonaut settings page. */
export function settingsPath(
	tab: 'connection' | 'storefront' | 'approval' | 'pixel' | 'updates',
): string {
	return `/wp-admin/admin.php?page=pixonaut&tab=${tab}`;
}

/** The settings page's Connection tab, with the connection card. */
export const SETTINGS_PATH = settingsPath('connection');

/** The pinned SDK release, as `sdk.lock.json` records it. */
export const SDK_LOCK: {
	version: string;
	baseUrl: string;
	script: string;
	integrity: string;
} = JSON.parse(
	readFileSync(
		join(__dirname, '..', '..', 'contracts', 'pixonaut', 'sdk.lock.json'),
		'utf8',
	),
);

/** The pinned SDK path on Pixonaut's CDN: the only real host tests may load. */
const SDK_BASE_URL = SDK_LOCK.baseUrl;

/** The pinned SDK script and its SRI, for a page that loads the SDK. */
export const SDK_SCRIPT = {
	url: `${SDK_LOCK.baseUrl}${SDK_LOCK.script}`,
	integrity: SDK_LOCK.integrity,
};

/** The fake Pixonaut's control API, for a test that drives it. */
export type FakePixonaut = {
	/** Its public base, as the browser reaches it. */
	baseURL: string;
	control: APIRequestContext;
	requests(): Promise<
		{ method: string; path: string; operation: string | null; status: number }[]
	>;
};

export type E2eOptions = {
	/** Theme slug the project runs against. */
	theme: string;
	/** On a block theme: the single product template's add-to-cart and
	 *  gallery blocks. `primary` is the theme's own Add to Cart Form and
	 *  Product Image Gallery; `secondary` swaps in Add to Cart with Options
	 *  and Product Gallery. */
	blocks: 'primary' | 'secondary';
	/** The checkout a customer pays at: the shortcode one or the store's
	 *  block checkout. */
	checkout: 'classic' | 'block';
	/** The fake Pixonaut's public base. */
	fakeBaseURL: string;
	/** A compatibility project's combination in tests/compat/combinations.json. */
	combination: string;
	/** That combination's page builder, or none. */
	builder: string;
};

export type Fixtures = {
	fake: FakePixonaut;
	/** A page signed in as the administrator, on a store that starts and
	 *  ends disconnected. */
	admin: Page;
	/** The administrator's page on the settings tab of a store connected
	 *  through the fake studio. */
	connectedStore: Page;
	/** A guest's page in a browser context of its own, set up as the
	 *  test's own context is. */
	customer: Page;
};

/** wp-login.php refuses a sign-in without the cookie its page sets. */
const LOGIN_TEST_COOKIE = {
	name: 'wordpress_test_cookie',
	value: 'WP Cookie check',
};

/** Signs in, as wp-env's administrator unless another user is given, by
 *  posting the login form from the page's own request context, so its
 *  cookies land in the browser. Typing into the form races the login page's
 *  own focus script. */
export async function signIn(
	page: Page,
	baseURL: string,
	user: { username: string; password: string } = ADMIN,
): Promise<void> {
	await page.context().addCookies([{ ...LOGIN_TEST_COOKIE, url: baseURL }]);
	const response = await page.request.post('/wp-login.php', {
		form: {
			log: user.username,
			pwd: user.password,
			testcookie: '1',
			redirect_to: `${baseURL}/wp-admin/`,
		},
		maxRedirects: 0,
	});
	expect(response.status(), `wp-login.php signs ${user.username} in`).toBe(302);
}

/** Forgets the tests site's connection. */
async function forgetConnection(request: APIRequestContext): Promise<void> {
	const response = await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
	expect(response.ok(), 'the tests site forgets its connection').toBe(true);
}

/** The connection card. */
export function connectionCard(page: Page) {
	return page.locator('.pixonaut-connection');
}

/** Back to plain permalinks once connected: the tests site has no
 *  .htaccess, so `/wp-json/` and product pages 404 under pretty ones. */
export async function usePlainPermalinks(
	page: Page,
	baseURL: string | undefined,
): Promise<void> {
	await page
		.context()
		.addCookies([{ name: PERMALINKS_COOKIE, value: 'plain', url: baseURL }]);
}

/** Connects the store from the settings page through the fake studio, which
 *  answers as its consent is set, offering only `environment` when given. */
export async function connect(
	page: Page,
	environment?: 'Live workspace' | 'Sandbox',
): Promise<void> {
	await page.goto(SETTINGS_PATH);
	await page
		.getByRole('button', { name: environment ?? 'Connect to Pixonaut' })
		.click();
	await page.waitForURL(/[?&]pixonaut_connect=/);
}

/** Lets a browser context reach only the tests site, the fake and the pinned
 *  SDK, and gives it the project's theme, blocks and checkout. */
async function prepareContext(
	context: BrowserContext,
	options: E2eOptions & { baseURL: string },
): Promise<void> {
	const { theme, blocks, checkout, baseURL, fakeBaseURL, combination } =
		options;
	const siteOrigin = new URL(baseURL).origin;
	const fakeOrigin = new URL(fakeBaseURL).origin;
	await context.route('**/*', (route) => {
		const url = route.request().url();
		const allowed =
			url.startsWith('data:') ||
			url.startsWith('blob:') ||
			new URL(url).origin === siteOrigin ||
			new URL(url).origin === fakeOrigin ||
			url.startsWith(SDK_BASE_URL);
		return allowed ? route.fallback() : route.abort('blockedbyclient');
	});
	await context.addCookies([
		{ name: THEME_COOKIE, value: theme, url: baseURL },
		{ name: BLOCKS_COOKIE, value: blocks, url: baseURL },
		{ name: CHECKOUT_COOKIE, value: checkout, url: baseURL },
		...(combination
			? [{ name: COMBINATION_COOKIE, value: combination, url: baseURL }]
			: []),
	]);
}

export const test = base.extend<E2eOptions & Fixtures>({
	theme: ['storefront', { option: true }],
	blocks: ['primary', { option: true }],
	checkout: ['classic', { option: true }],
	fakeBaseURL: ['', { option: true }],
	combination: ['', { option: true }],
	builder: ['', { option: true }],
	request: async ({ playwright, baseURL }, use) => {
		const request = await playwright.request.newContext({
			baseURL,
			extraHTTPHeaders: NO_KEEP_ALIVE,
		});
		await use(request);
		await request.dispose();
	},
	context: async (
		{
			context,
			theme,
			blocks,
			checkout,
			baseURL,
			fakeBaseURL,
			combination,
			builder,
		},
		use,
	) => {
		await prepareContext(context, {
			theme,
			blocks,
			checkout,
			baseURL: baseURL ?? '',
			fakeBaseURL,
			combination,
			builder,
		});
		await use(context);
	},
	customer: async (
		{
			browser,
			theme,
			blocks,
			checkout,
			baseURL,
			fakeBaseURL,
			combination,
			builder,
		},
		use,
	) => {
		const context = await browser.newContext({ baseURL });
		await prepareContext(context, {
			theme,
			blocks,
			checkout,
			baseURL: baseURL ?? '',
			fakeBaseURL,
			combination,
			builder,
		});
		await use(await context.newPage());
		await context.close();
	},
	// Every test starts on a freshly reset fake and fails when anything broke
	// the contract with it, even if the test itself passed.
	fake: [
		async ({ playwright, fakeBaseURL, baseURL }, use) => {
			const control = await playwright.request.newContext({
				baseURL: fakeBaseURL,
				extraHTTPHeaders: NO_KEEP_ALIVE,
			});
			const reset = await control.post('/__fake/reset', {
				data: { siteUrl: baseURL },
			});
			expect(reset.ok(), 'the fake Pixonaut resets').toBe(true);

			await use({
				baseURL: fakeBaseURL,
				control,
				requests: async () => (await control.get('/__fake/requests')).json(),
			});

			const violations = await (await control.get('/__fake/violations')).json();
			await control.dispose();
			expect(violations, 'contract violations at the fake Pixonaut').toEqual(
				[],
			);
		},
		{ auto: true },
	],
	admin: async ({ page, context, baseURL, request }, use) => {
		await forgetConnection(request);
		await context.addCookies([
			{ name: PERMALINKS_COOKIE, value: 'pretty', url: baseURL },
		]);
		await signIn(page, baseURL ?? '');
		await use(page);
		await forgetConnection(request);
	},
	connectedStore: async ({ admin }, use) => {
		await connect(admin);
		await expect(connectionCard(admin)).toHaveAttribute(
			'data-state',
			'connected',
		);
		await use(admin);
	},
});

export { expect } from '@playwright/test';

/** A REST route on the tests site, reachable without pretty permalinks. */
export function restUrl(route: string): string {
	return `/?rest_route=${encodeURIComponent(route)}`;
}
