import { type ThumbnailLender, thumbnailLender } from './held-thumbnails';

/** Attributes a still copy of the designer drops, so it names nothing the
 *  page or the form reads: no duplicate id, nothing submitted, no printed
 *  field the SDK would take as the customer's. */
const NAMING_ATTRIBUTES = ['id', 'name', 'form', 'for', 'data-pixonaut-field'];

/** On each node of a still copy, while it stands in for the designer. */
export const HELD_ATTRIBUTE = 'data-pixonaut-held';

/** On the element a hold shows its spinner in, once the next designer has
 *  taken longer than `SLOW_MOUNT_MS` to show. */
export const WAITING_CLASS = 'is-waiting';

/** How long a side may take to mount again before a spinner shows over what
 *  it held: a switch quicker than this shows none. */
export const SLOW_MOUNT_MS = 800;

/** What a side's designer shows, held in place while it mounts again. */
export type RemountHold = {
	/** Puts the copy in the emptied targets once the designer is destroyed,
	 *  and hides the form's parts it gave back until the next one takes
	 *  them. */
	place(): void;
	/** Lets go, once: the parts show again, the boxes size to what they
	 *  hold, a copy the next designer did not replace (its mount failed)
	 *  goes, and the next designer's view strip shows the held thumbnail of
	 *  each view it has not drawn yet (`thumbnailLender`). */
	release(): void;
	/** The form's parts hidden now: given back, and not taken by the next
	 *  designer yet. */
	hidden(): HTMLElement[];
};

/**
 * Copies what a side's designer shows in `targets` (its preview, form and
 * view strip) before it is destroyed and mounted again, so the page keeps
 * its layout and its picture while the next one mounts: a split mount
 * keeps what the host printed in its targets until its preview is drawn,
 * as it does for the server's printed form. The copy is inert, with each
 * canvas's pixels drawn into its own, and each target keeps at least the
 * height it had. Its pictures are the designer's own `<img>` elements,
 * put in the copy once the designer is destroyed: the SDK lets go of the
 * `blob:` URLs they show, which a copy of the element would load again,
 * and break on, while the element keeps the picture it decoded. `sized`
 * keep their height alone (the "Powered by" mark, which the SDK draws
 * beside what it finds), and `parts`, the add-to-cart form's own elements
 * the designer drew in its form, are shown in the copy and hidden where
 * they go back to until the next designer takes them in its form. `hidden`
 * are parts an earlier hold still hides, the designer it waited on being
 * destroyed before it took them, which stay hidden too. `waiting`, the
 * preview's frame, takes `WAITING_CLASS` once the hold has stood for
 * `SLOW_MOUNT_MS`, which shows its spinner over the held picture, and loses
 * it on release.
 */
export function holdShown(
	targets: readonly HTMLElement[],
	sized: readonly HTMLElement[],
	parts: readonly HTMLElement[],
	hidden: readonly HTMLElement[] = [],
	waiting: Element | null = null,
): RemountHold {
	const copies = targets.map(
		(target) => [target, copyOf(target, [...parts, ...hidden])] as const,
	);
	// Every box keeps at least its height too: the copy can lay out shorter
	// than the designer it copies, which styles its own parts as it draws.
	const heights = [...targets, ...sized].map(
		(element) => [element, element.getBoundingClientRect().height] as const,
	);
	const shown = new Set([
		...parts.filter((part) => targets.some((target) => target.contains(part))),
		...hidden,
	]);
	const displays = new Map<HTMLElement, string>();
	const placed: Node[] = [];
	/** Lends the designer's own pictures the copy shows, once it shows them. */
	let lender: ThumbnailLender | null = null;
	let released = false;
	let slow: ReturnType<typeof setTimeout> | undefined;
	const showTaken = (): void => {
		for (const [part, display] of displays) {
			if (targets.some((target) => target.contains(part))) {
				part.style.display = display;
				displays.delete(part);
			}
		}
	};
	// The next designer's view strip shows the held thumbnails from the
	// moment it is drawn, out of sight, so each has decoded by the time the
	// strip shows.
	const lend = (): void => {
		for (const target of targets) {
			lender?.lend(target);
		}
	};
	// The next designer draws its form ahead of its preview: a part it took
	// shows there at once, never hidden in the form while the preview waits.
	const taking =
		typeof MutationObserver === 'undefined'
			? null
			: new MutationObserver(() => {
					showTaken();
					lend();
				});
	return {
		place() {
			const kept: HTMLImageElement[] = [];
			for (const [target, copy] of copies) {
				target.replaceChildren(...copy.nodes);
				kept.push(...copy.keepPictures());
				placed.push(...target.childNodes);
			}
			lender = thumbnailLender(kept);
			for (const [element, height] of heights) {
				element.style.minHeight = `${height}px`;
			}
			for (const part of shown) {
				displays.set(part, part.style.display);
				part.style.display = 'none';
			}
			for (const target of targets) {
				taking?.observe(target, { childList: true, subtree: true });
			}
			if (waiting !== null) {
				slow = setTimeout(
					() => waiting.classList.add(WAITING_CLASS),
					SLOW_MOUNT_MS,
				);
			}
		},
		release() {
			// Once: a later hold may have sized the same boxes since.
			if (released) {
				return;
			}
			released = true;
			taking?.disconnect();
			clearTimeout(slow);
			waiting?.classList.remove(WAITING_CLASS);
			for (const [part, display] of displays) {
				part.style.display = display;
			}
			displays.clear();
			for (const [element] of heights) {
				element.style.removeProperty('min-height');
			}
			lend();
			lender = null;
			for (const node of placed.splice(0)) {
				node.parentNode?.removeChild(node);
			}
		},
		hidden() {
			return [...displays.keys()];
		},
	};
}

