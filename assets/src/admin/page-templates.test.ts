// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { clearPageTemplates, showPageTemplates } from './page-templates';
import { fillPlacements } from './product-panel';

/** A side whose placement is `placement`, its Page layouts field holding `layouts`. */
function side(placement: string, layouts: string[] = []): HTMLElement {
	const element = document.createElement('div');
	element.innerHTML = `
		<select class="pixonaut-side-placement"><option value=""></option></select>
		<p class="pixonaut-side-page-templates-field" hidden>
			<select class="pixonaut-side-page-templates" multiple>${layouts
				.map((id) => `<option value="${id}" selected>${id}</option>`)
				.join('')}</select>
		</p>`;
	const select = element.querySelector<HTMLSelectElement>(
		'.pixonaut-side-placement',
	);
	if (select) {
		fillPlacements(select, [
			{ id: 'cover', text: 'Cover' },
			{ id: 'pages', text: 'Pages', pages: true },
		]);
		select.value = placement;
	}
	return element;
}

function field(element: HTMLElement): HTMLElement | null {
	return element.querySelector('.pixonaut-side-page-templates-field');
}

describe('showPageTemplates', () => {
	it('shows the field on a placement printed as pages', () => {
		const pages = side('pages');

		showPageTemplates(pages);

		expect(field(pages)?.hidden).toBe(false);
	});

	it('hides it on any other placement', () => {
		const cover = side('cover');

		showPageTemplates(cover);

		expect(field(cover)?.hidden).toBe(true);
	});

	it('shows layouts kept on a placement no longer printed as pages, so they can be taken off', () => {
		const cover = side('cover', ['two-photos@2']);

		showPageTemplates(cover);

		expect(field(cover)?.hidden).toBe(false);

		clearPageTemplates(cover);

		expect(field(cover)?.hidden).toBe(true);
		expect(
			cover.querySelectorAll('.pixonaut-side-page-templates option'),
		).toHaveLength(0);
	});
});
