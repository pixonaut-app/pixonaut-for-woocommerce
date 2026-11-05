import type { Locator, Page } from '@playwright/test';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, test } from '../fixtures';
import {
	addToCartButton,
	connectFixtureStore,
	createProduct,
	PERSONALISED,
} from '../personalised';

// The theme's own rules on inputs, buttons, labels and selects reach the
// designer, which has no shadow DOM: each project's theme lays it out as the
// shop would. Only the layout is checked here; what the form does is the
// other specs' job.

/** The viewports a shop's customers use: a laptop and a phone. */
const VIEWPORTS = {
	desktop: { width: 1280, height: 800 },
	phone: { width: 390, height: 844 },
};

/** How far, in CSS pixels, two boxes may overlap and still sit apart: the
 *  SDK and the themes round their own sizes. */
const ROUNDING_PX = 2;

/** The smallest a label or control can be and still be read: a line of
 *  the SDK's small text, as its Reset link is. */
const MIN_CONTROL_PX = 12;

/** mini-tee on the tee's front and back, with the approval box: the tabs and
 *  the approval box are the plugin's own markup beside the SDK's form. */
const TWO_SIDES_APPROVED = {
	...PERSONALISED,
	approvalMode: 'checkbox',
	sides: [
		...PERSONALISED.sides,
		{
			key: 'back',
			label: 'Back',
			placement: { id: FIXTURE.placement.back, name: 'Back' },
			template: PERSONALISED.sides[0]?.template,
			printSizes: [],
		},
	],
};

const FRONT_FRAME = '.pixonaut-preview__frame[data-pixonaut-side="front"]';

const FRONT_FORM = '#pixonaut-form-front [data-testid="pixonaut-form"]';

/** WCAG 2.2's minimum target size, for the plugin's own buttons: a theme
 *  that leaves a bare button to the browser draws it smaller. */
const MIN_TARGET_PX = 24;

type Box = { x: number; y: number; width: number; height: number };

test.beforeEach(async ({ request, baseURL }) => {
	await connectFixtureStore(request, baseURL ?? '');
});

test.afterEach(async ({ request }) => {
	await request.delete(restUrl('/pixonaut-e2e/v1/connection'));
});

async function box(locator: Locator, name: string): Promise<Box> {
	const found = await locator.boundingBox();
	expect(found, `${name} is laid out`).not.toBeNull();
	return found ?? { x: 0, y: 0, width: 0, height: 0 };
}

function overlaps(a: Box, b: Box): boolean {
	return (
		a.x + a.width - ROUNDING_PX > b.x &&
		b.x + b.width - ROUNDING_PX > a.x &&
		a.y + a.height - ROUNDING_PX > b.y &&
		b.y + b.height - ROUNDING_PX > a.y
	);
}

function inside(inner: Box, outer: Box): boolean {
	return (
		inner.x >= outer.x - ROUNDING_PX &&
		inner.x + inner.width <= outer.x + outer.width + ROUNDING_PX
	);
}

/** A visible label or control of a form, with its box. `id` is its place
 *  among the form's labels and controls; `label` is the `id` of the label
 *  holding it, as the approval box's label holds the box. */
type Control = { id: number; label: number | null; name: string; box: Box };

/** Every visible label and control of a form. */
async function controls(form: Locator): Promise<Control[]> {
	return form
		.locator('label, input:not([type="hidden"]), select, textarea, button')
		.evaluateAll((all) =>
			all.flatMap((element, id) => {
				const rect = element.getBoundingClientRect();
				const style = getComputedStyle(element);
				if (
					rect.width === 0 ||
					rect.height === 0 ||
					style.visibility === 'hidden' ||
					// A checkbox or radio the SDK hides behind its own swatch.
					style.opacity === '0'
				) {
					return [];
				}
				const name =
					`${element.tagName.toLowerCase()}${element.getAttribute('type') ? `[type=${element.getAttribute('type')}]` : ''} ${(element.textContent ?? '').trim().slice(0, 30) || element.getAttribute('name') || element.getAttribute('aria-label') || ''}`.trim();
				const holder = element.parentElement?.closest('label');
				const label = holder ? all.indexOf(holder) : -1;
				return [
					{
						id,
						label: label === -1 ? null : label,
						name,
						box: {
							x: rect.x + window.scrollX,
							y: rect.y + window.scrollY,
							width: rect.width,
							height: rect.height,
						},
					},
				];
			}),
		);
}

