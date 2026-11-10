// The Pixonaut tab on the product screen, and each variation's Pixonaut
// panel: blank and template searches through the plugin's admin REST routes
// (selectWoo, as WooCommerce's own searches), the blank's placements once one
// is chosen, the views the product is shown in, and the sides repeater with
// each side's print-size menu. Each picker shows a picture beside each name,
// a required field left empty says what is missing under it, and the link
// beside a product's own image puts its Pixonaut picture back. Each global
// attribute's row in the Attributes tab has its "Show each value as its own
// product" control.
import './product-panel.css';
import { domReady } from '../shared/dom-ready';
import {
	type BlankParts,
	latestOnly,
	readBlankParts,
	showBlankFailed,
	showBlankLoading,
	showBlankParts,
} from './blank-fields';
import {
	ajaxOptions,
	type SearchOption,
	type SearchRequest,
	type SearchUrls,
	searchUrls,
	templateSearch,
} from './catalogue-search';
import {
	initDisclosures,
	showSideSummary,
	showStoreSummary,
	showViewsSummary,
} from './disclosures';
import { setFieldError } from './field-error';
import { initPictureCards } from './picture-cards';
import {
	describeLimit,
	overLimit,
	placementLimit,
	showSizeLimit,
} from './print-size-limit';
import { initRegeneratePictures } from './regenerate-pictures';
import { initRetiredVariants } from './retired-variants';
import { initSideGroups } from './side-groups';
import {
	CUSTOM_SIZE,
	isFixedDesign,
	NEW_SIDE_INDEX,
	NEW_SIZE_INDEX,
	nextIndex,
	PLACEMENT_DEFAULT_SIZE,
	withIndex,
} from './sides';
import { initSplitListing } from './split-listing';
import {
	initStandingOptions,
	showStandingOptions,
	standingOptionsChanged,
} from './standing-options';
import {
	initTemplateVersion,
	linkedTemplateId,
	offerVersions,
	showTemplateStudio,
} from './template-version';
import { chosenThumbnail, thumbnailOptions } from './thumbnails';
import { initUsePicture } from './use-picture';
import { initValueSizes } from './value-sizes';
import {
	initOverride,
	initVariationSizes,
	markChanged,
	newPanels,
} from './variations';
import { showOpeningView } from './view-tiles';

type Placement = {
	id: string;
	text: string;
	thumbnail?: string;
	/** The largest print the placement takes, when Pixonaut said. */
	maxWidthMm?: number;
	maxHeightMm?: number;
};

/** Puts a placement's largest print on its option, for `placementLimit()`. */
function setLimit(option: HTMLOptionElement, placement: Placement): void {
	if (
		placement.maxWidthMm !== undefined &&
		placement.maxHeightMm !== undefined
	) {
		option.dataset.maxWidthMm = String(placement.maxWidthMm);
		option.dataset.maxHeightMm = String(placement.maxHeightMm);
	}
}

/** A side's modes, as `Side::PERSONALISED` and `Side::STANDING` name them. */
export const PERSONALISED_MODE = 'personalised';
export const STANDING_MODE = 'standing';

/** Replaces a select's options with `placements`, keeping the first (empty)
 *  one, and picks the only placement when there is one. */
export function fillPlacements(
	select: HTMLSelectElement,
	placements: Placement[],
): void {
	const empty = select.options[0];
	select.replaceChildren(...(empty === undefined ? [] : [empty]));
	for (const placement of placements) {
		const option = new Option(placement.text, placement.id);
		option.dataset.thumbnail = placement.thumbnail ?? '';
		setLimit(option, placement);
		select.add(option);
	}
	select.value = placements.length === 1 ? (placements[0]?.id ?? '') : '';
}

/** Gives each of a select's placements its picture and largest print,
 *  changing no choice. */
export function addThumbnails(
	select: HTMLSelectElement,
	placements: Placement[],
): void {
	const byId = new Map(placements.map((p) => [p.id, p]));
	for (const option of select.options) {
		const placement = byId.get(option.value);
		if (placement !== undefined) {
			option.dataset.thumbnail = placement.thumbnail ?? '';
			setLimit(option, placement);
		}
	}
}

