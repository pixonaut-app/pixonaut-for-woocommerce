// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { HIDDEN_ROW, initDependents } from './settings';

const MESSAGE =
	'Designer accent colour is set to A custom colour: enter the colour, e.g. #1e73be.';

/** The accent rows as WooCommerce renders them: the custom colour applies
 *  while the accent is `custom`, and is required then. */
function form(accent: string, colour: string): HTMLElement {
	const root = document.createElement('table');
	root.innerHTML = `
		<tr><td><select id="settings[designer_accent]">
			<option value="none" ${accent === 'none' ? 'selected' : ''}>None</option>
			<option value="custom" ${accent === 'custom' ? 'selected' : ''}>A custom colour</option>
		</select></td></tr>
		<tr class="wc-settings-row-pixonaut-dependent"><td><input id="settings[designer_accent_colour]" value="${colour}"
			data-pixonaut-depends="settings[designer_accent]"
			data-pixonaut-when-equals="custom"
			data-pixonaut-required="${MESSAGE}" /></td></tr>
		<tr><td><input id="settings[proof_reminders]" type="number" value="0" /></td></tr>
		<tr class="wc-settings-row-pixonaut-dependent"><td><input id="settings[proof_reminder_every_days]" type="number" min="1" value="0"
			data-pixonaut-depends="settings[proof_reminders]"
			data-pixonaut-when-above="0" /></td></tr>`;
	document.body.replaceChildren(root);
	return root;
}

function row(root: HTMLElement, id: string): HTMLElement | null {
	return (
		root.ownerDocument.getElementById(id)?.closest<HTMLElement>('tr') ?? null
	);
}

function select(id: string, value: string): void {
	const field = document.getElementById(id) as HTMLInputElement | null;
	if (field) {
		field.value = value;
		field.dispatchEvent(new Event('change'));
	}
}

describe('initDependents', () => {
	it('shows a setting that depends on a checkbox only while it is ticked', () => {
		document.body.innerHTML = `<table>
			<tr><td><input type="checkbox" id="groups" value="1" /></td></tr>
			<tr id="row"><td><select id="place" data-pixonaut-depends="groups" data-pixonaut-when-checked="true"></select></td></tr>
		</table>`;
		const box = document.getElementById('groups') as HTMLInputElement;
		const row = document.getElementById('row') as HTMLElement;

		initDependents(document);
		expect(row.classList.contains(HIDDEN_ROW)).toBe(true);

		box.checked = true;
		box.dispatchEvent(new Event('change'));
		expect(row.classList.contains(HIDDEN_ROW)).toBe(false);
	});

	it('shows the custom colour only while the accent is a custom colour', () => {
		const root = form('none', '');
		initDependents(root);
		const colour = row(root, 'settings[designer_accent_colour]');

		expect(colour?.classList.contains(HIDDEN_ROW)).toBe(true);
		select('settings[designer_accent]', 'custom');
		expect(colour?.classList.contains(HIDDEN_ROW)).toBe(false);
	});

	it('says the custom colour is missing while it is shown and empty', () => {
		const root = form('custom', '');
		initDependents(root);

		expect(root.querySelector('.pixonaut-field-error')?.textContent).toBe(
			MESSAGE,
		);
		expect(
			document
				.getElementById('settings[designer_accent_colour]')
				?.getAttribute('aria-required'),
		).toBe('true');
		select('settings[designer_accent_colour]', '#1e73be');
		expect(root.querySelector('.pixonaut-field-error')).toBeNull();
	});

	it('shows the reminder intervals only while reminders are sent', () => {
		const root = form('none', '');
		initDependents(root);
		const every = row(root, 'settings[proof_reminder_every_days]');

		expect(every?.classList.contains(HIDDEN_ROW)).toBe(true);
		select('settings[proof_reminders]', '2');
		expect(every?.classList.contains(HIDDEN_ROW)).toBe(false);
	});

	it('keeps a hidden interval out of the checks that would stop the form saving', () => {
		const root = form('none', '');
		initDependents(root);
		const every = document.getElementById(
			'settings[proof_reminder_every_days]',
		) as HTMLInputElement | null;

		expect(every?.hasAttribute('min')).toBe(false);
		expect(every?.checkValidity()).toBe(true);
		select('settings[proof_reminders]', '2');
		expect(every?.getAttribute('min')).toBe('1');
		expect(every?.checkValidity()).toBe(false);
	});
});
