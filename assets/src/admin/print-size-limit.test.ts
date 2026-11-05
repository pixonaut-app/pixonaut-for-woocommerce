// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { describeLimit, overLimit, placementLimit } from './print-size-limit';
import { addThumbnails, checkSide } from './product-panel';

/** A side on the front placement, which prints at most 330 × 420 mm, with
 *  its template chosen and one custom size of `width` × `height`. */
function side(width: string, height: string): HTMLElement {
	const element = document.createElement('div');
	element.className = 'pixonaut-side';
	element.dataset.missingSize = 'Enter the width and height in millimetres.';
	element.dataset.sizeLimit = 'Up to %1$s × %2$s mm';
	element.dataset.sizeOver = 'This placement prints at most %1$s × %2$s mm.';
	element.innerHTML = `
		<select class="pixonaut-side-placement"><option value=""></option><option value="front" data-max-width-mm="330" data-max-height-mm="420" selected>Front</option></select>
		<select class="pixonaut-side-template"><option value="tee@1" selected>Tee</option></select>
		<ul>
			<li class="pixonaut-size">
				<select class="pixonaut-size-kind"><option value="custom" selected>Custom</option></select>
				<span class="pixonaut-size-custom">
					<input class="pixonaut-size-width" value="${width}" />
					<input class="pixonaut-size-height" value="${height}" />
				</span>
				<span class="pixonaut-size-limit" hidden></span>
			</li>
		</ul>`;
	document.body.append(element);
	return element;
}

function messages(element: HTMLElement): string[] {
	return [...element.querySelectorAll('.pixonaut-field-error')].map(
		(error) => error.textContent ?? '',
	);
}

afterEach(() => {
	document.body.replaceChildren();
	document.documentElement.lang = '';
});

describe('checkSide with a placement limit', () => {
	it('shows the placement’s largest print under a custom size', () => {
		const element = side('200', '250');

		expect(checkSide(element, true)).toBe(0);
		const hint = element.querySelector<HTMLElement>('.pixonaut-size-limit');
		expect(hint?.hidden).toBe(false);
		expect(hint?.textContent).toBe('Up to 330 × 420 mm');
		expect(messages(element)).toEqual([]);
	});

	it('refuses a width or height past it, naming the limit', () => {
		const element = side('400', '250');

		expect(checkSide(element, true)).toBe(1);
		expect(messages(element)).toEqual([
			'This placement prints at most 330 × 420 mm.',
		]);
		expect(
			[
				...element.querySelectorAll(
					'.pixonaut-size-width, .pixonaut-size-height',
				),
			].map((input) => input.getAttribute('aria-invalid')),
		).toEqual(['true', null]);
	});

	it('asks for a missing dimension before the limit', () => {
		const element = side('400', '');

		checkSide(element, true);
		expect(messages(element)).toEqual([
			'Enter the width and height in millimetres.',
		]);
	});

	it('says nothing of a limit for a placement without one', () => {
		const element = side('4000', '250');
		const option = element.querySelector<HTMLOptionElement>(
			'option[value="front"]',
		);
		if (option) {
			delete option.dataset.maxWidthMm;
		}

		expect(checkSide(element, true)).toBe(0);
		expect(
			element.querySelector<HTMLElement>('.pixonaut-size-limit')?.hidden,
		).toBe(true);
	});

	it('takes the limit of a placement whose details came later', () => {
		const element = side('400', '250');
		const placement = element.querySelector<HTMLSelectElement>(
			'.pixonaut-side-placement',
		);
		if (placement) {
			addThumbnails(placement, [
				{ id: 'front', text: 'Front', maxWidthMm: 450, maxHeightMm: 500 },
			]);
		}

		expect(placementLimit(element)).toEqual({ widthMm: 450, heightMm: 500 });
		expect(checkSide(element, true)).toBe(0);
	});
});

describe('overLimit', () => {
	it('reads a comma as the decimal mark', () => {
		const element = side('330,5', '420');
		const row = element.querySelector<HTMLElement>('.pixonaut-size');

		expect(
			row === null
				? []
				: overLimit(row, { widthMm: 330, heightMm: 420 }).map(
						(input) => input.className,
					),
		).toEqual(['pixonaut-size-width']);
	});
});

describe('describeLimit', () => {
	it('writes the numbers as the page’s language does', () => {
		document.documentElement.lang = 'sv-SE';

		expect(
			describeLimit('Upp till %1$s × %2$s mm', {
				widthMm: 297.5,
				heightMm: 420,
			}),
		).toBe('Upp till 297,5 × 420 mm');
	});

	it('shows a limit as exactly as it is checked', () => {
		expect(
			describeLimit('Up to %1$s × %2$s mm', {
				widthMm: 297.0005,
				heightMm: 420,
			}),
		).toBe('Up to 297.0005 × 420 mm');
	});
});