/**
 * Shows the views listed for the blank the panel was rendered with while it
 * is the one chosen. Under another blank, whose views are not listed until
 * the product is saved with it, the list is hidden and its fields disabled so
 * they are not posted, and a note says so.
 */
export function showViews(root: HTMLElement, blank: string): void {
	const menu = root.querySelector<HTMLElement>('.pixonaut-views');
	if (!menu) {
		return;
	}
	const sameBlank = (menu.dataset.blank ?? '') === blank;
	for (const placement of menu.querySelectorAll<HTMLElement>(
		'.pixonaut-views__placement',
	)) {
		placement.hidden = !sameBlank;
	}
	for (const field of menu.querySelectorAll<
		HTMLInputElement | HTMLSelectElement
	>('input, select')) {
		field.disabled = !sameBlank;
	}
	const initial = menu.querySelector<HTMLElement>('.pixonaut-views__initial');
	if (initial) {
		initial.hidden = !sameBlank;
	}
	const unlisted = menu.querySelector<HTMLElement>('.pixonaut-views-unlisted');
	if (unlisted) {
		unlisted.hidden = sameBlank;
	}
}

/**
 * Offers as the starting view only the views ticked, or every view while
 * none is: a starting view that is no longer ticked falls back to the first.
 */
export function offerInitialViews(root: HTMLElement): void {
	const select = root.querySelector<HTMLSelectElement>(
		'.pixonaut-initial-view',
	);
	if (!select) {
		return;
	}
	const ticked = [
		...root.querySelectorAll<HTMLInputElement>(
			'.pixonaut-views__list input[type=checkbox]:checked',
		),
	].map((checkbox) => checkbox.value);
	for (const option of select.options) {
		option.disabled =
			option.value !== '' &&
			ticked.length > 0 &&
			!ticked.includes(option.value);
	}
	if (select.selectedOptions[0]?.disabled) {
		select.value = '';
	}
}

/**
 * Shows, under a side's template, the sizes it cannot be printed at on the
 * placement, as the search that listed it says; empty hides the warning.
 */
export function showMisfitWarning(side: HTMLElement, warning: string): void {
	const box = side.querySelector<HTMLElement>('.pixonaut-misfit-warning');
	if (!box) {
		return;
	}
	const text = box.querySelector('p') ?? box;
	text.textContent = warning;
	box.hidden = warning === '';
}

/**
 * Shows the warning a side's mode calls for with its template: standing on a
 * template that offers fields to fill in, which customers then cannot, or
 * personalised on one that offers none. The side's `data-offers-properties`
 * says which the template is: `true`, `false`, or empty while unknown, and
 * then neither shows.
 */
export function showModeWarnings(side: HTMLElement): void {
	const fixed = isFixedDesign(side);
	const offers = side.dataset.offersProperties ?? '';
	const standing = side.querySelector<HTMLElement>(
		'.pixonaut-mode-warning--standing',
	);
	const personalised = side.querySelector<HTMLElement>(
		'.pixonaut-mode-warning--personalised',
	);
	if (standing) {
		standing.hidden = !(fixed && offers === 'true');
	}
	if (personalised) {
		personalised.hidden = !(!fixed && offers === 'false');
	}
}

/**
 * Warns that a template will print on white: the designer draws white paper
 * under it. Only without a blank, and only when Pixonaut said the template's
 * background shows through. The side's `data-background-see-through` is `true`, `false`, or
 * empty while unknown, and then nothing shows.
 */
export function showBackgroundWarning(
	side: HTMLElement,
	withBlank: boolean,
): void {
	const box = side.querySelector<HTMLElement>('.pixonaut-background-warning');
	if (box) {
		box.hidden = withBlank || side.dataset.backgroundSeeThrough !== 'true';
	}
}

/**
 * Shows the hints that depend on how many sides there are: past the most
 * sides Pixonaut's designer is built for (the hint's `data-recommended`),
 * that it recommends fewer; without a blank, that the product has one side.
 */
/** Shows a side's designer settings, its Groups and Canvas shape, while
 *  customers fill the side in: a standing side has no designer. */
export function showGroups(side: HTMLElement): void {
	for (const setting of side.querySelectorAll<HTMLElement>(
		'.pixonaut-groups, .pixonaut-side-canvas-ratio',
	)) {
		setting.hidden = isFixedDesign(side);
	}
}

