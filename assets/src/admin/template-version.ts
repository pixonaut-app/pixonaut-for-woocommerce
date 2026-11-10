/** Placeholder in the picker's `data-version` label for the version's number. */
const VERSION_PLACEHOLDER = '%d';

/** The template id's separator between base id and version. */
const SEPARATOR = '@';

/** The version in a template id, `<baseId>@<version>`; null for none. */
export function versionOf(templateId: string): number | null {
	const at = templateId.lastIndexOf(SEPARATOR);
	const version = at > 0 ? templateId.slice(at + 1) : '';
	return /^[1-9][0-9]*$/.test(version) ? Number(version) : null;
}

/** The base id in a template id, `<baseId>@<version>`: all of it when it
 *  names no version. */
export function baseIdOf(templateId: string): string {
	return versionOf(templateId) === null
		? templateId
		: templateId.slice(0, templateId.lastIndexOf(SEPARATOR));
}

/**
 * Points a side's "Open in Pixonaut" link at the template's page in the
 * studio, under the templates page its `data-studio` names, and hides it
 * without a template.
 */
export function showTemplateStudio(
	side: HTMLElement,
	templateId: string,
): void {
	const link = side.querySelector<HTMLAnchorElement>(
		'.pixonaut-template-studio',
	);
	if (!link) {
		return;
	}
	const baseId = baseIdOf(templateId);
	link.hidden = baseId === '';
	if (baseId !== '') {
		link.href = `${link.dataset.studio ?? ''}/${encodeURIComponent(baseId)}`;
	}
}

function pickerOf(side: HTMLElement): HTMLSelectElement | null {
	return side.querySelector<HTMLSelectElement>('.pixonaut-side-version');
}

function isPinned(side: HTMLElement): boolean {
	return (
		side.querySelector<HTMLInputElement>('.pixonaut-side-pinned')?.checked ??
		false
	);
}

/**
 * The template version a side is linked to: the template field names the
 * latest, which a followed side is on; a side stuck to a version is on the
 * one picked. Empty without a template.
 */
export function linkedTemplateId(
	side: HTMLElement,
	templateId: string,
): string {
	const picked = pickerOf(side)?.value ?? '';
	const at = templateId.lastIndexOf(SEPARATOR);
	if (!isPinned(side) || picked === '' || at <= 0) {
		return templateId;
	}
	return `${templateId.slice(0, at)}${SEPARATOR}${picked}`;
}

/** Shows a side's version picker while the side sticks to one version. */
export function showVersionPicker(side: HTMLElement): void {
	const picker = pickerOf(side);
	if (picker) {
		picker.hidden = !isPinned(side);
	}
}

/**
 * Offers the versions of the template chosen for a side, the latest first as
 * "Version N (current)", which starts selected: a template the search lists
 * names its latest version. At most the picker's `data-max-versions` are offered.
 * Without a template the setting is hidden.
 */
export function offerVersions(side: HTMLElement, templateId: string): void {
	const field = side.querySelector<HTMLElement>('.pixonaut-template-version');
	const picker = pickerOf(side);
	if (!field || !picker) {
		return;
	}
	const latest = versionOf(templateId) ?? 0;
	const most = Number(picker.dataset.maxVersions) || latest;
	picker.replaceChildren();
	for (let version = latest; version > Math.max(0, latest - most); version--) {
		const words =
			version === latest ? picker.dataset.current : picker.dataset.version;
		const label = (words ?? VERSION_PLACEHOLDER).replace(
			VERSION_PLACEHOLDER,
			String(version),
		);
		picker.append(new Option(label, String(version)));
	}
	field.hidden = latest === 0;
}

/**
 * Wires a side's Stick to one version checkbox and picker: the picker shows
 * while the box is ticked, and `onChange` hears the version the side is then
 * linked to, as `linkedTemplateId()` says, when either changes.
 */
export function initTemplateVersion(
	side: HTMLElement,
	onChange: () => void = () => {},
): void {
	side
		.querySelector('.pixonaut-side-pinned')
		?.addEventListener('change', () => {
			showVersionPicker(side);
			onChange();
		});
	pickerOf(side)?.addEventListener('change', onChange);
	showVersionPicker(side);
}
