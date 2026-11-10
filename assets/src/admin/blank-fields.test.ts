// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	latestOnly,
	showBlankFailed,
	showBlankLoading,
	showBlankParts,
} from './blank-fields';

/** A panel showing blank A's variant pick and views, a view left disabled
 *  by the panel itself. */
function panel(): HTMLElement {
	const root = document.createElement('div');
	root.innerHTML = `
		<p class="pixonaut-blank-loading" hidden>
			<span class="pixonaut-blank-reading">Reading the blank from Pixonaut…</span>
			<span class="pixonaut-blank-failed" hidden>Pixonaut could not be reached to read this blank.</span>
		</p>
		<div class="pixonaut-blank-fields">
			<select name="pixonaut_variant"><option value="v_a" selected>A</option></select>
		</div>
		<div class="pixonaut-views-disclosure">
			<input type="checkbox" name="pixonaut_views[ticked][]" value="front" checked />
			<input type="checkbox" name="pixonaut_views[ticked][]" value="back" disabled />
		</div>`;
	return root;
}

function controls(root: HTMLElement): (HTMLInputElement | HTMLSelectElement)[] {
	return [
		...root.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
			'input, select',
		),
	];
}

function line(root: HTMLElement, part: string): HTMLElement | null {
	return root.querySelector<HTMLElement>(part);
}

describe('showBlankLoading', () => {
	it('disables the last blank’s controls, so the form does not post them', () => {
		const root = panel();

		showBlankLoading(root, true);

		expect(controls(root).every((control) => control.disabled)).toBe(true);
		expect(line(root, '.pixonaut-blank-loading')?.hidden).toBe(false);
	});

	it('gives back only what it disabled', () => {
		const root = panel();

		showBlankLoading(root, true);
		showBlankLoading(root, false);

		expect(controls(root).map((control) => control.disabled)).toEqual([
			false,
			false,
			true,
		]);
		expect(line(root, '.pixonaut-blank-loading')?.hidden).toBe(true);
	});

	it('leaves the new blank’s parts enabled once they are in place', () => {
		const root = panel();

		showBlankLoading(root, true);
		showBlankParts(root, {
			blank_html: '<select name="pixonaut_variant"></select>',
			views_html: '<input type="checkbox" name="pixonaut_views[ticked][]" />',
		});
		showBlankLoading(root, false);

		expect(controls(root).some((control) => control.disabled)).toBe(false);
	});
});

describe('showBlankParts', () => {
	const views = (open: boolean) =>
		`<details class="pixonaut-disclosure" data-disclosure="views"${open ? ' open' : ''}><summary>Views</summary></details>`;

	it('keeps the views’ section open when it was', () => {
		const root = panel();
		showBlankParts(root, { blank_html: '', views_html: views(true) });

		showBlankParts(root, { blank_html: '', views_html: views(false) });

		expect(root.querySelector('details')?.open).toBe(true);
	});

	it('leaves a closed section as the server drew it', () => {
		const root = panel();

		showBlankParts(root, { blank_html: '', views_html: views(false) });

		expect(root.querySelector('details')?.open).toBe(false);
	});
});

describe('showBlankFailed', () => {
	it('keeps the last blank’s controls disabled and says why', () => {
		const root = panel();

		showBlankLoading(root, true);
		showBlankFailed(root);

		expect(controls(root).every((control) => control.disabled)).toBe(true);
		expect(line(root, '.pixonaut-blank-failed')?.hidden).toBe(false);
		expect(line(root, '.pixonaut-blank-reading')?.hidden).toBe(true);
	});

	it('is replaced by the spinner when another blank is read', () => {
		const root = panel();

		showBlankFailed(root);
		showBlankLoading(root, true);

		expect(line(root, '.pixonaut-blank-failed')?.hidden).toBe(true);
		expect(line(root, '.pixonaut-blank-reading')?.hidden).toBe(false);
	});
});

describe('latestOnly', () => {
	it('takes only the latest read', () => {
		const reads = latestOnly();
		const first = reads.next();
		const second = reads.next();

		expect(reads.isLatest(first)).toBe(false);
		expect(reads.isLatest(second)).toBe(true);
	});

	it('drops every read once the blank is cleared', () => {
		const reads = latestOnly();
		const read = reads.next();

		reads.next();

		expect(reads.isLatest(read)).toBe(false);
	});
});