export function showSideHints(
	root: HTMLElement,
	sides: number,
	withBlank: boolean,
): void {
	const over = root.querySelector<HTMLElement>(
		'.pixonaut-sides-over-recommended',
	);
	if (over) {
		over.hidden = sides <= Number(over.dataset.recommended ?? sides);
	}
	for (const element of root.querySelectorAll<HTMLElement>(
		'.pixonaut-without-blank',
	)) {
		element.hidden = withBlank;
	}
}

/** Marks a size row made custom only because the product has no blank. */
const AUTO_CUSTOM = 'pixonautAutoCustom';

/** Makes every size row of `root` a custom size while the product has no
 *  blank: there is no placement to take a default size from, so the store
 *  manager enters each one. Once a blank is chosen, a row made custom that
 *  way and still empty goes back to the placement's default. */
export function customSizesWithoutBlank(
	root: HTMLElement,
	withBlank: boolean,
): void {
	for (const kind of root.querySelectorAll<HTMLInputElement>(
		'.pixonaut-size-kind',
	)) {
		const row = kind.closest<HTMLElement>('.pixonaut-size');
		if (!withBlank && kind.value !== CUSTOM_SIZE) {
			kind.value = CUSTOM_SIZE;
			if (row) {
				row.dataset[AUTO_CUSTOM] = '';
			}
		} else if (
			withBlank &&
			row?.dataset[AUTO_CUSTOM] !== undefined &&
			[
				...row.querySelectorAll<HTMLInputElement>(
					'.pixonaut-size-width, .pixonaut-size-height',
				),
			].every((input) => input.value.trim() === '')
		) {
			kind.value = PLACEMENT_DEFAULT_SIZE;
			delete row.dataset[AUTO_CUSTOM];
		} else {
			continue;
		}
		// The row shows or hides its width and height on it.
		kind.dispatchEvent(new Event('change'));
	}
}

/** A side that was added and not touched yet: its empty fields are not
 *  called missing until the product is saved. */
const PRISTINE = 'pixonautPristine';

/** Where a field's message goes: after selectWoo's box when it has one. */
function anchorOf(field: HTMLElement): HTMLElement {
	const next = field.nextElementSibling;
	return next instanceof HTMLElement && next.classList.contains('select2')
		? next
		: field;
}

/**
 * Says under each required field of `side` left empty what is missing: its
 * template; with a blank, its placement; and each custom size's width and
 * height, or, once both are in, that the size is past the placement's largest
 * print. The messages are the side's `data-missing-*` and `data-size-over`
 * attributes. A side just added is left alone until `force`. Answers how many
 * fields are missing or wrong.
 */
export function checkSide(
	side: HTMLElement,
	withBlank: boolean,
	force = false,
): number {
	const skip = !force && side.dataset[PRISTINE] !== undefined;
	let missing = 0;
	const check = (
		field: HTMLElement | null,
		anchor: HTMLElement | null,
		empty: boolean,
		message: string | undefined,
	) => {
		if (!field || !anchor) {
			return;
		}
		const shown = empty && !skip && message !== undefined;
		setFieldError(field, anchor, shown ? message : null);
		missing += empty ? 1 : 0;
	};
	const template = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-template',
	);
	check(
		template,
		template && anchorOf(template),
		template?.value === '',
		side.dataset.missingTemplate,
	);
	const placement = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-placement',
	);
	check(
		placement,
		placement && anchorOf(placement),
		withBlank && placement?.value === '',
		side.dataset.missingPlacement,
	);
	for (const row of side.querySelectorAll<HTMLElement>('.pixonaut-size')) {
		const custom =
			row.querySelector<HTMLInputElement>('.pixonaut-size-kind')?.value ===
			CUSTOM_SIZE;
		const empty = [
			...row.querySelectorAll<HTMLInputElement>(
				'.pixonaut-size-width, .pixonaut-size-height',
			),
		].filter((input) => custom && input.value.trim() === '');
		showSizeLimit(side, row, custom);
		// A missing dimension is said first; a size past the placement's largest print once both are in.
		const limit = custom && empty.length === 0 ? placementLimit(side) : null;
		const over = overLimit(row, limit);
		const wrong = empty.length > 0 ? empty : over;
		const anchor =
			row.querySelector<HTMLElement>('.pixonaut-size-limit') ??
			row.querySelector<HTMLElement>('.pixonaut-size-custom');
		const shown = wrong.length > 0 && !skip;
		let message: string | null = null;
		if (shown && empty.length > 0) {
			message = side.dataset.missingSize ?? null;
		} else if (shown && limit !== null && side.dataset.sizeOver) {
			message = describeLimit(side.dataset.sizeOver, limit);
		}
		if (anchor) {
			// One message for the row; each dimension says itself whether it is wrong.
			setFieldError(null, anchor, message);
		}
		for (const input of row.querySelectorAll<HTMLInputElement>(
			'.pixonaut-size-width, .pixonaut-size-height',
		)) {
			if (shown && wrong.includes(input)) {
				input.setAttribute('aria-invalid', 'true');
			} else {
				input.removeAttribute('aria-invalid');
			}
		}
		missing += wrong.length > 0 ? 1 : 0;
	}
	return missing;
}

