// The Pixonaut tab's closed sections (`<details class="pixonaut-disclosure">`):
// each summary says what is set inside, and the sections open when the
// product is updated stay open on the page the update leads to (the panel
// posts them in `.pixonaut-open-disclosures`).
import { isFixedDesign } from './sides';

/** Placeholders in the summaries' `data-` texts, as `sprintf` writes them. */
const COUNT = '%d';
const TICKED = '%1$d';
const ALL = '%2$d';

/** Remembers in the posted field which sections are open. */
function rememberOpen(root: HTMLElement): void {
	const field = root.querySelector<HTMLInputElement>(
		'.pixonaut-open-disclosures',
	);
	if (!field) {
		return;
	}
	field.value = [
		...root.querySelectorAll<HTMLDetailsElement>(
			'details.pixonaut-disclosure[open]',
		),
	]
		.map((details) => details.dataset.disclosure ?? '')
		.filter((key) => key !== '')
		.join(',');
}

/** The summary's text: `data-none` for nothing set, else `data-some` with
 *  `count` (and `all`) put in. */
function counted(summary: HTMLElement, count: number, all?: number): string {
	if (count === 0) {
		return summary.dataset.none ?? '';
	}
	return (summary.dataset.some ?? '')
		.replace(TICKED, String(count))
		.replace(ALL, String(all ?? count))
		.replace(COUNT, String(count));
}

/** Says how many of the store settings the product changes. */
export function showStoreSummary(root: ParentNode): void {
	const section = root.querySelector('.pixonaut-store-settings');
	const summary = section?.querySelector<HTMLElement>(
		'.pixonaut-disclosure__summary',
	);
	if (!section || !summary) {
		return;
	}
	const changed = [
		...section.querySelectorAll<HTMLSelectElement>('.pixonaut-store-setting'),
	].filter((select) => select.value !== '').length;
	summary.textContent = counted(summary, changed);
}

/** Says how many of the blank's views the product is shown in. */
export function showViewsSummary(root: ParentNode): void {
	const summary = root.querySelector<HTMLElement>(
		'.pixonaut-views-section .pixonaut-disclosure__summary',
	);
	if (!summary) {
		return;
	}
	const ticked = root.querySelectorAll(
		'.pixonaut-views__list input[type=checkbox]:checked',
	).length;
	summary.textContent = counted(
		summary,
		ticked,
		Number(summary.dataset.all ?? ticked),
	);
}

/** Says how a side is filled in and which template version it follows. */
export function showSideSummary(side: HTMLElement): void {
	const summary = side.querySelector<HTMLElement>(
		'.pixonaut-side-more__summary',
	);
	if (!summary) {
		return;
	}
	const pinned =
		side.querySelector<HTMLInputElement>('.pixonaut-side-pinned')?.checked ??
		false;
	const version =
		side.querySelector<HTMLSelectElement>('.pixonaut-side-version')?.value ??
		'';
	const how = isFixedDesign(side)
		? summary.dataset.fixed
		: summary.dataset.fills;
	const follows = pinned
		? (summary.dataset.stays ?? '').replace(COUNT, version)
		: summary.dataset.follow;
	summary.textContent = `${how ?? ''} · ${follows ?? ''}`;
}

/** Keeps the summaries and the open sections up to date. */
export function initDisclosures(root: HTMLElement): void {
	root.addEventListener(
		'toggle',
		(event) => {
			if (
				event.target instanceof HTMLDetailsElement &&
				event.target.classList.contains('pixonaut-disclosure')
			) {
				rememberOpen(root);
			}
		},
		true,
	);
	root.addEventListener('change', (event) => {
		const target = event.target;
		if (!(target instanceof Element)) {
			return;
		}
		const side = target.closest<HTMLElement>('.pixonaut-side');
		if (side) {
			showSideSummary(side);
		}
		showStoreSummary(root);
		showViewsSummary(root);
	});
	rememberOpen(root);
}
