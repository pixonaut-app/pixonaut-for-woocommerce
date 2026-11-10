// The views a product is shown in (`admin/product-views.php`): which view the
// designer opens on is badged on its tile and named in the section's summary,
// and the hint under the tiles says what the ticks mean, as they change.

/** Where the view's name goes in the summary's `data-text`, as `sprintf` writes it. */
const NAME = '%s';

/**
 * Badges the view the designer opens on: the one Opens on names, else the
 * first view shown (the first ticked, or the first while none is).
 */
export function showOpeningView(root: ParentNode): void {
	const menu = root.querySelector<HTMLElement>('.pixonaut-views');
	if (!menu) {
		return;
	}
	const boxes = [
		...menu.querySelectorAll<HTMLInputElement>(
			'.pixonaut-views__list input[type=checkbox]',
		),
	];
	const ticked = boxes.filter((box) => box.checked);
	const chosen =
		menu.querySelector<HTMLSelectElement>('.pixonaut-initial-view')?.value ??
		'';
	const opens = chosen !== '' ? chosen : ((ticked[0] ?? boxes[0])?.value ?? '');
	let name = '';
	for (const box of boxes) {
		const view = box.closest('.pixonaut-view');
		const badge = view?.querySelector<HTMLElement>('.pixonaut-view__opens');
		if (badge) {
			badge.hidden = box.value !== opens;
		}
		if (box.value === opens) {
			name = box.closest('.pixonaut-view__name')?.textContent?.trim() ?? '';
		}
	}
	const summary = root.querySelector<HTMLElement>(
		'.pixonaut-views__opens-summary',
	);
	if (summary && name !== '') {
		summary.textContent = (summary.dataset.text ?? NAME).replace(NAME, name);
	}
	const hint = menu.querySelector<HTMLElement>('.pixonaut-views__hint');
	if (hint) {
		hint.textContent =
			(ticked.length === 0 ? hint.dataset.none : hint.dataset.some) ?? '';
	}
}
