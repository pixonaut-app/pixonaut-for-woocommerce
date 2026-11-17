// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import {
	COST_SELECTOR,
	type Cost,
	costUrl,
	initVariationPictures,
	updateCost,
} from './variation-pictures';

const ROUTE = 'http://localhost/wp-json/pixonaut/v1/variation-pictures/cost';

const NONCE = 'nonce-1';

const CONFIRM = 'pixonaut_variation_pictures_confirm';

const PICTURES = 480;

const BYTES = 310 * 1024 * 1024;

const PERCENT = 23;

const FORBIDDEN = 403;

/** The product tab's select, as its template renders it. */
function select(saved = 'default'): HTMLSelectElement {
	document.body.innerHTML = `
		<div class="pixonaut-variation-pictures">
			<select data-pixonaut-cost-url="${ROUTE}" data-pixonaut-cost-nonce="${NONCE}"
				data-pixonaut-cost-product="7" data-pixonaut-cost-saved="${saved}"
				data-pixonaut-confirm-name="${CONFIRM}" data-pixonaut-confirm-label="Turn it on anyway"
				data-pixonaut-cost-error="Could not read it.">
				<option value="default">Default</option><option value="on">On</option><option value="off">Off</option>
			</select>
			<div class="pixonaut-variation-pictures__cost" hidden></div>
		</div>`;
	const element = document.querySelector('select');
	if (!element) {
		throw new Error('no select');
	}
	return element;
}

/** The settings page's checkbox, in its table row. */
function checkbox(): HTMLInputElement {
	document.body.innerHTML = `
		<table><tr><td>
			<input type="checkbox" data-pixonaut-cost-url="${ROUTE}" data-pixonaut-cost-nonce="${NONCE}"
				data-pixonaut-cost-saved="off" data-pixonaut-confirm-name="${CONFIRM}"
				data-pixonaut-confirm-label="Turn it on anyway" data-pixonaut-cost-error="Could not read it.">
		</td></tr></table>`;
	const element = document.querySelector('input');
	if (!element) {
		throw new Error('no checkbox');
	}
	return element;
}

function cost(warning: string | null): Cost {
	return {
		on: true,
		pictures: PICTURES,
		bytes: BYTES,
		free: null,
		percent: warning === null ? null : PERCENT,
		warn: warning !== null,
		summary: 'About 480 new pictures, about 310 MB.',
		warning,
	};
}

function answering(answer: Cost | number): typeof fetch {
	return vi.fn(async () =>
		typeof answer === 'number'
			? new Response('', { status: answer })
			: new Response(JSON.stringify(answer), { status: 200 }),
	) as unknown as typeof fetch;
}

function box(): HTMLElement {
	const element = document.querySelector<HTMLElement>(COST_SELECTOR);
	if (!element) {
		throw new Error('no cost box');
	}
	return element;
}

describe('variation pictures cost', () => {
	it('asks for a product choice, or the store-wide setting', () => {
		const product = select();
		product.value = 'on';
		expect(costUrl(product)).toBe(`${ROUTE}?product=7&choice=on`);

		const store = checkbox();
		store.checked = true;
		expect(costUrl(store)).toBe(`${ROUTE}?on=1`);
	});

	it('shows the estimate without a box to tick below the warning', async () => {
		const control = select();
		control.value = 'on';
		const fetcher = answering(cost(null));

		await updateCost(control, fetcher);

		expect(fetcher).toHaveBeenCalledWith(`${ROUTE}?product=7&choice=on`, {
			headers: { 'X-WP-Nonce': NONCE },
		});
		expect(box().hidden).toBe(false);
		expect(box().textContent).toContain('About 480 new pictures');
		expect(box().querySelector(`[name="${CONFIRM}"]`)).toBeNull();
	});

	it('shows the warning and a required box to tick past it', async () => {
		const control = select();
		control.value = 'on';

		await updateCost(
			control,
			answering(cost('This will use about 23% of your shop.')),
		);

		expect(box().classList.contains('is-warning')).toBe(true);
		expect(box().textContent).toContain('about 23%');
		const confirm = box().querySelector<HTMLInputElement>(
			`[name="${CONFIRM}"]`,
		);
		expect(confirm?.required).toBe(true);
		expect(confirm?.value).toBe('1');
	});

	it('makes its box after the settings checkbox', async () => {
		const control = checkbox();
		control.checked = true;

		await updateCost(control, answering(cost('Uses 23%.')));

		expect(
			control.closest('td')?.querySelector(COST_SELECTOR)?.textContent,
		).toContain('Uses 23%.');
	});

	it('hides the box back at the saved value', async () => {
		const control = select('on');
		control.value = 'off';
		await updateCost(control, answering(cost(null)));
		expect(box().hidden).toBe(false);

		control.value = 'on';
		await updateCost(control, answering(cost(null)));

		expect(box().hidden).toBe(true);
		expect(box().textContent).toBe('');
	});

	it('says so when the cost cannot be read', async () => {
		const control = select();
		control.value = 'on';

		await updateCost(control, answering(FORBIDDEN));

		expect(box().textContent).toBe('Could not read it.');
	});

	it('drops an answer to a choice changed meanwhile', async () => {
		const control = select();
		let answerFirst: (response: Response) => void = () => {};
		const first = new Promise<Response>((resolve) => {
			answerFirst = resolve;
		});
		control.value = 'on';
		const late = updateCost(
			control,
			vi.fn(() => first) as unknown as typeof fetch,
		);
		control.value = 'default';
		await updateCost(control, answering(cost(null)));

		answerFirst(new Response(JSON.stringify(cost('Uses 23%.'))));
		await late;

		expect(box().hidden).toBe(true);
		expect(box().querySelector(`[name="${CONFIRM}"]`)).toBeNull();
	});

	it('asks what turning product pictures on draws', async () => {
		const control = checkbox();
		const pictures = document.createElement('input');
		pictures.type = 'checkbox';
		pictures.id = 'pixonaut_wc_settings[product_pictures]';
		pictures.checked = true;
		document.body.append(pictures);
		control.dataset.pixonautCostPictures = pictures.id;
		control.dataset.pixonautCostPicturesSaved = 'off';
		const fetcher = answering(cost('Uses 23%.'));

		await updateCost(control, fetcher);

		expect(fetcher).toHaveBeenCalledWith(`${ROUTE}?on=0&pictures=1`, {
			headers: { 'X-WP-Nonce': NONCE },
		});
		expect(box().textContent).toContain('Uses 23%.');
	});

	it('asks again whenever the control changes', async () => {
		const control = select();
		const fetcher = vi.fn(async () => new Response(JSON.stringify(cost(null))));
		vi.stubGlobal('fetch', fetcher);
		initVariationPictures(document);

		control.value = 'on';
		control.dispatchEvent(new Event('change'));
		await vi.waitFor(() => expect(box().hidden).toBe(false));

		expect(fetcher).toHaveBeenCalledTimes(1);
		vi.unstubAllGlobals();
	});
});
