// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { drawAttributeSwatches } from './attribute-swatches';
import { readSwatchLooks, type SwatchLooks } from './swatch-looks';

const IMAGE_URL = 'https://cdn.example.test/values/wooden.png';
const SVG_URL = 'https://cdn.example.test/values/oak.svg';

function frameForm(): HTMLFormElement {
	document.body.innerHTML = `
		<form class="variations_form cart">
			<table class="variations"><tbody>
				<tr><th class="label"><label for="frame">Frame</label></th><td class="value">
					<select id="frame" name="attribute_pa_frame">
						<option value="">Choose an option</option>
						<option value="white">White</option>
						<option value="black-white">Black and white</option>
						<option value="wooden">Wooden</option>
						<option value="oak">Oak</option>
						<option value="none">No frame</option>
					</select>
				</td></tr>
			</tbody></table>
		</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

function looks(names: boolean): SwatchLooks {
	return {
		attribute_pa_frame: {
			names,
			looks: {
				white: { kind: 'fill', colours: ['#ffffff'] },
				'black-white': { kind: 'fill', colours: ['#000000', '#ffffff'] },
				wooden: { kind: 'image', url: IMAGE_URL },
				oak: { kind: 'image', url: SVG_URL },
			},
		},
	};
}

function swatchOf(value: string): HTMLElement {
	const found = document.querySelector<HTMLElement>(
		`.pixonaut-swatches__swatch[data-value="${value}"]`,
	);
	if (found === null) {
		throw new Error(`No swatch ${value}`);
	}
	return found;
}

function lookOf(value: string): HTMLElement | null {
	return swatchOf(value).querySelector('.pixonaut-swatches__look');
}

describe('swatches drawn in their values’ looks', () => {
	it('draws a picture, an SVG picture, one colour and two before the value’s name', () => {
		drawAttributeSwatches(frameForm(), looks(true));

		expect(lookOf('wooden')?.querySelector('img')?.src).toBe(IMAGE_URL);
		expect(lookOf('oak')?.querySelector('img')?.src).toBe(SVG_URL);
		expect(lookOf('white')?.style.background).toBe('rgb(255, 255, 255)');
		expect(lookOf('black-white')?.style.background).toContain(
			'linear-gradient(135deg',
		);
		expect(swatchOf('wooden').textContent).toBe('Wooden');
		expect(swatchOf('wooden').classList).toContain('has-look');
		expect(swatchOf('wooden').hasAttribute('aria-label')).toBe(false);
	});

	it('draws a value without a look as its name alone', () => {
		drawAttributeSwatches(frameForm(), looks(false));

		expect(lookOf('none')).toBeNull();
		expect(swatchOf('none').textContent).toBe('No frame');
		expect(swatchOf('none').classList).not.toContain('is-bare');
	});

	it('leaves the name out where the store hides it, as the swatch’s accessible name and tooltip', () => {
		drawAttributeSwatches(frameForm(), looks(false));

		const wooden = swatchOf('wooden');
		expect(wooden.textContent).toBe('');
		expect(wooden.classList).toContain('is-bare');
		expect(wooden.getAttribute('aria-label')).toBe('Wooden');
		expect(wooden.title).toBe('Wooden');
	});

	it('shows the chosen value’s name beside the attribute’s while names are hidden', () => {
		const form = frameForm();
		const remove = drawAttributeSwatches(form, looks(false));
		const beside = () =>
			form.querySelector('label + .pixonaut-swatches__chosen-name');

		expect(beside()).toBeNull();
		swatchOf('wooden').click();
		expect(beside()?.textContent).toBe('Wooden');
		swatchOf('white').click();
		expect(beside()?.textContent).toBe('White');
		swatchOf('white').click();
		expect(beside()).toBeNull();
		swatchOf('oak').click();
		remove();
		expect(beside()).toBeNull();
	});

	it('shows no name beside the attribute’s while the swatches show theirs', () => {
		const form = frameForm();
		drawAttributeSwatches(form, looks(true));

		swatchOf('wooden').click();

		expect(form.querySelector('.pixonaut-swatches__chosen-name')).toBeNull();
	});

	it('keeps the picture element across a redraw', () => {
		const form = frameForm();
		drawAttributeSwatches(form, looks(true));
		const image = lookOf('wooden')?.querySelector('img');

		swatchOf('white').click();

		expect(lookOf('wooden')?.querySelector('img')).toBe(image);
	});

	it('draws as its name a look it cannot draw safely', () => {
		drawAttributeSwatches(frameForm(), {
			attribute_pa_frame: {
				names: false,
				looks: {
					white: { kind: 'fill', colours: ['red; background: url(x)'] },
					wooden: { kind: 'image', url: 'javascript:alert(1)' },
				},
			},
		});

		expect(lookOf('white')).toBeNull();
		expect(lookOf('wooden')).toBeNull();
		expect(swatchOf('wooden').textContent).toBe('Wooden');
	});

	it('draws as its name a value named like an object’s own members', () => {
		const form = frameForm();
		const option = form.querySelector(
			'option[value="none"]',
		) as HTMLOptionElement;
		option.value = 'constructor';

		drawAttributeSwatches(
			form,
			JSON.parse(JSON.stringify(looks(false))) as SwatchLooks,
		);

		expect(swatchOf('constructor').textContent).toBe('No frame');
		expect(lookOf('wooden')).not.toBeNull();
		swatchOf('constructor').click();
		expect(swatchOf('constructor').getAttribute('aria-checked')).toBe('true');
	});

	it('draws a value as its name once its picture fails to load, and keeps it so', () => {
		drawAttributeSwatches(frameForm(), looks(false));

		lookOf('oak')?.querySelector('img')?.dispatchEvent(new Event('error'));

		expect(lookOf('oak')).toBeNull();
		expect(swatchOf('oak').textContent).toBe('Oak');
		expect(swatchOf('oak').classList).not.toContain('is-bare');
		swatchOf('white').click();
		expect(swatchOf('oak').textContent).toBe('Oak');
		expect(lookOf('wooden')).not.toBeNull();
	});

	it('finds a block field’s looks by the attribute it posts', () => {
		document.body.innerHTML = `
			<form>
				<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">
					<span class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute-name" id="frame-name">Frame</span>
					<div role="radiogroup" aria-labelledby="frame-name">
						<button type="button" role="radio" value="wooden" aria-checked="false"><span>Wooden</span></button>
					</div>
					<input type="hidden" name="attribute_pa_frame" value="">
				</div>
			</form>`;

		drawAttributeSwatches(
			document.querySelector('form') as HTMLFormElement,
			looks(true),
		);

		expect(lookOf('wooden')?.querySelector('img')?.src).toBe(IMAGE_URL);
	});

	it('reads the looks the form printed, and none from mangled JSON', () => {
		const container = document.createElement('div');
		container.dataset.pixonautSwatches = JSON.stringify(looks(true));
		expect(readSwatchLooks(container)).toEqual(looks(true));
		container.dataset.pixonautSwatches = '{';
		expect(readSwatchLooks(container)).toEqual({});
	});
});
