import type { PixonautDesigner } from '../types/sdk';

type Values = ReturnType<PixonautDesigner['getState']>['values'];

/** A layer of the template document, as far as its field keys go: the
 *  document's type does not describe its layers' props. */
type KeyedLayer = {
	id?: unknown;
	props?: Record<string, { fieldKey?: unknown } | undefined>;
};

/**
 * Carries the customer's values on fields without a field key into the
 * template `setTemplate()` just showed, by layer id, as mounting the side
 * again on them would: the SDK carries a keyed field's value, and keeps an
 * unkeyed one for the way back. A field the new template's form offers on a
 * layer of the same id takes the value the customer last gave it, also over
 * the one that template kept from an earlier visit. A field the new template
 * keys is left to the SDK, which carries the value of its key.
 *
 * @param before The designer's values just before the switch.
 */
export function carryUnkeyedValues(
	designer: PixonautDesigner,
	before: Values,
): void {
	const now = designer.getState().values;
	const keyed = keyedFields(designer);
	for (const step of designer.getFormSchema().steps) {
		for (const { layerId, prop } of step.fields) {
			const given = before[layerId];
			if (
				given === undefined ||
				keyed.has(`${layerId}:${prop}`) ||
				!Object.hasOwn(given, prop) ||
				JSON.stringify(now[layerId]?.[prop]) === JSON.stringify(given[prop])
			) {
				continue;
			}
			designer.setValue(layerId, prop, given[prop]);
		}
	}
}

/** The fields the shown template gives a field key, as `<layerId>:<prop>`. */
function keyedFields(designer: PixonautDesigner): Set<string> {
	const { layers } = designer.getTemplate() as { layers?: unknown };
	const keyed = new Set<string>();
	for (const layer of Array.isArray(layers) ? (layers as KeyedLayer[]) : []) {
		for (const [prop, spec] of Object.entries(layer.props ?? {})) {
			if (typeof spec?.fieldKey === 'string' && spec.fieldKey !== '') {
				keyed.add(`${String(layer.id)}:${prop}`);
			}
		}
	}
	return keyed;
}
