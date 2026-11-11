// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	blankSearch,
	catalogueSource,
	importFailedIn,
	importingIn,
	pickCatalogueBlank,
	searchesCatalogue,
	showImportFailed,
	showSourceHint,
} from './catalogue-pick';
import type { SearchUrls } from './catalogue-search';

const URLS: SearchUrls = {
	templates: '/templates',
	blanks: '/blanks',
	fittingTemplates: '/fitting',
	placements: '/placements',
	template: '/template',
};

/** A panel whose Printed on searches Gelato's catalogue or the blanks. */
function panel(): HTMLElement {
	const root = document.createElement('div');
	root.dataset.catalogueUrl = '/catalogue';
	root.dataset.catalogueBlankUrl = '/catalogue/blank';
	root.dataset.restNonce = 'nonce';
	root.dataset.importFailed = 'Pixonaut could not import this product.';
	root.innerHTML = `
		<select class="pixonaut-blank-source">
			<option value="ps_gelato" selected>Gelato’s catalogue</option>
			<option value="">Your blanks in Pixonaut</option>
		</select>
		<span class="pixonaut-catalogue-hint">Pick the product as the printshop sells it.</span>
		<p class="pixonaut-blank-import-failed" hidden><span role="alert"></span></p>`;
	return root;
}

function answer(status: number, body: unknown): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'Content-Type': 'application/json' },
	});
}

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('blankSearch', () => {
	it("searches the printshop's catalogue while one is chosen, else the blanks", () => {
		const root = panel();
		const source = catalogueSource(root);
		const search = blankSearch(root, URLS, source);

		expect(search('poster')).toEqual({
			url: '/catalogue',
			data: { q: 'poster', connection: 'ps_gelato' },
		});

		if (source) {
			source.value = '';
		}

		expect(searchesCatalogue(source)).toBe(false);
		expect(search('tee')).toEqual({ url: '/blanks', data: { q: 'tee' } });
	});

	it('searches the blanks in a workspace without a catalogue', () => {
		const root = document.createElement('div');

		expect(blankSearch(root, URLS, catalogueSource(root))('tee').url).toBe(
			'/blanks',
		);
	});
});

describe('showSourceHint', () => {
	it('shows the hint only while a catalogue is chosen', () => {
		const root = panel();
		const source = catalogueSource(root);
		if (!source) {
			throw new Error('no source field');
		}
		source.value = '';

		showSourceHint(root, source);

		expect(
			root.querySelector<HTMLElement>('.pixonaut-catalogue-hint')?.hidden,
		).toBe(true);
	});
});

describe('pickCatalogueBlank', () => {
	it('answers the blank Pixonaut has the product as', async () => {
		const fetch = vi.fn(async () =>
			answer(200, {
				id: 'blank_poster',
				text: 'Classic poster',
				importing: true,
			}),
		);
		vi.stubGlobal('fetch', fetch);

		const picked = await pickCatalogueBlank(
			panel(),
			'ps_gelato',
			'gelato_poster',
			'Classic poster',
		);

		expect(picked).toEqual({
			id: 'blank_poster',
			text: 'Classic poster',
			importing: true,
		});
		expect(fetch).toHaveBeenCalledWith(
			'/catalogue/blank',
			expect.objectContaining({
				method: 'POST',
				body: JSON.stringify({
					connection: 'ps_gelato',
					product: 'gelato_poster',
					name: 'Classic poster',
				}),
			}),
		);
	});

	it("answers the route's message when the product cannot be picked", async () => {
		vi.stubGlobal('fetch', async () =>
			answer(422, {
				code: 'pixonaut_wc_import_refused',
				message:
					'Pixonaut cannot print on this product: it has no print position.',
			}),
		);

		expect(
			await pickCatalogueBlank(
				panel(),
				'ps_gelato',
				'gelato_card',
				'Gift card',
			),
		).toEqual({
			error: 'Pixonaut cannot print on this product: it has no print position.',
		});
	});

	it('answers its own message when the plugin cannot be reached', async () => {
		vi.stubGlobal('fetch', async () => {
			throw new TypeError('offline');
		});

		expect(
			await pickCatalogueBlank(
				panel(),
				'ps_gelato',
				'gelato_poster',
				'Classic poster',
			),
		).toEqual({
			error: 'Pixonaut could not import this product.',
		});
	});
});

describe('showImportFailed', () => {
	it('shows the message, and hides it again for none', () => {
		const root = panel();
		const line = root.querySelector<HTMLElement>(
			'.pixonaut-blank-import-failed',
		);

		showImportFailed(root, 'Refused.');

		expect([line?.hidden, line?.textContent]).toEqual([false, 'Refused.']);

		showImportFailed(root, null);

		expect(line?.hidden).toBe(true);
	});
});

describe('importingIn', () => {
	it('tells a blank Pixonaut is still importing by its parts', () => {
		expect(
			importingIn('<p class="pixonaut-blank-importing" data-importing>'),
		).toBe(true);
		expect(importingIn('<p class="pixonaut-printshop-line">')).toBe(false);
	});
});

describe('importFailedIn', () => {
	it('tells a blank whose import failed by its parts', () => {
		expect(
			importFailedIn(
				'<p class="pixonaut-blank-import-failed-state" data-import-failed>',
			),
		).toBe(true);
		expect(
			importFailedIn('<p class="pixonaut-blank-importing" data-importing>'),
		).toBe(false);
	});
});