/** A target's copy: its nodes, and how to put the designer's own pictures
 *  in place of theirs, once the designer is gone, which gives the pictures
 *  it put. The pictures in the form's parts are the page's, which the next
 *  designer draws again: the copy keeps its own of those. */
type Copy = { nodes: Node[]; keepPictures(): HTMLImageElement[] };

/** Inert copies of the target's content, its canvases drawn in and the
 *  pictures of the designer, not in one of the form's `parts`, kept. */
function copyOf(target: HTMLElement, parts: readonly HTMLElement[]): Copy {
	const sources = [...target.querySelectorAll('canvas')];
	const pictures = [...target.querySelectorAll('img')];
	const lent = new Set(
		pictures.filter((picture) => parts.some((part) => part.contains(picture))),
	);
	const copies = [...target.childNodes].map((node) => node.cloneNode(true));
	const drawn = copies.flatMap((node) => {
		if (node instanceof HTMLCanvasElement) {
			return [node];
		}
		return node instanceof Element ? [...node.querySelectorAll('canvas')] : [];
	});
	sources.forEach((source, index) => {
		drawCanvas(source, drawn[index]);
	});
	// A select's choice is not copied with it.
	const chosen = [...target.querySelectorAll('select')];
	const copied = copies.flatMap((node) => {
		if (node instanceof HTMLSelectElement) {
			return [node];
		}
		return node instanceof Element ? [...node.querySelectorAll('select')] : [];
	});
	chosen.forEach((select, index) => {
		const copy = copied[index];
		if (copy !== undefined) {
			copy.selectedIndex = select.selectedIndex;
		}
	});
	const pictureCopies = copies.flatMap((node) => {
		if (node instanceof HTMLImageElement) {
			return [node];
		}
		return node instanceof Element ? [...node.querySelectorAll('img')] : [];
	});
	for (const node of copies) {
		if (!(node instanceof HTMLElement)) {
			continue;
		}
		node.inert = true;
		node.setAttribute('aria-hidden', 'true');
		node.setAttribute(HELD_ATTRIBUTE, '');
		for (const element of [node, ...node.querySelectorAll('*')]) {
			unname(element);
		}
	}
	return {
		nodes: copies,
		keepPictures() {
			const put: HTMLImageElement[] = [];
			pictures.forEach((picture, index) => {
				const copy = pictureCopies[index];
				if (copy === undefined || lent.has(picture)) {
					return;
				}
				put.push(picture);
				unname(picture);
				// One at the copy's top is inert as each node there is.
				if (copy.inert) {
					picture.inert = true;
					picture.setAttribute('aria-hidden', 'true');
					picture.setAttribute(HELD_ATTRIBUTE, '');
				}
				copy.replaceWith(picture);
			});
			return put;
		},
	};
}

function unname(element: Element): void {
	for (const name of NAMING_ATTRIBUTES) {
		element.removeAttribute(name);
	}
}

/** Draws `source`'s pixels into its copy; one the browser will not read
 *  (a WebGL canvas without its buffer kept) stays as the copy was. */
function drawCanvas(
	source: HTMLCanvasElement,
	copy: HTMLCanvasElement | undefined,
): void {
	if (copy === undefined || source.width === 0 || source.height === 0) {
		return;
	}
	try {
		copy.width = source.width;
		copy.height = source.height;
		copy.getContext('2d')?.drawImage(source, 0, 0);
	} catch {
		// The copy shows the canvas empty, at its size.
	}
}
