// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import {
	HOME_WINDOW_MS,
	initDeveloperTools,
	PRESSES_TO_UNLOCK,
	STORAGE_KEY,
	Unlocker,
} from './developer-tools';

const COUNTDOWN = {
	1: '1 more to enable developer tools',
	2: '2 more to enable developer tools',
	3: '3 more to enable developer tools',
};

/** Between two presses of a burst, well within HOME_WINDOW_MS. */
const PRESS_GAP_MS = 100;

function page(): { heading: HTMLElement; section: HTMLElement } {
	document.body.innerHTML = `
		<form id="mainform">
			<div class="pixonaut-connection"><h2>Connection</h2></div>
			<h2>Product page designer</h2>
			<div class="pixonaut-developer-tools" hidden>
				<button type="button" class="pixonaut-developer-hide">Hide</button>
			</div>
		</form>`;
	const section = document.querySelector<HTMLElement>(
		'.pixonaut-developer-tools',
	);
	const heading = document.querySelectorAll<HTMLElement>('h2')[0];
	if (section === null || heading === undefined) {
		throw new Error('No section.');
	}
	section.dataset.countdown = JSON.stringify(COUNTDOWN);
	return { heading, section };
}

function pressHome(target: EventTarget = document.body): void {
	target.dispatchEvent(
		new KeyboardEvent('keydown', { key: 'Home', bubbles: true }),
	);
}

function countdown(): HTMLElement | null {
	return document.querySelector('.pixonaut-developer-countdown');
}

describe('Unlocker', () => {
	it('unlocks on the seventh click', () => {
		const unlocker = new Unlocker();
		const left = Array.from({ length: PRESSES_TO_UNLOCK }, () =>
			unlocker.click(),
		);
		expect(left).toEqual([6, 5, 4, 3, 2, 1, 0]);
	});

	it('counts only the Home presses within the window', () => {
		const unlocker = new Unlocker();
		for (let press = 0; press < PRESSES_TO_UNLOCK - 1; press++) {
			unlocker.home(press);
		}
		expect(unlocker.home(HOME_WINDOW_MS + 1)).toBeGreaterThan(0);

		const fresh = new Unlocker();
		let left = PRESSES_TO_UNLOCK;
		for (let press = 0; press < PRESSES_TO_UNLOCK; press++) {
			left = fresh.home(press * PRESS_GAP_MS);
		}
		expect(left).toBe(0);
	});
});

describe('initDeveloperTools', () => {
	beforeEach(() => {
		window.localStorage.clear();
	});

	it('shows the section on the seventh click of the heading, and remembers it', () => {
		const { heading, section } = page();
		initDeveloperTools(document, window.localStorage);

		for (let click = 0; click < PRESSES_TO_UNLOCK - 2; click++) {
			heading.click();
		}
		expect(section.hidden).toBe(true);
		expect(countdown()?.hidden).toBe(false);
		expect(countdown()?.textContent).toBe(COUNTDOWN[2]);

		heading.click();
		heading.click();
		expect(section.hidden).toBe(false);
		expect(countdown()?.hidden).toBe(true);
		expect(window.localStorage.getItem(STORAGE_KEY)).toBe('1');
	});

	it('shows the section after seven Home presses, but not while typing', () => {
		const { section } = page();
		let now = 0;
		initDeveloperTools(document, window.localStorage, () => now);
		const input = document.createElement('input');
		document.body.append(input);

		for (let press = 0; press < PRESSES_TO_UNLOCK; press++) {
			pressHome(input);
		}
		expect(section.hidden).toBe(true);

		for (let press = 0; press < PRESSES_TO_UNLOCK; press++) {
			now += PRESS_GAP_MS;
			pressHome();
		}
		expect(section.hidden).toBe(false);
	});

	it('keeps the section shown after a reload, until it is hidden', () => {
		window.localStorage.setItem(STORAGE_KEY, '1');
		const { section } = page();
		initDeveloperTools(document, window.localStorage);
		expect(section.hidden).toBe(false);

		section.querySelector<HTMLElement>('.pixonaut-developer-hide')?.click();
		expect(section.hidden).toBe(true);
		expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();

		page();
		initDeveloperTools(document, window.localStorage);
		expect(
			document.querySelector<HTMLElement>('.pixonaut-developer-tools')?.hidden,
		).toBe(true);
	});

	it('still unlocks where the browser blocks storage', () => {
		const { heading, section } = page();
		initDeveloperTools(document, null);

		for (let click = 0; click < PRESSES_TO_UNLOCK; click++) {
			heading.click();
		}
		expect(section.hidden).toBe(false);
	});
});
