// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { SideTabs } from './tabs';
import { MOUNTS, productPage, TWO_SIDES } from './test-support';

function start(mounts: object = TWO_SIDES) {
	const { container } = productPage(mounts, { checkbox: true });
	const tabs = SideTabs.find(container, () =>
		document.querySelector<HTMLElement>('.pixonaut-preview'),
	);
	tabs?.start();
	return { container, tabs };
}

function tab(side: string): HTMLElement {
	const element = document.getElementById(`pixonaut-tab-${side}`);
	if (element === null) {
		throw new Error(`No tab for side ${side}.`);
	}
	return element;
}

/** Whether each of the side's parts is shown: form, preview, proof button. */
function shown(side: string): boolean[] {
	return [
		`#pixonaut-form-${side}`,
		`#pixonaut-preview-${side}`,
		`.pixonaut-approval__proof[data-pixonaut-side="${side}"]`,
	].map((selector) => !document.querySelector<HTMLElement>(selector)?.hidden);
}

function press(element: HTMLElement, key: string): KeyboardEvent {
	const event = new KeyboardEvent('keydown', {
		key,
		bubbles: true,
		cancelable: true,
	});
	element.dispatchEvent(event);
	return event;
}

describe('SideTabs', () => {
	it('is absent for a product with one side', () => {
		expect(start(MOUNTS).tabs).toBeNull();
	});

	it('shows the first side until another tab is selected', () => {
		const { tabs } = start();

		expect(tabs?.selected()).toBe('front');
		expect(shown('front')).toEqual([true, true, true]);
		expect(shown('back')).toEqual([false, false, false]);
	});

	it('shows the clicked side and hides the others', () => {
		start();

		tab('back').click();

		expect(shown('back')).toEqual([true, true, true]);
		expect(shown('front')).toEqual([false, false, false]);
		expect(tab('back').getAttribute('aria-selected')).toBe('true');
		expect(tab('front').getAttribute('aria-selected')).toBe('false');
		expect([tab('front').tabIndex, tab('back').tabIndex]).toEqual([-1, 0]);
	});

	it('moves between tabs with the arrow keys, Home and End', () => {
		start();
		tab('front').focus();

		const right = press(tab('front'), 'ArrowRight');
		expect(right.defaultPrevented).toBe(true);
		expect(document.activeElement).toBe(tab('back'));
		expect(shown('back')[0]).toBe(true);

		press(tab('back'), 'ArrowRight');
		expect(document.activeElement).toBe(tab('front'));

		press(tab('front'), 'ArrowLeft');
		expect(document.activeElement).toBe(tab('back'));

		press(tab('back'), 'Home');
		expect(document.activeElement).toBe(tab('front'));

		press(tab('front'), 'End');
		expect(document.activeElement).toBe(tab('back'));
	});

	it('leaves other keys to the browser', () => {
		start();

		const event = press(tab('front'), 'Enter');

		expect(event.defaultPrevented).toBe(false);
		expect(tab('front').getAttribute('aria-selected')).toBe('true');
	});

	it('hides a part made after it started that belongs to another side', () => {
		const { container, tabs } = start();
		const made = document.createElement('div');
		made.className = 'pixonaut-preview__side';
		made.dataset.pixonautSide = 'back';
		container.prepend(made);

		tabs?.sync();

		expect(made.hidden).toBe(true);
	});

	it('ignores an unknown side', () => {
		const { tabs } = start();

		tabs?.select('sleeve');

		expect(tabs?.selected()).toBe('front');
		expect(shown('front')).toEqual([true, true, true]);
	});

	it('shows only the tabs of the chosen variation’s sides, and keys skip the others', () => {
		const { container, tabs } = start({
			...TWO_SIDES,
			sleeve: { ...MOUNTS.front, placementId: 'sleeve' },
		});
		tabs?.select('back');

		tabs?.limit(['front', 'sleeve']);

		expect(tab('back').hidden).toBe(true);
		expect(tabs?.selected()).toBe('front');
		expect(shown('front')).toEqual([true, true, true]);
		press(tab('front'), 'ArrowRight');
		expect(tabs?.selected()).toBe('sleeve');

		tabs?.limit(['front']);
		expect(container.querySelector<HTMLElement>('.pixonaut-tabs')?.hidden).toBe(
			true,
		);
		tabs?.limit(['front', 'back']);
		expect(container.querySelector<HTMLElement>('.pixonaut-tabs')?.hidden).toBe(
			false,
		);
	});
});
