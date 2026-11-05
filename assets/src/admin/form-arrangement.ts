// A side's arrangement of the designer's form, as its Groups setting edits
// it: the template's groups and fields, the product's attributes placed among
// them, groups of the product's own, and the words that replace the
// template's. Stored as the `formGroups` shape `FormGroups` (PHP) reads.

/** A field of the template's form, as the template groups route lists it. */
export type TemplateField = {
	/** `"<layerId>:<prop>"` */
	field: string;
	label: string;
	help: string;
	type: string;
	/** The values it offers, when it offers a list. */
	options: string[];
};

/** A group of the template's form, as the template groups route lists it. */
export type TemplateGroup = {
	id: string;
	label: string;
	fields: TemplateField[];
};

/** One of the product's attributes used for variations. */
export type Attribute = { slug: string; label: string };

/** The words a field shows on this product, over the template's. */
export type FieldWords = {
	label?: string;
	help?: string;
	placeholder?: string;
	options?: Record<string, string>;
};

/** A template field with the words it shows on this product. */
export type FieldItem = { field: string } & FieldWords;

/** An item as stored: a template field with its words, or an attribute. */
export type StoredItem = FieldItem | { attribute: string };

/** A group as stored. */
export type StoredGroup = { id: string; title?: string; items: StoredItem[] };

/** An item as the setting shows it: a stored item, and whether the template
 *  gained it since the arrangement was saved. */
export type Item = StoredItem & { isNew?: boolean };

export type Group = { id: string; title: string; items: Item[] };

/** What the setting edits. */
export type Arrangement = {
	groups: Group[];
	/** The attributes in no group, by slug, in the product's order. */
	tray: string[];
	/** Names of what the saved arrangement held that the template no longer
	 *  has. */
	gone: string[];
};

/** Put before the id of a group of the product's own (`FormGroups::OWN_GROUP_PREFIX`). */
export const OWN_GROUP_PREFIX = 'wc-';

export const isOwn = (id: string): boolean => id.startsWith(OWN_GROUP_PREFIX);

export const isAttribute = (item: StoredItem): item is { attribute: string } =>
	'attribute' in item;

/** The key an item is found by: its field or its attribute. */
export const itemKey = (item: StoredItem): string =>
	isAttribute(item) ? `attribute:${item.attribute}` : `field:${item.field}`;

/**
 * The arrangement the setting shows: `saved` laid over the template's
 * groups as they are now, or the template's own without one. What the
 * template no longer has is left out and named in `gone`; a field it gained
 * goes at the end of its own group, marked new; an attribute the product no
 * longer varies by is left out, and one placed nowhere is in the tray.
 */
export function arrange(
	template: TemplateGroup[],
	attributes: Attribute[],
	saved: StoredGroup[] | null,
): Arrangement {
	const slugs = new Set(attributes.map((attribute) => attribute.slug));
	const fields = new Map(
		template.flatMap((group) => group.fields.map((f) => [f.field, f] as const)),
	);
	const known = new Set(template.map((group) => group.id));
	const gone: string[] = [];
	const placed = new Set<string>();
	const groups: Group[] = [];
	for (const group of saved ?? []) {
		if (!isOwn(group.id) && !known.has(group.id)) {
			gone.push(group.title ?? group.id);
			continue;
		}
		const items: Item[] = [];
		for (const item of group.items) {
			const present = isAttribute(item)
				? slugs.has(item.attribute)
				: fields.has(item.field);
			if (!present) {
				if (!isAttribute(item)) {
					gone.push(item.label ?? item.field);
				}
				continue;
			}
			placed.add(itemKey(item));
			items.push(item);
		}
		groups.push({ id: group.id, title: group.title ?? '', items });
	}
	const isNew = saved !== null;
	for (const group of template) {
		let shown = groups.find((one) => one.id === group.id);
		if (shown === undefined) {
			shown = { id: group.id, title: '', items: [] };
			groups.push(shown);
		}
		for (const field of group.fields) {
			if (!placed.has(itemKey({ field: field.field }))) {
				shown.items.push(
					isNew ? { field: field.field, isNew } : { field: field.field },
				);
			}
		}
	}
	return {
		groups,
		tray: attributes
			.map((attribute) => attribute.slug)
			.filter((slug) => !placed.has(itemKey({ attribute: slug }))),
		gone,
	};
}

/** `arrangement` as stored: only the words set, and no group of the
 *  product's own without a name, which the designer could not show. */
