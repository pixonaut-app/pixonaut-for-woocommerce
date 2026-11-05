import { describe, expect, it, vi } from 'vitest';
import { domReady } from './dom-ready';

function fakeDocument(readyState: DocumentReadyState) {
	const listeners: Array<() => void> = [];
	const doc = {
		readyState,
		addEventListener: (_type: string, listener: () => void) =>
			listeners.push(listener),
	} as unknown as Document;
	const fire = () => {
		for (const listener of listeners) {
			listener();
		}
	};
	return { doc, fire };
}

describe('domReady', () => {
	it('runs at once when the document is parsed', () => {
		const { doc } = fakeDocument('interactive');
		const callback = vi.fn();

		domReady(callback, doc);

		expect(callback).toHaveBeenCalledOnce();
	});

	it('waits for DOMContentLoaded while the document loads', () => {
		const { doc, fire } = fakeDocument('loading');
		const callback = vi.fn();

		domReady(callback, doc);
		expect(callback).not.toHaveBeenCalled();

		fire();
		expect(callback).toHaveBeenCalledOnce();
	});
});
