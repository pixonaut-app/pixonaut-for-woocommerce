// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	act,
	addEntry,
	entries,
	initVariationPlan,
	parseFixed,
	removeEntry,
} from './variation-plan';

const URL = 'http://localhost/wp-json/pixonaut/v1/products/7/variation-plan';

function entry(index: number, blank: string, when = '', fixed = ''): string {
	return `
		<li class="pixonaut-variation-plan__entry">
			<p class="form-field"><label for="pixonaut-variation-plan-blank-${index}">Blank</label>
			<input class="pixonaut-variation-plan__blank" id="pixonaut-variation-plan-blank-${index}" value="${blank}" /></p>
			<p class="form-field"><label for="pixonaut-variation-plan-when-${index}-pa_frame">When Frame is</label>
			<select class="pixonaut-variation-plan__when" id="pixonaut-variation-plan-when-${index}-pa_frame" data-attribute="pa_frame">
				<option value="">Any value</option>
				<option value="none" ${when === 'none' ? 'selected' : ''}>None</option>
				<option value="framed" ${when === 'framed' ? 'selected' : ''}>Framed</option>
			</select></p>
			<p class="form-field"><label for="pixonaut-variation-plan-fixed-${index}">Fixed options</label>
			<input class="pixonaut-variation-plan__fixed" id="pixonaut-variation-plan-fixed-${index}" value="${fixed}" /></p>
			<button type="button" class="button-link pixonaut-variation-plan__remove">Remove</button>
		</li>`;
}

function section(rows: string, status = ''): string {
	return `
		<div class="pixonaut-variation-plan" data-url="${URL}" data-rest-nonce="nonce-1" data-failed-text="Could not work it out">
			<ol class="pixonaut-variation-plan__entries">${rows}</ol>
			<button type="button" class="button pixonaut-variation-plan__add">Add</button>
			<button type="button" class="button" data-action="preview">Preview</button>
			<button type="button" class="button" data-action="generate">Generate</button>
			<span class="spinner pixonaut-variation-plan__spinner"></span>
			<p class="pixonaut-variation-plan__status">${status}</p>
		</div>`;
}

function mount(html: string): HTMLElement {
	document.body.innerHTML = html;
	const root = document.querySelector<HTMLElement>('.pixonaut-variation-plan');
	if (!root) {
		throw new Error('no section');
	}
	return root;
}

afterEach(() => {
	vi.unstubAllGlobals();
	document.body.innerHTML = '';
});

describe('parseFixed', () => {
	it('reads options and values, separated by commas, semicolons or lines', () => {
		expect(
			parseFixed(
				' Orientation: Vertical; Finish : Matte\nSize: 30x40cm / 12x16"',
			),
		).toEqual({
			Orientation: 'Vertical',
			Finish: 'Matte',
			Size: '30x40cm / 12x16"',
		});
		expect(parseFixed('nothing, :empty, Option:')).toEqual({});
	});
});

describe('entries', () => {
	it('gives each blank with the value that picks it and its fixed options, leaving out a row without a blank', () => {
		const root = mount(
			section(
				entry(0, 'blank_poster', 'none', 'Orientation: Vertical') +
					entry(1, 'blank_framed', 'framed') +
					entry(2, ''),
			),
		);

		expect(entries(root)).toEqual([
			{
				blank_id: 'blank_poster',
				when: { pa_frame: 'none' },
				fixed: { Orientation: 'Vertical' },
			},
			{ blank_id: 'blank_framed', when: { pa_frame: 'framed' }, fixed: {} },
		]);
	});
});

describe('adding and removing a blank', () => {
	it('adds an empty row with ids of its own, and removing the last row empties it', () => {
		const root = mount(section(entry(0, 'blank_poster', 'none')));

		addEntry(root);

		const rows = root.querySelectorAll<HTMLElement>(
			'.pixonaut-variation-plan__entry',
		);
		expect(rows).toHaveLength(2);
		expect(
			rows[1]?.querySelector<HTMLInputElement>(
				'.pixonaut-variation-plan__blank',
			)?.value,
		).toBe('');
		expect(
			rows[1]?.querySelector('#pixonaut-variation-plan-blank-1'),
		).not.toBeNull();
		expect(rows[1]?.querySelector('label')?.htmlFor).toBe(
			'pixonaut-variation-plan-blank-1',
		);

		removeEntry(root, rows[1] as HTMLElement);
		removeEntry(root, rows[0] as HTMLElement);

		expect(
			root.querySelectorAll('.pixonaut-variation-plan__entry'),
		).toHaveLength(1);
		expect(entries(root)).toEqual([]);
	});
});

describe('act', () => {
	it('posts the plan as a preview and draws the section answered', async () => {
		const fetch = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({
				html: section(entry(0, 'blank_poster')),
				message: 'Generating would add 4, change 0 and disable 0 variations.',
				changed: false,
			}),
		});
		vi.stubGlobal('fetch', fetch);
		const root = mount(
			section(entry(0, 'blank_poster', '', 'Orientation: Vertical')),
		);

		await act(root, false);

		expect(fetch).toHaveBeenCalledWith(
			URL,
			expect.objectContaining({ method: 'POST' }),
		);
		expect(JSON.parse(fetch.mock.calls[0]?.[1].body)).toEqual({
			entries: [
				{
					blank_id: 'blank_poster',
					when: {},
					fixed: { Orientation: 'Vertical' },
				},
			],
			dry_run: true,
		});
		expect(fetch.mock.calls[0]?.[1].headers['X-WP-Nonce']).toBe('nonce-1');
		expect(
			document.querySelector('.pixonaut-variation-plan__status')?.textContent,
		).toBe('Generating would add 4, change 0 and disable 0 variations.');
	});

	it('reloads the variations once Generate changed them', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({
					html: section(entry(0, 'blank_poster')),
					message: 'Added 4.',
					changed: true,
				}),
			}),
		);
		const trigger = vi.fn();
		vi.stubGlobal(
			'jQuery',
			vi.fn(() => ({ trigger })),
		);
		const root = mount(section(entry(0, 'blank_poster')));

		initVariationPlan(document);
		root.querySelector<HTMLButtonElement>('[data-action="generate"]')?.click();
		await vi.waitFor(() => expect(trigger).toHaveBeenCalledWith('reload'));
	});

	it('says why a plan was refused', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue({
				ok: false,
				json: async () => ({ message: 'The plan names no blank.' }),
			}),
		);
		const root = mount(section(entry(0, '')));

		await act(root, true);

		const status = root.querySelector('.pixonaut-variation-plan__status');
		expect(status?.textContent).toBe('The plan names no blank.');
		expect(status?.classList.contains('is-error')).toBe(true);
	});
});
