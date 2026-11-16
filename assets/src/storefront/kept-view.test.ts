import { describe, expect, it } from 'vitest';
import { shownViewOf } from './kept-view';

function designer(shown: string | undefined, views: string[], hidden = '') {
	return {
		getView: () => shown,
		getViews: () =>
			views.map((viewId) => ({ viewId, hasPhoto: viewId !== hidden })),
	} as unknown as Parameters<typeof shownViewOf>[0];
}

describe('shownViewOf', () => {
	it('names the view shown and its place among the views with a photo', () => {
		expect(
			shownViewOf(
				designer('scn_wall', ['scn_studio', 'gone', 'scn_wall'], 'gone'),
			),
		).toEqual({ viewId: 'scn_wall', index: 1 });
	});

	it('gives a view that is not listed with a photo no place', () => {
		expect(shownViewOf(designer('design', ['scn_studio', 'scn_wall']))).toEqual(
			{ viewId: 'design', index: -1 },
		);
	});

	it('names none for a designer that shows no view', () => {
		expect(shownViewOf(designer(undefined, []))).toBeUndefined();
	});
});