/** The name of a select's chosen option; empty when nothing is chosen, or
 *  when the option only repeats its id, as a template whose name is not
 *  known yet does. */
function chosenText(select: HTMLSelectElement | null): string {
	const option =
		select && select.value !== '' ? select.selectedOptions[0] : null;
	const text = option?.text.trim() ?? '';
	return text === option?.value ? '' : text;
}

/** Names the side in its heading: its name as typed, else what an empty
 *  name follows, its print position's name, else its template's, else its
 *  key, else "Side"; never a template id. The name field's placeholder
 *  shows what it follows. */
export function showSideName(side: HTMLElement): void {
	const label = side.querySelector<HTMLInputElement>('.pixonaut-side-label');
	const name = side.querySelector<HTMLElement>('.pixonaut-side__name');
	if (!name) {
		return;
	}
	const followed =
		chosenText(
			side.querySelector<HTMLSelectElement>('.pixonaut-side-placement'),
		) ||
		chosenText(
			side.querySelector<HTMLSelectElement>('.pixonaut-side-template'),
		);
	if (label) {
		label.dataset.placeholder ??= label.placeholder;
		label.placeholder = followed || label.dataset.placeholder;
	}
	const key = side.querySelector<HTMLInputElement>('.pixonaut-side-key');
	name.textContent =
		label?.value.trim() ||
		followed ||
		key?.value.trim() ||
		(name.dataset.placeholder ?? '');
}

/** Asks one of the plugin's routes; its results, or null when it cannot
 *  answer. */
async function resultsOf(
	route: string,
	nonce: string,
	params: Record<string, string>,
): Promise<SearchOption[] | null> {
	if (route === '') {
		return null;
	}
	const url = new URL(route, window.location.href);
	for (const [name, value] of Object.entries(params)) {
		url.searchParams.set(name, value);
	}
	try {
		const response = await fetch(url, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return null;
		}
		const answer = (await response.json()) as { results?: SearchOption[] };
		return answer.results ?? null;
	} catch {
		return null;
	}
}

/** A blank's placements, each with its first view's photo. */
function placementsOf(
	urls: SearchUrls,
	nonce: string,
	blank: string,
): Promise<Placement[] | null> {
	return resultsOf(urls.placements, nonce, { blank });
}

type Select2Event = { params: { data: SearchOption } };

/** The parts of jQuery and selectWoo the panel uses. */
type Wrapped = {
	selectWoo(options: Record<string, unknown>): Wrapped;
	on(event: string, handler: (event: Select2Event) => void): Wrapped;
	on(event: string, handler: () => void): Wrapped;
	on(
		event: string,
		handler: (event: { target: EventTarget | null }) => void,
	): Wrapped;
	val(value: string): Wrapped;
	trigger(event: string): Wrapped;
};

type JQuery = (element: Element) => Wrapped;

declare global {
	interface Window {
		jQuery?: JQuery;
	}
}

function searchField(
	$: JQuery,
	select: HTMLSelectElement,
	nonce: string,
	request: SearchRequest,
): Wrapped {
	return $(select).selectWoo({
		allowClear: true,
		placeholder: select.dataset.placeholder ?? '',
		minimumInputLength: 0,
		ajax: ajaxOptions(request, nonce),
		...thumbnailOptions,
	});
}

