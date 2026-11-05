// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import type { SplitMountOptions } from '../types/sdk';
import { withStandIns } from './lent-parts';

afterEach(() => {
	document.body.replaceChildren();
});

/** A form with a slot of the printed form, inside the designer's target, holding the cart row. */
function page(): {
	form: HTMLFormElement;
	target: HTMLElement;
	cartRow: HTMLElement;
	input: HTMLInputElement;
} {
	const form = document.createElement('form');
	const target = document.createElement('div');
	const slot = document.createElement('div');
	const cartRow = document.createElement('div');
	const input = document.createElement('input');
	cartRow.append(input);
	slot.append(cartRow);
	target.append(slot);
	form.append(target);
	document.body.append(form);
	return { form, target, cartRow, input };
}

function options(
	target: HTMLElement,
	lastStepContent: HTMLElement,
): SplitMountOptions {
	return {
		formTarget: target,
		previewTarget: document.createElement('div'),
		lastStepContent,
	} as unknown as SplitMountOptions;
}

describe('withStandIns', () => {
	it('lends a detached stand-in for a part inside the designer target', () => {
		const { form, target, cartRow } = page();
		const lent = withStandIns(options(target, cartRow), form).lastStepContent;
		expect(lent).not.toBe(cartRow);
		expect(lent?.parentElement).toBeNull();
		expect(target.contains(cartRow)).toBe(true);
	});

	it('takes the part as the designer draws the stand-in, keeping its form', () => {
		const { form, target, cartRow, input } = page();
		const lent = withStandIns(options(target, cartRow), form).lastStepContent;
		const drawn = document.createElement('div');
		document.body.append(drawn);
		drawn.append(lent as HTMLElement);
		expect(lent?.contains(cartRow)).toBe(true);
		expect(input.getAttribute('form')).toBe(form.id);
	});

	it('leaves the part free when the designer gives the stand-in back', () => {
		const { form, target, cartRow, input } = page();
		const lent = withStandIns(options(target, cartRow), form).lastStepContent;
		document.body.append(lent as HTMLElement);
		lent?.remove();
		expect(input.getAttribute('form')).toBeNull();
		expect(cartRow.isConnected).toBe(false);
	});

	it('lends the same stand-in to a designer drawn again', () => {
		const { form, target, cartRow } = page();
		const first = withStandIns(options(target, cartRow), form).lastStepContent;
		document.body.append(first as HTMLElement);
		first?.remove();
		const second = withStandIns(options(target, cartRow), form).lastStepContent;
		expect(second).toBe(first);
	});

	it('keeps the box sizing the part had in its slot each time it is drawn', () => {
		const { form, target, cartRow } = page();
		const style = document.createElement('style');
		style.textContent = '.cart-row { box-sizing: border-box; }';
		document.body.append(style);
		cartRow.classList.add('cart-row');
		const lent = withStandIns(options(target, cartRow), form).lastStepContent;

		document.body.append(lent as HTMLElement);
		expect(cartRow.style.boxSizing).toBe('border-box');

		lent?.remove();
		expect(cartRow.style.boxSizing).toBe('');

		document.body.append(lent as HTMLElement);
		expect(cartRow.style.boxSizing).toBe('border-box');
	});

	it('leaves a box sizing the part sets inline alone', () => {
		const { form, target, cartRow } = page();
		cartRow.style.boxSizing = 'content-box';
		const lent = withStandIns(options(target, cartRow), form).lastStepContent;

		document.body.append(lent as HTMLElement);
		lent?.remove();

		expect(cartRow.style.boxSizing).toBe('content-box');
	});

	it('lends a part outside the designer target as it is', () => {
		const { form, target } = page();
		const outside = document.createElement('div');
		form.append(outside);
		const lent = withStandIns(options(target, outside), form).lastStepContent;
		expect(lent).toBe(outside);
	});
});
