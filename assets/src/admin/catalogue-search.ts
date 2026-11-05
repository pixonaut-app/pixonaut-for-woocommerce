// What the product screen's catalogue search fields ask, and where.

/** An option as the plugin's search routes answer it. */
export type SearchOption = {
	id: string;
	text: string;
	/** The picture shown beside its name; empty without one. */
	thumbnail?: string;
	/** A blank's placements. */
	placements?: { id: string; text: string; thumbnail?: string }[];
	/** Whether a template's version offers the customer a field, when Pixonaut says. */
	offersProperties?: boolean;
	/** Whether a template's background shows through, when Pixonaut says. */
	backgroundSeeThrough?: boolean;
	/** The sizes a template cannot be printed at on the placement, named under its name. */
	note?: string;
	/** The warning the side shows while the template is chosen; empty when it fits every size. */
	warning?: string;
};

/** Wait after the last keystroke before searching. */
export const SEARCH_DELAY_MS = 300;

export type SearchUrls = {
	templates: string;
	blanks: string;
	fittingTemplates: string;
	placements: string;
	template: string;
};

/**
 * Where the template field searches: the templates that fit the placement
 * once a blank and placement are chosen, else every live template.
 */
export function templateSearch(
	urls: SearchUrls,
	blank: string,
	placement: string,
	q: string,
): { url: string; data: Record<string, string> } {
	if (blank !== '' && placement !== '') {
		return { url: urls.fittingTemplates, data: { q, blank, placement } };
	}
	return { url: urls.templates, data: { q } };
}

/** The search URLs the panel carries in its `data-` attributes. */
export function searchUrls(panel: Pick<HTMLElement, 'dataset'>): SearchUrls {
	return {
		templates: panel.dataset.templatesUrl ?? '',
		blanks: panel.dataset.blanksUrl ?? '',
		fittingTemplates: panel.dataset.fittingTemplatesUrl ?? '',
		placements: panel.dataset.placementsUrl ?? '',
		template: panel.dataset.templateUrl ?? '',
	};
}

/** Builds the request for a search text from the fields' current values. */
export type SearchRequest = (q: string) => {
	url: string;
	data: Record<string, string>;
};

/** What selectWoo passes its `url` and `data` callbacks. */
export type SearchParams = { term?: string; page?: number };

/** An answer of the plugin's search routes. */
export type SearchAnswer = {
	results?: SearchOption[];
	pagination?: { more?: boolean };
};

/**
 * selectWoo's `ajax` options. selectWoo calls `url` before `data`, so each
 * builds its part from the fields as they are now, never from the last
 * request: switching between template-only and blank-plus-placement changes
 * the route on the very next search.
 */
export function ajaxOptions(request: SearchRequest, nonce: string) {
	return {
		delay: SEARCH_DELAY_MS,
		url: (params: SearchParams) => request(params.term ?? '').url,
		headers: { 'X-WP-Nonce': nonce },
		data: (params: SearchParams) => ({
			...request(params.term ?? '').data,
			page: String(params.page ?? 1),
		}),
		processResults: (answer: SearchAnswer) => ({
			results: answer.results ?? [],
			pagination: { more: answer.pagination?.more ?? false },
		}),
	};
}
