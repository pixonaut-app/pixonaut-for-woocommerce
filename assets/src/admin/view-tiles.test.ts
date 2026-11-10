// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { showOpeningView } from './view-tiles';

function view(id: string, label: string, ticked: boolean): string {
	return `
		<li class="pixonaut-view"><label>
			<span class="pixonaut-view__thumb"><span class="pixonaut-view__opens" hidden>Opens first</span></span>
			<span class="pixonaut-view__name"><input type="checkbox" value="${id}" ${ticked ? 'checked' : ''} /><span>${label}</span></span>
		</label></li>`;
}

function section(ticked: string[], opening = ''): HTMLElement {
	const root = document.createElement('div');
	root.innerHTML = `
		<details class="pixonaut-views-section">
			<summary><span class="pixonaut-disclosure__summary"></span><span class="pixonaut-disclosure__summary pixonaut-views__opens-summary" data-text="opens on %s"></span></summary>
			<div class="pixonaut-views">
				<ul class="pixonaut-views__list">
					${view('front', 'Front', ticked.includes('front'))}
					${view('back', 'Back', ticked.includes('back'))}
					${view('design', 'The design alone', ticked.includes('design'))}
				</ul>
				<span class="pixonaut-views__hint" data-none="None ticked: every view is shown." data-some="Only the ticked views are shown."></span>
				<select class="pixonaut-initial-view">
					<option value=""></option>
					<option value="front"></option>
					<option value="back" ${opening === 'back' ? 'selected' : ''}></option>
				</select>
			</div>
		</details>`;
	document.body.replaceChildren(root);
	showOpeningView(root);
	return root;
}

function opening(root: HTMLElement): string[] {
	return [...root.querySelectorAll<HTMLElement>('.pixonaut-view__opens')]
		.filter((badge) => !badge.hidden)
		.map(
			(badge) => badge.closest('label')?.querySelector('input')?.value ?? '',
		);
}

describe('showOpeningView', () => {
	it('badges the first view while none is ticked and nothing is chosen', () => {
		const root = section([]);
		expect(opening(root)).toEqual(['front']);
		expect(
			root.querySelector('.pixonaut-views__opens-summary')?.textContent,
		).toBe('opens on Front');
		expect(root.querySelector('.pixonaut-views__hint')?.textContent).toBe(
			'None ticked: every view is shown.',
		);
	});

	it('badges the first ticked view', () => {
		const root = section(['design']);
		expect(opening(root)).toEqual(['design']);
		expect(
			root.querySelector('.pixonaut-views__opens-summary')?.textContent,
		).toBe('opens on The design alone');
		expect(root.querySelector('.pixonaut-views__hint')?.textContent).toBe(
			'Only the ticked views are shown.',
		);
	});

	it('badges the view chosen to open on', () => {
		const root = section([], 'back');
		expect(opening(root)).toEqual(['back']);
	});
});