/** What shows each placement field's chosen picture. */
const chosenPlacements = new WeakMap<
	HTMLSelectElement,
	(url: string) => void
>();

/** The placement field: the blank's placements, each with its picture, and
 *  the chosen one's beside it. */
function placementField($: JQuery, select: HTMLSelectElement): Wrapped {
	const show = chosenThumbnail(select);
	chosenPlacements.set(select, show);
	return $(select)
		.selectWoo({
			minimumResultsForSearch: Number.POSITIVE_INFINITY,
			...thumbnailOptions,
		})
		.on('change', () =>
			show(select.selectedOptions[0]?.dataset.thumbnail ?? ''),
		);
}

/** A new element from a `<template>`'s markup, its placeholder index replaced. */
function fromTemplate(
	template: HTMLTemplateElement | null,
	placeholder: string,
	index: number,
): HTMLElement | null {
	if (!template) {
		return null;
	}
	const holder = document.createElement('div');
	holder.innerHTML = withIndex(template.innerHTML, placeholder, index);
	return holder.firstElementChild as HTMLElement | null;
}

/** Shows a size row's width, height and label only for a custom size, and
 *  the print position's own size otherwise. */
function initSize(row: HTMLElement, onRemove: () => void): void {
	const kind = row.querySelector<HTMLInputElement>('.pixonaut-size-kind');
	const custom = row.querySelector<HTMLElement>('.pixonaut-size-custom');
	const resolved = row.querySelector<HTMLElement>('.pixonaut-size-default');
	const show = () => {
		const isCustom = kind?.value === CUSTOM_SIZE;
		if (custom) {
			custom.hidden = !isCustom;
		}
		if (resolved) {
			resolved.hidden = isCustom;
		}
		const side = row.closest<HTMLElement>('.pixonaut-side');
		if (side) {
			showSizeLimit(side, row, isCustom);
		}
	};
	kind?.addEventListener('change', show);
	row.querySelector('.pixonaut-remove-size')?.addEventListener('click', () => {
		row.remove();
		onRemove();
	});
	show();
}

type Panel = {
	$: JQuery;
	nonce: string;
	urls: ReturnType<typeof searchUrls>;
	blank: HTMLSelectElement;
	/** After a side or size row was added or removed. */
	onChange: () => void;
};

/** Checks every side of a panel while it is personalised, and clears the
 *  messages while it is not. */
function checkPanel(root: HTMLElement, force = false): void {
	const personalised =
		root.querySelector<HTMLInputElement>('.pixonaut-personalise')?.checked ??
		false;
	const withBlank =
		root.querySelector<HTMLSelectElement>('.pixonaut-blank')?.value !== '';
	for (const side of root.querySelectorAll<HTMLElement>('.pixonaut-side')) {
		if (!personalised || side.closest('[hidden]') !== null) {
			for (const error of side.querySelectorAll('.pixonaut-field-error')) {
				error.remove();
			}
			continue;
		}
		checkSide(side, withBlank, force);
	}
}

/** Shows the configuration below Personalise only while it is ticked. */
export function showPersonalised(root: HTMLElement): void {
	const personalised =
		root.querySelector<HTMLInputElement>('.pixonaut-personalise')?.checked ??
		false;
	const rest = root.querySelector<HTMLElement>('.pixonaut-personalised');
	if (rest) {
		rest.hidden = !personalised;
	}
	const hint = root.querySelector<HTMLElement>('.pixonaut-unpersonalised-hint');
	if (hint) {
		hint.hidden = personalised;
	}
}

