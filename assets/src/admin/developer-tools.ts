// The hidden developer tools on the Pixonaut settings tab: shown once the
// tab's Connection heading is clicked 7 times, or Home is pressed 7 times
// within 3 seconds, and kept shown in this browser until hidden again.
// Unlocking only shows the section; the server checks every action in it.
import './developer-tools.css';
import { domReady } from '../shared/dom-ready';

/** Clicks or Home presses that unlock the section. */
export const PRESSES_TO_UNLOCK = 7;

/** Home presses count only within this window. */
export const HOME_WINDOW_MS = 3000;

/** Remembers the section is unlocked, in this browser. */
export const STORAGE_KEY = 'pixonautDeveloperTools';

const UNLOCKED = '1';

/** The connection card's heading, the first on the tab, which unlocks the
 *  section when clicked. */
const HEADING_SELECTOR = '.pixonaut-connection > h2';

/** Counts presses towards unlocking. A press returns how many are still
 *  needed; 0 unlocks. */
export class Unlocker {
	private clicks = 0;

	private homes: number[] = [];

	click(): number {
		this.clicks += 1;
		return Math.max(PRESSES_TO_UNLOCK - this.clicks, 0);
	}

	home(now: number): number {
		this.homes = [...this.homes, now].filter((at) => now - at < HOME_WINDOW_MS);
		return Math.max(PRESSES_TO_UNLOCK - this.homes.length, 0);
	}

	reset(): void {
		this.clicks = 0;
		this.homes = [];
	}
}

/** Whether a key press belongs to a field, where Home moves the caret. */
function typing(target: EventTarget | null): boolean {
	return (
		target instanceof HTMLElement &&
		(target.isContentEditable ||
			['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
	);
}

/** The site's storage; null where the browser blocks it, when even reading
 *  `localStorage` throws. */
function siteStorage(): Storage | null {
	try {
		return window.localStorage;
	} catch {
		return null;
	}
}

function remembered(storage: Storage | null): boolean {
	try {
		return storage?.getItem(STORAGE_KEY) === UNLOCKED;
	} catch {
		return false;
	}
}

function remember(storage: Storage | null, unlocked: boolean): void {
	try {
		if (unlocked) {
			storage?.setItem(STORAGE_KEY, UNLOCKED);
		} else {
			storage?.removeItem(STORAGE_KEY);
		}
	} catch {
		// Private browsing: the section stays shown until the page reloads.
	}
}

/** Wires the section's unlocking and hiding. */
export function initDeveloperTools(
	doc: Document = document,
	storage: Storage | null = siteStorage(),
	now: () => number = Date.now,
): void {
	const section = doc.querySelector<HTMLElement>('.pixonaut-developer-tools');
	if (section === null) {
		return;
	}
	const countdown = JSON.parse(section.dataset.countdown ?? '{}') as Record<
		string,
		string
	>;
	const heading = doc.querySelector<HTMLElement>(HEADING_SELECTOR);
	const notice = doc.createElement('p');
	notice.className = 'pixonaut-developer-countdown';
	notice.setAttribute('role', 'status');
	notice.hidden = true;
	(heading ?? section).after(notice);

	const unlocker = new Unlocker();
	const show = (shown: boolean) => {
		section.hidden = !shown;
		notice.hidden = true;
		remember(storage, shown);
		unlocker.reset();
	};
	const pressed = (left: number) => {
		if (!section.hidden) {
			return;
		}
		if (left === 0) {
			show(true);
			// Not in every DOM (jsdom has none).
			section.scrollIntoView?.();
			return;
		}
		const text = countdown[String(left)];
		notice.hidden = text === undefined;
		notice.textContent = text ?? '';
	};

	section.hidden = !remembered(storage);
	heading?.addEventListener('click', () => pressed(unlocker.click()));
	doc.addEventListener('keydown', (event) => {
		if (event.key === 'Home' && !typing(event.target)) {
			pressed(unlocker.home(now()));
		}
	});
	section
		.querySelector('.pixonaut-developer-hide')
		?.addEventListener('click', () => show(false));
}

domReady(() => initDeveloperTools());
