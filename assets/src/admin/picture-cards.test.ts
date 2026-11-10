// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { initPictureCards } from './picture-cards';

/** A set's card, ticked or not, drawn on the photo or as the design alone. */
function card(id: string, ticked: boolean): string {
	return `
		<li class="pixonaut-picture-card pixonaut-showcase-image" data-id="${id}">
			<span class="pixonaut-picture-card__thumb">
				<img class="pixonaut-picture-card__photo" src="https://img.example/photo" alt="" />
				<img class="pixonaut-picture-card__preview" src="https://img.example/${id}" alt="" />
				<span class="pixonaut-picture-card__badge" hidden></span>
			</span>
			<label><input type="checkbox" class="pixonaut-picture-card__use" ${ticked ? 'checked' : ''} />${id}</label>
			<div class="pixonaut-showcase-image__kinds">
				<label class="pixonaut-showcase-kind"><input type="radio" name="${id}" value="mockup" checked /><span class="pixonaut-showcase-kind__thumbnail"><img src="https://img.example/photo" /></span></label>
				<label class="pixonaut-showcase-kind pixonaut-showcase-kind--photo"><input type="radio" name="${id}" value="mockup:back" /><span class="pixonaut-showcase-kind__thumbnail"><img src="https://img.example/back" /></span></label>
				<label class="pixonaut-showcase-kind"><input type="radio" name="${id}" value="mockup:gone" /><span class="pixonaut-showcase-kind__thumbnail"></span></label>
				<label class="pixonaut-showcase-kind pixonaut-showcase-kind--flat"><input type="radio" name="${id}" value="preview" /><span class="pixonaut-showcase-kind__thumbnail"><img src="https://img.example/${id}" /></span></label>
			</div>
		</li>`;
}

function cards(ticked: boolean[], max = 10): HTMLElement {
	const root = document.createElement('div');
	root.innerHTML = `
		<ul class="pixonaut-picture-cards" data-max="${max}" data-first="Product image" data-gallery="Gallery" data-left-out="Left out">
			${ticked.map((on, i) => card(`set-${i}`, on)).join('')}
			<li class="pixonaut-picture-card pixonaut-picture-card--own">
				<span class="pixonaut-picture-card__thumb"><span class="pixonaut-picture-card__badge" hidden></span></span>
			</li>
		</ul>`;
	document.body.replaceChildren(root);
	initPictureCards(root);
	return root;
}

function badges(root: HTMLElement): string[] {
	return [
		...root.querySelectorAll<HTMLElement>('.pixonaut-picture-card__badge'),
	].map((badge) => (badge.hidden ? '' : (badge.textContent ?? '')));
}

function tick(root: HTMLElement, index: number, on: boolean): void {
	const box = root.querySelectorAll<HTMLInputElement>(
		'.pixonaut-picture-card__use',
	)[index];
	box.checked = on;
	box.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('initPictureCards', () => {
	it('makes the first ticked set the product image and the others the gallery, as they are ticked', () => {
		const root = cards([false, false, false]);
		tick(root, 1, true);
		tick(root, 2, true);
		expect(badges(root)).toEqual(['', 'Product image', 'Gallery', '']);
		expect(
			root
				.querySelector('.pixonaut-picture-cards')
				?.classList.contains('pixonaut-picture-cards--none-ticked'),
		).toBe(false);
	});

	it('leaves out a set ticked past the most a product shows', () => {
		const root = cards([true, true, false], 2);
		tick(root, 2, true);
		expect(badges(root)).toEqual(['Product image', 'Gallery', 'Left out', '']);
	});

	it('makes the template’s own picture the product image once nothing is ticked', () => {
		const root = cards([true, false]);
		tick(root, 0, false);
		expect(badges(root)).toEqual(['', '', 'Product image']);
		expect(
			root
				.querySelector('.pixonaut-picture-cards')
				?.classList.contains('pixonaut-picture-cards--none-ticked'),
		).toBe(true);
	});

	it('draws a card’s thumbnail on the photo chosen, or as the design alone', () => {
		const root = cards([true]);
		const thumb = root.querySelector('.pixonaut-picture-card__thumb');
		const photo = root.querySelector<HTMLImageElement>(
			'.pixonaut-picture-card__photo',
		);
		const pick = (value: string) => {
			const radio = root.querySelector<HTMLInputElement>(
				`input[value="${value}"]`,
			);
			if (radio) {
				radio.checked = true;
				radio.dispatchEvent(new Event('change', { bubbles: true }));
			}
		};

		pick('mockup:back');
		expect(photo?.src).toBe('https://img.example/back');
		expect(photo?.hidden).toBe(false);
		expect(thumb?.classList.contains('is-flat')).toBe(false);

		pick('preview');
		expect(photo?.hidden).toBe(true);
		expect(thumb?.classList.contains('is-flat')).toBe(true);

		// A photo the blank no longer has is not the design alone.
		pick('mockup:gone');
		expect(photo?.hidden).toBe(true);
		expect(thumb?.classList.contains('is-flat')).toBe(false);
	});
});