function initSide(panel: Panel, side: HTMLElement): void {
	const { $ } = panel;
	const placement = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-placement',
	);
	const template = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-template',
	);
	const templateName = side.querySelector<HTMLInputElement>(
		'.pixonaut-side-template-name',
	);
	const sizes = side.querySelector<HTMLElement>('.pixonaut-sizes');
	if (!placement || !template || !templateName || !sizes) {
		return;
	}
	// What is known of the chosen template: from the search that listed it, else unknown until saved.
	const offers = (known: boolean | undefined) => {
		side.dataset.offersProperties = known === undefined ? '' : String(known);
		showModeWarnings(side);
	};
	const background = (known: boolean | undefined) => {
		side.dataset.backgroundSeeThrough =
			known === undefined ? '' : String(known);
		showBackgroundWarning(side, panel.blank.value !== '');
	};
	// Reads the version the side is linked to; an answer to a side that moved on since is dropped.
	const readLinked = (show: (read: SearchOption | undefined) => void) => {
		const asked = linkedTemplateId(side, template.value);
		if (asked === '') {
			return;
		}
		void resultsOf(panel.urls.template, panel.nonce, { id: asked }).then(
			(results) => {
				if (linkedTemplateId(side, template.value) === asked) {
					show(results?.[0]);
				}
			},
		);
	};
	const showTemplate = chosenThumbnail(template);
	// A template whose name was not known when the side was saved takes the one read.
	const nameLinked = (read: SearchOption | undefined) => {
		const option = template.selectedOptions[0];
		if (
			!option ||
			!read ||
			read.text === read.id ||
			option.text !== option.value
		) {
			return;
		}
		option.text = read.text;
		templateName.value = read.text;
		$(template).trigger('change.select2');
		showSideName(side);
	};
	// What is shown of the version the side is linked to, as read.
	const showLinked = (read: SearchOption | undefined) => {
		showTemplate(read?.thumbnail ?? '');
		background(read?.backgroundSeeThrough);
		nameLinked(read);
	};
	const groups = initSideGroups(
		side,
		panel.nonce,
		linkedTemplateId(side, template.value),
		panel.onChange,
	);
	initStandingOptions(side);
	// Another version of the template: its groups, and new standing designs on the next update.
	initTemplateVersion(side, () => {
		standingOptionsChanged(side);
		groups?.versionChanged(linkedTemplateId(side, template.value));
		background(undefined);
		readLinked(showLinked);
	});
	const clearTemplate = () => {
		standingOptionsChanged(side);
		$(template).val('').trigger('change');
		templateName.value = '';
		offers(undefined);
		background(undefined);
		showMisfitWarning(side, '');
		showTemplate('');
		showTemplateStudio(side, '');
		groups?.templateChanged('');
		offerVersions(side, '');
	};
	// selectWoo announces a pick through jQuery, which native listeners miss.
	placementField($, placement).on('change', clearTemplate);
	searchField($, template, panel.nonce, (q) =>
		templateSearch(panel.urls, panel.blank.value, placement.value, q),
	)
		.on('select2:select', (event) => {
			standingOptionsChanged(side);
			templateName.value = event.params.data.text;
			offers(event.params.data.offersProperties);
			background(event.params.data.backgroundSeeThrough);
			showMisfitWarning(side, event.params.data.warning ?? '');
			showTemplate(event.params.data.thumbnail ?? '');
			showTemplateStudio(side, event.params.data.id);
			groups?.templateChanged(event.params.data.id);
			offerVersions(side, event.params.data.id);
		})
		.on('select2:clear', () => {
			standingOptionsChanged(side);
			templateName.value = '';
			offers(undefined);
			background(undefined);
			showMisfitWarning(side, '');
			showTemplate('');
			showTemplateStudio(side, '');
			groups?.templateChanged('');
			offerVersions(side, '');
		});
	side.querySelector('.pixonaut-side-mode')?.addEventListener('change', () => {
		showModeWarnings(side);
		showGroups(side);
		showStandingOptions(side);
	});
	// A size changed, added or removed makes new designs on the next update.
	for (const event of ['change', 'input']) {
		sizes.addEventListener(event, () => standingOptionsChanged(side));
	}
	sizes.addEventListener('click', (event) => {
		if ((event.target as Element).closest('.pixonaut-remove-size')) {
			standingOptionsChanged(side);
		}
	});
	showModeWarnings(side);
	initValueSizes(side);
	side
		.querySelector('.pixonaut-side-label')
		?.addEventListener('input', () => showSideName(side));
	$(placement).on('change', () => showSideName(side));
	$(template).on('change', () => showSideName(side));
	showSideName(side);
	showSideSummary(side);
	// The saved template's picture: its link is never stored, so it is asked for.
	readLinked(showLinked);

	for (const row of sizes.querySelectorAll<HTMLElement>('.pixonaut-size')) {
		initSize(row, panel.onChange);
	}
	let sizeIndex = sizes.children.length;
	const addSize = (kind: string) => {
		const row = fromTemplate(
			side.querySelector('template.pixonaut-size-template'),
			NEW_SIZE_INDEX,
			sizeIndex++,
		);
		const kindField = row?.querySelector<HTMLInputElement>(
			'.pixonaut-size-kind',
		);
		if (row && kindField) {
			kindField.value = kind;
			sizes.append(row);
			initSize(row, panel.onChange);
			standingOptionsChanged(side);
			panel.onChange();
			row.querySelector<HTMLInputElement>('.pixonaut-size-label')?.focus();
		}
	};
	side
		.querySelector('.pixonaut-add-size')
		?.addEventListener('click', () => addSize(PLACEMENT_DEFAULT_SIZE));
	side
		.querySelector('.pixonaut-add-custom-size')
		?.addEventListener('click', () => addSize(CUSTOM_SIZE));
	side.querySelector('.pixonaut-remove-side')?.addEventListener('click', () => {
		side.remove();
		panel.onChange();
	});
}

