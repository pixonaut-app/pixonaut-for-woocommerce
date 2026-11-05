// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fitPreviews } from './preview-fit';

/** Where the theme's header ends, in the page. */
const HEADER_PX = 240;

/** How far the customer has scrolled down. */
const SCROLLED_PX = 100;

/** A square photo's preview, and a portrait one twice as tall. */
const SQUARE = { width: 400, height: 400 };
const PORTRAIT = { width: 400, height: 800 };

const PINNED_ATTRIBUTE = 'data-pixonaut-pinned';

/** A phone's screen, a preview well under half of it, and one well over. */
const PHONE_HEIGHT_PX = 844;
const SHORT_PREVIEW_PX = 300;
const TALL_PREVIEW_PX = 560;

/** The part of a phone's screen an open keyboard leaves. */
const ABOVE_KEYBOARD_SHARE = 0.55;

/** Stands in for the browser's ResizeObserver, which jsdom lacks: `resize`
 *  reports a box change of every element observed. */
class FakeResizeObserver {
	static last: FakeResizeObserver | undefined;
	static all: FakeResizeObserver[] = [];
	readonly observed: Element[] = [];

	constructor(private readonly callback: ResizeObserverCallback) {
		FakeResizeObserver.last = this;
		FakeResizeObserver.all.push(this);
	}

	observe(element: Element): void {
		this.observed.push(element);
	}

	resize(): void {
		this.callback(
			this.observed.map((target) => ({ target }) as ResizeObserverEntry),
			this as unknown as ResizeObserver,
		);
	}
}

function frame(): { frame: HTMLElement; side: HTMLElement; mark: HTMLElement } {
	document.body.innerHTML =
		'<div class="pixonaut-preview"><div class="pixonaut-preview__frame"><div class="pixonaut-preview__stage"><div class="pixonaut-preview__side"></div></div><div class="pixonaut-preview__mark"></div></div></div>';
	const frameElement = document.querySelector<HTMLElement>(
		'.pixonaut-preview__frame',
	);
	const side = document.querySelector<HTMLElement>('.pixonaut-preview__side');
	const mark = document.querySelector<HTMLElement>('.pixonaut-preview__mark');
	if (frameElement === null || side === null || mark === null) {
		throw new Error('markup');
	}
	return { frame: frameElement, side, mark };
}

function drawn(side: HTMLElement, size: { width: number; height: number }) {
	vi.spyOn(side, 'getBoundingClientRect').mockReturnValue(
		DOMRect.fromRect({ x: 0, y: 0, ...size }),
	);
	FakeResizeObserver.last?.resize();
}

function preview(): HTMLElement {
	document.body.innerHTML = '<div class="pixonaut-preview"></div>';
	const element = document.querySelector<HTMLElement>('.pixonaut-preview');
	if (element === null) {
		throw new Error('markup');
	}
	return element;
}

function isTall(element: HTMLElement, height: number): void {
	vi.spyOn(element, 'getBoundingClientRect').mockReturnValue(
		DOMRect.fromRect({ x: 0, y: 0, width: 0, height }),
	);
}

function startsAt(element: HTMLElement, viewportTop: number): void {
	vi.spyOn(element, 'getBoundingClientRect').mockReturnValue(
		DOMRect.fromRect({ x: 0, y: viewportTop, width: 0, height: 0 }),
	);
}

afterEach(() => {
	vi.restoreAllMocks();
	vi.unstubAllGlobals();
	FakeResizeObserver.last = undefined;
	FakeResizeObserver.all = [];
	document.body.innerHTML = '';
});

