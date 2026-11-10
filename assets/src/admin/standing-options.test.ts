// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import {
	initStandingOptions,
	showStandingOptions,
	standingOptionsChanged,
} from './standing-options';

const STANDING = 'standing';

/** A side with `count` option previews, the ones past the first two hidden
 *  behind Show all, as the panel prints them. */
function side(count: number, mode = STANDING): HTMLElement {
	const element = document.createElement('div');
	const tiles = Array.from(
		{ length: count },
		(_, i) =>
			`<li class="pixonaut-standing-option" ${i < 2 ? '' : 'hidden data-more'}>${i}</li>`,
	).join('');
	element.innerHTML = `
		<input type="checkbox" class="pixonaut-side-mode" value="personalised" ${mode === STANDING ? '' : 'checked'} />
		<div class="pixonaut-standing-options">
			<p class="pixonaut-standing-options__pending" hidden>Pending</p>
			<ul class="pixonaut-standing-options__tiles">${tiles}</ul>
			<button type="button" class="pixonaut-standing-options__more" aria-expanded="false" data-all="Show all ${count}" data-fewer="Show fewer">Show all ${count}</button>
		</div>`;
	return element;
}

const shown = (element: HTMLElement) =>
	[
		...element.querySelectorAll<HTMLElement>('.pixonaut-standing-option'),
	].filter((tile) => !tile.hidden).length;

describe('initStandingOptions', () => {
	it('opens every preview with Show all, and the first again with Show fewer', () => {
		const element = side(5);
		initStandingOptions(element);
		const more = element.querySelector<HTMLButtonElement>(
			'.pixonaut-standing-options__more',
		);

		more?.click();
		expect(shown(element)).toBe(5);
		expect(more?.textContent).toBe('Show fewer');
		expect(more?.getAttribute('aria-expanded')).toBe('true');

		more?.click();
		expect(shown(element)).toBe(2);
		expect(more?.textContent).toBe('Show all 5');
	});
});

describe('standingOptionsChanged', () => {
	it('puts the line about the next update in place of the previews', () => {
		const element = side(5);

		standingOptionsChanged(element);

		expect(
			element.querySelector<HTMLElement>('.pixonaut-standing-options__pending')
				?.hidden,
		).toBe(false);
		expect(
			element.querySelector<HTMLElement>('.pixonaut-standing-options__tiles')
				?.hidden,
		).toBe(true);
		expect(
			element.querySelector<HTMLElement>('.pixonaut-standing-options__more')
				?.hidden,
		).toBe(true);
	});

	it('does nothing on a side without previews', () => {
		const element = document.createElement('div');

		expect(() => standingOptionsChanged(element)).not.toThrow();
	});
});

describe('showStandingOptions', () => {
	it('shows the previews only while the side is a fixed design', () => {
		const element = side(1, 'personalised');
		const options = element.querySelector<HTMLElement>(
			'.pixonaut-standing-options',
		);

		showStandingOptions(element);
		expect(options?.hidden).toBe(true);

		const mode = element.querySelector<HTMLInputElement>('.pixonaut-side-mode');
		if (mode) {
			mode.checked = false;
		}
		showStandingOptions(element);
		expect(options?.hidden).toBe(false);
	});
});

describe('Reset', () => {
	function adjusted(): { element: HTMLElement; reset: HTMLAnchorElement } {
		const element = side(1);
		element
			.querySelector('.pixonaut-standing-option')
			?.insertAdjacentHTML(
				'beforeend',
				'<a class="pixonaut-standing-option__reset" href="#reset" data-confirm="Reset S?">Reset</a>',
			);
		initStandingOptions(element);
		const reset = element.querySelector<HTMLAnchorElement>(
			'.pixonaut-standing-option__reset',
		);
		if (!reset) {
			throw new Error('No Reset link.');
		}
		return { element, reset };
	}

	const follow = (reset: HTMLAnchorElement): boolean =>
		reset.dispatchEvent(
			new MouseEvent('click', { bubbles: true, cancelable: true }),
		);

	it('asks first, and stays when the store manager says no', () => {
		const { reset } = adjusted();
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

		expect(follow(reset)).toBe(false);
		expect(confirm).toHaveBeenCalledWith('Reset S?');
		confirm.mockRestore();
	});

	it('follows the link once the store manager agrees', () => {
		const { reset } = adjusted();
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);

		expect(follow(reset)).toBe(true);
		confirm.mockRestore();
	});
});
