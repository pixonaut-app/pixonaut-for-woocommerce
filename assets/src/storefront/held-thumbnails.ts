/** On each thumbnail of the SDK's view strip: the id of the view it shows,
 *  the same on every blank that shows that scene. */
const VIEW_ID_ATTRIBUTE = 'data-view-id';

/** Lends a side's held thumbnails to the next designer's strip. */
export type ThumbnailLender = {
	/** Shows, in each thumbnail of the strip in `target` the SDK has not
	 *  drawn yet, the held picture of the same view. */
	lend(target: HTMLElement): void;
};

/**
 * Lends the thumbnails a side's last designer drew to the next one's strip,
 * where it has not drawn that view yet. The SDK draws each thumbnail after
 * the designer is shown, its picture left empty until then; a side mounted
 * again on another blank showing the same scenes would blank its whole
 * strip and draw it again. `held` are the last designer's own pictures,
 * which keep what they decoded though the SDK let go of their URLs: each
 * stands in, as a copy of its pixels, for the next strip's picture of the
 * same view until the SDK draws that one over it. A thumbnail the SDK has
 * drawn, or is loading, is left as it is.
 *
 * Each copy is made, and decoded, here, ahead of the next strip: a picture
 * given a URL the page has already decoded shows it in the frame it is
 * given it, where a new one would show nothing until it decodes.
 */
export function thumbnailLender(
	held: readonly HTMLImageElement[],
): ThumbnailLender {
	const byView = new Map<string, string>();
	// Kept while the lender is, so the browser keeps each copy decoded.
	const decoded: HTMLImageElement[] = [];
	const targets = new Set<HTMLElement>();
	const lendTo = (target: HTMLElement): void => {
		for (const thumbnail of target.querySelectorAll<HTMLElement>(
			`[${VIEW_ID_ATTRIBUTE}]`,
		)) {
			const picture = thumbnail.querySelector('img');
			const standIn = byView.get(
				thumbnail.getAttribute(VIEW_ID_ATTRIBUTE) ?? '',
			);
			if (
				picture !== null &&
				standIn !== undefined &&
				picture.getAttribute('src') === null
			) {
				picture.src = standIn;
			}
		}
	};
	const keep = (viewId: string, picture: HTMLImageElement): void => {
		const standIn = pixelsOf(picture);
		if (standIn === null || byView.has(viewId)) {
			return;
		}
		byView.set(viewId, standIn);
		const copy = document.createElement('img');
		copy.src = standIn;
		decoded.push(copy);
	};
	const seen = new Set<string>();
	for (const picture of held) {
		const viewId = picture
			.closest(`[${VIEW_ID_ATTRIBUTE}]`)
			?.getAttribute(VIEW_ID_ATTRIBUTE);
		if (viewId === null || viewId === undefined || seen.has(viewId)) {
			continue;
		}
		seen.add(viewId);
		if (picture.complete) {
			keep(viewId, picture);
			continue;
		}
		// Drawn again just before the hold, the SDK's next picture of the
		// view still loading: it lends that one once it has loaded.
		picture.addEventListener(
			'load',
			() => {
				keep(viewId, picture);
				for (const target of targets) {
					lendTo(target);
				}
			},
			{ once: true },
		);
	}
	return {
		lend(target) {
			targets.add(target);
			lendTo(target);
		},
	};
}

/** A data URL of the picture's decoded pixels; null for one with none, or
 *  one the browser will not read. */
function pixelsOf(picture: HTMLImageElement): string | null {
	const { naturalWidth: width, naturalHeight: height } = picture;
	if (!picture.complete || width === 0 || height === 0) {
		return null;
	}
	const canvas = document.createElement('canvas');
	canvas.width = width;
	canvas.height = height;
	try {
		const context = canvas.getContext('2d');
		if (context === null) {
			return null;
		}
		context.drawImage(picture, 0, 0);
		return canvas.toDataURL();
	} catch {
		// A picture from another origin taints the canvas.
		return null;
	}
}
