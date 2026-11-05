// The SDK draws a "Powered by Pixonaut" mark under the preview where the
// workspace shows it, as the fake's template read says, into the target the
// page prints under the preview, outside anything a theme draws around the
// preview; nothing of the plugin may hide or clip it.
import type { Locator } from '@playwright/test';
import { expect, restUrl, test } from '../fixtures';
import {
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

/** A small phone held upright, where the preview takes the screen's width. */
const PHONE = { width: 360, height: 640 };

const MARK = '[data-testid="pixonaut-powered-by"]';

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

/**
 * The part of $locator's box that no ancestor of it, up to the document,
 * cuts off with its overflow, against its whole box.
 */
async function unclipped(locator: Locator) {
	return locator.evaluate((element) => {
		const box = element.getBoundingClientRect();
		let { top, bottom, left, right } = box;
		for (
			let ancestor = element.parentElement;
			ancestor !== null && ancestor !== document.documentElement;
			ancestor = ancestor.parentElement
		) {
			const style = getComputedStyle(ancestor);
			if (style.overflowX === 'visible' && style.overflowY === 'visible') {
				continue;
			}
			const clip = ancestor.getBoundingClientRect();
			top = Math.max(top, clip.top);
			bottom = Math.min(bottom, clip.bottom);
			left = Math.max(left, clip.left);
			right = Math.min(right, clip.right);
		}
		return {
			whole: { width: box.width, height: box.height },
			shown: {
				width: Math.max(0, right - left),
				height: Math.max(0, bottom - top),
			},
		};
	});
}

test('on a phone, the plugin leaves the SDK mark under the preview whole', async ({
	page,
	request,
	theme,
}) => {
	await page.setViewportSize(PHONE);
	const permalink = await createProduct(
		request,
		`Free workspace ${theme} ${Date.now()}`,
		PERSONALISED,
	);
	await page.goto(permalink);
	const preview = page.locator('#pixonaut-preview-front');
	await expect(
		page.locator('.pixonaut-preview__frame[data-pixonaut-side="front"]'),
	).toHaveClass(/is-drawn/);
	const mark = page.locator(`#pixonaut-mark-front ${MARK}`);
	// The preview, not the mark: scrolling to the mark would scroll a clipping
	// box to it too, which no customer does.
	await preview.scrollIntoViewIfNeeded();

	await expect(mark).toBeVisible();
	await expect(mark).toBeInViewport({ ratio: 1 });
	const { whole, shown } = await unclipped(mark);
	expect(shown).toEqual(whole);
	await expect(preview.locator(MARK)).toHaveCount(0);
	const previewBox = await preview.boundingBox();
	const markBox = await mark.boundingBox();
	expect(
		markBox?.y ?? 0,
		'the mark is under the preview',
	).toBeGreaterThanOrEqual((previewBox?.y ?? 0) + (previewBox?.height ?? 0));
});
