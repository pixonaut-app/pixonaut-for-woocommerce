// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	DRAWN_ASPECT_PROPERTY,
	followDrawnShape,
	type ShapedDesigner,
} from './frame-shape';

/** A designer whose preview has `aspect`, and what reports another. */
function designerOf(aspect: number, shown: Promise<void> = Promise.resolve()) {
	const handlers: Array<(payload: { aspect: number }) => void> = [];
	const designer: ShapedDesigner = {
		shown: () => shown,
		getPreviewAspect: () => aspect,
		on: (_event, handler) => {
			handlers.push(handler);
		},
	};
	return {
		designer,
		reshape: (next: number) => {
			for (const handler of handlers) {
				handler({ aspect: next });
			}
		},
	};
}

const drawnAspect = (frame: HTMLElement): string =>
	frame.style.getPropertyValue(DRAWN_ASPECT_PROPERTY);

/** Lets the designer's `shown()` settle and what waits on it run. */
async function settled(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve));
}

describe('followDrawnShape', () => {
	it('gives the frame the shape of the first picture once it is shown, not before', async () => {
		const frame = document.createElement('div');
		let show: () => void = () => undefined;
		const shown = new Promise<void>((resolve) => {
			show = resolve;
		});
		const { designer } = designerOf(0.8, shown);

		followDrawnShape(frame, designer);
		await settled();
		expect(drawnAspect(frame)).toBe('');

		show();
		await settled();
		expect(drawnAspect(frame)).toBe('0.8');
	});

	it('follows a preview that takes another shape, and leaves one no different to look at', async () => {
		const frame = document.createElement('div');
		const { designer, reshape } = designerOf(0.8);

		followDrawnShape(frame, designer);
		await settled();

		reshape(0.805);
		expect(drawnAspect(frame)).toBe('0.8');
		reshape(1.25);
		expect(drawnAspect(frame)).toBe('1.25');
		reshape(Number.NaN);
		expect(drawnAspect(frame)).toBe('1.25');
	});

	it('keeps the printed shape for a preview that never shows', async () => {
		const frame = document.createElement('div');
		const { designer } = designerOf(0.8, Promise.reject(new Error('gone')));

		followDrawnShape(frame, designer);
		await settled();
		expect(drawnAspect(frame)).toBe('');
	});
});
