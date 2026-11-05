import { applyFilters } from '@wordpress/hooks';

/**
 * JavaScript filter every customer-facing text passes through, the mirror of
 * the PHP filter `pixonaut_wc_storefront_label`.
 */
export const LABEL_FILTER = 'pixonaut.storefront.label';

export type LabelContext = Readonly<Record<string, unknown>>;

export type Labeler = (key: string, context?: LabelContext) => string;

/**
 * Returns a lookup over the labels the server printed (already filtered in
 * PHP). Each lookup runs `pixonaut.storefront.label`; an unknown key reads as
 * itself.
 */
export function createLabeler(
	labels: Readonly<Record<string, string>>,
): Labeler {
	return (key, context = {}) => {
		// Own keys only, so `toString` or `__proto__` read as themselves.
		const printed = Object.hasOwn(labels, key) ? labels[key] : undefined;
		const text = typeof printed === 'string' ? printed : key;
		const filtered = applyFilters(LABEL_FILTER, text, key, context);
		return typeof filtered === 'string' ? filtered : text;
	};
}
