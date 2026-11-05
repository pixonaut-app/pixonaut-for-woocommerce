// Field keys: the author's word for a field, by which a customer's value
// belongs to the word rather than to the layer. Pixonaut's `fieldKeySites`
// (packages/schema/src/field-keys.ts), reduced to the keys themselves.

/** The longest field key an author may write. */
const FIELD_KEY_MAX_LENGTH = 64;

/** The prop a field's key is stored under. */
const FIELD_KEY_PROP = 'fieldKey';

type TemplateDoc = {
	background?: unknown;
	shape?: unknown;
	layers?: readonly unknown[];
};

export function isRecord(value: unknown): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isFieldKey(key: unknown): key is string {
	return (
		typeof key === 'string' &&
		key.length > 0 &&
		key.length <= FIELD_KEY_MAX_LENGTH &&
		key.trim() === key
	);
}

/** Every prop of `doc` a customer may be offered: the background's, the
 *  die's, then each layer's props and opacity. */
function propSpecs(doc: TemplateDoc): unknown[] {
	const specs: unknown[] = [];
	if (isRecord(doc.background)) {
		specs.push(...Object.values(doc.background));
	}
	specs.push(doc.shape);
	for (const layer of doc.layers ?? []) {
		if (!isRecord(layer) || typeof layer.id !== 'string') {
			continue;
		}
		if (isRecord(layer.props)) {
			specs.push(...Object.values(layer.props));
		}
		specs.push(layer.opacity);
	}
	return specs;
}

/** The field keys `doc`'s customer-offered fields carry, in the order they
 *  appear, each once. */
export function fieldKeysOf(doc: TemplateDoc): string[] {
	const keys = new Set<string>();
	for (const spec of propSpecs(doc)) {
		if (
			isRecord(spec) &&
			spec.editable === true &&
			isFieldKey(spec[FIELD_KEY_PROP])
		) {
			keys.add(spec[FIELD_KEY_PROP]);
		}
	}
	return [...keys];
}

/** Whether `doc` offers a customer at least one field, keyed or not: what
 *  Pixonaut answers as a template's `offersProperties`. */
export function offersProperties(doc: TemplateDoc): boolean {
	return propSpecs(doc).some(
		(spec) => isRecord(spec) && spec.editable === true,
	);
}
