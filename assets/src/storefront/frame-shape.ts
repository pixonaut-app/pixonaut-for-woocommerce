/** The frame's ratio, as `width / height`, which the stylesheet sizes the
 *  side's box by. */
export const RATIO_PROPERTY = '--pixonaut-preview-ratio';

/** The same ratio as one number, for the stylesheet's arithmetic. */
export const ASPECT_PROPERTY = '--pixonaut-preview-aspect';

/** A change in shape too small to see: the SDK rounds its sizes to whole
 *  pixels. */
const ASPECT_TOLERANCE = 0.01;

/** How long each painted frame is checked for the new size before its
 *  image's load event: well past a slow phone's drawing. */
const PAINT_WATCH_MS = 5000;

/** The shape a preview's frame is drawn in: what it shows (`template`, or
 *  none for a photo), and its ratio as the stylesheet reads it. */
export interface FrameShape {
	shape: string | undefined;
	ratio: string;
	aspect: string;
}

/** The shape the frame is drawn in now. */
export function shapeOf(frame: HTMLElement): FrameShape {
	return {
		shape: frame.dataset.pixonautShape,
		ratio: frame.style.getPropertyValue(RATIO_PROPERTY),
		aspect: frame.style.getPropertyValue(ASPECT_PROPERTY),
	};
}

/** Draws the frame in `shape`. */
export function applyShape(frame: HTMLElement, shape: FrameShape): void {
	if (shape.shape === undefined) {
		delete frame.dataset.pixonautShape;
	} else {
		frame.dataset.pixonautShape = shape.shape;
	}
	frame.style.setProperty(RATIO_PROPERTY, shape.ratio);
	frame.style.setProperty(ASPECT_PROPERTY, shape.aspect);
}

/**
 * Draws the frame in `shape` once the SDK has drawn the preview in it, for
 * a print size switched in place: until then the preview still shows the
 * old size, and a frame of the new shape would stretch it. A shape no
 * different to look at is drawn at once.
 *
 * The SDK draws the new size into its preview `<img>` and fits the preview
 * to its box when that image loads. The frame changes in the same event,
 * heard on the way down to the image, so the SDK fits the new picture to
 * the new box and both show in one paint; or before a frame is painted,
 * where the browser shows the image before telling it loaded. An image
 * shows the new size when its shape is nearer the new shape than the one
 * it had at the switch (the frame's, for one not drawn then): another
 * drawing of the old size, or of a size switched from since, still landing,
 * leaves the frame as it is. So does a drawing that fails.
 *
 * Returns what stops waiting, for a switch made before this one is drawn.
 */
export function shapeOnceDrawn(
	preview: HTMLElement,
	frame: HTMLElement,
	shape: FrameShape,
): () => void {
	const now = Number.parseFloat(frame.style.getPropertyValue(ASPECT_PROPERTY));
	const next = Number.parseFloat(shape.aspect);
	if (
		!Number.isFinite(now) ||
		!Number.isFinite(next) ||
		Math.abs(now - next) <= ASPECT_TOLERANCE
	) {
		applyShape(frame, shape);
		return () => undefined;
	}
	const before = new WeakMap<HTMLImageElement, number>();
	for (const image of preview.querySelectorAll('img')) {
		const aspect = aspectOf(image);
		if (aspect !== undefined) {
			before.set(image, aspect);
		}
	}
	/** Whether `image` shows the new size. */
	const isNewSize = (image: HTMLImageElement): boolean => {
		const aspect = aspectOf(image);
		const old = before.get(image) ?? now;
		return (
			aspect !== undefined && Math.abs(aspect - next) < Math.abs(aspect - old)
		);
	};
	const win = preview.ownerDocument.defaultView;
	let frameRequest: number | undefined;
	const stopPainting = (): void => {
		if (frameRequest !== undefined) {
			win?.cancelAnimationFrame(frameRequest);
			frameRequest = undefined;
		}
	};
	// A drawing that never lands stops the check; its load would still
	// reshape the frame.
	const paintingLimit = win?.setTimeout(stopPainting, PAINT_WATCH_MS);
	const stop = (): void => {
		preview.removeEventListener('load', loaded, true);
		stopPainting();
		win?.clearTimeout(paintingLimit);
	};
	const reshape = (): void => {
		stop();
		applyShape(frame, shape);
	};
	function loaded(event: Event): void {
		if (event.target instanceof HTMLImageElement && isNewSize(event.target)) {
			reshape();
		}
	}
	// A browser may paint an image before its load event: each frame about
	// to be painted is checked too.
	const painting = (): void => {
		if (Array.from(preview.querySelectorAll('img')).some(isNewSize)) {
			reshape();
			return;
		}
		frameRequest = win?.requestAnimationFrame(painting);
	};
	// Heard while capturing: before the SDK's own listener on the image
	// fits the preview to the frame.
	preview.addEventListener('load', loaded, true);
	frameRequest = win?.requestAnimationFrame(painting);
	return stop;
}

/** The image's width per unit of height; undefined before it has a
 *  picture. */
function aspectOf(image: HTMLImageElement): number | undefined {
	return image.naturalWidth > 0 && image.naturalHeight > 0
		? image.naturalWidth / image.naturalHeight
		: undefined;
}
