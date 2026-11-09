// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	type ArrangedGroups,
	hostComplete,
	hostGroups,
	lentElements,
	OWN_STEP_ATTRIBUTE,
} from './form-groups';
import { ATTRIBUTE_TABLE_CLASS } from './shop-parts';

const ARRANGED: ArrangedGroups = {
	groups: [
		{ id: 'wc-options', title: 'Options', items: [{ attribute: 'colour' }] },
		{
			id: 'text-front',
			title: 'Your words',
			items: [
				{ attribute: 'size' },
				{ field: 'slogan:content', label: 'Name' },
			],
		},
	],
};

function classicForm(): HTMLFormElement {
	document.body.innerHTML = `
		<form class="variations_form cart">
			<table class="variations"><tbody>
				<tr class="colour"><th>Colour</th><td><select name="attribute_colour"><option value="">Choose</option><option value="red">Red</option></select></td></tr>
				<tr class="size"><th>Size</th><td><select name="attribute_size"><option value="">Choose</option><option value="S">S</option></select></td></tr>
			</tbody></table>
		</form>`;
	return document.querySelector('form') as HTMLFormElement;
}

describe('hostGroups', () => {
	it('lends each classic attribute row in a table of its own, before the form’s', () => {
		const form = classicForm();

		const { formGroups, hosts } = hostGroups(ARRANGED, form);

		expect(hosts).toEqual(['attribute_colour', 'attribute_size']);
		const size = formGroups.groups[1]?.items[0] as {
			host: string;
			element: HTMLElement;
		};
		expect(size.host).toBe('attribute_size');
		expect(size.element.className).toBe(ATTRIBUTE_TABLE_CLASS);
		expect(size.element.querySelector('tr.size')).not.toBeNull();
		// Each before the form's own table, where the designer puts it back.
		expect(size.element.nextElementSibling).toBe(
			form.querySelector('table.variations:not(.pixonaut-attribute)'),
		);
		expect(formGroups.groups[1]?.items[1]).toEqual({
			field: 'slogan:content',
			label: 'Name',
		});
		expect(lentElements({ formGroups, hosts })).toHaveLength(2);
	});

	it('lends a row lent before as it is', () => {
		const form = classicForm();
		const first = hostGroups(ARRANGED, form);

		const again = hostGroups(ARRANGED, form);

		expect(lentElements(again)).toEqual(lentElements(first));
		expect(form.querySelectorAll('table')).toHaveLength(3);
	});

	it('lends the block form’s attribute element', () => {
		document.body.innerHTML = `
			<form>
				<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector">
					<div class="wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute"><input type="hidden" name="attribute_size" value=""></div>
				</div>
			</form>`;
		const form = document.querySelector('form') as HTMLFormElement;

		const { hosts, formGroups } = hostGroups(ARRANGED, form);

		expect(hosts).toEqual(['attribute_size']);
		const [element] = lentElements({ formGroups, hosts });
		expect(element?.className).toBe(
			'wp-block-woocommerce-add-to-cart-with-options-variation-selector-attribute',
		);
	});

	it('marks an attribute lent to a group of its own, and only there', () => {
		const form = classicForm();
		const own: ArrangedGroups = {
			groups: [
				{
					id: 'wc-option-colour',
					title: 'Colour',
					items: [{ attribute: 'colour' }],
				},
				{ id: 'wc-options', title: 'Options', items: [{ attribute: 'size' }] },
			],
		};

		const [colour, size] = lentElements(hostGroups(own, form));

		expect(colour?.hasAttribute(OWN_STEP_ATTRIBUTE)).toBe(true);
		expect(size?.hasAttribute(OWN_STEP_ATTRIBUTE)).toBe(false);

		// Lent again to a group it shares, it shows its label again.
		const [again] = lentElements(hostGroups(ARRANGED, form));
		expect(again).toBe(colour);
		expect(again?.hasAttribute(OWN_STEP_ATTRIBUTE)).toBe(false);
	});

	it('leaves the attributes out without a form, and the product’s own group they leave empty', () => {
		const { formGroups, hosts } = hostGroups(ARRANGED, null);

		expect(hosts).toEqual([]);
		expect(formGroups).toEqual({
			groups: [
				{
					id: 'text-front',
					title: 'Your words',
					items: [{ field: 'slogan:content', label: 'Name' }],
				},
			],
		});
	});
});

describe('hostComplete', () => {
	it('is complete once its field holds a choice', () => {
		const form = classicForm();
		const size = form.querySelector(
			'select[name="attribute_size"]',
		) as HTMLSelectElement;

		expect(hostComplete(form, 'attribute_size')).toBe(false);
		size.value = 'S';
		expect(hostComplete(form, 'attribute_size')).toBe(true);
	});

	it('reads the block form’s hidden field', () => {
		document.body.innerHTML = `<form><input type="hidden" name="attribute_size" value="L"></form>`;

		expect(
			hostComplete(
				document.querySelector('form') as HTMLFormElement,
				'attribute_size',
			),
		).toBe(true);
	});
});
