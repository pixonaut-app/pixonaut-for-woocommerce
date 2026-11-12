/** Attributes a still copy of the designer drops, so it names nothing the
 *  page or the form reads: no duplicate id, nothing submitted, no printed
 *  field the SDK would take as the customer's. */
const NAMING_ATTRIBUTES = ['id', 'name', 'form', 'for', 'data-pixonaut-field'];

/** What a side's designer shows, held in place while it mounts again. */
export type RemountHold = {
	/** Puts the copy in the emptied targets once the designer is destroyed,
	 *  and hides the form's parts it gave back until the next one takes
	 *  them. */
	place(): void;
	/** Lets go: the parts show again, the boxes size to what they hold, and
	 *  a copy the next designer did not replace (its mount failed) goes. */
	release(): void;
};

/**
 * Copies what a side's designer shows in `targets` (its preview, form and
 * view strip) before it is destroyed and mounted again, so the page keeps
 * its layout and its picture while the next one mounts: a split mount
 * keeps what the host printed in its targets until its preview is drawn,
 * as it does for the server's printed form. The copy is inert, with each
 * canvas's pixels drawn into its own, and each target keeps at least the
 * height it had. `sized` keep their height alone (the "Powered by" mark,
 * which the SDK draws beside what it finds), and
 * `parts`, the add-to-cart form's own elements the designer drew in its
 * form, are shown in the copy and hidden where they go back to meanwhile.
 */
export function holdShown(
	targets: readonly HTMLElement[],
	sized: readonly HTMLElement[],
	parts: readonly HTMLElement[],
): RemountHold {
	const copies = targets.map((target) => [target, copyOf(target)] as const);
	// Every box keeps at least its height too: the copy can lay out shorter
	// than the designer it copies, which styles its own parts as it draws.
	const heights = [...targets, ...sized].map(
		(element) => [element, element.getBoundingClientRect().height] as const,
	);
	const shown = new Set(
		parts.filter((part) => targets.some((target) => target.contains(part))),
	);
	const displays = new Map<HTMLElement, string>();
	const placed: Node[] = [];
	return {
		place() {
			for (const [target, copy] of copies) {
				placed.push(...copy);
				target.replaceChildren(...copy);
			}
			for (const [element, height] of heights) {
				element.style.minHeight = `${height}px`;
			}
			for (const part of shown) {
				displays.set(part, part.style.display);
				part.style.display = 'none';
			}
		},
		release() {
			for (const [part, display] of displays) {
				part.style.display = display;
			}
			displays.clear();
			for (const [element] of heights) {
				element.style.removeProperty('min-height');
			}
			for (const node of placed.splice(0)) {
				node.parentNode?.removeChild(node);
			}
		},
	};
}

/** Inert copies of the target's content, its canvases drawn in. */
function copyOf(target: HTMLElement): Node[] {
	const sources = [...target.querySelectorAll('canvas')];
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
	for (const node of copies) {
		if (!(node instanceof HTMLElement)) {
			continue;
		}
		node.inert = true;
		node.setAttribute('aria-hidden', 'true');
		for (const element of [node, ...node.querySelectorAll('*')]) {
			for (const name of NAMING_ATTRIBUTES) {
				element.removeAttribute(name);
			}
		}
	}
	return copies;
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