export function initPanel(root: HTMLElement): void {
	const $ = window.jQuery;
	const blank = root.querySelector<HTMLSelectElement>('.pixonaut-blank');
	const sides = root.querySelector<HTMLElement>('.pixonaut-sides');
	const addSide = root.querySelector<HTMLButtonElement>('.pixonaut-add-side');
	const sideTemplate = root.querySelector<HTMLTemplateElement>(
		'template.pixonaut-side-template',
	);
	if (!$ || !blank || !sides || !addSide) {
		return;
	}
	const maxSides = Number(root.dataset.maxSides ?? '1');
	const sideElements = () => [
		...sides.querySelectorAll<HTMLElement>('.pixonaut-side'),
	];
	// Without a blank a product has one side, its template alone.
	const update = () => {
		const withBlank = blank.value !== '';
		for (const element of root.querySelectorAll<HTMLElement>(
			'.pixonaut-with-blank',
		)) {
			element.hidden = !withBlank;
		}
		sideElements().forEach((side, index) => {
			side.hidden = !withBlank && index > 0;
		});
		// One side sits in the group as it is; several are each a card.
		root.classList.toggle(
			'pixonaut-has-sides',
			withBlank && sideElements().length > 1,
		);
		addSide.disabled = sideElements().length >= maxSides;
		customSizesWithoutBlank(root, withBlank);
		showSideHints(root, sideElements().length, withBlank);
		if (root.dataset.blankFieldsUrl === undefined) {
			showViews(root, blank.value);
		}
	};
	// The printshop, variant and views of a blank picked, read before the product is updated.
	const reads = latestOnly();
	const readBlank = (id: string) => {
		if (root.dataset.blankFieldsUrl === undefined) {
			return;
		}
		const read = reads.next();
		showBlankLoading(root, true);
		void readBlankParts(root, id).then((parts: BlankParts | null) => {
			if (!reads.isLatest(read)) {
				return;
			}
			if (parts === null) {
				showBlankFailed(root);
				return;
			}
			showBlankParts(root, parts);
			initRetiredVariants(root);
			offerInitialViews(root);
			showOpeningView(root);
			showViewsSummary(root);
			showBlankLoading(root, false);
		});
	};
	// Without a blank nothing of the last one stays, nor a read still to answer.
	const forgetBlank = () => {
		if (root.dataset.blankFieldsUrl === undefined) {
			return;
		}
		reads.next();
		showBlankParts(root, { blank_html: '', views_html: '' });
		showBlankLoading(root, false);
	};
	const panel: Panel = {
		$,
		nonce: root.dataset.restNonce ?? '',
		urls: searchUrls(root),
		blank,
		onChange: () => {
			if (sideElements().length === 0) {
				add();
			}
			update();
			markChanged(root);
		},
	};
	let placements: Placement[] = [];
	const add = () => {
		const side = fromTemplate(
			sideTemplate,
			NEW_SIDE_INDEX,
			nextIndex(sideElements().map((s) => s.dataset.index ?? '')),
		);
		if (!side) {
			return;
		}
		side.dataset[PRISTINE] = '';
		sides.append(side);
		const placement = side.querySelector<HTMLSelectElement>(
			'.pixonaut-side-placement',
		);
		if (placement && placements.length > 0) {
			fillPlacements(placement, placements);
			placement.value = '';
		}
		initSide(panel, side);
		update();
		markChanged(root);
	};

	const fillAll = (next: Placement[]) => {
		placements = next;
		for (const side of sideElements()) {
			const placement = side.querySelector<HTMLSelectElement>(
				'.pixonaut-side-placement',
			);
			if (placement) {
				fillPlacements(placement, next);
				placement.dispatchEvent(new Event('change'));
			}
		}
	};
	const showBlank = chosenThumbnail(blank);
	searchField($, blank, panel.nonce, (q) => ({
		url: panel.urls.blanks,
		data: { q },
	}))
		.on('select2:select', (event) => {
			const { id, placements: listed, thumbnail } = event.params.data;
			showBlank(thumbnail ?? '');
			fillAll(listed ?? []);
			update();
			readBlank(id);
			// The pictures, once they come; the placements picked meanwhile stay.
			void placementsOf(panel.urls, panel.nonce, id).then((pictured) => {
				if (pictured !== null && blank.value === id) {
					placements = pictured;
					for (const select of root.querySelectorAll<HTMLSelectElement>(
						'.pixonaut-side-placement',
					)) {
						addThumbnails(select, pictured);
						chosenPlacements.get(select)?.(
							select.selectedOptions[0]?.dataset.thumbnail ?? '',
						);
					}
					// The placements' limits may have come with them.
					checkPanel(root);
				}
			});
		})
		.on('select2:clear', () => {
			showBlank('');
			forgetBlank();
			fillAll([]);
			update();
		});

	for (const side of sideElements()) {
		initSide(panel, side);
	}
	addSide.addEventListener('click', add);
	// Delegated: the views are drawn again when another blank is picked.
	root.addEventListener('change', (event) => {
		if (
			event.target instanceof Element &&
			event.target.closest('.pixonaut-views') !== null
		) {
			offerInitialViews(root);
			showOpeningView(root);
		}
	});
	initDisclosures(root);
	initPictureCards(root);
	showStoreSummary(root);
	root
		.querySelector('.pixonaut-personalise')
		?.addEventListener('change', () => {
			showPersonalised(root);
			checkPanel(root);
		});
	// jQuery's, so selectWoo's picks are heard too.
	$(root).on('change', (event: { target: EventTarget | null }) => {
		if (event.target instanceof Element) {
			const side = event.target.closest<HTMLElement>('.pixonaut-side');
			if (side) {
				delete side.dataset[PRISTINE];
			}
		}
		checkPanel(root);
	});
	// A configuration not personalised yet has nothing missing until saved.
	if (!root.querySelector<HTMLInputElement>('.pixonaut-personalise')?.checked) {
		for (const side of sideElements()) {
			side.dataset[PRISTINE] = '';
		}
	}
	offerInitialViews(root);
	showOpeningView(root);
	showPersonalised(root);
	update();
	checkPanel(root);
}

