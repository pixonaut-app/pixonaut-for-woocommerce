// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	initDisclosures,
	showSideSummary,
	showStoreSummary,
	showViewsSummary,
} from './disclosures';

function panel(markup: string): HTMLElement {
	const root = document.createElement('div');
	root.innerHTML = markup;
	document.body.replaceChildren(root);
	return root;
}

describe('showStoreSummary', () => {
	const markup = (approval: string) => `
		<details class="pixonaut-disclosure pixonaut-store-settings" data-disclosure="store">
			<summary><span class="pixonaut-disclosure__summary" data-none="Follows the store's settings" data-some="%d changed for this product"></span></summary>
			<select class="pixonaut-store-setting"><option value="" selected></option></select>
			<select class="pixonaut-store-setting"><option value=""></option><option value="email" ${approval}></option></select>
		</details>`;

	it('says the product follows the store while nothing is changed', () => {
		const root = panel(markup(''));
		showStoreSummary(root);
		expect(
			root.querySelector('.pixonaut-disclosure__summary')?.textContent,
		).toBe("Follows the store's settings");
	});

	it('counts the settings the product changes', () => {
		const root = panel(markup('selected'));
		showStoreSummary(root);
		expect(
			root.querySelector('.pixonaut-disclosure__summary')?.textContent,
		).toBe('1 changed for this product');
	});
});

describe('showViewsSummary', () => {
	it('says how many of the views are ticked, or every view', () => {
		const root = panel(`
			<details class="pixonaut-disclosure pixonaut-views-section">
				<summary><span class="pixonaut-disclosure__summary" data-none="Every view" data-some="%1$d of %2$d views" data-all="3"></span></summary>
				<ul class="pixonaut-views__list"><li><input type="checkbox" /></li><li><input type="checkbox" /></li></ul>
			</details>`);
		showViewsSummary(root);
		const summary = root.querySelector('.pixonaut-disclosure__summary');
		expect(summary?.textContent).toBe('Every view');

		root.querySelector<HTMLInputElement>('input')?.click();
		showViewsSummary(root);
		expect(summary?.textContent).toBe('1 of 3 views');
	});
});

describe('showSideSummary', () => {
	it('says how the side is filled in and which version it follows', () => {
		const root = panel(`
			<div class="pixonaut-side">
				<span class="pixonaut-side-more__summary" data-fills="Customers fill in" data-fixed="Fixed design" data-follow="follows new template versions" data-stays="stays on version %d"></span>
				<input type="checkbox" class="pixonaut-side-mode" checked />
				<input type="checkbox" class="pixonaut-side-pinned" />
				<select class="pixonaut-side-version"><option value="2" selected></option></select>
			</div>`);
		const side = root.querySelector<HTMLElement>('.pixonaut-side');
		if (!side) {
			throw new Error('no side');
		}
		const summary = side.querySelector('.pixonaut-side-more__summary');

		showSideSummary(side);
		expect(summary?.textContent).toBe(
			'Customers fill in · follows new template versions',
		);

		side.querySelector<HTMLInputElement>('.pixonaut-side-mode')?.click();
		side.querySelector<HTMLInputElement>('.pixonaut-side-pinned')?.click();
		showSideSummary(side);
		expect(summary?.textContent).toBe('Fixed design · stays on version 2');
	});
});

describe('initDisclosures', () => {
	it('posts the sections open, so they open again after the update', () => {
		const root = panel(`
			<input class="pixonaut-open-disclosures" value="" />
			<details class="pixonaut-disclosure" data-disclosure="store" open></details>
			<details class="pixonaut-disclosure" data-disclosure="side-0"></details>`);
		initDisclosures(root);
		const field = root.querySelector<HTMLInputElement>(
			'.pixonaut-open-disclosures',
		);
		expect(field?.value).toBe('store');

		const side = root.querySelector<HTMLDetailsElement>(
			'[data-disclosure="side-0"]',
		);
		if (side) {
			side.open = true;
			side.dispatchEvent(new Event('toggle'));
		}
		expect(field?.value).toBe('store,side-0');
	});
});
