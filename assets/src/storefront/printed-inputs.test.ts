// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import { dropUntouchedInputs, followPrintedInputs } from './printed-inputs';

const NAME = 'layer-1:content';

function printed(html: string): HTMLElement {
	document.body.innerHTML = `<form><div class="pixonaut-form__placeholder">${html}</div></form>`;
	followPrintedInputs(document);
	return document.body;
}

function field<T extends Element>(): T {
	return document.querySelector(`[data-pixonaut-field="${NAME}"]`) as T;
}

describe('followPrintedInputs', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('keeps Enter in a printed text from sending the add-to-cart form', () => {
		printed(`<input type="text" data-pixonaut-field="${NAME}">`);
		const enter = new KeyboardEvent('keydown', {
			key: 'Enter',
			bubbles: true,
			cancelable: true,
		});
		field<HTMLInputElement>().dispatchEvent(enter);
		expect(enter.defaultPrevented).toBe(true);
	});

	it('leaves Enter in a printed text area, where it starts a line', () => {
		printed(`<textarea data-pixonaut-field="${NAME}"></textarea>`);
		const enter = new KeyboardEvent('keydown', {
			key: 'Enter',
			bubbles: true,
			cancelable: true,
		});
		field<HTMLTextAreaElement>().dispatchEvent(enter);
		expect(enter.defaultPrevented).toBe(false);
	});

	it('moves a slider’s fill and thumb with its value', () => {
		printed(`<span class="track">
			<span class="pixonaut-placeholder__fill"></span>
			<span class="pixonaut-placeholder__thumb"></span>
			<input type="range" min="0" max="10" step="1" value="2" data-pixonaut-field="${NAME}">
		</span>`);
		const input = field<HTMLInputElement>();
		input.value = '5';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		expect(
			document.querySelector<HTMLElement>('.pixonaut-placeholder__fill')?.style
				.width,
		).toBe('50%');
		expect(
			document.querySelector<HTMLElement>('.pixonaut-placeholder__thumb')?.style
				.left,
		).toBe('50%');
	});

	it('shows a colour in its swatch and beside the heading', () => {
		printed(`<span data-pixonaut-type="color">
			<span class="pixonaut-placeholder__aside">#000000</span>
			<span class="pixonaut-placeholder__swatch"><input type="color" value="#000000" data-pixonaut-field="${NAME}"></span>
		</span>`);
		const input = field<HTMLInputElement>();
		input.value = '#ff0000';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		expect(
			document.querySelector<HTMLElement>('.pixonaut-placeholder__swatch')
				?.style.background,
		).toContain('255, 0, 0');
		expect(
			document.querySelector('.pixonaut-placeholder__aside')?.textContent,
		).toBe('#FF0000');
	});

	it('counts a text’s characters beside its heading', () => {
		printed(`<span data-pixonaut-type="text">
			<span class="pixonaut-placeholder__aside">0 / 40</span>
			<input type="text" maxlength="40" data-pixonaut-field="${NAME}">
		</span>`);
		const input = field<HTMLInputElement>();
		input.value = 'Table 12';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		expect(
			document.querySelector('.pixonaut-placeholder__aside')?.textContent,
		).toBe('8 / 40');
	});

	it('has a slider read as untouched at the value its browser rounded it to', () => {
		document.body.innerHTML = `<form><div class="pixonaut-form__placeholder">
			<input type="range" min="0" max="1" step="0.5" value="0.6" data-pixonaut-field="${NAME}">
		</div></form>`;
		const input = field<HTMLInputElement>();
		input.value = '0.5';
		input.setAttribute('value', '0.6');
		followPrintedInputs(document);
		expect(input.defaultValue).toBe(input.value);
	});

	it('leaves a slider the customer moved before the script ran as moved', () => {
		document.body.innerHTML = `<form><div class="pixonaut-form__placeholder">
			<input type="range" min="0" max="10" step="1" value="2" data-pixonaut-field="${NAME}">
		</div></form>`;
		const input = field<HTMLInputElement>();
		input.value = '7';
		followPrintedInputs(document);
		expect(input.defaultValue).toBe('2');
	});
});

describe('dropUntouchedInputs', () => {
	const MARKER = 'data-pixonaut-field';

	const MENU = `<select ${MARKER}="${NAME}">
		<option value="a">A</option>
		<option value="b" selected>B</option>
	</select>`;

	function target(html: string): HTMLElement {
		document.body.innerHTML = `<div id="target">${html}</div>`;
		return document.getElementById('target') as HTMLElement;
	}

	it.each([
		['text', `<input type="text" value="Hello" ${MARKER}="${NAME}">`],
		['text area', `<textarea ${MARKER}="${NAME}">Hello</textarea>`],
		[
			'slider',
			`<input type="range" min="0" max="10" step="1" value="2" ${MARKER}="${NAME}">`,
		],
		['colour', `<input type="color" value="#112233" ${MARKER}="${NAME}">`],
		['menu', MENU],
	])('unmarks an untouched %s and leaves it in place', (_kind, html) => {
		const form = target(html);
		const input = field<HTMLElement>();

		dropUntouchedInputs(form);

		expect(input.hasAttribute(MARKER)).toBe(false);
		expect(input.isConnected).toBe(true);
	});

	it('keeps the marker on a text the customer changed', () => {
		const form = target(
			`<input type="text" value="Hello" ${MARKER}="${NAME}">`,
		);
		const input = field<HTMLInputElement>();
		input.value = 'Table 12';

		dropUntouchedInputs(form);

		expect(input.getAttribute(MARKER)).toBe(NAME);
	});

	it('keeps the marker on a menu the customer changed', () => {
		const form = target(MENU);
		const input = field<HTMLSelectElement>();
		input.value = 'a';

		dropUntouchedInputs(form);

		expect(input.getAttribute(MARKER)).toBe(NAME);
	});
});
