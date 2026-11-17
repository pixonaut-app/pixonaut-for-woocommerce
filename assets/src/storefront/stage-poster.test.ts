// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { dropBrokenPosters, dropPosterUnlike } from './stage-poster';

/** A side's preview as the server prints it, with a poster of `template`
 *  in variant `variant`. */
function preview(template = 'poster@5', variant = 'v_50x70'): HTMLElement {
	const where =
		'data-pixonaut-blank="blank_poster" data-pixonaut-placement="front" data-pixonaut-view="view_wall"';
	document.body.innerHTML = `
		<div id="pixonaut-preview-default" class="pixonaut-preview__side">
			<span class="pixonaut-preview__placeholder" aria-hidden="true"><img class="pixonaut-preview__poster" src="https://shop.test/poster.webp" width="825" height="1024" alt="" data-pixonaut-template="${template}" ${where} data-pixonaut-variant="${variant}" /></span>
		</div>`;
	return document.getElementById('pixonaut-preview-default') as HTMLElement;
}

/** What the poster shows. */
const OPENED = {
	templateId: 'poster@5',
	blankId: 'blank_poster',
	placementId: 'front',
	variant: 'v_50x70',
};

const poster = () => document.querySelector('.pixonaut-preview__poster');

describe('dropPosterUnlike', () => {
	it('keeps the poster of the variation the page opened on', () => {
		const side = preview();

		dropPosterUnlike(side, OPENED);

		expect(poster()).not.toBeNull();
	});

	it('takes down the poster of another variant or template', () => {
		dropPosterUnlike(preview(), { ...OPENED, variant: 'v_30x40' });
		expect(poster()).toBeNull();

		dropPosterUnlike(preview(), { ...OPENED, templateId: 'card@1' });
		expect(poster()).toBeNull();
	});

	it('takes down the poster of another blank, placement or view', () => {
		dropPosterUnlike(preview(), { ...OPENED, blankId: 'blank_frame' });
		expect(poster()).toBeNull();

		dropPosterUnlike(preview(), { ...OPENED, placementId: 'back' });
		expect(poster()).toBeNull();

		dropPosterUnlike(preview(), { ...OPENED, initialView: 'view_flat' });
		expect(poster()).toBeNull();
	});

	it('keeps the poster of a side opening on the view it shows', () => {
		dropPosterUnlike(preview(), { ...OPENED, initialView: 'view_wall' });

		expect(poster()).not.toBeNull();
	});

	it('takes down a poster with a variant for a side shown in none', () => {
		dropPosterUnlike(preview(), { ...OPENED, variant: undefined });

		expect(poster()).toBeNull();
	});

	it('does nothing where the preview has no poster', () => {
		expect(() =>
			dropPosterUnlike(null, { templateId: 'poster@5' }),
		).not.toThrow();
	});
});

describe('dropBrokenPosters', () => {
	it('takes down a poster once it fails to load', () => {
		preview();
		const image = poster() as HTMLImageElement;
		Object.defineProperty(image, 'complete', { value: false });

		dropBrokenPosters(document);
		expect(poster()).not.toBeNull();
		image.dispatchEvent(new Event('error'));

		expect(poster()).toBeNull();
	});

	it('takes down a poster that already failed', () => {
		preview();
		const image = poster() as HTMLImageElement;
		Object.defineProperty(image, 'complete', { value: true });
		Object.defineProperty(image, 'naturalWidth', { value: 0 });

		dropBrokenPosters(document);

		expect(poster()).toBeNull();
	});
});
