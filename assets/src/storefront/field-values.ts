import type { PixonautDesigner } from '../types/sdk';

/** The form field the add-to-cart form posts each side's values in, as
 *  `pixonaut_fields[<side key>]`: PHP's `AddToCart::FIELDS_FIELD`. */
export const FIELD_VALUES_NAME = 'pixonaut_fields';

/** The field types a line's details list; the others (a crop, an opacity)
 *  place the design rather than say what it shows. PHP's
 *  `FieldValue::TYPES` takes the same. */
const LISTED_TYPES = ['text', 'select', 'number', 'color', 'image'] as const;

type ListedType = (typeof LISTED_TYPES)[number];

/** One field's value as the cart line keeps it: PHP's `FieldValue`. A
 *  picture keeps no value; the line shows it by the `field_image` label. */
export type FieldValue = { label: string; type: ListedType; value: string };

type FormField = ReturnType<
	PixonautDesigner['getFormSchema']
>['steps'][number]['fields'][number];

/** A CMYK colour's channels, each shown by its letter and percentage:
 *  `C0 M100 Y100 K0`. */
const CMYK_CHANNELS = ['C', 'M', 'Y', 'K'] as const;

function isNumbers(value: unknown): value is number[] {
	return Array.isArray(value) && value.every((c) => typeof c === 'number');
}

/** Hex digits per colour channel. */
const HEX_CHANNEL_DIGITS = 2;
const HEX_RADIX = 16;

/** The designer calls a side's field values are read through. Releases
 *  before `getFieldTexts()` are read through the form and the values. */
type FieldReader = Pick<PixonautDesigner, 'getFormSchema' | 'getValue'> &
	Partial<Pick<PixonautDesigner, 'getFieldTexts'>>;

/**
 * What the side's design shows for each field of its form, as the customer
 * chose it, in the form's order: read once the side is approved, so it is
 * the approved design's. A field with nothing in it is left out.
 */
export function fieldValues(designer: FieldReader): FieldValue[] {
	return designer.getFieldTexts === undefined
		? valuesFromForm(designer)
		: valuesFromTexts(designer, designer.getFieldTexts());
}

/** Each field by the words the form shows its value in: a palette colour
 *  by its name, a number as the form writes it, a choice by its label. */
function valuesFromTexts(
	designer: FieldReader,
	texts: ReturnType<PixonautDesigner['getFieldTexts']>,
): FieldValue[] {
	const types = new Map<string, string>();
	for (const step of designer.getFormSchema().steps) {
		for (const field of step.fields) {
			types.set(fieldId(field), field.type);
		}
	}
	const values: FieldValue[] = [];
	const seen = new Set<string>();
	for (const text of texts) {
		const id = fieldId(text);
		const type = types.get(id) ?? '';
		if (!isListed(type) || seen.has(id) || text.value.trim() === '') {
			continue;
		}
		seen.add(id);
		// A picture keeps no value: the line names it by the `field_image` label.
		values.push({
			label: text.label.trim(),
			type,
			value: type === 'image' ? '' : text.value,
		});
	}
	return values;
}

function valuesFromForm(designer: FieldReader): FieldValue[] {
	const values: FieldValue[] = [];
	const seen = new Set<string>();
	for (const step of designer.getFormSchema().steps) {
		for (const field of step.fields) {
			const id = fieldId(field);
			if (!isListed(field.type) || seen.has(id)) {
				continue;
			}
			seen.add(id);
			const value = shown(field, designer.getValue(field.layerId, field.prop));
			if (value !== null) {
				values.push({ label: field.label.trim(), type: field.type, value });
			}
		}
	}
	return values;
}

/** A field by its layer and prop, which two fields never share. */
function fieldId(field: { layerId: string; prop: string }): string {
	return `${field.layerId}\u0000${field.prop}`;
}

function isListed(type: string): type is ListedType {
	return (LISTED_TYPES as readonly string[]).includes(type);
}

/** The value as text; null when the field holds none. */
function shown(field: FormField, value: unknown): string | null {
	switch (field.type) {
		case 'image':
			return hasValue(value) ? '' : null;
		case 'color':
			return colour(value);
		case 'select':
			return hasValue(value) ? optionLabel(field, String(value)) : null;
		default:
			return typeof value === 'string' || typeof value === 'number'
				? nonEmpty(String(value))
				: null;
	}
}

function hasValue(value: unknown): boolean {
	return value !== null && value !== undefined && value !== '';
}

function nonEmpty(text: string): string | null {
	return text.trim() === '' ? null : text;
}

/** A choice by the label its option shows, when it has one. */
function optionLabel(field: FormField, value: string): string {
	for (const option of field.constraints.options ?? []) {
		if (typeof option === 'object' && option.value === value) {
			return option.label ?? value;
		}
	}
	return value;
}

/** A colour by its spot name, else as `#rrggbb` from its RGB, else by its
 *  CMYK percentages, or as the CSS colour it is. */
function colour(value: unknown): string | null {
	if (typeof value === 'string') {
		return nonEmpty(value);
	}
	const color = value as {
		rgb?: unknown;
		cmyk?: unknown;
		spot?: { name?: unknown };
	} | null;
	if (typeof color?.spot?.name === 'string') {
		return nonEmpty(color.spot.name);
	}
	const { rgb, cmyk } = color ?? {};
	if (isNumbers(rgb)) {
		return `#${rgb
			.map((c) =>
				Math.round(c).toString(HEX_RADIX).padStart(HEX_CHANNEL_DIGITS, '0'),
			)
			.join('')}`;
	}
	if (isNumbers(cmyk) && cmyk.length === CMYK_CHANNELS.length) {
		return CMYK_CHANNELS.map(
			(channel, index) => `${channel}${Math.round(cmyk[index] ?? 0)}`,
		).join(' ');
	}
	return null;
}
