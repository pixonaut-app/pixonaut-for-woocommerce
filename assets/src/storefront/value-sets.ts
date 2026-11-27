/** A template's value set by the template's base id: the side's own values
 *  (`set-product-values`), which the designer opens on and names in every
 *  read and request it makes (the SDK's `valueSets` mount option). */
export type ValueSets = Readonly<Record<string, string>>;

/** What names a side's template and its set. */
type Named = { templateId?: string; valueSets?: ValueSets };

/** A template id's base id: `mini-mug` of `mini-mug@3`. */
export function baseIdOf(templateId: string): string {
	return templateId.split('@', 1)[0] ?? templateId;
}

/** The value set options open their template on; undefined for none. */
export function valueSetOf(options: Named | undefined): string | undefined {
	if (options?.templateId === undefined) {
		return undefined;
	}
	return (
		options.valueSets?.[baseIdOf(options.templateId)] ??
		options.valueSets?.[options.templateId]
	);
}

/** The `valueSets` option opening a template on a set; none without one. */
export function valueSetsFor(
	templateId: string,
	valueSet: string | undefined,
): { valueSets?: ValueSets } {
	return valueSet === undefined
		? {}
		: { valueSets: { [baseIdOf(templateId)]: valueSet } };
}

/** Whether two options open their templates on the same sets. */
export function sameValueSets(
	first: ValueSets | undefined,
	second: ValueSets | undefined,
): boolean {
	const entries = (sets: ValueSets | undefined) =>
		JSON.stringify(Object.entries(sets ?? {}).sort());
	return entries(first) === entries(second);
}
