// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, initPricing } from './pricing';

const URL = 'http://localhost/wp-json/pixonaut/v1/products/7/prices';

const NONCE = 'nonce-1';

function section({
	price = '$100.00',
	standing = 'own',
	simple = false,
	unread = false,
	open = true,
	note = '',
} = {}): string {
	return `
		<details class="pixonaut-prices" data-url="${URL}" data-failed-text="Could not read"
			${simple ? 'data-simple' : ''} ${unread ? 'data-unread' : ''} ${open ? 'open' : ''}>
			<summary>Prices</summary>
			<input class="pixonaut-prices__input" data-part="margin" value="50" />
			<input class="pixonaut-prices__input" data-part="step" value="" />
			<button type="button" class="pixonaut-prices__apply" data-action="apply">Apply</button>
			<button type="button" class="pixonaut-prices__refresh" data-action="refresh">Refresh</button>
			<span class="spinner pixonaut-prices__spinner"></span>
			<span class="pixonaut-prices__status"></span>
			<table><tbody>
				<tr class="pixonaut-prices__row" data-item="7" data-standing="${standing}">
					<td class="pixonaut-prices__price">${price}</td>
					<td>${note}
						<button type="button" data-action="apply" data-item="7">Apply one</button>
						<button type="button" data-action="follow" data-item="7">Follow</button>
					</td>
				</tr>
			</tbody></table>
		</details>`;
}

function mount(html: string): HTMLElement {
	document.body.innerHTML = `<div class="pixonaut-product-panel">${html}</div><input id="_regular_price" value="100" />`;
	const panel = document.querySelector<HTMLElement>('.pixonaut-product-panel');
	if (!panel) {
		throw new Error('no panel');
	}
	return panel;
}

function root(): HTMLElement {
	const element = document.querySelector<HTMLElement>('.pixonaut-prices');
	if (!element) {
		throw new Error('no section');
	}
	return element;
}

function answering(body: unknown, status = 200) {
	const fetch = vi.fn<typeof globalThis.fetch>(
		async () => new Response(JSON.stringify(body), { status }),
	);
	vi.stubGlobal('fetch', fetch);
	return fetch;
}

function sent(fetch: ReturnType<typeof answering>, call = 0): unknown {
	return JSON.parse(String(fetch.mock.calls[call]?.[1]?.body));
}

async function settled(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
	vi.unstubAllGlobals();
	document.body.innerHTML = '';
	delete (window as { jQuery?: unknown }).jQuery;
});

