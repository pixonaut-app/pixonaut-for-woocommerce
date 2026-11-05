import { describe, expect, it } from 'vitest';
import { ajaxOptions, searchUrls, templateSearch } from './catalogue-search';

const URLS = {
	templates: '/wp-json/pixonaut/v1/admin/search/templates',
	blanks: '/wp-json/pixonaut/v1/admin/search/blanks',
	fittingTemplates: '/wp-json/pixonaut/v1/admin/search/fitting-templates',
	placements: '/wp-json/pixonaut/v1/admin/search/placements',
	template: '/wp-json/pixonaut/v1/admin/search/template',
};

describe('templateSearch', () => {
	it('searches every live template without a blank', () => {
		expect(templateSearch(URLS, '', '', 'tee')).toEqual({
			url: URLS.templates,
			data: { q: 'tee' },
		});
	});

	it('searches every live template until a placement is chosen', () => {
		expect(templateSearch(URLS, 'blank_tee', '', '')).toEqual({
			url: URLS.templates,
			data: { q: '' },
		});
	});

	it('searches the templates that fit the blank and placement', () => {
		expect(templateSearch(URLS, 'blank_tee', 'front', 'tee')).toEqual({
			url: URLS.fittingTemplates,
			data: { q: 'tee', blank: 'blank_tee', placement: 'front' },
		});
	});
});

describe('searchUrls', () => {
	it("reads the panel's data attributes", () => {
		const panel = {
			dataset: {
				templatesUrl: URLS.templates,
				blanksUrl: URLS.blanks,
				fittingTemplatesUrl: URLS.fittingTemplates,
				placementsUrl: URLS.placements,
				templateUrl: URLS.template,
			},
		};

		expect(searchUrls(panel)).toEqual(URLS);
	});

	it('answers empty URLs for a panel without them', () => {
		expect(searchUrls({ dataset: {} })).toEqual({
			templates: '',
			blanks: '',
			fittingTemplates: '',
			placements: '',
			template: '',
		});
	});
});

describe('ajaxOptions', () => {
	const fields = { blank: '', placement: '' };
	const options = ajaxOptions(
		(q) => templateSearch(URLS, fields.blank, fields.placement, q),
		'nonce',
	);

	/** One search as selectWoo runs it: `url` first, then `data`. */
	function search(params: { term?: string; page?: number }) {
		return { url: options.url(params), data: options.data(params) };
	}

	it('switches route on the first search after the placement changes', () => {
		search({ term: 'tee' });

		fields.blank = 'blank_tee';
		fields.placement = 'front';
		expect(search({})).toEqual({
			url: URLS.fittingTemplates,
			data: { q: '', blank: 'blank_tee', placement: 'front', page: '1' },
		});

		fields.blank = '';
		fields.placement = '';
		expect(search({ page: 2 })).toEqual({
			url: URLS.templates,
			data: { q: '', page: '2' },
		});
	});

	it('sends the nonce and reads the pagination', () => {
		expect(options.headers).toEqual({ 'X-WP-Nonce': 'nonce' });
		expect(
			options.processResults({
				results: [{ id: 'mini-tee@2', text: 'Mini tee' }],
				pagination: { more: true },
			}),
		).toEqual({
			results: [{ id: 'mini-tee@2', text: 'Mini tee' }],
			pagination: { more: true },
		});
		expect(options.processResults({})).toEqual({
			results: [],
			pagination: { more: false },
		});
	});
});
