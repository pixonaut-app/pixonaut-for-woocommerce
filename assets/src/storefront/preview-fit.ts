import { DRAWN_ASPECT_PROPERTY } from './frame-shape';

/** The previews the page printed in place of a gallery. */
const PREVIEW_SELECTOR = '.pixonaut-preview';

/** Each side's preview, in its frame. */
const SIDE_SELECTOR = '.pixonaut-preview__frame .pixonaut-preview__side';

const FRAME_SELECTOR = '.pixonaut-preview__frame';

/** Set on a frame once the SDK has drawn its preview. */
const DRAWN_CLASS = 'is-drawn';

/** How far down the page a preview starts, which the stylesheet holds the
 *  preview's height under the window by. */
const TOP_PROPERTY = '--pixonaut-preview-top';

/** A theme's fixed preview height, under which the SDK fits whatever it
 *  draws, so the drawn box's shape says nothing about the photo's. */
const HELD_HEIGHT_PROPERTY = '--pixonaut-preview-height';

/** On a frame whose mark target the stylesheet keeps the mark's room in. */
const MARK_ATTRIBUTE = 'data-pixonaut-mark';

/** Each side's target for the SDK's "Powered by Pixonaut" mark, under its
 *  preview. */
const MARK_TARGET_SELECTOR = '.pixonaut-preview__frame .pixonaut-preview__mark';

/** The SDK's "Powered by Pixonaut" mark, once drawn. */
const MARK_SELECTOR = '[data-testid="pixonaut-powered-by"]';

/** A change in shape too small to be another photo: the SDK rounds its sizes
 *  to whole pixels. */
const ASPECT_TOLERANCE = 0.01;

/** On a preview short enough for the stylesheet to keep it at the top of a
 *  phone's screen while the form scrolls under it. */
const PINNED_ATTRIBUTE = 'data-pixonaut-pinned';

/** The most of the screen a preview may take and stay pinned. An open
 *  keyboard takes around half of a phone's screen, and the field being typed
 *  in has to show in what is left. */
const PINNED_SCREEN_SHARE = 0.45;

/**
 * Tells the stylesheet where each preview starts on the page, so a preview
 * on a short window fits under the theme's header rather than running below
 * the fold, and whether it is short enough to stay pinned on a phone.
 * Measured again whenever the page's box changes (a web font or an
 * image above it loading moves it, often before the load event, which a
 * script still loading holds back), once the page has loaded and whenever
 * the window is resized. Once drawn, each
 * side's shape is followed too: a view or variant switched to may show a
 * photo of another shape than the one the page printed.
 */
export function fitPreviews(doc: Document): void {
	const win = doc.defaultView;
	const previews = Array.from(
		doc.querySelectorAll<HTMLElement>(PREVIEW_SELECTOR),
	);
	if (win === null || previews.length === 0) {
		return;
	}
	const measure = (): void => {
		for (const preview of previews) {
			const box = preview.getBoundingClientRect();
			preview.style.setProperty(
				TOP_PROPERTY,
				`${Math.max(0, restingTop(preview, win))}px`,
			);
			// The document's height, unlike the window's, stays put as a phone's
			// browser bars come and go, so scrolling never flips this. Only the
			// visual viewport shrinks for an open keyboard.
			const screen = Math.min(
				doc.documentElement.clientHeight,
				win.visualViewport?.height ?? Number.POSITIVE_INFINITY,
			);
			preview.toggleAttribute(
				PINNED_ATTRIBUTE,
				box.height <= screen * PINNED_SCREEN_SHARE,
			);
		}
	};
	measure();
	win.addEventListener('load', measure);
	win.addEventListener('resize', measure);
	win.visualViewport?.addEventListener('resize', measure);
	if (win.ResizeObserver === undefined) {
		return;
	}
	const boxes = new win.ResizeObserver(measure);
	boxes.observe(doc.body);
	for (const preview of previews) {
		boxes.observe(preview);
	}
	const shapes = new win.ResizeObserver((entries) => {
		for (const entry of entries) {
			const side = entry.target
				.closest(FRAME_SELECTOR)
				?.querySelector<HTMLElement>(SIDE_SELECTOR);
			if (side) {
				followShape(side);
			}
		}
	});
	for (const side of doc.querySelectorAll<HTMLElement>(SIDE_SELECTOR)) {
		shapes.observe(side);
	}
	// The SDK draws its mark there, or none, after the preview may be done.
	for (const mark of doc.querySelectorAll<HTMLElement>(MARK_TARGET_SELECTOR)) {
		shapes.observe(mark);
	}
}

/** The position that takes an element out of the page's flow of scrolling
 *  while it is in view. */
const STICKY = 'sticky';

/**
 * How far down the page `preview` starts where it rests, before anything
 * keeps it in view: a theme may keep the preview, or the column it is in, at
 * the top of the window while the form scrolls (`position: sticky`), and
 * measured there a preview far down a long form would start as far down the
 * page as the customer has scrolled, leaving it no room. Each such element
 * is put back in the flow for the one measurement, in the same task, so
 * nothing of it is painted.
 */
function restingTop(preview: HTMLElement, win: Window): number {
	const kept: Array<{ element: HTMLElement; position: string }> = [];
	for (
		let element: HTMLElement | null = preview;
		element !== null && element !== preview.ownerDocument.body;
		element = element.parentElement
	) {
		if (win.getComputedStyle(element).position === STICKY) {
			kept.push({ element, position: element.style.position });
			element.style.position = 'static';
		}
	}
	const top = preview.getBoundingClientRect().top + win.scrollY;
	for (const { element, position } of kept) {
		element.style.position = position;
	}
	return top;
}

/** Sets the frame's drawn shape from its side's box, once drawn. */
function followShape(side: HTMLElement): void {
	const frame = side.closest<HTMLElement>(FRAME_SELECTOR);
	const { width, height } = side.getBoundingClientRect();
	// The page guessed whether the mark shows where its reads failed; what
	// the SDK drew, into the frame's mark target, settles it.
	frame?.toggleAttribute(
		MARK_ATTRIBUTE,
		frame.classList.contains(DRAWN_CLASS)
			? frame.querySelector(MARK_SELECTOR) !== null
			: frame.hasAttribute(MARK_ATTRIBUTE),
	);
	const style = side.ownerDocument.defaultView?.getComputedStyle(side);
	const held = style?.getPropertyValue(HELD_HEIGHT_PROPERTY).trim() !== '';
	if (
		frame === null ||
		!frame.classList.contains(DRAWN_CLASS) ||
		held ||
		width === 0 ||
		height === 0
	) {
		return;
	}
	const aspect = width / height;
	const current = Number.parseFloat(
		frame.style.getPropertyValue(DRAWN_ASPECT_PROPERTY),
	);
	// Unchanged within the tolerance, so a narrower frame does not resize it
	// again and again.
	if (!(Math.abs(aspect - current) <= ASPECT_TOLERANCE)) {
		frame.style.setProperty(DRAWN_ASPECT_PROPERTY, String(aspect));
	}
}
