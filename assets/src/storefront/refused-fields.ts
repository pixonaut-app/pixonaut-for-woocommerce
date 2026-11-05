import type { PixonautDesigner } from '../types/sdk';

/** The designer call a refused side's fields are named through. Releases
 *  before `getFieldTexts()` cannot name them. */
type FieldNamer = Partial<Pick<PixonautDesigner, 'getFieldTexts'>>;

/** A refused design's field, as Pixonaut names it in a violation. */
type Violation = { layerId: string; prop: string };

function isViolation(value: unknown): value is Violation {
	return (
		typeof value === 'object' &&
		value !== null &&
		typeof (value as { layerId?: unknown }).layerId === 'string' &&
		typeof (value as { prop?: unknown }).prop === 'string'
	);
}

/** The fields a design was refused for: a refusal carrying `violations`,
 *  whether the SDK found them before sending or Pixonaut answered 422
 *  `constraint_violation`. Empty for any other failure; an entry naming no
 *  field is left out. */
function violationsOf(error: unknown): Violation[] {
	if (typeof error !== 'object' || error === null) {
		return [];
	}
	const violations = (error as { violations?: unknown }).violations;
	return Array.isArray(violations) ? violations.filter(isViolation) : [];
}

/**
 * The labels of the fields the customer must change before a refused design
 * is taken, each once, in the order the refusal names them; empty when the
 * refusal names no field the form heads (or the error is not a refusal of
 * the design's fields).
 */
export function refusedFieldLabels(
	error: unknown,
	designer: FieldNamer | undefined,
): string[] {
	const violations = violationsOf(error);
	if (violations.length === 0 || designer?.getFieldTexts === undefined) {
		return [];
	}
	const labels = new Map<string, string>();
	for (const text of designer.getFieldTexts()) {
		labels.set(`${text.layerId}.${text.prop}`, text.label);
	}
	const named = new Set<string>();
	for (const { layerId, prop } of violations) {
		const label = labels.get(`${layerId}.${prop}`);
		if (label !== undefined && label !== '') {
			named.add(label);
		}
	}
	return [...named];
}

/** `labels` as one list in `locale`'s words ("Name, Class and Photo"); the
 *  page's own language when `locale` is not one the browser knows. */
export function joinLabels(
	labels: string[],
	locale: string | undefined,
): string {
	try {
		return new Intl.ListFormat(locale, { type: 'conjunction' }).format(labels);
	} catch {
		return new Intl.ListFormat(undefined, { type: 'conjunction' }).format(
			labels,
		);
	}
}
