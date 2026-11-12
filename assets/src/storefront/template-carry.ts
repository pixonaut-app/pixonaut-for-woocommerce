import type { PixonautDesigner } from '../types/sdk';

type Values = ReturnType<PixonautDesigner['getState']>['values'];

/**
 * Carries the customer's values on fields without a field key into the
 * template `setTemplate()` just showed, by layer id, as mounting the side
 * again on them would: the SDK carries a keyed field's value, and keeps an
 * unkeyed one for the way back. A field the new template's form offers on a
 * layer of the same id takes the value the customer last gave it, also over
 * the one that template kept from an earlier visit.
 *
 * @param before The designer's values just before the switch.
 */
export function carryUnkeyedValues(
	designer: PixonautDesigner,
	before: Values,
): void {
	const now = designer.getState().values;
	for (const step of designer.getFormSchema().steps) {
		for (const { layerId, prop } of step.fields) {
			const given = before[layerId];
			if (
				given === undefined ||
				!Object.hasOwn(given, prop) ||
				JSON.stringify(now[layerId]?.[prop]) === JSON.stringify(given[prop])
			) {
				continue;
			}
			designer.setValue(layerId, prop, given[prop]);
		}
	}
}