describe('fitPreviews', () => {
	it('tells the stylesheet where the preview starts on the page, wherever it is scrolled to', () => {
		const element = preview();
		vi.spyOn(window, 'scrollY', 'get').mockReturnValue(SCROLLED_PX);
		startsAt(element, HEADER_PX - SCROLLED_PX);

		fitPreviews(document);

		expect(element.style.getPropertyValue('--pixonaut-preview-top')).toBe(
			`${HEADER_PX}px`,
		);
	});

	it('measures again when the window is resized', () => {
		const element = preview();
		startsAt(element, HEADER_PX);
		fitPreviews(document);

		startsAt(element, HEADER_PX / 2);
		window.dispatchEvent(new Event('resize'));

		expect(element.style.getPropertyValue('--pixonaut-preview-top')).toBe(
			`${HEADER_PX / 2}px`,
		);
	});

	it('measures again when something above it moves it before the page has loaded', () => {
		vi.stubGlobal('ResizeObserver', FakeResizeObserver);
		const element = preview();
		startsAt(element, HEADER_PX);
		fitPreviews(document);
		const page = FakeResizeObserver.all.find((observer) =>
			observer.observed.includes(document.body),
		);
		expect(page).toBeDefined();

		startsAt(element, HEADER_PX * 2);
		page?.resize();

		expect(element.style.getPropertyValue('--pixonaut-preview-top')).toBe(
			`${HEADER_PX * 2}px`,
		);
	});

	it('pins a preview that leaves the form room on a phone, and not one that does not', () => {
		const element = preview();
		vi.spyOn(document.documentElement, 'clientHeight', 'get').mockReturnValue(
			PHONE_HEIGHT_PX,
		);
		isTall(element, SHORT_PREVIEW_PX);
		fitPreviews(document);
		expect(element.hasAttribute(PINNED_ATTRIBUTE)).toBe(true);

		isTall(element, TALL_PREVIEW_PX);
		window.dispatchEvent(new Event('resize'));
		expect(element.hasAttribute(PINNED_ATTRIBUTE)).toBe(false);
	});

	it('stops pinning while an open keyboard leaves too little of the screen, and pins again once it closes', () => {
		const element = preview();
		vi.spyOn(document.documentElement, 'clientHeight', 'get').mockReturnValue(
			PHONE_HEIGHT_PX,
		);
		const visible = Object.assign(new EventTarget(), {
			height: PHONE_HEIGHT_PX,
		});
		vi.stubGlobal('visualViewport', visible);
		isTall(element, SHORT_PREVIEW_PX);
		fitPreviews(document);
		expect(element.hasAttribute(PINNED_ATTRIBUTE)).toBe(true);

		visible.height = PHONE_HEIGHT_PX * ABOVE_KEYBOARD_SHARE;
		visible.dispatchEvent(new Event('resize'));
		expect(element.hasAttribute(PINNED_ATTRIBUTE)).toBe(false);

		visible.height = PHONE_HEIGHT_PX;
		visible.dispatchEvent(new Event('resize'));
		expect(element.hasAttribute(PINNED_ATTRIBUTE)).toBe(true);
	});

	it('measures again when the preview itself changes height', () => {
		vi.stubGlobal('ResizeObserver', FakeResizeObserver);
		const element = preview();
		vi.spyOn(document.documentElement, 'clientHeight', 'get').mockReturnValue(
			PHONE_HEIGHT_PX,
		);
		isTall(element, SHORT_PREVIEW_PX);
		fitPreviews(document);
		const watching = FakeResizeObserver.all.find((observer) =>
			observer.observed.includes(element),
		);
		expect(watching).toBeDefined();

		isTall(element, TALL_PREVIEW_PX);
		watching?.resize();

		expect(element.hasAttribute(PINNED_ATTRIBUTE)).toBe(false);
	});

	it('follows the shape the SDK drew, as a view of another shape is shown', () => {
		vi.stubGlobal('ResizeObserver', FakeResizeObserver);
		const { frame: element, side } = frame();
		fitPreviews(document);

		drawn(side, SQUARE);
		expect(
			element.style.getPropertyValue('--pixonaut-preview-drawn-aspect'),
		).toBe('');

		element.classList.add('is-drawn');
		drawn(side, SQUARE);
		expect(
			element.style.getPropertyValue('--pixonaut-preview-drawn-aspect'),
		).toBe('1');

		drawn(side, PORTRAIT);
		expect(
			element.style.getPropertyValue('--pixonaut-preview-drawn-aspect'),
		).toBe('0.5');
	});

	it('drops the mark’s room once the SDK has drawn no mark', () => {
		vi.stubGlobal('ResizeObserver', FakeResizeObserver);
		const { frame: element, side } = frame();
		element.setAttribute('data-pixonaut-mark', '');
		fitPreviews(document);

		drawn(side, SQUARE);
		expect(element.hasAttribute('data-pixonaut-mark')).toBe(true);

		element.classList.add('is-drawn');
		drawn(side, SQUARE);
		expect(element.hasAttribute('data-pixonaut-mark')).toBe(false);
	});

	it('keeps the mark’s room once the SDK has drawn the mark under the preview, wherever the page guessed none', () => {
		vi.stubGlobal('ResizeObserver', FakeResizeObserver);
		const { frame: element, side, mark } = frame();
		element.classList.add('is-drawn');
		mark.innerHTML = '<a data-testid="pixonaut-powered-by"></a>';
		fitPreviews(document);

		drawn(side, SQUARE);

		expect(element.hasAttribute('data-pixonaut-mark')).toBe(true);
	});

	it('follows the mark target, which the SDK may draw into after the preview', () => {
		vi.stubGlobal('ResizeObserver', FakeResizeObserver);
		const { frame: element, side, mark } = frame();
		vi.spyOn(side, 'getBoundingClientRect').mockReturnValue(
			DOMRect.fromRect({ x: 0, y: 0, ...SQUARE }),
		);
		fitPreviews(document);
		const observer = FakeResizeObserver.last;

		expect(observer?.observed).toContain(mark);
		element.classList.add('is-drawn');
		mark.innerHTML = '<a data-testid="pixonaut-powered-by"></a>';
		observer?.resize();

		expect(element.hasAttribute('data-pixonaut-mark')).toBe(true);
		expect(
			element.style.getPropertyValue('--pixonaut-preview-drawn-aspect'),
		).toBe('1');
	});

	it('leaves the shape alone under a theme holding previews at a height', () => {
		vi.stubGlobal('ResizeObserver', FakeResizeObserver);
		const { frame: element, side } = frame();
		element.classList.add('is-drawn');
		side.style.setProperty('--pixonaut-preview-height', '300px');
		fitPreviews(document);

		drawn(side, PORTRAIT);

		expect(
			element.style.getPropertyValue('--pixonaut-preview-drawn-aspect'),
		).toBe('');
	});
});
