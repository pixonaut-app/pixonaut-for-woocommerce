/** On each thumbnail of the SDK's view strip: the id of the view it shows,
 *  the same on every blank that shows that scene. */
const VIEW_ID_ATTRIBUTE = 'data-view-id';

/**
 * Lends the thumbnails a side's last designer drew to the next one's strip
 * in `target`, where it has not drawn that view yet. The SDK draws each
 * thumbnail after the designer is shown, its picture left empty until
 * then; a side mounted again on another blank showing the same scenes would
 * blank its whole strip and draw it again. `held` are the last designer's
 * own pictures, which keep what they decoded though the SDK let go of
 * their URLs: each stands in, as a copy of its pixels, for the next strip's
 * picture of the same view until the SDK draws that one over it. A
 * thumbnail the SDK has drawn, or is loading, is left as it is.
 */
export function lendThumbnails(
	held: readonly HTMLImageElement[],
	target: HTMLElement,
): void {
	const byView = new Map<string, HTMLImageElement>();
	for (const picture of held) {
		const viewId = picture
			.closest(`[${VIEW_ID_ATTRIBUTE}]`)
			?.getAttribute(VIEW_ID_ATTRIBUTE);
		if (viewId !== null && viewId !== undefined && !byView.has(viewId)) {
			byView.set(viewId, picture);
		}
	}
	if (byView.size === 0) {
		return;
	}
	for (const thumbnail of target.querySelectorAll<HTMLElement>(
		`[${VIEW_ID_ATTRIBUTE}]`,
	)) {
		const picture = thumbnail.querySelector('img');
		const source = byView.get(thumbnail.getAttribute(VIEW_ID_ATTRIBUTE) ?? '');
		if (
			picture === null ||
			source === undefined ||
			picture.getAttribute('src') !== null
		) {
			continue;
		}
		const standIn = pixelsOf(source);
		if (standIn !== null) {
			picture.src = standIn;
		}
	}
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
