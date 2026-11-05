/**
 * Runs `callback` once the DOM is parsed: at once when it already is,
 * otherwise on `DOMContentLoaded`.
 */
export function domReady(callback: () => void, doc: Document = document): void {
	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', callback, { once: true });
		return;
	}
	callback();
}