export function stored(arrangement: Arrangement): StoredGroup[] {
	return arrangement.groups
		.filter((group) => !isOwn(group.id) || group.title.trim() !== '')
		.map((group) => ({
			id: group.id,
			...(group.title.trim() === '' ? {} : { title: group.title.trim() }),
			items: group.items.map(storedItem),
		}));
}

function storedItem(item: Item): StoredItem {
	if (isAttribute(item)) {
		return { attribute: item.attribute };
	}
	const words: FieldWords = {};
	for (const key of ['label', 'help', 'placeholder'] as const) {
		const value = item[key]?.trim() ?? '';
		if (value !== '') {
			words[key] = value;
		}
	}
	const options = Object.entries(item.options ?? {}).filter(
		([, value]) => value.trim() !== '',
	);
	if (options.length > 0) {
		words.options = Object.fromEntries(
			options.map(([key, value]) => [key, value.trim()]),
		);
	}
	return { field: item.field, ...words };
}

/** Where an item is: its group's index and its index in the group. */
export type Place = { group: number; index: number };

/** Moves the item at `from` to `to`, `to.index` counted before it leaves. */
export function moveItem(
	arrangement: Arrangement,
	from: Place,
	to: Place,
): void {
	const source = arrangement.groups[from.group];
	const target = arrangement.groups[to.group];
	const [item] = source?.items.splice(from.index, 1) ?? [];
	if (item === undefined || target === undefined) {
		return;
	}
	const index =
		from.group === to.group && to.index > from.index ? to.index - 1 : to.index;
	target.items.splice(Math.min(index, target.items.length), 0, item);
}

/** Moves the group at `from` to `to`, `to` counted before it leaves. */
export function moveGroup(
	arrangement: Arrangement,
	from: number,
	to: number,
): void {
	const [group] = arrangement.groups.splice(from, 1);
	if (group !== undefined) {
		arrangement.groups.splice(to > from ? to - 1 : to, 0, group);
	}
}

/** Puts the tray's attribute `slug` in a group, at `to`. */
export function placeAttribute(
	arrangement: Arrangement,
	slug: string,
	to: Place,
): void {
	const target = arrangement.groups[to.group];
	if (target === undefined || !arrangement.tray.includes(slug)) {
		return;
	}
	arrangement.tray = arrangement.tray.filter((one) => one !== slug);
	target.items.splice(to.index, 0, { attribute: slug });
}

/** Takes the attribute at `from` back to the tray, in the product's order. */
export function unplaceAttribute(
	arrangement: Arrangement,
	from: Place,
	order: Attribute[],
): void {
	const item = arrangement.groups[from.group]?.items[from.index];
	if (item === undefined || !isAttribute(item)) {
		return;
	}
	arrangement.groups[from.group]?.items.splice(from.index, 1);
	const tray = new Set([...arrangement.tray, item.attribute]);
	arrangement.tray = order
		.map((one) => one.slug)
		.filter((slug) => tray.has(slug));
}

/** Adds a group of the product's own named `title`, at the end. */
export function addGroup(arrangement: Arrangement, title: string): Group {
	const taken = new Set(arrangement.groups.map((group) => group.id));
	let n = arrangement.groups.length + 1;
	while (taken.has(`${OWN_GROUP_PREFIX}group-${n}`)) {
		n++;
	}
	const group: Group = {
		id: `${OWN_GROUP_PREFIX}group-${n}`,
		title,
		items: [],
	};
	arrangement.groups.push(group);
	return group;
}

/** Removes the product's own group at `index`; its attributes go back to
 *  the tray, its fields to the end of the first group. */
export function removeGroup(
	arrangement: Arrangement,
	index: number,
	order: Attribute[],
): void {
	const group = arrangement.groups[index];
	if (group === undefined || !isOwn(group.id)) {
		return;
	}
	arrangement.groups.splice(index, 1);
	const fields = group.items.filter((item) => !isAttribute(item));
	const tray = new Set([
		...arrangement.tray,
		...group.items.flatMap((item) =>
			isAttribute(item) ? [item.attribute] : [],
		),
	]);
	arrangement.tray = order
		.map((one) => one.slug)
		.filter((slug) => tray.has(slug));
	if (fields.length > 0) {
		const first =
			arrangement.groups.find((one) => !isOwn(one.id)) ?? arrangement.groups[0];
		first?.items.push(...fields);
	}
}
