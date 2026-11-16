import type {
	FormGroup,
	FormGroupField,
	FormGroupItem,
	FormGroups,
} from '../types/sdk';
import { NOT_APPLICABLE_ATTRIBUTE } from './applicable-form';
import { OWN_STEP_ATTRIBUTE } from './own-step';
import { attributePart } from './shop-parts';
import { chosenValue } from './variations';

/** An item of a group as the server prints it: a template field, or one of
 *  the product's attributes used for variations, by its slug. */
export type ArrangedItem = FormGroupField | { attribute: string };

/** A side's `formGroups` as the server prints it. */
export type ArrangedGroups = {
	groups: Array<Omit<FormGroup, 'items'> & { items: ArrangedItem[] }>;
};

/** Put before the id of a group of the product's own (`FormGroups::OWN_GROUP_PREFIX`). */
const OWN_GROUP_PREFIX = 'wc-';

/** Put before an attribute's slug for the id of the group holding that
 *  attribute alone, named after it (`FormGroupsMount::OPTION_GROUP_PREFIX`). */
const OPTION_GROUP_PREFIX = `${OWN_GROUP_PREFIX}option-`;

/** The add-to-cart form names an attribute's field `attribute_<slug>`. */
const ATTRIBUTE_FIELD_PREFIX = 'attribute_';

/** The mount option, and the host items it lends. A host item is named by
 *  the add-to-cart form's field it holds, which says when it is complete. */
export type HostGroups = {
	formGroups: FormGroups;
	hosts: string[];
	/** The fields of the attributes left out for not applying to the form's
	 *  choices (`inapplicableHosts()`). */
	inapplicable: string[];
};

const isAttribute = (item: ArrangedItem): item is { attribute: string } =>
	'attribute' in item;

/**
 * `arranged` as the designer takes it. With `form`, each attribute item is
 * that attribute's part of the form, moved into the group; without it, or
 * for an attribute the form has no field of, the item is left out, with a
 * group of the product's own that it leaves empty. So is an attribute that
 * does not apply to the form's choices, which has nothing to pick: the
 * designer numbers the groups it is given, so those after it move up. An
 * attribute in a group of its own is marked so (`OWN_STEP_ATTRIBUTE`).
 */
export function hostGroups(
	arranged: ArrangedGroups,
	form: HTMLFormElement | null,
): HostGroups {
	const hosts: string[] = [];
	const inapplicable = form === null ? [] : inapplicableHosts(arranged, form);
	const groups: FormGroup[] = [];
	for (const group of arranged.groups) {
		const items: FormGroupItem[] = [];
		const ownStep = group.id.startsWith(OPTION_GROUP_PREFIX);
		for (const item of group.items) {
			if (!isAttribute(item)) {
				items.push(item);
				continue;
			}
			const name = ATTRIBUTE_FIELD_PREFIX + item.attribute;
			if (inapplicable.includes(name)) {
				continue;
			}
			const element = form === null ? null : attributePart(form, name);
			if (element !== null) {
				element.toggleAttribute(OWN_STEP_ATTRIBUTE, ownStep);
				items.push({ host: name, element });
				hosts.push(name);
			}
		}
		if (items.length > 0 || !group.id.startsWith(OWN_GROUP_PREFIX)) {
			groups.push({ ...group, items });
		}
	}
	return { formGroups: { groups }, hosts, inapplicable };
}

/**
 * The fields of the attributes `arranged` places that do not apply to the
 * choices made in `form`, in the order it places them: those whose row
 * `hideInapplicableAttributes()` hides.
 */
export function inapplicableHosts(
	arranged: ArrangedGroups,
	form: HTMLFormElement,
): string[] {
	const fields = Array.from(
		form.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
			'input, select',
		),
	);
	return arranged.groups
		.flatMap((group) => group.items)
		.filter(isAttribute)
		.map((item) => ATTRIBUTE_FIELD_PREFIX + item.attribute)
		.filter(
			(name) =>
				fields
					.find((field) => field.name === name)
					?.closest(`[${NOT_APPLICABLE_ATTRIBUTE}]`) != null,
		);
}

/** Whether the host item `host` is complete: its field holds a choice. */
export function hostComplete(form: HTMLFormElement, host: string): boolean {
	return chosenValue(form, host) !== '';
}

/** The form's parts `groups` lends the designer. */
export function lentElements(
	groups: Pick<HostGroups, 'formGroups'>,
): HTMLElement[] {
	return groups.formGroups.groups.flatMap((group) =>
		group.items.flatMap((item) => ('host' in item ? [item.element] : [])),
	);
}
