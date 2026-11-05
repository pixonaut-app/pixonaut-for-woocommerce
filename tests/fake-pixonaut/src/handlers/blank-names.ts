// A blank's option names, option values and view labels in the language a
// read names with `?lang=`: each in that language where the blank has one,
// else as written, as Pixonaut answers them.
import type { Blank } from '../state';
import type { HandlerContext } from './types';

/** The query parameter naming the language. */
const LANGUAGE = 'lang';

/** Names a blank's options and views. */
export type BlankNamer = {
	axis(axis: string): string;
	value(axis: string, value: string): string;
	view(id: string, label: string): string;
};

/** Every name as written. */
export const AS_WRITTEN: BlankNamer = {
	axis: (axis) => axis,
	value: (_axis, value) => value,
	view: (_id, label) => label,
};

/** The names the read asks for. The spec's enum refuses an unknown
 *  language before a handler runs. */
export function namerFor(context: HandlerContext, blank: Blank): BlankNamer {
	const language = context.query.get(LANGUAGE);
	const names = language === null ? undefined : blank.names?.[language];
	if (names === undefined) {
		return AS_WRITTEN;
	}
	return {
		axis: (axis) => names.axes[axis] ?? axis,
		value: (axis, value) => names.values[axis]?.[value] ?? value,
		view: (id, label) => names.views[id] ?? label,
	};
}
