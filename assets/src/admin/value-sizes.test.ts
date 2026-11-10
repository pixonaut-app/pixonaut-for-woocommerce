// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { initValueSizes } from './value-sizes';

/** A side with sizes per value of Size or Colour, Size chosen. */
function side(personalised = true): HTMLElement {
	const element = document.createElement('div');
	element.innerHTML = `
		<input type="checkbox" class="pixonaut-side-mode" value="personalised" ${personalised ? 'checked' : ''} />
		<div class="form-field pixonaut-value-sizes">
			<select class="pixonaut-value-attribute">
				<option value="">None</option>
				<option value="pa_size" selected>Size</option>
				<option value="colour">Colour</option>
			</select>
			<table class="pixonaut-value-size-table" data-attribute="pa_size"></table>
			<table class="pixonaut-value-size-table" data-attribute="colour" hidden></table>
		</div>`;
	return element;
}

const table = (element: HTMLElement, attribute: string) =>
	element.querySelector<HTMLElement>(`[data-attribute="${attribute}"]`);

const choose = (element: HTMLElement, attribute: string) => {
	const select = element.querySelector('select') as HTMLSelectElement;
	select.value = attribute;
	select.dispatchEvent(new Event('change'));
};

describe('value sizes', () => {
	it('shows the values of the attribute chosen alone', () => {
		const element = side();
		initValueSizes(element);

		choose(element, 'colour');

		expect(table(element, 'colour')?.hidden).toBe(false);
		expect(table(element, 'pa_size')?.hidden).toBe(true);
	});

	it('shows no values with no attribute chosen', () => {
		const element = side();
		initValueSizes(element);

		choose(element, '');

		expect(table(element, 'colour')?.hidden).toBe(true);
		expect(table(element, 'pa_size')?.hidden).toBe(true);
	});

	it('is hidden on a fixed design', () => {
		const element = side(false);
		initValueSizes(element);
		const field = element.querySelector<HTMLElement>('.pixonaut-value-sizes');
		expect(field?.hidden).toBe(true);

		const mode = element.querySelector(
			'.pixonaut-side-mode',
		) as HTMLInputElement;
		mode.checked = true;
		mode.dispatchEvent(new Event('change'));

		expect(field?.hidden).toBe(false);
	});
});
