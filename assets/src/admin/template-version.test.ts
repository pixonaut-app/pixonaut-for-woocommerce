// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import {
	initTemplateVersion,
	linkedTemplateId,
	offerVersions,
	versionOf,
} from './template-version';

/** A side's version setting as the panel prints it. */
function side(pinned: boolean, options = ''): HTMLElement {
	const element = document.createElement('div');
	element.innerHTML = `
		<p class="pixonaut-template-version" ${options === '' ? 'hidden' : ''}>
			<input type="checkbox" class="pixonaut-side-pinned" ${pinned ? 'checked' : ''} />
			<select class="pixonaut-side-version" data-current="Version %d (current)" data-version="Version %d" data-max-versions="5" ${pinned ? '' : 'hidden'}>${options}</select>
		</p>`;
	return element;
}

const picker = (element: HTMLElement) =>
	element.querySelector('.pixonaut-side-version') as HTMLSelectElement;

const setting = (element: HTMLElement) =>
	element.querySelector('.pixonaut-template-version') as HTMLElement;

describe('versionOf', () => {
	it('reads the version after the last @', () => {
		expect(versionOf('mini-tee@3')).toBe(3);
		expect(versionOf('shirt@2@4')).toBe(4);
		expect(versionOf('mini-tee')).toBeNull();
		expect(versionOf('')).toBeNull();
	});
});

describe('offerVersions', () => {
	it('lists the chosen template versions, the latest by its number, as current, and selected', () => {
		const element = side(
			true,
			'<option value="1">Version 1 (current)</option>',
		);

		offerVersions(element, 'mini-tee@3');

		expect(
			[...picker(element).options].map((option) => [option.value, option.text]),
		).toEqual([
			['3', 'Version 3 (current)'],
			['2', 'Version 2'],
			['1', 'Version 1'],
		]);
		expect(picker(element).value).toBe('3');
		expect(setting(element).hidden).toBe(false);
	});

	it('offers the newest versions alone past the most it shows', () => {
		const element = side(
			true,
			'<option value="1">Version 1 (current)</option>',
		);

		offerVersions(element, 'mini-tee@40');

		expect([...picker(element).options].map((option) => option.value)).toEqual([
			'40',
			'39',
			'38',
			'37',
			'36',
		]);
	});

	it('hides the setting without a template', () => {
		const element = side(
			false,
			'<option value="1">Version 1 (current)</option>',
		);

		offerVersions(element, '');

		expect(picker(element).options).toHaveLength(0);
		expect(setting(element).hidden).toBe(true);
	});
});

describe('initTemplateVersion', () => {
	it('shows the picker only while the side sticks to one version', () => {
		const element = side(
			false,
			'<option value="1">Version 1 (current)</option>',
		);
		initTemplateVersion(element);
		const pinned = element.querySelector(
			'.pixonaut-side-pinned',
		) as HTMLInputElement;

		pinned.checked = true;
		pinned.dispatchEvent(new Event('change'));
		expect(picker(element).hidden).toBe(false);

		pinned.checked = false;
		pinned.dispatchEvent(new Event('change'));
		expect(picker(element).hidden).toBe(true);
	});
});

describe('linkedTemplateId', () => {
	const options =
		'<option value="3">Version 3 (current)</option><option value="1" selected>Version 1</option>';

	it('is the version picked while the side sticks to one, else the latest', () => {
		expect(linkedTemplateId(side(true, options), 'mini-tee@3')).toBe(
			'mini-tee@1',
		);
		expect(linkedTemplateId(side(false, options), 'mini-tee@3')).toBe(
			'mini-tee@3',
		);
		expect(linkedTemplateId(side(true, options), '')).toBe('');
	});

	it('is heard when the box or the picker changes', () => {
		const element = side(false, options);
		const heard = vi.fn(() =>
			heardIds.push(linkedTemplateId(element, 'mini-tee@3')),
		);
		const heardIds: string[] = [];
		initTemplateVersion(element, heard);
		const pinned = element.querySelector(
			'.pixonaut-side-pinned',
		) as HTMLInputElement;

		pinned.checked = true;
		pinned.dispatchEvent(new Event('change'));
		picker(element).value = '3';
		picker(element).dispatchEvent(new Event('change'));

		expect(heardIds).toEqual(['mini-tee@1', 'mini-tee@3']);
	});
});
