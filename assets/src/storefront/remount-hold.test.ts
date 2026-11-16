// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HELD_ATTRIBUTE, holdShown } from './remount-hold';

/** A preview and a form as a designer draws them, a part of the
 *  add-to-cart form lent to the form, and a mark beside them. */
function drawn() {
	document.body.innerHTML = `
		<form id="cart">
			<div id="pixonaut-preview-front"><div class="stage" id="stage"><canvas width="4" height="4"></canvas><img id="layer" src="blob:http://localhost/layer" alt=""></div></div>
			<div id="pixonaut-form-front"><label for="f1">Name</label><input id="f1" name="pixonaut_name" data-pixonaut-field="name:content" value="Ada"><div id="row"><select name="attribute_size"><option value="">Choose</option><option value="Tee">Tee</option></select><button name="add-to-cart">Add</button></div></div>
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
		layer: byId('layer'),
	};
}

/** Mutation observers report after the task that changed the page. */
const observed = () => new Promise((resolve) => setTimeout(resolve));

describe('holdShown', () => {
	beforeEach(() => {
		// jsdom draws no canvas: the copy keeps the source's size alone.
		vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
	});

	it('shows an inert copy that names nothing in the emptied targets, and the lent part hidden at home', () => {
		const { preview, form, row, home, mark } = drawn();
		(row.querySelector('select') as HTMLSelectElement).value = 'Tee';
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
		expect(copy.hasAttribute(HELD_ATTRIBUTE)).toBe(true);
		expect(form.querySelector('input')?.value).toBe('Ada');
		expect(form.querySelector('select')?.value).toBe('Tee');
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
		// A part named twice is hidden, and shown again, once.
		const hold = holdShown([preview, form], [mark], [row, row]);
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

	it('shows the designer’s own pictures in the copy, which the SDK took down', () => {
		const { preview, form, row, mark, layer } = drawn();
		const hold = holdShown([preview, form], [mark], [row]);
		// The SDK takes its pictures down and lets go of their URLs.
		layer.remove();
		preview.replaceChildren();
		form.replaceChildren();

		hold.place();

		const shown = preview.querySelectorAll('img');
		expect(shown).toHaveLength(1);
		expect(shown[0]).toBe(layer);
		expect(layer.id).toBe('');
		const copy = preview.firstElementChild as HTMLElement;
		expect(copy.inert).toBe(true);
		expect(copy.contains(layer)).toBe(true);

		hold.release();

		expect(layer.isConnected).toBe(false);
	});

	it('keeps a picture at the top of a target inert', () => {
		const { preview, form, row, mark } = drawn();
		const picture = document.createElement('img');
		picture.src = 'blob:http://localhost/thumbnail';
		const views = document.createElement('div');
		views.append(picture);
		document.body.append(views);
		const hold = holdShown([preview, form, views], [mark], [row]);
		views.replaceChildren();

		hold.place();

		expect(views.firstChild).toBe(picture);
		expect(picture.inert).toBe(true);
		expect(picture.getAttribute('aria-hidden')).toBe('true');
		expect(picture.hasAttribute(HELD_ATTRIBUTE)).toBe(true);
		hold.release();
		expect(views.childNodes).toHaveLength(0);
	});

	it('shows a part as soon as the next designer takes it in its form', async () => {
		const { preview, form, row, home, mark } = drawn();
		const hold = holdShown([preview, form], [mark], [row]);
		preview.replaceChildren();
		form.replaceChildren();
		home.append(row);
		hold.place();
		expect(hold.hidden()).toEqual([row]);

		// The next designer draws its form, the part in it, ahead of its
		// preview.
		const drawnForm = document.createElement('div');
		drawnForm.append(row);
		form.replaceChildren(drawnForm);
		await observed();

		expect(row.style.display).toBe('');
		expect(hold.hidden()).toEqual([]);
		expect(preview.querySelector('canvas')).not.toBeNull();
		hold.release();
	});

	it('keeps hidden the parts an earlier hold hid, when the side mounts again before it took them', () => {
		const { preview, form, row, home, mark } = drawn();
		const first = holdShown([preview, form], [mark], [row]);
		preview.replaceChildren();
		form.replaceChildren();
		home.append(row);
		first.place();

		// Mounted again before the next designer took the part.
		const second = holdShown([preview, form], [mark], [row], first.hidden());
		first.release();
		expect(row.style.display).toBe('');
		second.place();

		expect(row.style.display).toBe('none');
		expect(preview.querySelector('canvas')).not.toBeNull();
		second.release();
		expect(row.style.display).toBe('');
	});

	it('leaves the pictures of a lent part in it, the copy showing its own', () => {
		const { preview, form, row, home, mark } = drawn();
		const icon = document.createElement('img');
		icon.src = 'http://localhost/cart.svg';
		row.append(icon);
		const hold = holdShown([preview, form], [mark], [row]);
		preview.replaceChildren();
		form.replaceChildren();
		home.append(row);

		hold.place();

		expect(row.contains(icon)).toBe(true);
		expect(form.querySelectorAll('img')).toHaveLength(1);
		hold.release();
		expect(icon.isConnected).toBe(true);
	});
});
