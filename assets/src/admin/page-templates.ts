// A side's Page layouts field: the other templates a page of a book may be
// designed on, offered only on a placement printed as pages.

/** Whether a placement option is printed as pages, as the panel marks it. */
export function printedAsPages(option: HTMLOptionElement | undefined): boolean {
	return option?.dataset.pages === 'true';
}

/** Marks a placement option printed as pages, for `showPageTemplates()`. */
export function markPages(
	option: HTMLOptionElement,
	placement: { pages?: boolean },
): void {
	if (placement.pages === true) {
		option.dataset.pages = 'true';
	} else {
		delete option.dataset.pages;
	}
}

/**
 * Shows a side's Page layouts field while its placement is printed as pages,
 * or while it holds layouts, so layouts kept on a placement that no longer
 * is can be seen and taken off.
 */
export function showPageTemplates(side: HTMLElement): void {
	const field = side.querySelector<HTMLElement>(
		'.pixonaut-side-page-templates-field',
	);
	const select = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-page-templates',
	);
	const placement = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-placement',
	);
	if (!field || !select) {
		return;
	}
	field.hidden =
		!printedAsPages(placement?.selectedOptions[0]) &&
		select.selectedOptions.length === 0;
}

/** Takes every layout off a side, as a new placement or template does. */
export function clearPageTemplates(side: HTMLElement): void {
	const select = side.querySelector<HTMLSelectElement>(
		'.pixonaut-side-page-templates',
	);
	if (select) {
		select.replaceChildren();
	}
	showPageTemplates(side);
}
