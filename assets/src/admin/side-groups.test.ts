// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initSideGroups } from './side-groups';

/** A side with its Groups setting, as `admin/product-side.php` prints it. */
function side(): HTMLElement {
	const root = document.createElement('div');
	root.innerHTML = `
		<div class="pixonaut-groups" data-strings="{}" data-attributes="[]" data-url="">
			<button type="button" class="pixonaut-groups__expand" aria-expanded="false" aria-controls="groups"><span class="pixonaut-groups__arrow">▸</span>Groups</button>
			<span class="description pixonaut-groups__summary">Summary</span>
			<div class="pixonaut-groups__content" id="groups" hidden>
				<div class="pixonaut-groups__body"></div>
			</div>
			<input type="hidden" class="pixonaut-groups-value" value="" />
		</div>`;
	return root;
}

describe('initSideGroups', () => {
	it('opens collapsed, and its heading opens and closes it', () => {
		const root = side();
		initSideGroups(root, 'nonce', '', () => {});
		const expand = root.querySelector(
			'.pixonaut-groups__expand',
		) as HTMLButtonElement;
		const content = root.querySelector(
			'.pixonaut-groups__content',
		) as HTMLElement;
		const summary = root.querySelector(
			'.pixonaut-groups__summary',
		) as HTMLElement;

		expect(content.hidden).toBe(true);
		expect(expand.getAttribute('aria-expanded')).toBe('false');

		expand.click();
		expect(content.hidden).toBe(false);
		expect(summary.hidden).toBe(true);
		expect(expand.getAttribute('aria-expanded')).toBe('true');
		expect(expand.textContent).toBe('▾Groups');

		expand.click();
		expect(content.hidden).toBe(true);
		expect(summary.hidden).toBe(false);
		expect(expand.getAttribute('aria-expanded')).toBe('false');
	});

	describe('another version of the template', () => {
		afterEach(() => {
			vi.unstubAllGlobals();
		});

		/** Answers every groups read with none, and records the ids asked. */
		function answerReads(): string[] {
			const asked: string[] = [];
			vi.stubGlobal(
				'fetch',
				vi.fn(async (route: URL) => {
					asked.push(route.searchParams.get('id') ?? '');
					return new Response(JSON.stringify({ groups: [] }));
				}),
			);
			return asked;
		}

		const arrangement = JSON.stringify({
			groups: [{ id: 'text-front', items: [] }],
		});

		it('reads its groups and keeps the arrangement', () => {
			const asked = answerReads();
			const root = side();
			const value = root.querySelector(
				'.pixonaut-groups-value',
			) as HTMLInputElement;
			value.value = arrangement;
			const groups = initSideGroups(root, 'nonce', 'mini-tee@3', () => {});

			groups?.versionChanged('mini-tee@1');

			expect(asked).toEqual(['mini-tee@3', 'mini-tee@1']);
			expect(value.value).toBe(arrangement);
		});

		it('another template forgets the arrangement', () => {
			answerReads();
			const root = side();
			const value = root.querySelector(
				'.pixonaut-groups-value',
			) as HTMLInputElement;
			value.value = arrangement;
			const groups = initSideGroups(root, 'nonce', 'mini-tee@3', () => {});

			groups?.templateChanged('other-tee@1');

			expect(value.value).toBe('');
		});
	});
});
