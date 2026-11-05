// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PersonaliseButton } from './personalise-button';
import { VARIATION_SETTLE_MS } from './variations';

const STATES = {
	'11': 'personalised',
	'12': 'plain',
	'13': 'unavailable',
	'14': 'designed',
};

/** The button template's markup on a variable product, in its form. */
function page(shown: boolean, available: boolean): HTMLElement {
	document.body.innerHTML = `<form class="cart">
		<input type="hidden" name="variation_id" value="">
		<span class="pixonaut-personalise" data-pixonaut-variations='${JSON.stringify(STATES)}'${shown ? '' : ' hidden'}></span>
		<button type="${shown && available ? 'submit' : 'button'}" class="pixonaut-personalise__button"${shown && available ? '' : ' hidden'}>Personalise</button>
		<p class="pixonaut-personalise__unavailable"${shown && !available ? '' : ' hidden'}>Unavailable</p>
	</form>`;
	const container = document.querySelector<HTMLElement>(
		'.pixonaut-personalise',
	);
	if (container === null) {
		throw new Error('No button.');
	}
	return container;
}

/** Puts the page's button in a product with a summary price and each
 *  variation's price on the button; returns the summary price. */
function pricedSummary(container: HTMLElement): HTMLElement {
	const form = container.closest('form');
	if (form === null) {
		throw new Error('No form.');
	}
	const product = document.createElement('div');
	product.className = 'product';
	product.innerHTML =
		'<div class="summary"><p class="price">10 kr – 20 kr</p></div>';
	form.replaceWith(product);
	product.querySelector('.summary')?.append(form);
	container.dataset.pixonautPrices = JSON.stringify({
		'11': '10 kr',
		'12': '20 kr',
	});
	container.dataset.pixonautProduct = '7';
	container.dataset.pixonautSide = 'front';
	const summary = product.querySelector<HTMLElement>('.price');
	if (summary === null) {
		throw new Error('No price.');
	}
	return summary;
}

function choose(id: string): void {
	const field = document.querySelector<HTMLInputElement>(
		'[name="variation_id"]',
	);
	if (field === null) {
		throw new Error('No variation field.');
	}
	field.value = id;
	field.form?.dispatchEvent(new Event('change'));
	vi.advanceTimersByTime(VARIATION_SETTLE_MS);
}

function shown(container: HTMLElement): string {
	if (container.hidden) {
		return 'plain';
	}
	const row = container.parentElement;
	const buttons = row?.querySelector<HTMLElement>(
		'.pixonaut-personalise__button',
	);
	const unavailable = row?.querySelector<HTMLElement>(
		'.pixonaut-personalise__unavailable',
	);
	expect(buttons?.hidden).not.toBe(unavailable?.hidden);
	return buttons?.hidden ? 'unavailable' : 'personalised';
}

/** The type of the Personalise button. */
function buttonType(): string | undefined {
	return document.querySelector('button')?.type;
}

