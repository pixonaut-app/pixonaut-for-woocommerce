// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { lendThumbnails } from './held-thumbnails';

/** What the stand-in canvas gives for a held picture's pixels. */
const PIXELS = 'data:image/png;base64,held';

/** A held thumbnail's decoded size, in pixels. */
const DECODED_PX = 120;

/** A view strip as the SDK draws it: one thumbnail per view id, each with
 *  its picture, `src` set where given. */
function strip(views: Record<string, string | null>): HTMLElement {
	const root = document.createElement('div');
	for (const [viewId, src] of Object.entries(views)) {
		const button = document.createElement('button');
		button.dataset.viewId = viewId;
		const picture = document.createElement('img');
		if (src !== null) {
			picture.src = src;
		}
		button.append(picture);
		root.append(button);
	}
	return root;
}

/** The last designer's strip, its pictures decoded. */
function held(viewIds: string[]): HTMLImageElement[] {
	const pictures = [
		...strip(
			Object.fromEntries(viewIds.map((id) => [id, `blob:old-${id}`])),
		).querySelectorAll('img'),
	];
	for (const picture of pictures) {
		Object.defineProperties(picture, {
			complete: { value: true },
			naturalWidth: { value: DECODED_PX, configurable: true },
			naturalHeight: { value: DECODED_PX },
		});
	}
	return pictures;
}

const pictureOf = (root: HTMLElement, viewId: string) =>
	root.querySelector<HTMLImageElement>(`[data-view-id="${viewId}"] img`);

describe('lendThumbnails', () => {
	const drawImage = vi.fn();

	beforeEach(() => {
		vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
			drawImage,
		} as unknown as CanvasRenderingContext2D);
		vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockReturnValue(PIXELS);
	});

	afterEach(() => {
		vi.restoreAllMocks();
		drawImage.mockClear();
	});

	it('shows the held picture of each view the next strip has not drawn yet', () => {
		const target = strip({ studio: null, nursery: null });
		const pictures = held(['studio', 'nursery']);

		lendThumbnails(pictures, target);

		expect(pictureOf(target, 'studio')?.getAttribute('src')).toBe(PIXELS);
		expect(pictureOf(target, 'nursery')?.getAttribute('src')).toBe(PIXELS);
		expect(drawImage).toHaveBeenCalledWith(pictures[0], 0, 0);
		expect(drawImage).toHaveBeenCalledWith(pictures[1], 0, 0);
	});

	it('leaves a thumbnail the SDK has drawn or is loading, and a view the last blank did not show', () => {
		const target = strip({ studio: 'blob:new-studio', hall: null });

		lendThumbnails(held(['studio', 'nursery']), target);

		expect(pictureOf(target, 'studio')?.getAttribute('src')).toBe(
			'blob:new-studio',
		);
		expect(pictureOf(target, 'hall')?.hasAttribute('src')).toBe(false);
		expect(drawImage).not.toHaveBeenCalled();
	});

	it('lends nothing from a held picture without pixels', () => {
		const target = strip({ studio: null });
		const [picture] = held(['studio']);
		Object.defineProperty(picture, 'naturalWidth', { value: 0 });

		lendThumbnails(picture === undefined ? [] : [picture], target);

		expect(pictureOf(target, 'studio')?.hasAttribute('src')).toBe(false);
	});

	it('lends nothing from a picture the browser will not read', () => {
		drawImage.mockImplementation(() => {
			throw new DOMException('tainted', 'SecurityError');
		});
		const target = strip({ studio: null });

		lendThumbnails(held(['studio']), target);

		expect(pictureOf(target, 'studio')?.hasAttribute('src')).toBe(false);
	});
});
