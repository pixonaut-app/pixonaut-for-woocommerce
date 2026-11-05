// A side's Groups setting: the template's form groups as an accordion, the
// product's attributes used for variations in a tray, drag and drop (and
// Alt + arrow keys) to arrange them, and renames for this product. The
// setting is one collapsible section, closed whenever the page opens. The
// arrangement is posted as JSON in the side's hidden `form_groups` field;
// nothing is posted until the store manager changes something.
import {
	type Arrangement,
	type Attribute,
	addGroup,
	arrange,
	type Group,
	type Item,
	isAttribute,
	isOwn,
	itemKey,
	moveGroup,
	moveItem,
	type Place,
	placeAttribute,
	removeGroup,
	type StoredGroup,
	stored,
	type TemplateField,
	type TemplateGroup,
	unplaceAttribute,
} from './form-arrangement';
import {
	element,
	fieldWords,
	parse,
	type Strings,
	templateGroups,
} from './side-groups-parts';

/** What is being dragged. */
type Dragged =
	| { kind: 'group'; group: number }
	| { kind: 'item'; place: Place }
	| { kind: 'attribute'; slug: string };

/** The setting of one side. */
export type SideGroups = {
	/** Reads the template's groups again, for another template. */
	templateChanged(templateId: string): void;
	/** Reads the groups of another version of the same template, keeping the
	 *  arrangement, as a side keeps it when it moves to a new version. */
	versionChanged(templateId: string): void;
};

const ROW_CLASS = 'pixonaut-groups';

/** Opens and closes the setting with its heading's button. */
function collapsible(row: HTMLElement): void {
	const expand = row.querySelector<HTMLButtonElement>(
		':scope > .pixonaut-groups__expand',
	);
	const content = row.querySelector<HTMLElement>(
		':scope > .pixonaut-groups__content',
	);
	const summary = row.querySelector<HTMLElement>(
		':scope > .pixonaut-groups__summary',
	);
	const arrow = expand?.querySelector<HTMLElement>('.pixonaut-groups__arrow');
	if (!expand || !content) {
		return;
	}
	expand.addEventListener('click', () => {
		const open = content.hidden;
		content.hidden = !open;
		if (summary) {
			summary.hidden = open;
		}
		expand.setAttribute('aria-expanded', String(open));
		if (arrow) {
			arrow.textContent = open ? '▾' : '▸';
		}
	});
}

/**
 * Sets up the Groups setting of `side`, or nothing where it has none.
 * `onChange` is called after each change of the arrangement.
 */
