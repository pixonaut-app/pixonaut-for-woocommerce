// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { drawStepNumbers } from './step-numbers';

/** A heading as the SDK draws one on a form laid out as a page. */
const SDK_HEADING_STYLE =
	'margin:8px 0 2px;font:600 16px system-ui;color:#1c2b5e;';

/** The SDK's form laid out as a page: a numbered heading per step. */
function pageForm(labels: string[]): HTMLElement {
	const form = document.createElement('div');
	form.dataset.testid = 'pixonaut-form';
	labels.forEach((label, index) => {
		const heading = document.createElement('h3');
		heading.textContent = `${index + 1}. ${label}`;
		heading.style.cssText = SDK_HEADING_STYLE;
		form.append(heading);
	});
	return form;
}

function container(): { container: HTMLElement; target: HTMLElement } {
	document.body.innerHTML =
		'<div class="pixonaut-form"><div id="pixonaut-form-front"></div></div>';
	const element = document.querySelector<HTMLElement>('.pixonaut-form');
	const target = document.getElementById('pixonaut-form-front');
	if (element === null || target === null) {
		throw new Error('markup');
	}
	return { container: element, target };
}

/** Lets the observer's callback run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
	document.body.innerHTML = '';
});

describe('drawStepNumbers', () => {
	it('draws each step number of a page as its own element', async () => {
		const { container: element, target } = container();
		drawStepNumbers(element, { badges: true, numbered: true });

		target.append(pageForm(['Ladda upp motiv', 'Text']));
		await settle();

		const headings = target.querySelectorAll('h3');
		expect(Array.from(headings, (heading) => heading.className)).toEqual([
			'pixonaut-step',
			'pixonaut-step',
		]);
		expect(
			Array.from(
				target.querySelectorAll('.pixonaut-step__number'),
				(number) => number.textContent,
			),
		).toEqual(['1', '2']);
		expect(
			Array.from(
				target.querySelectorAll('.pixonaut-step__label'),
				(label) => label.textContent,
			),
		).toEqual(['Ladda upp motiv', 'Text']);
	});

	it('draws the heading and its badge in the theme’s accent and type, inline', async () => {
		const { container: element, target } = container();
		drawStepNumbers(element, { badges: true, numbered: true });

		target.append(pageForm(['Text']));
		await settle();

		const heading = target.querySelector<HTMLElement>('h3');
		const number = target.querySelector<HTMLElement>('.pixonaut-step__number');
		expect(heading?.style.getPropertyValue('color')).toBe(
			'var(--pixonaut-step-color, var(--wp--preset--color--accent))',
		);
		expect(heading?.style.margin).toBe('8px 0px 2px');
		expect(heading?.style.getPropertyValue('font-family')).toBe(
			'var(--wp--preset--font-family--heading, system-ui)',
		);
		expect(heading?.style.getPropertyValue('font-size')).toBe(
			'var(--wp--preset--font-size--large, 16px)',
		);
		expect(number?.style.getPropertyValue('background')).toBe(
			'var(--pixonaut-step-number-background, var(--wp--preset--color--accent))',
		);
		expect(number?.style.getPropertyValue('border-radius')).toBe('50%');
		expect(number?.style.getPropertyValue('font-size')).toBe(
			'var(--wp--preset--font-size--medium, 0.75em)',
		);
	});

	it('draws a form the SDK drew before it was called', () => {
		const { container: element, target } = container();
		target.append(pageForm(['Text']));

		drawStepNumbers(element, { badges: true, numbered: true });

		expect(target.querySelector('.pixonaut-step__number')?.textContent).toBe(
			'1',
		);
	});

	it('draws a form the SDK draws again', async () => {
		const { container: element, target } = container();
		drawStepNumbers(element, { badges: true, numbered: true });
		target.append(pageForm(['Text']));
		await settle();

		target.replaceChildren(pageForm(['Text', 'Storlek']));
		await settle();

		expect(target.querySelectorAll('.pixonaut-step__number')).toHaveLength(2);
	});

	it('keeps a label that reads like a number', async () => {
		const { container: element, target } = container();
		drawStepNumbers(element, { badges: true, numbered: true });

		target.append(pageForm(['3. Size']));
		await settle();

		expect(target.querySelector('.pixonaut-step__number')?.textContent).toBe(
			'1',
		);
		expect(target.querySelector('.pixonaut-step__label')?.textContent).toBe(
			'3. Size',
		);
	});

	it('leaves the heading of a form laid out as steps', async () => {
		const { container: element, target } = container();
		drawStepNumbers(element, { badges: true, numbered: true });

		const form = pageForm(['2. Text']);
		form.dataset.layout = 'steps';
		target.append(form);
		await settle();

		expect(target.querySelector('h3')?.textContent).toBe('1. 2. Text');
		expect(target.querySelector('.pixonaut-step')).toBeNull();
	});

	it('takes the number out of an SDK heading when the form shows none', async () => {
		const { container: element, target } = container();
		drawStepNumbers(element, { badges: false, numbered: false });

		target.append(pageForm(['Foto', '2. Text']));
		await settle();
		await settle();

		const headings = [...target.querySelectorAll<HTMLElement>('h3')];
		expect(headings.map((heading) => heading.textContent)).toEqual([
			'Foto',
			// A label starting like a number loses only the number the SDK put.
			'2. Text',
		]);
		expect(headings[0]?.style.getPropertyValue('margin')).toBe('8px 0px 2px');
		expect(target.querySelector('.pixonaut-step__number')).toBeNull();
	});

	it('draws the heading in the theme’s accent with no badge when the form shows no numbers', async () => {
		const { container: element, target } = container();
		drawStepNumbers(element, { badges: true, numbered: false });

		target.append(pageForm(['Foto']));
		await settle();

		const heading = target.querySelector<HTMLElement>('h3');
		expect(heading?.className).toBe('pixonaut-step');
		expect(heading?.textContent).toBe('Foto');
		expect(target.querySelector('.pixonaut-step__number')).toBeNull();
	});

	it('hides the steps layout’s counter when the form shows no numbers, and only then', async () => {
		const stepsForm = () => {
			const counter = document.createElement('div');
			counter.dataset.testid = 'form-step-counter';
			counter.textContent = 'Step 1 of 2';
			return counter;
		};
		const off = container();
		drawStepNumbers(off.container, { badges: false, numbered: false });
		const hidden = stepsForm();
		off.target.append(hidden);
		await settle();
		expect(hidden.style.getPropertyValue('display')).toBe('none');

		const on = container();
		drawStepNumbers(on.container, { badges: true, numbered: true });
		const shown = stepsForm();
		on.target.append(shown);
		await settle();
		expect(shown.style.getPropertyValue('display')).toBe('');
	});
});
