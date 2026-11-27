// A template's value sets: values for its layers a server makes once and a
// template read names as `valueSet`, written in as the layers' own values.
import { createHash } from 'node:crypto';
import type { State, TemplateVersion } from '../state';
import { visibleTemplate } from './templates';
import { callerOf, type Handler, type HandlerContext, refusal } from './types';

/** Values by layer id and prop. */
export type LayerValues = Record<string, Record<string, unknown>>;

export type ValueSet = {
	id: string;
	tenantId: string;
	/** The template it was made for, by base id: every version reads it. */
	baseId: string;
	values: LayerValues;
};

/** Characters of a set's id after `vs_`, as Pixonaut makes them. */
const ID_LENGTH = 24;

/** A set's id: the same values for the same template make the same set. */
function setId(tenantId: string, baseId: string, values: LayerValues): string {
	const hash = createHash('sha256')
		.update(JSON.stringify([tenantId, baseId, values]))
		.digest('hex');
	return `vs_${hash.slice(0, ID_LENGTH)}`;
}

/** Each value the version does not take, as `value_set_invalid` names it. */
function issues(template: TemplateVersion, values: LayerValues) {
	const found: { layerId: string; prop: string; rule: string }[] = [];
	for (const [layerId, props] of Object.entries(values)) {
		const layer = template.template.layers.find((l) => l.id === layerId);
		if (layer === undefined) {
			found.push({ layerId, prop: '', rule: 'unknown_layer' });
			continue;
		}
		for (const prop of Object.keys(props)) {
			if (!(prop in (layer.props as Record<string, unknown>))) {
				found.push({ layerId, prop, rule: 'unknown_prop' });
			}
		}
	}
	return found;
}

/** `POST /v1/templates/{id}/value-sets`. */
export const createValueSet: Handler = (context) => {
	const template = visibleTemplate(context, context.params.id ?? '');
	if (template === undefined) {
		return refusal(404, 'template_not_found');
	}
	const values = ((context.body ?? {}) as { values?: LayerValues }).values;
	const refused = issues(template, values ?? {});
	if (refused.length > 0) {
		return {
			status: 422,
			body: { error: 'value_set_invalid', issues: refused },
		};
	}
	const { tenantId } = callerOf(context);
	const id = setId(tenantId, template.baseId, values ?? {});
	const known = context.state.valueSets.has(id);
	if (!known) {
		context.state.valueSets.set(id, {
			id,
			tenantId,
			baseId: template.baseId,
			values: values ?? {},
		});
	}
	return {
		status: known ? 200 : 201,
		body: {
			valueSetId: id,
			templateId: `${template.baseId}@${template.version}`,
			values: values ?? {},
		},
	};
};

/** The set a read names in `valueSet` for its template: `none` without one,
 *  `unknown` for one the template holds no set by. */
export function namedSet(
	context: HandlerContext,
	template: TemplateVersion,
): ValueSet | 'none' | 'unknown' {
	const id = context.query.get('valueSet');
	if (id === null) {
		return 'none';
	}
	return heldSet(context.state, template, id) ?? 'unknown';
}

/** The set `id` of the template; undefined for none it holds. */
export function heldSet(
	state: State,
	template: TemplateVersion,
	id: string,
): ValueSet | undefined {
	const set = state.valueSets.get(id);
	return set === undefined ||
		set.tenantId !== template.tenantId ||
		set.baseId !== template.baseId
		? undefined
		: set;
}

/** A template read's form with each field the set gives a value starting
 *  at it, as the form Pixonaut builds from the document the set was
 *  written into. */
export function formWithValues(formSchema: unknown, set: ValueSet): unknown {
	const schema = formSchema as {
		steps?: { fields?: { layerId: string; prop: string }[] }[];
	};
	if (!Array.isArray(schema?.steps)) {
		return formSchema;
	}
	return {
		...schema,
		steps: schema.steps.map((step) => ({
			...step,
			fields: (step.fields ?? []).map((field) => {
				const given = set.values[field.layerId];
				return given !== undefined && field.prop in given
					? { ...field, default: given[field.prop] }
					: field;
			}),
		})),
	};
}

/** The template's document with the set's values written in as its
 *  layers' own; a value for a layer or prop it no longer has is left out. */
export function withValues(
	document: TemplateVersion['template'],
	set: ValueSet,
): TemplateVersion['template'] {
	return {
		...document,
		layers: document.layers.map((layer) => {
			const given = set.values[layer.id];
			if (given === undefined) {
				return layer;
			}
			const props = { ...(layer.props as Record<string, unknown>) };
			for (const [prop, value] of Object.entries(given)) {
				const held = props[prop];
				if (typeof held === 'object' && held !== null) {
					props[prop] = { ...held, value };
				}
			}
			return { ...layer, props } as typeof layer;
		}),
	};
}