/** Opens the two-sided product at `viewport`, with the designer drawn. */
async function openDesigner(
	page: Page,
	request: Parameters<typeof createProduct>[0],
	theme: string,
	viewport: { width: number; height: number },
): Promise<void> {
	await page.setViewportSize(viewport);
	const permalink = await createProduct(
		request,
		`Theme layout ${theme} ${viewport.width} ${Date.now()}`,
		TWO_SIDES_APPROVED,
	);
	await page.goto(permalink);
	await expect(page.locator(FRONT_FRAME)).toHaveClass(/is-drawn/);
	await expect(page.locator(FRONT_FORM)).toBeVisible();
	await page.evaluate(() => document.fonts.ready);
}

for (const [device, viewport] of Object.entries(VIEWPORTS)) {
	test(`the designer keeps its layout under the theme on a ${device}`, async ({
		page,
		request,
		theme,
	}, testInfo) => {
		await openDesigner(page, request, theme, viewport);
		await testInfo.attach(`${theme}-${device}`, {
			body: await page.locator('main').first().screenshot(),
			contentType: 'image/png',
		});

		// Nothing pushes the page wider than the screen.
		const widths = await page.evaluate(() => ({
			page: document.documentElement.scrollWidth,
			screen: document.documentElement.clientWidth,
		}));
		expect(
			widths.page,
			'the page is no wider than the screen',
		).toBeLessThanOrEqual(widths.screen);

		// The preview and the form sit beside or under each other, never on top.
		const preview = await box(
			page.locator('#pixonaut-preview-front'),
			'the preview',
		);
		const form = await box(page.locator('.pixonaut-form'), 'the form');
		expect(overlaps(preview, form), 'the preview and the form overlap').toBe(
			false,
		);
		if (device === 'phone') {
			expect(form.y, 'the form is under the preview').toBeGreaterThanOrEqual(
				preview.y + preview.height - ROUNDING_PX,
			);
		}

		// Each label and control is in the form's column, big enough to use,
		// and clear of the others.
		const fields = await controls(page.locator('.pixonaut-form'));
		expect(fields.length, 'the form shows its fields').toBeGreaterThan(0);
		for (const field of fields) {
			expect
				.soft(
					inside(field.box, form),
					`${field.name} stays in the form's column`,
				)
				.toBe(true);
			expect
				.soft(
					Math.min(field.box.width, field.box.height),
					`${field.name} is big enough to use`,
				)
				.toBeGreaterThanOrEqual(MIN_CONTROL_PX);
		}
		for (const [i, a] of fields.entries()) {
			for (const b of fields.slice(i + 1)) {
				// A label holds its own control, as the approval box does.
				const nested = a.label === b.id || b.label === a.id;
				if (!nested) {
					expect
						.soft(overlaps(a.box, b.box), `${a.name} overlaps ${b.name}`)
						.toBe(false);
				}
			}
		}

		// The side tabs sit in one row, the same height.
		const tabs = page.locator('.pixonaut-tabs__tab');
		await expect(tabs).toHaveCount(2);
		const front = await box(tabs.nth(0), 'the Front tab');
		const back = await box(tabs.nth(1), 'the Back tab');
		expect(
			Math.abs(front.y - back.y),
			'the tabs share a row',
		).toBeLessThanOrEqual(ROUNDING_PX);
		expect(
			Math.abs(front.height - back.height),
			'the tabs are the same height',
		).toBeLessThanOrEqual(ROUNDING_PX);
		for (const [name, target] of Object.entries({ front, back })) {
			expect
				.soft(target.height, `the ${name} button is big enough to tap`)
				.toBeGreaterThanOrEqual(MIN_TARGET_PX);
		}

		// The approval box leads its label's first line, rather than taking a
		// line of its own or the whole width as a theme's input rule can.
		const tick = await box(
			page.locator('.pixonaut-approval__box'),
			'the approval box',
		);
		const label = await box(
			page.locator('.pixonaut-approval__label'),
			'the approval label',
		);
		expect(
			tick.y + tick.height / 2,
			'the approval box is on its label line',
		).toBeLessThanOrEqual(label.y + label.height);
		expect(
			tick.x + tick.width,
			'the approval box leads its label',
		).toBeLessThan(label.x + label.width / 2);
		// The word that opens the proof is in the label's sentence, where a
		// link needs no bigger target (WCAG 2.2's inline exception).
		const proof = await box(
			page.locator(
				'.pixonaut-approval__label .pixonaut-approval__proof:visible',
			),
			'the proof link',
		);
		expect(inside(proof, label), 'the proof link is in the label').toBe(true);
		expect(proof.x, 'the proof link follows the approval box').toBeGreaterThan(
			tick.x + tick.width,
		);
		await expect(addToCartButton(page)).toBeVisible();
	});
}
