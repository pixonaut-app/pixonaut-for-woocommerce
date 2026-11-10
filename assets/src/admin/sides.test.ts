// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { isFixedDesign, NEW_SIDE_INDEX, nextIndex, withIndex } from './sides';

describe('nextIndex', () => {
	it('starts at 0 without rows', () => {
		expect(nextIndex([])).toBe(0);
	});

	it('follows the highest index, whatever was removed between', () => {
		expect(nextIndex(['0', '3', '1'])).toBe(4);
	});

	it('skips a row still holding the placeholder', () => {
		expect(nextIndex([NEW_SIDE_INDEX, '0'])).toBe(1);
	});
});

describe('withIndex', () => {
	it('puts the index into every name and id', () => {
		const markup = `<select id="pixonaut_side_${NEW_SIDE_INDEX}_placement" name="pixonaut_sides[${NEW_SIDE_INDEX}][placement]">`;

		expect(withIndex(markup, NEW_SIDE_INDEX, 2)).toBe(
			'<select id="pixonaut_side_2_placement" name="pixonaut_sides[2][placement]">',
		);
	});
});

describe('isFixedDesign', () => {
	function side(markup: string): HTMLElement {
		const element = document.createElement('div');
		element.innerHTML = markup;
		return element;
	}

	it('reads the box: unticked is a fixed design', () => {
		expect(
			isFixedDesign(
				side('<input type="checkbox" class="pixonaut-side-mode" />'),
			),
		).toBe(true);
		expect(
			isFixedDesign(
				side('<input type="checkbox" class="pixonaut-side-mode" checked />'),
			),
		).toBe(false);
	});

	it('reads the mode select of a theme’s copy of the 0.1.0 side template', () => {
		const markup = (selected: string) =>
			side(`<select class="pixonaut-side-mode">
				<option value="personalised" ${selected === 'personalised' ? 'selected' : ''}>Personalised</option>
				<option value="standing" ${selected === 'standing' ? 'selected' : ''}>Fixed design</option>
			</select>`);

		expect(isFixedDesign(markup('standing'))).toBe(true);
		expect(isFixedDesign(markup('personalised'))).toBe(false);
	});
});
