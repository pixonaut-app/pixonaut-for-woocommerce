// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { bringRefusalIntoView, firstMarkedField } from './refusal-in-view';

/** jsdom's window height. */
const WINDOW_PX = window.innerHeight;

/** A message and a field that fit in the window together. */
const NEAR_PX = WINDOW_PX / 4;

/** A field a screen and more below its message. */
const FAR_PX = WINDOW_PX * 2;

/** How tall a field draws. */
const FIELD_PX = 80;

afterEach(() => {
	document.body.innerHTML = '';
	vi.restoreAllMocks();
});

/** `element` drawn `top` px from the window's top, `height` tall. */
function drawnAt(element: Element, top: number, height = FIELD_PX): void {
	const rect = DOMRect.fromRect({ x: 0, y: top, width: 300, height });
	vi.spyOn(element, 'getBoundingClientRect').mockReturnValue(rect);
	vi.spyOn(element, 'getClientRects').mockReturnValue([
		rect,
	] as unknown as DOMRectList);
}

/** `wrapper` and everything in it drawn `top` px from the window's top. */
function fieldAt(wrapper: HTMLElement, top: number): void {
	for (const element of [wrapper, ...wrapper.querySelectorAll('*')]) {
		drawnAt(element, top);
	}
}

/** A field as the SDK draws it: label, control, and its violation mark,
 *  telling `sentence` (none: the field owes nothing). */
function field(id: string, control: string, sentence = ''): HTMLElement {
	const wrapper = document.createElement('div');
	wrapper.innerHTML = `<span>${id}</span>${control}<span data-testid="violation-${id}-content" role="alert"></span>`;
	const mark = wrapper.querySelector<HTMLElement>('[role="alert"]');
	if (mark !== null) {
		mark.textContent = sentence;
		mark.hidden = sentence === '';
	}
	return wrapper;
}

function page(...fields: HTMLElement[]) {
	const root = document.createElement('div');
	const message = document.createElement('p');
	message.className = 'pixonaut-form__error';
	message.textContent = 'Please change these fields: Name';
	root.append(message, ...fields);
	document.body.append(root);
	const scrolled = vi.fn();
	for (const element of [message, ...fields]) {
		element.scrollIntoView = scrolled.bind(null, element);
	}
	return { root, message, scrolled };
}

describe('firstMarkedField', () => {
	it('finds the first drawn field the SDK marks, in form order', () => {
		const clean = field('class', '<input type="text">');
		const hidden = field(
			'photo',
			'<button type="button">Upload</button>',
			'Upload a picture',
		);
		const name = field('name', '<input type="text">', 'Change this first');
		const { root } = page(clean, hidden, name);
		drawnAt(clean, 0);
		// `hidden` sits in a step the form does not show: no client rects.
		drawnAt(name, NEAR_PX);

		expect(firstMarkedField(root)).toBe(name);
	});

	it('finds none when no mark tells a sentence', () => {
		const clean = field('class', '<input type="text">');
		const { root } = page(clean);
		drawnAt(clean, 0);

		expect(firstMarkedField(root)).toBeNull();
	});
});

describe('bringRefusalIntoView', () => {
	it('scrolls to the message above the field, and focuses the field, when both fit', () => {
		const name = field('name', '<input type="text">', 'Change this first');
		const { root, message, scrolled } = page(name);
		drawnAt(message, -FAR_PX, FIELD_PX / 2);
		fieldAt(name, -FAR_PX + NEAR_PX);

		bringRefusalIntoView(root, message);

		expect(scrolled).toHaveBeenCalledWith(message, { block: 'start' });
		expect(document.activeElement).toBe(name.querySelector('input'));
	});

	it('centres the field when its message is too far above it to show both', () => {
		const photo = field(
			'photo',
			'<button type="button">Upload</button><input type="file">',
			'Upload a picture',
		);
		const { root, message, scrolled } = page(photo);
		drawnAt(message, -FAR_PX * 2, FIELD_PX / 2);
		fieldAt(photo, -FAR_PX);

		bringRefusalIntoView(root, message);

		expect(scrolled).toHaveBeenCalledWith(photo, { block: 'center' });
		expect(document.activeElement).toBe(photo.querySelector('button'));
	});

	it('scrolls to the message alone when no field is marked and it is out of view', () => {
		const { root, message, scrolled } = page();
		drawnAt(message, -FAR_PX, FIELD_PX / 2);

		bringRefusalIntoView(root, message);

		expect(scrolled).toHaveBeenCalledWith(message, { block: 'center' });
	});

	it('leaves the page where it is when the message is in view', () => {
		const { root, message, scrolled } = page();
		drawnAt(message, NEAR_PX, FIELD_PX / 2);

		bringRefusalIntoView(root, message);

		expect(scrolled).not.toHaveBeenCalled();
	});
});
