// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { holdShown } from './remount-hold';

/** A preview and a form as a designer draws them, a part of the
 *  add-to-cart form lent to the form, and a mark beside them. */
function drawn() {
	document.body.innerHTML = `
		<form id="cart">
			<div id="pixonaut-preview-front"><div class="stage" id="stage"><canvas width="4" height="4"></canvas></div></div>
			<div id="pixonaut-form-front"><label for="f1">Name</label><input id="f1" name="pixonaut_name" data-pixonaut-field="name:content" value="Ada"><div id="row"><button name="add-to-cart">Add</button></div></div>
			<div id="home"></div>
			<div id="pixonaut-mark-front"></div>
		</form>`;
	const byId = (id: string) => document.getElementById(id) as HTMLElement;
	return {
		preview: byId('pixonaut-preview-front'),
		form: byId('pixonaut-form-front'),
		row: byId('row'),
		home: byId('home'),
		mark: byId('pixonaut-mark-front'),
	};
}

describe('holdShown', () => {
	beforeEach(() => {
		// jsdom draws no canvas: the copy keeps the source's size alone.
		vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
	});

	it('shows an inert copy that names nothing in the emptied targets, and the lent part hidden at home', () => {
		const { preview, form, row, home, mark } = drawn();
		const hold = holdShown([preview, form], [mark], [row]);
		// The designer is destroyed: it empties its targets and gives the
		// part back.
		preview.replaceChildren();
		form.replaceChildren();
		home.append(row);

		hold.place();

		const copy = form.firstElementChild as HTMLElement;
		expect(copy.inert).toBe(true);
		expect(copy.getAttribute('aria-hidden')).toBe('true');
		expect(form.querySelector('input')?.value).toBe('Ada');
		expect(
			form.querySelectorAll('[id], [name], [for], [data-pixonaut-field]'),
		).toHaveLength(0);
		expect(preview.querySelector('canvas')).toMatchObject({
			width: 4,
			height: 4,
		});
		expect(document.querySelectorAll('[name="pixonaut_name"]')).toHaveLength(0);
		expect(row.style.display).toBe('none');
		expect(mark.style.minHeight).toBe('0px');
	});

	it('lets go: the part shows, the mark sizes freely and a copy nothing replaced goes', () => {
		const { preview, form, row, home, mark } = drawn();
		row.style.display = 'flex';
		const hold = holdShown([preview, form], [mark], [row]);
		preview.replaceChildren();
		form.replaceChildren();
		home.append(row);
		hold.place();
		// The next designer replaced the preview's copy, not the form's.
		const drawnAgain = document.createElement('canvas');
		preview.replaceChildren(drawnAgain);

		hold.release();

		expect(row.style.display).toBe('flex');
		expect(mark.style.minHeight).toBe('');
		expect(form.childNodes).toHaveLength(0);
		expect(preview.firstChild).toBe(drawnAgain);
	});
});
