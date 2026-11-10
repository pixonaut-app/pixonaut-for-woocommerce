// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import {
	initOverride,
	initVariationSizes,
	markChanged,
	newPanels,
} from './variations';

function panel(override: boolean): HTMLElement {
	const element = document.createElement('div');
	element.className = 'pixonaut-variation-panel';
	element.innerHTML = `
		<input type="checkbox" class="pixonaut-override" ${override ? 'checked' : ''} />
		<p class="pixonaut-variation-inherits"></p>
		<div class="pixonaut-variation-fields"></div>
		<div class="pixonaut-variation-sizes">
			<p class="pixonaut-variation-size">
				<select class="pixonaut-variation-size-kind">
					<option value="product" selected>As the product</option>
					<option value="custom">Custom size</option>
				</select>
				<span class="pixonaut-variation-size-custom"></span>
			</p>
		</div>`;
	return element;
}

function fields(element: HTMLElement): HTMLElement | null {
	return element.querySelector<HTMLElement>('.pixonaut-variation-fields');
}

describe('newPanels', () => {
	it('finds each panel once across loaded pages of variations', () => {
		const container = document.createElement('div');
		container.append(panel(false));

		expect(newPanels(container)).toHaveLength(1);
		container.append(panel(true));

		expect(newPanels(container)).toHaveLength(1);
		expect(newPanels(container)).toHaveLength(0);
	});
});

describe('initOverride', () => {
	it('says the variation follows its product only while it does', () => {
		const element = panel(false);
		initOverride(element);
		const line = (name: string) =>
			element.querySelector<HTMLElement>(`.pixonaut-variation-${name}`)?.hidden;

		expect(line('inherits')).toBe(false);
		const override =
			element.querySelector<HTMLInputElement>('.pixonaut-override');
		if (override) {
			override.checked = true;
			override.dispatchEvent(new Event('change'));
		}
		expect(line('inherits')).toBe(true);
	});

	it("hides the fields while the variation inherits the product's", () => {
		const element = panel(false);

		initOverride(element);

		expect(fields(element)?.hidden).toBe(true);
	});

	it('shows the fields once the override is ticked', () => {
		const element = panel(false);
		initOverride(element);
		const override =
			element.querySelector<HTMLInputElement>('.pixonaut-override');

		if (override) {
			override.checked = true;
			override.dispatchEvent(new Event('change'));
		}

		expect(fields(element)?.hidden).toBe(false);
	});
});

describe('markChanged', () => {
	it('fires a bubbling change from a field of the variation', () => {
		const element = panel(true);
		const changed = vi.fn();
		element.addEventListener('change', changed);

		markChanged(element);

		expect(changed).toHaveBeenCalledOnce();
	});

	it('does nothing in the product tab, which has no override', () => {
		const element = document.createElement('div');
		const changed = vi.fn();
		element.addEventListener('change', changed);

		markChanged(element);

		expect(changed).not.toHaveBeenCalled();
	});
});

describe('the variation print sizes', () => {
	it('are hidden while the variation overrides its whole setup', () => {
		const element = panel(false);
		initOverride(element);
		const sizes = element.querySelector<HTMLElement>(
			'.pixonaut-variation-sizes',
		);
		const override =
			element.querySelector<HTMLInputElement>('.pixonaut-override');

		expect(sizes?.hidden).toBe(false);
		if (override) {
			override.checked = true;
			override.dispatchEvent(new Event('change'));
		}

		expect(sizes?.hidden).toBe(true);
	});

	it("show a width and height only for a size of the variation's own", () => {
		const element = panel(false);
		initVariationSizes(element);
		const kind = element.querySelector<HTMLSelectElement>(
			'.pixonaut-variation-size-kind',
		);
		const custom = element.querySelector<HTMLElement>(
			'.pixonaut-variation-size-custom',
		);

		expect(custom?.hidden).toBe(true);
		if (kind) {
			kind.value = 'custom';
			kind.dispatchEvent(new Event('change'));
		}

		expect(custom?.hidden).toBe(false);
	});
});
