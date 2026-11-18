/** A variation's or a choice's values by `attribute_*` field; an empty or
 *  missing one is unset. */
export type AttributeValues = Record<string, string>;

/** What the server prints for a product whose options depend on each
 *  other: its `attribute_*` fields in its order of attributes, and the
 *  values of each variation its form offers. */
export type ApplicableAttributes = {
	fields: string[];
	variations: AttributeValues[];
};

/**
 * The attributes a choice hides: those some variation sets, but none of the
 * variations still matching the choice. The server's
 * `Catalogue\ApplicableAttributes` states the rule and applies it to the
 * cart and order line; `tests/fixtures/applicable-attributes.json` holds
 * the cases both must answer alike.
 */
export function hiddenAttributes(
	{ fields, variations }: ApplicableAttributes,
	chosen: AttributeValues,
): string[] {
	const dependent = dependentAttributes({ fields, variations });
	const counted: AttributeValues = {};
	for (const field of fields) {
		if (!dependent.includes(field)) {
			counted[field] = chosen[field] ?? '';
		}
	}
	const hidden: string[] = [];
	for (const field of dependent) {
		const matching = variations.filter((variation) =>
			matches(variation, counted),
		);
		// A choice no variation matches hides nothing: the form refuses it
		// by itself.
		if (
			matching.length > 0 &&
			matching.every((variation) => isUnset(variation, field))
		) {
			hidden.push(field);
		} else {
			counted[field] = chosen[field] ?? '';
		}
	}
	return hidden;
}

/** The attributes that can be hidden: those some variation sets and
 *  another leaves unset. */
export function dependentAttributes({
	fields,
	variations,
}: ApplicableAttributes): string[] {
	return fields.filter((field) => {
		const set = variations.filter(
			(variation) => !isUnset(variation, field),
		).length;
		return set > 0 && set < variations.length;
	});
}

/** Whether a variation holds every chosen value, one it leaves unset
 *  holding any. */
export function matches(
	variation: AttributeValues,
	chosen: AttributeValues,
): boolean {
	return Object.entries(chosen).every(
		([field, value]) =>
			value === '' || isUnset(variation, field) || variation[field] === value,
	);
}

function isUnset(variation: AttributeValues, field: string): boolean {
	return (variation[field] ?? '') === '';
}
