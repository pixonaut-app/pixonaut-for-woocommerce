// The Pixonaut settings page: a setting that applies only while another has
// some value is shown only then, and one required then says so while it is
// empty. The server keeps the earlier value of a required setting saved empty.
import './settings.css';
import { domReady } from '../shared/dom-ready';
import { setFieldError } from './field-error';

/** The row of a hidden dependent setting, as the server renders it too. */
export const HIDDEN_ROW = 'pixonaut-hidden';

/** Whether the setting `field` depends on has the value it applies with. */
export function applies(
	field: HTMLElement,
	controller: HTMLInputElement | HTMLSelectElement,
): boolean {
	const {
		pixonautWhenChecked: checked,
		pixonautWhenEquals: equals,
		pixonautWhenAbove: above,
	} = field.dataset;
	if (checked !== undefined) {
		return controller instanceof HTMLInputElement && controller.checked;
	}
	if (equals !== undefined) {
		return controller.value === equals;
	}
	return above !== undefined && Number(controller.value) > Number(above);
}

/** Shows the required message while a shown required field is empty. */
export function checkRequired(field: HTMLInputElement): void {
	const message = field.dataset.pixonautRequired;
	if (message === undefined) {
		return;
	}
	const row = field.closest('tr');
	const missing =
		!row?.classList.contains(HIDDEN_ROW) && field.value.trim() === '';
	setFieldError(field, field, missing ? message : null);
}

/** The limits a field is checked against while it shows. */
const LIMITS = ['min', 'max', 'required'] as const;

/**
 * Takes a hidden field's limits away, so a value it cannot have while it
 * does not apply never stops the form being saved, and puts them back once
 * it shows. The server reads a value out of its limits as the default.
 */
export function holdLimits(field: HTMLElement, hidden: boolean): void {
	for (const limit of LIMITS) {
		const held = `pixonautHeld${limit[0]?.toUpperCase()}${limit.slice(1)}`;
		if (hidden && field.hasAttribute(limit)) {
			field.dataset[held] = field.getAttribute(limit) ?? '';
			field.removeAttribute(limit);
		} else if (!hidden && field.dataset[held] !== undefined) {
			field.setAttribute(limit, field.dataset[held] ?? '');
			delete field.dataset[held];
		}
	}
}

/** Sets up every dependent setting in `root`. */
export function initDependents(root: ParentNode): void {
	for (const field of root.querySelectorAll<HTMLInputElement>(
		'[data-pixonaut-depends]',
	)) {
		const controller = document.getElementById(
			field.dataset.pixonautDepends ?? '',
		);
		const row = field.closest('tr');
		if (
			!(
				controller instanceof HTMLInputElement ||
				controller instanceof HTMLSelectElement
			) ||
			!row
		) {
			continue;
		}
		if (field.dataset.pixonautRequired !== undefined) {
			field.setAttribute('aria-required', 'true');
		}
		const update = () => {
			const hidden = !applies(field, controller);
			row.classList.toggle(HIDDEN_ROW, hidden);
			holdLimits(field, hidden);
			checkRequired(field);
		};
		controller.addEventListener('change', update);
		controller.addEventListener('input', update);
		field.addEventListener('change', () => checkRequired(field));
		update();
	}
}

domReady(() => initDependents(document));
