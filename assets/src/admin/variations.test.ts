// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import { initOverride, markChanged, newPanels } from './variations';

function panel(override: boolean): HTMLElement {
	const element = document.createElement('div');
	element.className = 'pixonaut-variation-panel';
	element.innerHTML = `
		<input type="checkbox" class="pixonaut-override" ${override ? 'checked' : ''} />
		<p class="pixonaut-variation-inherits"></p>
		<p class="pixonaut-variation-overrides"></p>
		<div class="pixonaut-variation-fields"></div>`;
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
	it('says which configuration the variation uses', () => {
		const element = panel(false);
		initOverride(element);
		const line = (name: string) =>
			element.querySelector<HTMLElement>(`.pixonaut-variation-${name}`)?.hidden;

		expect([line('inherits'), line('overrides')]).toEqual([false, true]);
		const override =
			element.querySelector<HTMLInputElement>('.pixonaut-override');
		if (override) {
			override.checked = true;
			override.dispatchEvent(new Event('change'));
		}
		expect([line('inherits'), line('overrides')]).toEqual([true, false]);
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
