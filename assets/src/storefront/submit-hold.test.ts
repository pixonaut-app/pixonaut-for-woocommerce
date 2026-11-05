// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import { BUSY_CLASS, SubmitHold } from './submit-hold';

function page() {
	document.body.innerHTML = `
		<form class="cart">
			<div class="pixonaut-form"><button type="submit" class="sdk-step">Next</button></div>
			<button type="submit" name="add-to-cart" value="7" class="single_add_to_cart_button">Add to cart</button>
		</form>`;
	const form = document.querySelector('form') as HTMLFormElement;
	const inside = document.querySelector('.pixonaut-form') as HTMLElement;
	const button = document.querySelector(
		'.single_add_to_cart_button',
	) as HTMLButtonElement;
	return {
		form,
		inside,
		button,
		hold: new SubmitHold(form, (element) => inside.contains(element)),
	};
}

function isBusy(button: HTMLElement): boolean {
	return (
		button.classList.contains(BUSY_CLASS) &&
		button.getAttribute('aria-busy') === 'true' &&
		button.getAttribute('aria-disabled') === 'true'
	);
}

describe('SubmitHold', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('marks the button the form was sent with busy, without disabling it', () => {
		const { button, hold } = page();

		hold.hold(button);

		expect(isBusy(button)).toBe(true);
		// A disabled button would drop `add-to-cart` from what the form posts.
		expect(button.disabled).toBe(false);
	});

	it("marks the form's own add-to-cart button when it was sent without one", () => {
		const { button, hold } = page();

		hold.hold(null);

		expect(isBusy(button)).toBe(true);
		expect(document.querySelector('.sdk-step')?.classList).not.toContain(
			BUSY_CLASS,
		);
	});

	it('frees the button again', () => {
		const { button, hold } = page();
		hold.hold(button);

		hold.release();

		expect(button.classList).not.toContain(BUSY_CLASS);
		expect(button.hasAttribute('aria-busy')).toBe(false);
		expect(button.hasAttribute('aria-disabled')).toBe(false);
	});

	it('keeps the button busy and disables it once the form is sent on', () => {
		const { button, hold } = page();
		hold.hold(button);

		hold.left();
		hold.release();

		expect(hold.leaving).toBe(true);
		expect(isBusy(button)).toBe(true);
		expect(button.disabled).toBe(true);
	});

	it('frees the button of a page the browser brings back from its cache', () => {
		const { button, hold } = page();
		hold.hold(button);
		hold.left();

		window.dispatchEvent(
			new PageTransitionEvent('pageshow', { persisted: true }),
		);

		expect(hold.leaving).toBe(false);
		expect(button.classList).not.toContain(BUSY_CLASS);
		expect(button.disabled).toBe(false);
	});

	it('leaves a button the page had disabled itself disabled on restore', () => {
		const { button, hold } = page();
		button.disabled = true;
		hold.hold(button);
		hold.left();

		window.dispatchEvent(
			new PageTransitionEvent('pageshow', { persisted: true }),
		);

		expect(button.disabled).toBe(true);
	});
});