describe('the Prices section', () => {
	it('applies the rule as typed and draws the section the route answers', async () => {
		mount(section());
		const fetch = answering({
			html: section({ price: '$249.00', standing: 'follows', open: false }),
			message: '1 price set.',
			changed: 1,
			prices: { 7: '249' },
		});

		await act(root(), 'apply', { nonce: NONCE });

		expect(fetch).toHaveBeenCalledWith(
			URL,
			expect.objectContaining({
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			}),
		);
		expect(sent(fetch)).toEqual({
			action: 'apply',
			rule: { margin: '50', step: '' },
		});
		expect(root().querySelector('.pixonaut-prices__price')?.textContent).toBe(
			'$249.00',
		);
		expect(root().dataset.standing).toBeUndefined();
		expect(root().hasAttribute('open')).toBe(true);
		expect(root().querySelector('.pixonaut-prices__status')?.textContent).toBe(
			'1 price set.',
		);
	});

	it("puts a simple product's new price in the product form", async () => {
		mount(section({ simple: true }));
		answering({
			html: section({ simple: true, price: '$249.00' }),
			message: '1 price set.',
			changed: 1,
			prices: { 7: '249' },
		});

		await act(root(), 'apply', { nonce: NONCE });

		expect(
			document.querySelector<HTMLInputElement>('#_regular_price')?.value,
		).toBe('249');
	});

	it('leaves a typed price and typed rule fields alone when it only reads costs', async () => {
		mount(section({ simple: true }));
		const price = document.querySelector<HTMLInputElement>('#_regular_price');
		const margin = root().querySelector<HTMLInputElement>(
			'[data-part="margin"]',
		);
		if (!price || !margin) {
			throw new Error('no fields');
		}
		price.value = '180';
		margin.value = '62';
		answering({
			html: section({ simple: true }),
			message: 'The costs are read.',
			changed: 0,
			prices: { 7: '100' },
		});

		await act(root(), 'refresh', { nonce: NONCE });

		expect(price.value).toBe('180');
		expect(
			root().querySelector<HTMLInputElement>('[data-part="margin"]')?.value,
		).toBe('62');
	});

	it('sends the choice for automatic price changes with apply, and keeps one not stored yet when it only reads costs', async () => {
		const withChoice = (chosen: string) =>
			section().replace(
				'<button',
				`<select class="pixonaut-prices__changes">${['store', 'always', 'never']
					.map(
						(value) =>
							`<option value="${value}" ${value === chosen ? 'selected' : ''}>${value}</option>`,
					)
					.join('')}</select><button`,
			);
		const choice = () =>
			root().querySelector<HTMLSelectElement>('.pixonaut-prices__changes');
		mount(withChoice('store'));
		const select = choice();
		if (!select) {
			throw new Error('no choice');
		}
		select.value = 'never';
		const fetch = answering({
			html: withChoice('store'),
			message: 'The costs are read.',
			changed: 0,
			prices: { 7: '100' },
		});

		await act(root(), 'refresh', { nonce: NONCE });

		expect(sent(fetch)).toEqual({ action: 'refresh' });
		expect(choice()?.value).toBe('never');

		await act(root(), 'apply', { nonce: NONCE });

		expect(sent(fetch, 1)).toEqual({
			action: 'apply',
			rule: { margin: '50', step: '' },
			changes: 'never',
		});
	});

	it("loads a variable product's variations again once a price changed", async () => {
		mount(section());
		const trigger = vi.fn();
		const jQuery = vi.fn(() => ({ trigger }));
		(window as { jQuery?: unknown }).jQuery = jQuery;
		answering({
			html: section({ price: '$249.00' }),
			message: '1 price set.',
			changed: 1,
			prices: { 7: '249' },
		});

		await act(root(), 'apply', { nonce: NONCE });

		expect(jQuery).toHaveBeenCalledWith('#variable_product_options');
		expect(trigger).toHaveBeenCalledWith('reload');
		expect(
			document.querySelector<HTMLInputElement>('#_regular_price')?.value,
		).toBe('100');
	});

	it('names the one price a row button acts on, and sends the rule only to apply', async () => {
		const panel = mount(section());
		initPricing(panel, { nonce: NONCE });
		const fetch = answering({
			html: section(),
			message: '',
			changed: 0,
			prices: {},
		});

		root()
			.querySelector<HTMLButtonElement>('button[data-action="follow"]')
			?.click();
		await settled();
		// The section was drawn again: its buttons still work.
		root()
			.querySelector<HTMLButtonElement>('button[data-action="refresh"]')
			?.click();
		await settled();

		expect(sent(fetch, 0)).toEqual({ action: 'follow', ids: [7] });
		expect(sent(fetch, 1)).toEqual({ action: 'refresh' });
	});

	it('says what the route refused, and keeps the section', async () => {
		mount(section());
		answering({ message: 'Pixonaut could not be reached.' }, 502);

		await act(root(), 'refresh', { nonce: NONCE });

		const status = root().querySelector('.pixonaut-prices__status');
		expect(status?.textContent).toBe('Pixonaut could not be reached.');
		expect(status?.classList.contains('is-error')).toBe(true);
		expect(
			root().querySelector<HTMLButtonElement>('.pixonaut-prices__apply')
				?.disabled,
		).toBe(false);
	});

	it('says so when the route cannot be asked', async () => {
		mount(section());
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => {
				throw new Error('offline');
			}),
		);

		await act(root(), 'refresh', { nonce: NONCE });

		expect(root().querySelector('.pixonaut-prices__status')?.textContent).toBe(
			'Could not read',
		);
	});

	it('reads the costs of an open section that has none yet, once', async () => {
		const panel = mount(section({ unread: true }));
		const fetch = answering({
			html: section({ note: 'read' }),
			message: 'The costs are read.',
			changed: 0,
			prices: {},
		});

		initPricing(panel, { nonce: NONCE });
		await settled();

		expect(fetch).toHaveBeenCalledTimes(1);
		expect(sent(fetch)).toEqual({ action: 'refresh' });
	});

	it('waits for a closed section to open before reading its costs', async () => {
		const panel = mount(section({ unread: true, open: false }));
		const fetch = answering({
			html: section(),
			message: '',
			changed: 0,
			prices: {},
		});
		initPricing(panel, { nonce: NONCE });
		await settled();
		expect(fetch).not.toHaveBeenCalled();

		root().setAttribute('open', '');
		root().dispatchEvent(new Event('toggle'));
		await settled();

		expect(fetch).toHaveBeenCalledTimes(1);
	});
});