/** Says what is missing in every panel on the page, sides just added too:
 *  the product or its variations are being saved. */
export function checkAll(container: ParentNode): void {
	for (const root of container.querySelectorAll<HTMLElement>(
		'.pixonaut-fields-root',
	)) {
		checkPanel(root, true);
	}
}

/** Sets up the variation panels WooCommerce has loaded since the last call. */
function initVariations(): void {
	for (const panel of newPanels(document)) {
		initOverride(panel);
		initVariationSizes(panel);
		initPanel(panel);
		initRetiredVariants(panel);
	}
}

domReady(() => {
	initUsePicture(document);
	initSplitListing(document);
	const panel = document.querySelector<HTMLElement>('.pixonaut-product-panel');
	if (panel) {
		initPanel(panel);
		initRetiredVariants(panel);
		initRegeneratePictures(panel, { nonce: panel.dataset.restNonce ?? '' });
	}
	// WooCommerce loads the variations, a page at a time, after the screen.
	window
		.jQuery?.(document.body)
		.on(
			'woocommerce_variations_loaded woocommerce_variations_added',
			initVariations,
		);
	document
		.getElementById('post')
		?.addEventListener('submit', () => checkAll(document));
	document.addEventListener('click', (event) => {
		if (
			event.target instanceof Element &&
			event.target.closest('.save-variation-changes')
		) {
			checkAll(document);
		}
	});
});
