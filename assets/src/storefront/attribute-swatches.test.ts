// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import {
	drawAttributeSwatches,
	PRINTED_CLASS,
	REPLACED_CLASS,
	removePrintedSwatches,
} from './attribute-swatches';

function classicForm(): HTMLFormElement {
	document.body.innerHTML = `
		<form class="variations_form cart">
			<table class="variations"><tbody>
				<tr><th class="label"><label for="size">Size</label></th><td class="value">
					<select id="size" name="attribute_size">
						<option value="">Choose an option</option>
						<option value="35x50">35 × 50 cm</option>
						<option value="50x70">50 × 70 cm</option>
						<option value="70x100" disabled>70 × 100 cm</option>
					</select>
				</td></tr>
			</tbody></table>
		</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

function blockForm(): HTMLFormElement {
	document.body.innerHTML = `
		<form>
			<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">
				<span class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute-name" id="size-name">Size</span>
				<div role="radiogroup" aria-labelledby="size-name">
					<button type="button" role="radio" value="S" aria-checked="false"><span>S</span></button>
					<button type="button" role="radio" value="L" aria-checked="false"><span>L</span></button>
					<button type="button" role="radio" value="XL" aria-checked="false" disabled><span>XL</span></button>
				</div>
				<input type="hidden" name="attribute_size" value="">
			</div>
		</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

function swatches(): HTMLElement[] {
	return Array.from(
		document.querySelectorAll<HTMLElement>('.pixonaut-swatches__swatch'),
	);
}

function swatch(label: string): HTMLElement {
	const found = swatches().find((one) => one.textContent === label);
	if (found === undefined) {
		throw new Error(`No swatch ${label}`);
	}
	return found;
}

/** Lets a MutationObserver's callback run. */
const observed = () => new Promise((resolve) => setTimeout(resolve));

describe('drawAttributeSwatches on the classic form', () => {
	it('draws a radio group labelled by the row, and hides the select', () => {
		const form = classicForm();

		drawAttributeSwatches(form);

		const group = form.querySelector('.pixonaut-swatches');
		expect(group?.getAttribute('role')).toBe('radiogroup');
		const label = form.querySelector('label');
		expect(group?.getAttribute('aria-labelledby')).toBe(label?.id);
		expect(label?.id).not.toBe('');
		expect(form.querySelector('select')?.classList).toContain(REPLACED_CLASS);
		expect(swatches().map((one) => one.textContent)).toEqual([
			'35 × 50 cm',
			'50 × 70 cm',
			'70 × 100 cm',
		]);
		expect(swatches().map((one) => one.getAttribute('role'))).toEqual([
			'radio',
			'radio',
			'radio',
		]);
		expect(swatch('70 × 100 cm').getAttribute('aria-disabled')).toBe('true');
	});

	it('picks in the select as the customer would, and marks the swatch chosen', () => {
		const form = classicForm();
		const select = form.querySelector('select') as HTMLSelectElement;
		const changed = vi.fn();
		select.addEventListener('change', changed);
		drawAttributeSwatches(form);

		swatch('50 × 70 cm').click();

		expect(select.value).toBe('50x70');
		expect(changed).toHaveBeenCalledTimes(1);
		expect(swatch('50 × 70 cm').getAttribute('aria-checked')).toBe('true');
		expect(swatch('50 × 70 cm').tabIndex).toBe(0);
		expect(swatch('35 × 50 cm').getAttribute('aria-checked')).toBe('false');
		expect(swatch('35 × 50 cm').tabIndex).toBe(-1);
	});

	it('picks nothing for an unavailable swatch', () => {
		const form = classicForm();
		drawAttributeSwatches(form);

		swatch('70 × 100 cm').click();

		expect((form.querySelector('select') as HTMLSelectElement).value).toBe('');
	});

	it('follows a choice made in the select, and an option it takes out', async () => {
		const form = classicForm();
		const select = form.querySelector('select') as HTMLSelectElement;
		drawAttributeSwatches(form);

		select.value = '35x50';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(swatch('35 × 50 cm').getAttribute('aria-checked')).toBe('true');

		// WooCommerce takes out an option the other choices leave no variation for.
		select.querySelector('option[value="50x70"]')?.remove();
		await observed();
		expect(swatch('50 × 70 cm').getAttribute('aria-disabled')).toBe('true');
	});

	it('moves the choice with the arrow keys, past an unavailable one', () => {
		const form = classicForm();
		const select = form.querySelector('select') as HTMLSelectElement;
		drawAttributeSwatches(form);
		const first = swatch('35 × 50 cm');
		expect(first.tabIndex).toBe(0);

		first.dispatchEvent(
			new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
		);
		expect(select.value).toBe('50x70');
		expect(document.activeElement).toBe(swatch('50 × 70 cm'));
		// Drawn again as the field changes, it keeps the focus.
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(document.activeElement).toBe(swatch('50 × 70 cm'));

		swatch('50 × 70 cm').dispatchEvent(
			new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
		);
		expect(select.value).toBe('35x50');
	});

	it('clears the attribute on a click on the chosen swatch', () => {
		const form = classicForm();
		const select = form.querySelector('select') as HTMLSelectElement;
		drawAttributeSwatches(form);

		swatch('50 × 70 cm').click();
		swatch('50 × 70 cm').click();

		expect(select.value).toBe('');
		expect(swatch('50 × 70 cm').getAttribute('aria-checked')).toBe('false');
	});

	it('takes the swatches away and shows the select again', () => {
		const form = classicForm();
		const select = form.querySelector('select') as HTMLSelectElement;
		const remove = drawAttributeSwatches(form);

		remove();

		expect(form.querySelector('.pixonaut-swatches')).toBeNull();
		expect(select.classList).not.toContain(REPLACED_CLASS);
		// Drawn again once lent again.
		drawAttributeSwatches(form);
		expect(form.querySelectorAll('.pixonaut-swatches')).toHaveLength(1);
	});

	it('takes the place of the swatches the page printed', () => {
		const form = classicForm();
		form
			.querySelector('select')
			?.insertAdjacentHTML(
				'afterend',
				`<div class="pixonaut-swatches ${PRINTED_CLASS}" aria-hidden="true" inert><span class="pixonaut-swatches__swatch">35 × 50 cm</span></div>`,
			);

		drawAttributeSwatches(form);

		expect(form.querySelector(`.${PRINTED_CLASS}`)).toBeNull();
		expect(form.querySelectorAll('.pixonaut-swatches')).toHaveLength(1);
		expect(form.querySelector('select')?.nextElementSibling?.className).toBe(
			'pixonaut-swatches',
		);
	});

	it('takes away printed swatches no live ones replaced', () => {
		const form = classicForm();
		form
			.querySelector('select')
			?.insertAdjacentHTML(
				'afterend',
				`<div class="pixonaut-swatches ${PRINTED_CLASS}" inert></div>`,
			);

		removePrintedSwatches(form);

		expect(form.querySelector('.pixonaut-swatches')).toBeNull();
	});

	it('draws one set of swatches however often it is called', () => {
		const form = classicForm();

		drawAttributeSwatches(form);
		drawAttributeSwatches(form);

		expect(form.querySelectorAll('.pixonaut-swatches')).toHaveLength(1);
	});
});

describe('drawAttributeSwatches on the block form', () => {
	it('draws the chips’ options, labelled by the attribute’s name', () => {
		const form = blockForm();

		drawAttributeSwatches(form);

		const group = form.querySelector('.pixonaut-swatches');
		expect(group?.getAttribute('aria-labelledby')).toBe('size-name');
		expect(form.querySelector('[role="radiogroup"]')?.classList).toContain(
			REPLACED_CLASS,
		);
		expect(swatches().map((one) => one.textContent)).toEqual(['S', 'L', 'XL']);
		expect(swatch('XL').getAttribute('aria-disabled')).toBe('true');
	});

	it('picks by clicking the theme’s chip, and follows its state', async () => {
		const form = blockForm();
		const chip = form.querySelector('button[value="L"]') as HTMLButtonElement;
		const clicked = vi.fn(() => chip.setAttribute('aria-checked', 'true'));
		chip.addEventListener('click', clicked);
		drawAttributeSwatches(form);

		swatch('L').click();
		await observed();

		expect(clicked).toHaveBeenCalledTimes(1);
		expect(swatch('L').getAttribute('aria-checked')).toBe('true');
	});

	it('clears the attribute by clicking the chosen chip, as the block does', async () => {
		const form = blockForm();
		const chip = form.querySelector('button[value="L"]') as HTMLButtonElement;
		chip.addEventListener('click', () =>
			chip.setAttribute(
				'aria-checked',
				String(chip.getAttribute('aria-checked') !== 'true'),
			),
		);
		drawAttributeSwatches(form);

		swatch('L').click();
		await observed();
		swatch('L').click();
		await observed();

		expect(chip.getAttribute('aria-checked')).toBe('false');
		expect(swatch('L').getAttribute('aria-checked')).toBe('false');
	});

	it('shows a chip the block hides as unavailable', async () => {
		const form = blockForm();
		drawAttributeSwatches(form);

		(form.querySelector('button[value="S"]') as HTMLButtonElement).hidden =
			true;
		await observed();

		expect(swatch('S').getAttribute('aria-disabled')).toBe('true');
	});
});
