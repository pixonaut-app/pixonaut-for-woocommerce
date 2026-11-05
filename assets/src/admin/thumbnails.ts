// The picture beside each name in the product screen's blank, placement and
// template pickers: selectWoo's `templateResult` and `templateSelection`.
// The pictures' links work for an hour or two; they are only shown.

/** What selectWoo passes for an option: one of the search routes' results,
 *  or an option of the select itself (`element`). */
export type PickerOption = {
	id?: string;
	text: string;
	thumbnail?: string;
	/** A line under the name, such as the sizes a template cannot be printed at. */
	note?: string;
	loading?: boolean;
	element?: HTMLOptionElement;
};

/** The picture's class; `--empty` when the option has none. */
export const THUMBNAIL_CLASS = 'pixonaut-thumbnail';

/** The option's picture: from its search result, else its `data-thumbnail`. */
export function thumbnailOf(option: PickerOption): string {
	return option.thumbnail ?? option.element?.dataset.thumbnail ?? '';
}

/**
 * The option as the picker lists it: its picture, or an empty tile when it
 * has none, beside its name and its note, if any. The placeholder and
 * "Searching…" rows stay text.
 */
export function withThumbnail(option: PickerOption): Node | string {
	if (option.loading || option.id === undefined || option.id === '') {
		return option.text;
	}
	const row = document.createElement('span');
	row.className = 'pixonaut-option';
	const url = thumbnailOf(option);
	let picture: HTMLElement;
	if (url === '') {
		picture = document.createElement('span');
		picture.className = `${THUMBNAIL_CLASS} ${THUMBNAIL_CLASS}--empty`;
		picture.setAttribute('aria-hidden', 'true');
	} else {
		const img = document.createElement('img');
		img.className = THUMBNAIL_CLASS;
		img.src = url;
		img.alt = '';
		img.loading = 'lazy';
		picture = img;
	}
	const name = document.createElement('span');
	name.textContent = option.text;
	if (option.note === undefined || option.note === '') {
		row.append(picture, name);
		return row;
	}
	const named = document.createElement('span');
	named.className = 'pixonaut-option__name';
	const note = document.createElement('span');
	note.className = 'pixonaut-option__note';
	note.textContent = option.note;
	named.append(name, note);
	row.append(picture, named);
	return row;
}

/** selectWoo's option that shows the pictures in the list; it draws the
 *  choice itself as text, so `chosenThumbnail` shows the chosen one's. */
export const thumbnailOptions = {
	templateResult: withThumbnail,
};

/**
 * Puts a picture before `select`, for the chosen option, and answers what
 * changes it: a picture's link, or empty to hide it. It starts on the
 * selected option's `data-thumbnail`.
 */
export function chosenThumbnail(
	select: HTMLSelectElement,
): (url: string) => void {
	const img = document.createElement('img');
	img.className = `${THUMBNAIL_CLASS} pixonaut-chosen-thumbnail`;
	img.alt = '';
	select.before(img);
	const show = (url: string) => {
		img.hidden = url === '';
		if (url !== '') {
			img.src = url;
		}
	};
	show(select.selectedOptions[0]?.dataset.thumbnail ?? '');
	return show;
}