describe('PersonaliseButton', () => {
	afterEach(() => {
		vi.useRealTimers();
	});

	it('follows the chosen variation and returns to the product’s state when none is chosen', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		new PersonaliseButton(container).start();

		choose('12');
		expect(shown(container)).toBe('plain');
		choose('13');
		expect(shown(container)).toBe('unavailable');
		choose('11');
		expect(shown(container)).toBe('personalised');
		choose('0');
		expect(shown(container)).toBe('personalised');
	});

	it('gives way to add to cart and shows the waiting design only on its own variation', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		container.dataset.pixonautProduct = '7';
		const box = document.createElement('div');
		box.dataset.pixonautDesign = '7:14';
		box.hidden = true;
		const other = document.createElement('div');
		other.dataset.pixonautDesign = '8:14';
		other.hidden = true;
		document.body.append(box, other);
		new PersonaliseButton(container).start();

		choose('14');
		expect(container.hidden).toBe(true);
		expect(box.hidden).toBe(false);
		expect(other.hidden).toBe(true);

		choose('11');
		expect(shown(container)).toBe('personalised');
		expect(box.hidden).toBe(true);
	});

	it('hides the button that uses a kept design on the variation the page shows a design of', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		const retry = document.createElement('button');
		retry.className =
			'pixonaut-personalise__button pixonaut-personalise__retry';
		container.after(retry);
		const personalise = document.querySelector<HTMLButtonElement>(
			'button:not(.pixonaut-personalise__retry)',
		);
		new PersonaliseButton(container).start();

		choose('14');
		expect(container.hidden).toBe(true);
		expect(retry.hidden).toBe(true);
		expect(retry.type).toBe('button');
		expect(personalise?.hidden).toBe(true);
		expect(personalise?.type).toBe('button');

		choose('11');
		expect(retry.hidden).toBe(false);
		expect(retry.type).toBe('submit');
	});

	it('goes back to Personalise when a page that opened on a designed variation loses its choice', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		container.hidden = true;
		container.dataset.pixonautOpensDesigned = '';
		new PersonaliseButton(container).start();

		choose('14');
		expect(container.hidden).toBe(true);
		choose('0');
		expect(shown(container)).toBe('personalised');
	});

	it('takes WooCommerce’s marks off before its variation script alerts on a press', () => {
		const container = page(true, true);
		new PersonaliseButton(container).start();
		const button = document.querySelector('button');
		button?.classList.add('disabled', 'wc-variation-selection-needed');

		button?.dispatchEvent(new Event('click'));

		expect(button?.classList.contains('disabled')).toBe(false);
		expect(button?.classList.contains('wc-variation-selection-needed')).toBe(
			false,
		);
	});

	it('stays hidden until a personalised variation is chosen on a product that is not personalised', () => {
		vi.useFakeTimers();
		const container = page(false, false);
		new PersonaliseButton(container).start();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		expect(shown(container)).toBe('plain');

		choose('11');
		expect(shown(container)).toBe('personalised');
		choose('');
		expect(shown(container)).toBe('plain');
	});

	it('follows a variation the page preselected', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		const field = document.querySelector<HTMLInputElement>(
			'[name="variation_id"]',
		);
		if (field !== null) {
			field.value = '12';
		}
		new PersonaliseButton(container).start();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		expect(shown(container)).toBe('plain');
	});

	it('shows the chosen variation’s price in place of the range, and the range again when none is chosen', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		const summary = pricedSummary(container);
		new PersonaliseButton(container).start();

		choose('11');
		expect(summary.innerHTML).toBe('10 kr');
		choose('12');
		expect(summary.innerHTML).toBe('20 kr');
		choose('');
		expect(summary.innerHTML).toBe('10 kr – 20 kr');
	});

	it('shows the product’s own price once the form settles on none after the page printed an opening variation’s', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		const summary = pricedSummary(container);
		summary.innerHTML = '20 kr';
		container.dataset.pixonautProductPrice = '10 kr – 20 kr';
		new PersonaliseButton(container).start();

		vi.advanceTimersByTime(VARIATION_SETTLE_MS);
		expect(summary.innerHTML).toBe('10 kr – 20 kr');
	});

	it('leaves the price alone on a template without the prices', () => {
		vi.useFakeTimers();
		const container = page(true, true);
		const summary = pricedSummary(container);
		delete container.dataset.pixonautPrices;
		new PersonaliseButton(container).start();

		choose('11');
		expect(summary.innerHTML).toBe('10 kr – 20 kr');
	});

	it('submits the form only while the button is shown', () => {
		vi.useFakeTimers();
		const container = page(false, false);
		new PersonaliseButton(container).start();
		expect(buttonType()).toBe('button');

		choose('11');
		expect(buttonType()).toBe('submit');
		choose('12');
		expect(buttonType()).toBe('button');
		choose('13');
		expect(buttonType()).toBe('button');
		choose('11');
		expect(buttonType()).toBe('submit');
	});
});
