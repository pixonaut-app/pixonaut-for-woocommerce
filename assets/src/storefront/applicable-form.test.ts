// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ApplicableAttributes } from './applicable-attributes';
import {
	hideInapplicableAttributes,
	MAX_RETRIES,
	NOT_APPLICABLE_ATTRIBUTE,
	RETRY_MS,
} from './applicable-form';
import { VARIATION_SETTLE_MS } from './variations';

/** A poster sold bare, with a hanger in a colour or framed in a colour. */
const POSTER: ApplicableAttributes = {
	fields: ['attribute_framing', 'attribute_hanger', 'attribute_frame'],
	variations: [
		{ attribute_framing: 'none' },
		{ attribute_framing: 'hanger', attribute_hanger: 'oak' },
		{ attribute_framing: 'hanger', attribute_hanger: 'black' },
		{ attribute_framing: 'frame', attribute_frame: 'black' },
		{ attribute_framing: 'frame', attribute_frame: 'white' },
	],
};

const VALUES: Record<string, string[]> = {
	attribute_framing: ['none', 'hanger', 'frame'],
	attribute_hanger: ['oak', 'black'],
	attribute_frame: ['black', 'white'],
};

function classicForm(): HTMLFormElement {
	const rows = Object.entries(VALUES).map(
		([name, values], index, all) => `
			<tr><th class="label"><label for="${name}">${name}</label></th><td class="value">
				<select id="${name}" name="${name}">
					<option value="">Choose an option</option>
					${values.map((value) => `<option value="${value}">${value}</option>`).join('')}
				</select>
				${index === all.length - 1 ? '<a class="reset_variations" href="#">Clear</a>' : ''}
			</td></tr>`,
	);
	document.body.innerHTML = `
		<form class="variations_form cart">
			<table class="variations"><tbody>${rows.join('')}</tbody></table>
		</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

/** The block form, whose chips answer a click as WooCommerce's do once
 *  `interactive`: a chip is checked, the chosen one cleared, and the
 *  attribute's posted field follows. */
function blockForm(interactive: () => boolean = () => true): HTMLFormElement {
	const attributes = Object.entries(VALUES).map(
		([name, values]) => `
			<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute">
				<div role="radiogroup">
					${values.map((value) => `<button type="button" role="radio" value="${value}" aria-checked="false"></button>`).join('')}
				</div>
				<input type="hidden" name="${name}" value="">
			</div>`,
	);
	document.body.innerHTML = `<form>${attributes.join('')}</form>`;
	const form = document.querySelector('form') as HTMLFormElement;
	form.addEventListener('click', (event) => {
		const chip = (event.target as Element).closest<HTMLButtonElement>(
			'button[role="radio"]',
		);
		const group = chip?.closest('[role="radiogroup"]');
		const posted = group?.parentElement?.querySelector('input');
		if (!interactive() || !chip || !group || !posted) {
			return;
		}
		const checked = chip.getAttribute('aria-checked') === 'true';
		for (const other of group.querySelectorAll('button')) {
			other.setAttribute('aria-checked', 'false');
		}
		chip.setAttribute('aria-checked', checked ? 'false' : 'true');
		posted.value = checked ? '' : chip.value;
	});
	return form;
}

function select(name: string): HTMLSelectElement {
	return document.querySelector(`select[name="${name}"]`) as HTMLSelectElement;
}

function choose(name: string, value: string): void {
	select(name).value = value;
	select(name).dispatchEvent(new Event('change', { bubbles: true }));
	vi.advanceTimersByTime(VARIATION_SETTLE_MS);
}

function chip(name: string, value: string): HTMLButtonElement {
	return document
		.querySelector(`input[name="${name}"]`)
		?.parentElement?.querySelector(
			`button[value="${value}"]`,
		) as HTMLButtonElement;
}

/** The fields whose row is hidden. */
function hiddenRows(): string[] {
	return Array.from(
		document.querySelectorAll<HTMLElement>(`[${NOT_APPLICABLE_ATTRIBUTE}]`),
	).map((row) => {
		expect(row.style.display).toBe('none');
		expect(row.style.getPropertyPriority('display')).toBe('important');
		return (row.querySelector('[name]') as HTMLInputElement).name;
	});
}

beforeEach(() => {
	vi.useFakeTimers();
});

afterEach(() => {
	vi.useRealTimers();
});

describe('hideInapplicableAttributes on the classic form', () => {
	it('shows every attribute before a choice', () => {
		hideInapplicableAttributes(classicForm(), POSTER);

		expect(hiddenRows()).toEqual([]);
		expect(select('attribute_hanger').value).toBe('');
	});

	it('hides the attributes a choice leaves no use for, each with a value', () => {
		hideInapplicableAttributes(classicForm(), POSTER);

		choose('attribute_framing', 'none');

		expect(hiddenRows()).toEqual(['attribute_hanger', 'attribute_frame']);
		expect(select('attribute_hanger').value).toBe('oak');
		expect(select('attribute_frame').value).toBe('black');
	});

	it('announces the value it gives a hidden field, as a choice is', () => {
		const form = classicForm();
		hideInapplicableAttributes(form, POSTER);
		const changed: string[] = [];
		form.addEventListener('change', (event) =>
			changed.push((event.target as HTMLSelectElement).name),
		);

		choose('attribute_framing', 'none');

		expect(changed).toEqual([
			'attribute_framing',
			'attribute_hanger',
			'attribute_frame',
		]);
	});

	it('shows an attribute again, cleared, once a choice makes it apply', () => {
		hideInapplicableAttributes(classicForm(), POSTER);
		choose('attribute_framing', 'none');

		choose('attribute_framing', 'hanger');

		expect(hiddenRows()).toEqual(['attribute_frame']);
		expect(select('attribute_hanger').value).toBe('');
		expect(select('attribute_hanger').closest('tr')?.style.display).toBe('');
		expect(select('attribute_frame').value).toBe('black');
	});

	it('clears a choice made before its attribute stopped applying', () => {
		hideInapplicableAttributes(classicForm(), POSTER);
		choose('attribute_frame', 'white');
		choose('attribute_framing', 'none');
		expect(select('attribute_frame').value).toBe('white');

		choose('attribute_framing', 'frame');

		expect(hiddenRows()).toEqual(['attribute_hanger']);
		expect(select('attribute_frame').value).toBe('');
	});

	it('hides for the choice the page opens on', () => {
		const form = classicForm();
		select('attribute_framing').value = 'hanger';

		hideInapplicableAttributes(form, POSTER);

		expect(hiddenRows()).toEqual(['attribute_frame']);
	});

	it('keeps the link that clears every choice in the last row shown', () => {
		const form = classicForm();
		const link = form.querySelector('.reset_variations') as HTMLElement;
		hideInapplicableAttributes(form, POSTER);
		expect(link.closest('tr')).toBe(select('attribute_frame').closest('tr'));

		choose('attribute_framing', 'hanger');
		expect(link.closest('tr')).toBe(select('attribute_hanger').closest('tr'));
		expect(link.parentElement?.matches('td.value')).toBe(true);

		choose('attribute_framing', 'none');
		expect(link.closest('tr')).toBe(select('attribute_framing').closest('tr'));

		choose('attribute_framing', 'frame');
		expect(link.closest('tr')).toBe(select('attribute_frame').closest('tr'));
	});

	it('leaves alone an attribute the rule does not name', () => {
		const form = classicForm();
		hideInapplicableAttributes(form, {
			...POSTER,
			fields: ['attribute_framing', 'attribute_hanger'],
		});

		choose('attribute_framing', 'none');

		expect(hiddenRows()).toEqual(['attribute_hanger']);
		expect(select('attribute_frame').value).toBe('');
	});
});

describe('hideInapplicableAttributes on the block form', () => {
	it('hides an attribute and checks its first chip', () => {
		hideInapplicableAttributes(blockForm(), POSTER);

		chip('attribute_framing', 'none').click();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		expect(hiddenRows()).toEqual(['attribute_hanger', 'attribute_frame']);
		expect(chip('attribute_hanger', 'oak').getAttribute('aria-checked')).toBe(
			'true',
		);
		// Its own clicks settle too, and change nothing more.
		vi.advanceTimersByTime(VARIATION_SETTLE_MS * 2);
		expect(chip('attribute_hanger', 'oak').getAttribute('aria-checked')).toBe(
			'true',
		);
	});

	it('shows an attribute again with no chip checked', () => {
		hideInapplicableAttributes(blockForm(), POSTER);
		chip('attribute_framing', 'none').click();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS);

		chip('attribute_framing', 'hanger').click();
		vi.advanceTimersByTime(VARIATION_SETTLE_MS * 2);

		expect(hiddenRows()).toEqual(['attribute_frame']);
		expect(
			(document.querySelector('[name="attribute_hanger"]') as HTMLInputElement)
				.value,
		).toBe('');
	});

	it('fills a hidden field once the form answers clicks', () => {
		let interactive = false;
		const form = blockForm(() => interactive);
		chip('attribute_framing', 'none').setAttribute('aria-checked', 'true');
		hideInapplicableAttributes(form, POSTER);
		expect(hiddenRows()).toEqual(['attribute_hanger', 'attribute_frame']);
		expect(chip('attribute_hanger', 'oak').getAttribute('aria-checked')).toBe(
			'false',
		);

		interactive = true;
		vi.advanceTimersByTime(RETRY_MS);

		expect(chip('attribute_hanger', 'oak').getAttribute('aria-checked')).toBe(
			'true',
		);
	});

	it('stops looking at a form that never answers, until the next choice', () => {
		const form = blockForm(() => false);
		chip('attribute_framing', 'none').setAttribute('aria-checked', 'true');
		const clicks = vi.fn();
		chip('attribute_hanger', 'oak').addEventListener('click', clicks);
		hideInapplicableAttributes(form, POSTER);

		vi.advanceTimersByTime(RETRY_MS * (MAX_RETRIES + 10));
		const tried = clicks.mock.calls.length;
		vi.advanceTimersByTime(RETRY_MS * 10);

		expect(clicks.mock.calls.length).toBe(tried);
		expect(tried).toBeGreaterThan(1);
	});
});