export function initSideGroups(
	side: HTMLElement,
	nonce: string,
	templateId: string,
	onChange: () => void,
): SideGroups | null {
	const row = side.querySelector<HTMLElement>(`.${ROW_CLASS}`);
	const body = row?.querySelector<HTMLElement>('.pixonaut-groups__body');
	const value = row?.querySelector<HTMLInputElement>('.pixonaut-groups-value');
	if (!row || !body || !value) {
		return null;
	}
	collapsible(row);
	const strings = parse<Strings>(row.dataset.strings, {});
	const attributes = parse<Attribute[]>(row.dataset.attributes, []);
	const url = row.dataset.url ?? '';
	const say = (key: string) => strings[key] ?? '';
	let template: TemplateGroup[] = [];
	let arrangement: Arrangement | null = null;
	let dragged: Dragged | null = null;
	const open = new Set<string>();
	/** Whether the tray shows its options; closed, as the groups are, until opened. */
	let trayOpen = false;
	const more = new Set<string>();
	let reading = 0;
	/** The `data-key` of the handle to focus once drawn again. */
	let refocus: string | null = null;
	/** Whether the tray's toggle takes the focus once drawn again. */
	let refocusTray = false;
	/** The id of the group whose name field to focus once drawn. */
	let refocusTitle: string | null = null;

	const fieldOf = (item: Item): TemplateField | undefined =>
		isAttribute(item)
			? undefined
			: template
					.flatMap((group) => group.fields)
					.find((field) => field.field === item.field);
	const labelOf = (slug: string) =>
		attributes.find((one) => one.slug === slug)?.label ?? slug;

	const changed = () => {
		if (arrangement !== null) {
			value.value = JSON.stringify({ groups: stored(arrangement) });
		}
		onChange();
	};
	const status = (text: string) => {
		body.replaceChildren(element('span', 'description', text));
	};

	const moveKeys = (event: KeyboardEvent, move: (by: number) => void) => {
		if (
			!event.altKey ||
			(event.key !== 'ArrowUp' && event.key !== 'ArrowDown')
		) {
			return;
		}
		event.preventDefault();
		// The handle is drawn again: the new one takes the focus.
		refocus = (event.currentTarget as HTMLElement | null)?.dataset.key ?? null;
		move(event.key === 'ArrowUp' ? -1 : 1);
	};

	/** A drop on `list`, the items of group `group`, at the pointer. */
	const dropIndex = (list: HTMLElement, y: number) => {
		const rows = [
			...list.querySelectorAll<HTMLElement>(':scope > .pixonaut-groups__item'),
		];
		const after = rows.findIndex((one) => {
			const box = one.getBoundingClientRect();
			return y < box.top + box.height / 2;
		});
		return after === -1 ? rows.length : after;
	};
	const dropOnItems = (group: number, index: number) => {
		if (arrangement === null || dragged === null) {
			return;
		}
		if (dragged.kind === 'item') {
			moveItem(arrangement, dragged.place, { group, index });
		} else if (dragged.kind === 'attribute') {
			placeAttribute(arrangement, dragged.slug, { group, index });
		}
	};

	const handle = (label: string) => {
		const button = element('button', 'pixonaut-groups__handle', '⋮⋮');
		button.type = 'button';
		button.draggable = true;
		button.setAttribute('aria-label', label);
		return button;
	};

	const renderItem = (item: Item, place: Place): HTMLElement => {
		const li = element('li', 'pixonaut-groups__item');
		li.classList.toggle('is-option', isAttribute(item));
		const line = element('span', 'pixonaut-groups__line');
		const grip = handle(say('move'));
		grip.dataset.key = itemKey(item);
		grip.addEventListener('dragstart', (event) => {
			dragged = { kind: 'item', place };
			event.dataTransfer?.setData('text/plain', '');
		});
		grip.addEventListener('keydown', (event) =>
			moveKeys(event, (by) => {
				if (arrangement === null) {
					return;
				}
				const group = arrangement.groups[place.group];
				const index = place.index + by;
				if (group !== undefined && index >= 0 && index < group.items.length) {
					moveItem(arrangement, place, {
						group: place.group,
						index: by > 0 ? index + 1 : index,
					});
				} else if (arrangement.groups[place.group + by] !== undefined) {
					const next = arrangement.groups[place.group + by] as Group;
					moveItem(arrangement, place, {
						group: place.group + by,
						index: by > 0 ? 0 : next.items.length,
					});
				} else {
					return;
				}
				render();
				changed();
			}),
		);
		line.append(grip);
		if (isAttribute(item)) {
			line.append(
				element('span', 'pixonaut-groups__kind', say('option')),
				element('span', 'pixonaut-groups__name', labelOf(item.attribute)),
				element('span', 'description', say('wcOption')),
			);
			// Back to the tray without dragging, for the keyboard.
			const unplace = element('button', 'button-link', say('unplace'));
			unplace.type = 'button';
			unplace.addEventListener('click', () => {
				if (arrangement !== null) {
					unplaceAttribute(arrangement, place, attributes);
					trayOpen = true;
					render();
					changed();
				}
			});
			line.append(unplace);
			li.append(line);
			return li;
		}
		const field = fieldOf(item);
		const types = strings.types ?? {};
		line.append(
			element(
				'span',
				'pixonaut-groups__kind',
				types[field?.type ?? ''] ?? say('field'),
			),
		);
		const label = element('input', 'pixonaut-groups__input');
		label.type = 'text';
		label.value = item.label ?? '';
		label.placeholder = field?.label ?? item.field;
		label.setAttribute('aria-label', say('fieldLabel'));
		label.addEventListener('input', () => {
			item.label = label.value;
			changed();
		});
		const toggle = element(
			'button',
			'button-link',
			more.has(item.field) ? say('fewer') : say('more'),
		);
		toggle.type = 'button';
		toggle.setAttribute('aria-expanded', String(more.has(item.field)));
		toggle.addEventListener('click', () => {
			more.has(item.field) ? more.delete(item.field) : more.add(item.field);
			render();
		});
		line.append(label, toggle);
		if (item.isNew === true) {
			line.append(element('span', 'pixonaut-groups__new', say('new')));
		}
		li.append(line);
		if (more.has(item.field)) {
			li.append(fieldWords(item, field, say, changed));
		}
		return li;
	};

	const renderGroup = (group: Group, index: number): HTMLElement => {
		const box = element('div', 'pixonaut-groups__group');
		const head = element('div', 'pixonaut-groups__head');
		const grip = handle(say('move'));
		grip.dataset.key = `group:${group.id}`;
		grip.addEventListener('dragstart', (event) => {
			dragged = { kind: 'group', group: index };
			event.dataTransfer?.setData('text/plain', '');
		});
		grip.addEventListener('keydown', (event) =>
			moveKeys(event, (by) => {
				if (
					arrangement === null ||
					arrangement.groups[index + by] === undefined
				) {
					return;
				}
				moveGroup(arrangement, index, by > 0 ? index + 2 : index - 1);
				render();
				changed();
			}),
		);
		const isOpen =
			open.has(group.id) || (isOwn(group.id) && group.items.length === 0);
		const toggle = element(
			'button',
			'pixonaut-groups__toggle',
			isOpen ? '▾' : '▸',
		);
		toggle.type = 'button';
		toggle.setAttribute('aria-expanded', String(isOpen));
		toggle.setAttribute('aria-label', say('toggle'));
		toggle.addEventListener('click', () => {
			open.has(group.id) ? open.delete(group.id) : open.add(group.id);
			render();
		});
		const title = element(
			'input',
			'pixonaut-groups__input pixonaut-groups__title',
		);
		title.type = 'text';
		title.value = group.title;
		title.placeholder =
			template.find((one) => one.id === group.id)?.label ??
			say('newGroupTitle');
		title.setAttribute('aria-label', say('groupName'));
		title.addEventListener('input', () => {
			group.title = title.value;
			changed();
		});
		head.append(grip, toggle, title);
		if (isOwn(group.id)) {
			const remove = element(
				'button',
				'button-link button-link-delete',
				say('remove'),
			);
			remove.type = 'button';
			remove.addEventListener('click', () => {
				if (arrangement !== null) {
					removeGroup(arrangement, index, attributes);
					render();
					changed();
				}
			});
			head.append(
				element('span', 'pixonaut-groups__tag', say('ownGroup')),
				remove,
			);
		}
		box.append(head);
		if (!isOpen) {
			return box;
		}
		const list = element('ul', 'pixonaut-groups__items');
		group.items.forEach((item, at) => {
			list.append(renderItem(item, { group: index, index: at }));
		});
		if (group.items.length === 0) {
			list.append(element('li', 'description', say('emptyGroup')));
		}
		list.addEventListener('dragover', (event) => {
			if (dragged !== null && dragged.kind !== 'group') {
				event.preventDefault();
			}
		});
		list.addEventListener('drop', (event) => {
			// A group dropped here is the groups' to place.
			if (dragged === null || dragged.kind === 'group') {
				return;
			}
			event.preventDefault();
			dropOnItems(index, dropIndex(list, event.clientY));
			dragged = null;
			render();
			changed();
		});
		box.append(list);
		return box;
	};

	const renderTray = (): HTMLElement => {
		const tray = element('div', 'pixonaut-groups__tray');
		const head = element('div', 'pixonaut-groups__head');
		const toggle = element(
			'button',
			'pixonaut-groups__toggle',
			trayOpen ? '▾' : '▸',
		);
		toggle.type = 'button';
		toggle.setAttribute('aria-expanded', String(trayOpen));
		toggle.setAttribute('aria-label', say('trayToggle'));
		toggle.addEventListener('click', () => {
			trayOpen = !trayOpen;
			refocusTray = true;
			render();
		});
		head.append(toggle, element('strong', '', say('tray')));
		tray.append(head);
		const sides =
			side.closest('.pixonaut-sides')?.querySelectorAll('.pixonaut-side')
				.length ?? 1;
		const chips = element('span', 'pixonaut-groups__chips');
		for (const slug of arrangement?.tray ?? []) {
			const chip = element('button', 'pixonaut-groups__chip', labelOf(slug));
			chip.type = 'button';
			chip.draggable = true;
			chip.addEventListener('dragstart', (event) => {
				dragged = { kind: 'attribute', slug };
				event.dataTransfer?.setData('text/plain', '');
			});
			// Enter or a click puts it first in the first group, for the keyboard.
			chip.addEventListener('click', () => {
				if (arrangement !== null && arrangement.groups.length > 0) {
					placeAttribute(arrangement, slug, { group: 0, index: 0 });
					open.add(arrangement.groups[0]?.id ?? '');
					render();
					changed();
				}
			});
			chips.append(chip);
		}
		const content = element('div', 'pixonaut-groups__tray-body');
		if (chips.childElementCount > 0) {
			content.append(chips);
		}
		const hint =
			attributes.length === 0
				? say('noOptions')
				: sides > 1
					? say('manySides')
					: (arrangement?.tray.length ?? 0) > 0
						? say('trayHint')
						: say('trayEmpty');
		content.append(element('span', 'description', hint));
		if (trayOpen) {
			tray.append(content);
		}
		// Closed, its head still takes an option dragged back.
		tray.addEventListener('dragover', (event) => {
			if (dragged?.kind === 'item' && arrangement !== null) {
				const item =
					arrangement.groups[dragged.place.group]?.items[dragged.place.index];
				if (item !== undefined && isAttribute(item)) {
					event.preventDefault();
				}
			}
		});
		tray.addEventListener('drop', (event) => {
			event.preventDefault();
			if (dragged?.kind === 'item' && arrangement !== null) {
				unplaceAttribute(arrangement, dragged.place, attributes);
				trayOpen = true;
				dragged = null;
				render();
				changed();
			}
		});
		return tray;
	};

	const renderNewGroup = (): HTMLElement => {
		const zone = element(
			'button',
			'pixonaut-groups__new-group',
			say('newGroup'),
		);
		zone.type = 'button';
		// A click starts an empty group of the product's own, to be named.
		zone.addEventListener('click', () => {
			if (arrangement === null) {
				return;
			}
			const group = addGroup(arrangement, '');
			open.add(group.id);
			refocusTitle = group.id;
			render();
			changed();
		});
		zone.addEventListener('dragover', (event) => {
			if (dragged !== null && dragged.kind !== 'group') {
				event.preventDefault();
			}
		});
		zone.addEventListener('drop', (event) => {
			event.preventDefault();
			if (
				arrangement === null ||
				dragged === null ||
				dragged.kind === 'group'
			) {
				return;
			}
			const from = dragged;
			const item =
				from.kind === 'item'
					? arrangement.groups[from.place.group]?.items[from.place.index]
					: undefined;
			// Named after what it starts with, so it is shown before it is renamed.
			const title =
				from.kind === 'attribute'
					? labelOf(from.slug)
					: item === undefined || isAttribute(item)
						? labelOf(item?.attribute ?? '')
						: (item.label ?? fieldOf(item)?.label ?? '');
			const group = addGroup(arrangement, title);
			open.add(group.id);
			dropOnItems(arrangement.groups.length - 1, 0);
			dragged = null;
			render();
			changed();
		});
		return zone;
	};

	const render = () => {
		if (arrangement === null) {
			return;
		}
		const groups = element('div', 'pixonaut-groups__groups');
		arrangement.groups.forEach((group, index) => {
			groups.append(renderGroup(group, index));
		});
		groups.addEventListener('dragover', (event) => {
			if (dragged?.kind === 'group') {
				event.preventDefault();
			}
		});
		groups.addEventListener('drop', (event) => {
			if (dragged?.kind !== 'group' || arrangement === null) {
				return;
			}
			event.preventDefault();
			const boxes = [
				...groups.querySelectorAll<HTMLElement>(
					':scope > .pixonaut-groups__group',
				),
			];
			const at = boxes.findIndex((box) => {
				const rect = box.getBoundingClientRect();
				return event.clientY < rect.top + rect.height / 2;
			});
			moveGroup(arrangement, dragged.group, at === -1 ? boxes.length : at);
			dragged = null;
			render();
			changed();
		});
		const notices: HTMLElement[] = [];
		if (arrangement.gone.length > 0) {
			notices.push(
				element(
					'div',
					'pixonaut-groups__notice',
					say('gone').replace(
						'%s',
						arrangement.gone.map((name) => `“${name}”`).join(', '),
					),
				),
			);
		}
		if (
			arrangement.groups.some((group) =>
				group.items.some((item) => item.isNew === true),
			)
		) {
			notices.push(element('div', 'pixonaut-groups__notice', say('added')));
		}
		const reset = element('button', 'button-link', say('reset'));
		reset.type = 'button';
		reset.addEventListener('click', () => {
			arrangement = arrange(template, attributes, null);
			value.value = '';
			render();
			onChange();
		});
		const footer = element('span', 'pixonaut-groups__footer');
		footer.append(reset);
		body.replaceChildren(
			...notices,
			renderTray(),
			groups,
			renderNewGroup(),
			footer,
		);
		const titled = refocusTitle;
		refocusTitle = null;
		if (titled !== null) {
			[...body.querySelectorAll<HTMLElement>('.pixonaut-groups__handle')]
				.find((one) => one.dataset.key === `group:${titled}`)
				?.closest('.pixonaut-groups__head')
				?.querySelector<HTMLInputElement>('.pixonaut-groups__title')
				?.focus();
		}
		if (refocusTray) {
			refocusTray = false;
			body
				.querySelector<HTMLElement>(
					'.pixonaut-groups__tray .pixonaut-groups__toggle',
				)
				?.focus();
		}
		const focused = refocus;
		refocus = null;
		[...body.querySelectorAll<HTMLElement>('.pixonaut-groups__handle')]
			.find((one) => one.dataset.key === focused)
			?.focus();
	};

	const read = (id: string) => {
		const reads = ++reading;
		if (id === '') {
			arrangement = null;
			status(say('noTemplate'));
			return;
		}
		status(say('loading'));
		void templateGroups(url, nonce, id).then((groups) => {
			if (reads !== reading) {
				return;
			}
			if (groups === null) {
				arrangement = null;
				status(say('unreachable'));
				return;
			}
			template = groups;
			const saved = parse<{ groups?: StoredGroup[] } | null>(value.value, null);
			arrangement = arrange(template, attributes, saved?.groups ?? null);
			open.clear();
			const first = arrangement.groups[0];
			if (first !== undefined) {
				open.add(first.id);
			}
			render();
		});
	};

	read(templateId);
	return {
		templateChanged(id: string) {
			// Another template's groups and fields are not this one's.
			value.value = '';
			read(id);
		},
		versionChanged(id: string) {
			read(id);
		},
	};
}
