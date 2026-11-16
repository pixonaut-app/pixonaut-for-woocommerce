import { describe, expect, it } from 'vitest';
import { shownViewOf, viewStandingFor } from './kept-view';

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

	it('names none for a designer that shows no view', () => {
		expect(shownViewOf(designer(undefined, []))).toBeUndefined();
	});
});

describe('viewStandingFor', () => {
	const kept = { viewId: 'scn_wall', index: 1 };

	it('is the view of the same id, wherever the next blank lists it', () => {
		expect(
			viewStandingFor(designer('scn_wall', ['scn_wall', 'scn_studio']), kept),
		).toBe('scn_wall');
	});

	it('is the view at the same place when the next blank lacks the id', () => {
		expect(
			viewStandingFor(designer('own_a', ['own_a', 'own_b', 'own_c']), kept),
		).toBe('own_b');
	});

	it('counts only the views with a photo for the place', () => {
		expect(
			viewStandingFor(
				designer('own_a', ['own_a', 'gone', 'own_c'], 'gone'),
				kept,
			),
		).toBe('own_c');
	});

	it('is none when the next blank has neither, so its first view stays', () => {
		expect(viewStandingFor(designer('own_a', ['own_a']), kept)).toBeUndefined();
	});
});
