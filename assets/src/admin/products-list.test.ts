// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';

const regenerateNow = vi.fn(async () => true);
vi.mock('./regenerate-now', () => ({
	ROOT_ID: 'pixonaut-regenerate-now',
	regenerateNow,
}));

const { initProductsList, NOW_ACTION } = await import('./products-list');

function list(action: string, checked: number[]): void {
	document.body.innerHTML = `
		<form id="posts-filter">
			<select id="bulk-action-selector-top" name="action">
				<option value="-1">Bulk actions</option>
				<option value="pixonaut_regenerate_pictures">Regenerate in background</option>
				<option value="${NOW_ACTION}">Regenerate now</option>
			</select>
			<input type="submit" id="doaction" value="Apply">
			${[7, 12]
				.map(
					(id) =>
						`<input type="checkbox" name="post[]" value="${id}"${checked.includes(id) ? ' checked' : ''}>
						<a href="edit.php?action=${NOW_ACTION}&post[]=${id}" class="pixonaut-regenerate-row" data-action="${NOW_ACTION}" data-product-id="${id}">Regenerate now</a>`,
				)
				.join('')}
		</form>
		<dialog id="pixonaut-regenerate-now"></dialog>`;
	const select = document.querySelector<HTMLSelectElement>('select');
	if (select) {
		select.value = action;
	}
	initProductsList(document);
}

function clickOn(selector: string): boolean {
	const event = new MouseEvent('click', { bubbles: true, cancelable: true });
	document.querySelector(selector)?.dispatchEvent(event);
	return event.defaultPrevented;
}

afterEach(() => {
	regenerateNow.mockClear();
	document.body.innerHTML = '';
});

describe('the products list', () => {
	it('runs Regenerate now in the page for the selected products', () => {
		list(NOW_ACTION, [7, 12]);

		expect(clickOn('#doaction')).toBe(true);
		expect(regenerateNow).toHaveBeenCalledWith(
			document,
			[7, 12],
			expect.anything(),
		);
	});

	it('leaves Regenerate in background, and a bulk action without products, to the server', () => {
		list('pixonaut_regenerate_pictures', [7]);
		expect(clickOn('#doaction')).toBe(false);

		list(NOW_ACTION, []);
		expect(clickOn('#doaction')).toBe(false);
		expect(regenerateNow).not.toHaveBeenCalled();
	});

	it('runs a row’s Regenerate now in the page for its product', () => {
		list('-1', []);

		expect(clickOn('.pixonaut-regenerate-row[data-product-id="12"]')).toBe(
			true,
		);
		expect(regenerateNow).toHaveBeenCalledWith(
			document,
			[12],
			expect.anything(),
		);
	});
});
