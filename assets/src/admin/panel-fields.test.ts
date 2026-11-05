// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	addThumbnails,
	checkSide,
	customSizesWithoutBlank,
	showPersonalised,
} from './product-panel';

/** A side with no template, no placement, and a custom size missing its height. */
function side(): HTMLElement {
	const element = document.createElement('div');
	element.className = 'pixonaut-side';
	element.dataset.missingTemplate = 'Choose a template for this side.';
	element.dataset.missingPlacement =
		'Choose where on the blank this side is printed.';
	element.dataset.missingSize = 'Enter the width and height in millimetres.';
	element.innerHTML = `
		<p><select class="pixonaut-side-placement"><option value=""></option><option value="front">Front</option></select></p>
		<p><select class="pixonaut-side-template"><option value=""></option><option value="tee@1">Tee</option></select></p>
		<ul>
			<li class="pixonaut-size">
				<select class="pixonaut-size-kind"><option value="custom" selected>Custom</option></select>
				<span class="pixonaut-size-custom">
					<input class="pixonaut-size-width" value="200" />
					<input class="pixonaut-size-height" value="" />
				</span>
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

describe('checkSide', () => {
	it('says under each required field with a blank what is missing', () => {
		const element = side();

		expect(checkSide(element, true)).toBe(3);
		expect(messages(element)).toEqual([
			'Choose where on the blank this side is printed.',
			'Choose a template for this side.',
			'Enter the width and height in millimetres.',
		]);
		expect(
			element
				.querySelector('.pixonaut-size-height')
				?.getAttribute('aria-invalid'),
		).toBe('true');
	});

	it('clears both dimensions once the size is complete', () => {
		const element = side();
		const width = element.querySelector<HTMLInputElement>(
			'.pixonaut-size-width',
		);
		const height = element.querySelector<HTMLInputElement>(
			'.pixonaut-size-height',
		);
		if (width && height) {
			width.value = '';
			checkSide(element, true);
			expect([
				width.getAttribute('aria-invalid'),
				height.getAttribute('aria-invalid'),
			]).toEqual(['true', 'true']);
			width.value = '200';
			checkSide(element, true);
			expect([
				width.hasAttribute('aria-invalid'),
				height.getAttribute('aria-invalid'),
			]).toEqual([false, 'true']);
			height.value = '300';
			checkSide(element, true);
		}
		expect(width?.hasAttribute('aria-invalid')).toBe(false);
		expect(height?.hasAttribute('aria-invalid')).toBe(false);
		expect(messages(element)).not.toContain(
			'Enter the width and height in millimetres.',
		);
	});

	it('asks for the template and each size without a blank, not a placement', () => {
		const element = side();

		expect(checkSide(element, false)).toBe(2);
		expect(messages(element)).toEqual([
			'Choose a template for this side.',
			'Enter the width and height in millimetres.',
		]);
	});

	it('takes a message away once the field is filled in', () => {
		const element = side();
		checkSide(element, false);
		const template = element.querySelector<HTMLSelectElement>(
			'.pixonaut-side-template',
		);
		if (template) {
			template.value = 'tee@1';
		}

		expect(checkSide(element, false)).toBe(1);
		expect(messages(element)).toEqual([
			'Enter the width and height in millimetres.',
		]);
		expect(template?.hasAttribute('aria-invalid')).toBe(false);
	});

	it('leaves a side just added alone until the product is saved', () => {
		const element = side();
		element.dataset.pixonautPristine = '';

		expect(checkSide(element, false)).toBe(2);
		expect(messages(element)).toEqual([]);
		checkSide(element, false, true);
		expect(messages(element)).toEqual([
			'Choose a template for this side.',
			'Enter the width and height in millimetres.',
		]);
	});
});

describe('customSizesWithoutBlank', () => {
	/** A side whose one size is the placement's default. */
	function defaultSized(): { root: HTMLElement; kind: HTMLSelectElement } {
		const root = document.createElement('div');
		root.innerHTML = `
			<li class="pixonaut-size">
				<select class="pixonaut-size-kind">
					<option value="default" selected>Placement default</option>
					<option value="custom">Custom size</option>
				</select>
				<input class="pixonaut-size-width" value="" />
				<input class="pixonaut-size-height" value="" />
			</li>`;
		const kind = root.querySelector<HTMLSelectElement>('.pixonaut-size-kind');
		if (!kind) {
			throw new Error('no size kind');
		}
		return { root, kind };
	}

	it('makes every size custom without a blank, and says so to the row', () => {
		const { root, kind } = defaultSized();
		const changes: string[] = [];
		kind.addEventListener('change', () => changes.push(kind.value));

		customSizesWithoutBlank(root, false);

		expect(kind.value).toBe('custom');
		expect(changes).toEqual(['custom']);
	});

	it('leaves the sizes alone with a blank', () => {
		const { root, kind } = defaultSized();

		customSizesWithoutBlank(root, true);

		expect(kind.value).toBe('default');
	});

	it('gives a size it made custom back to the placement once a blank is chosen, while it is empty', () => {
		const { root, kind } = defaultSized();
		customSizesWithoutBlank(root, false);

		customSizesWithoutBlank(root, true);

		expect(kind.value).toBe('default');
	});

	it('keeps a size it made custom once the store manager typed one in', () => {
		const { root, kind } = defaultSized();
		customSizesWithoutBlank(root, false);
		const width = root.querySelector<HTMLInputElement>('.pixonaut-size-width');
		if (width) {
			width.value = '500';
		}

		customSizesWithoutBlank(root, true);

		expect(kind.value).toBe('custom');
	});

	it('keeps a size the store manager made custom with a blank', () => {
		const { root, kind } = defaultSized();
		kind.value = 'custom';

		customSizesWithoutBlank(root, true);

		expect(kind.value).toBe('custom');
	});
});

describe('showPersonalised', () => {
	it('shows the configuration only while Personalise is ticked', () => {
		const root = document.createElement('div');
		root.innerHTML = `
			<input type="checkbox" class="pixonaut-personalise" />
			<span class="pixonaut-unpersonalised-hint"></span>
			<div class="pixonaut-personalised"></div>`;
		const checkbox = root.querySelector<HTMLInputElement>(
			'.pixonaut-personalise',
		);
		const rest = root.querySelector<HTMLElement>('.pixonaut-personalised');
		const hint = root.querySelector<HTMLElement>(
			'.pixonaut-unpersonalised-hint',
		);

		showPersonalised(root);
		expect([rest?.hidden, hint?.hidden]).toEqual([true, false]);

		if (checkbox) {
			checkbox.checked = true;
		}
		showPersonalised(root);
		expect([rest?.hidden, hint?.hidden]).toEqual([false, true]);
	});
});

describe('addThumbnails', () => {
	it('gives each placement its picture and keeps the one chosen', () => {
		const select = document.createElement('select');
		select.append(
			new Option('', ''),
			new Option('Front', 'front'),
			new Option('Back', 'back'),
		);
		select.value = 'back';

		addThumbnails(select, [
			{ id: 'front', text: 'Front', thumbnail: 'http://127.0.0.1/i/front.png' },
			{ id: 'back', text: 'Back', thumbnail: '' },
		]);

		expect(select.value).toBe('back');
		expect(
			[...select.options].map((option) => option.dataset.thumbnail),
		).toEqual([undefined, 'http://127.0.0.1/i/front.png', '']);
	});
});
