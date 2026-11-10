// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
	offerInitialViews,
	PERSONALISED_MODE,
	STANDING_MODE,
	showBackgroundWarning,
	showMisfitWarning,
	showModeWarnings,
	showSideHints,
	showSideName,
	showViews,
} from './product-panel';

/** A panel rendered for `blank_a`: its front views listed, one ticked. */
function panel(): HTMLElement {
	const element = document.createElement('div');
	element.innerHTML = `
		<div class="pixonaut-views" data-blank="blank_a">
			<input type="hidden" name="pixonaut_views[blank]" value="blank_a" />
			<fieldset class="pixonaut-views__placement">
				<ul class="pixonaut-views__list">
					<li><input type="checkbox" name="pixonaut_views[ticked][]" value="view-flat" checked /></li>
				</ul>
			</fieldset>
			<p class="pixonaut-views-unlisted" hidden></p>
		</div>`;
	return element;
}

function parts(element: HTMLElement) {
	return {
		placement: element.querySelector<HTMLElement>('.pixonaut-views__placement'),
		blank: element.querySelector<HTMLInputElement>('input[type=hidden]'),
		checkbox: element.querySelector<HTMLInputElement>('input[type=checkbox]'),
		unlisted: element.querySelector<HTMLElement>('.pixonaut-views-unlisted'),
	};
}

describe('showViews', () => {
	it('shows the views of the blank the panel was rendered with', () => {
		const element = panel();

		showViews(element, 'blank_a');

		const { placement, blank, checkbox, unlisted } = parts(element);
		expect(placement?.hidden).toBe(false);
		expect(blank?.disabled).toBe(false);
		expect(checkbox?.disabled).toBe(false);
		expect(unlisted?.hidden).toBe(true);
	});

	it('hides and disables the views under another blank, and says when they are listed', () => {
		const element = panel();

		showViews(element, 'blank_b');

		const { placement, blank, checkbox, unlisted } = parts(element);
		expect(placement?.hidden).toBe(true);
		expect(blank?.disabled).toBe(true);
		expect(checkbox?.disabled).toBe(true);
		expect(unlisted?.hidden).toBe(false);
	});

	it('restores the views, ticks kept, when the first blank is chosen again', () => {
		const element = panel();

		showViews(element, 'blank_b');
		showViews(element, 'blank_a');

		const { placement, checkbox } = parts(element);
		expect(placement?.hidden).toBe(false);
		expect(checkbox?.disabled).toBe(false);
		expect(checkbox?.checked).toBe(true);
	});

	it('leaves a panel without the views field alone', () => {
		const element = document.createElement('div');

		expect(() => showViews(element, 'blank_a')).not.toThrow();
	});
});

/** The views field with two views listed, `ticked` ticked, and the starting
 *  view `initial`. */
function startingViews(ticked: string[], initial: string): HTMLElement {
	const element = document.createElement('div');
	const box = (id: string) =>
		`<li><input type="checkbox" value="${id}" ${ticked.includes(id) ? 'checked' : ''} /></li>`;
	element.innerHTML = `
		<div class="pixonaut-views" data-blank="blank_a">
			<fieldset class="pixonaut-views__placement">
				<ul class="pixonaut-views__list">${box('view-flat')}${box('view-model')}</ul>
			</fieldset>
			<p class="pixonaut-views__initial">
				<select class="pixonaut-initial-view">
					<option value="">The first view</option>
					<option value="view-flat">Flat</option>
					<option value="view-model">On model</option>
				</select>
			</p>
		</div>`;
	const select = element.querySelector<HTMLSelectElement>('select');
	if (select) {
		select.value = initial;
	}
	return element;
}

function disabledViews(element: HTMLElement): string[] {
	return [...(element.querySelector('select')?.options ?? [])]
		.filter((option) => option.disabled)
		.map((option) => option.value);
}

describe('offerInitialViews', () => {
	it('offers only the views ticked', () => {
		const element = startingViews(['view-model'], 'view-model');

		offerInitialViews(element);

		expect(disabledViews(element)).toEqual(['view-flat']);
		expect(element.querySelector('select')?.value).toBe('view-model');
	});

	it('offers every view while none is ticked', () => {
		const element = startingViews([], 'view-flat');

		offerInitialViews(element);

		expect(disabledViews(element)).toEqual([]);
		expect(element.querySelector('select')?.value).toBe('view-flat');
	});

	it('starts on the first view once the starting view is unticked', () => {
		const element = startingViews(['view-model'], 'view-flat');

		offerInitialViews(element);

		expect(element.querySelector('select')?.value).toBe('');
	});

	it('hides and disables the starting view under another blank', () => {
		const element = startingViews([], 'view-flat');

		showViews(element, 'blank_b');

		expect(element.querySelector('select')?.disabled).toBe(true);
		expect(
			element.querySelector<HTMLElement>('.pixonaut-views__initial')?.hidden,
		).toBe(true);
	});
});

/** The side hints as the panel renders them, the recommendation at 4. */
function hints(): HTMLElement {
	const element = document.createElement('div');
	element.innerHTML = `
		<span class="pixonaut-sides-over-recommended" data-recommended="4" hidden></span>
		<p class="pixonaut-without-blank"></p>`;
	return element;
}

describe('showSideHints', () => {
	it('recommends fewer sides only past the recommended count', () => {
		const element = hints();
		const over = element.querySelector<HTMLElement>(
			'.pixonaut-sides-over-recommended',
		);

		showSideHints(element, 4, true);
		expect(over?.hidden).toBe(true);

		showSideHints(element, 5, true);
		expect(over?.hidden).toBe(false);

		showSideHints(element, 4, true);
		expect(over?.hidden).toBe(true);
	});

	it('says a product without a blank has one side', () => {
		const element = hints();
		const single = element.querySelector<HTMLElement>(
			'.pixonaut-without-blank',
		);

		showSideHints(element, 1, false);
		expect(single?.hidden).toBe(false);

		showSideHints(element, 1, true);
		expect(single?.hidden).toBe(true);
	});
});

/** A side in `mode` whose template offers fields (`true`), none (`false`), or is not known yet (`''`). */
function modeSide(mode: string, offers: string): HTMLElement {
	const side = document.createElement('div');
	side.dataset.offersProperties = offers;
	side.innerHTML = `
		<input type="checkbox" class="pixonaut-side-mode" value="${PERSONALISED_MODE}" ${mode === PERSONALISED_MODE ? 'checked' : ''} />
		<div class="pixonaut-mode-warning pixonaut-mode-warning--standing" hidden></div>
		<div class="pixonaut-mode-warning pixonaut-mode-warning--personalised" hidden></div>`;
	return side;
}

function shownWarnings(side: HTMLElement): string[] {
	return [...side.querySelectorAll<HTMLElement>('.pixonaut-mode-warning')]
		.filter((warning) => !warning.hidden)
		.map((warning) =>
			warning.classList.contains('pixonaut-mode-warning--standing')
				? STANDING_MODE
				: PERSONALISED_MODE,
		);
}

describe('showBackgroundWarning', () => {
	/** A side whose template's background shows through (`true`), does not (`false`), or is not known (`''`). */
	function backgroundSide(seeThrough: string): HTMLElement {
		const side = document.createElement('div');
		side.dataset.backgroundSeeThrough = seeThrough;
		side.innerHTML = `<div class="pixonaut-background-warning" hidden></div>`;
		return side;
	}

	it.each([
		['true', false, true],
		['true', true, false],
		['false', false, false],
		['', false, false],
	])(
		'with backgroundSeeThrough "%s" and a blank %s shows %s',
		(seeThrough, withBlank, shown) => {
			const side = backgroundSide(seeThrough);

			showBackgroundWarning(side, withBlank);

			expect(
				side.querySelector<HTMLElement>('.pixonaut-background-warning')?.hidden,
			).toBe(!shown);
		},
	);
});

describe('showMisfitWarning', () => {
	function misfitSide(): HTMLElement {
		const side = document.createElement('div');
		side.innerHTML = `<div class="pixonaut-misfit-warning" hidden><p></p></div>`;
		return side;
	}

	it('names the sizes the chosen template cannot be printed at', () => {
		const side = misfitSide();

		showMisfitWarning(side, 'This template does not fit the placement at XL.');

		const box = side.querySelector<HTMLElement>('.pixonaut-misfit-warning');
		expect(box?.hidden).toBe(false);
		expect(box?.textContent).toBe(
			'This template does not fit the placement at XL.',
		);
	});

	it('hides once a template that fits every size is chosen', () => {
		const side = misfitSide();
		showMisfitWarning(side, 'This template does not fit the placement at XL.');

		showMisfitWarning(side, '');

		expect(
			side.querySelector<HTMLElement>('.pixonaut-misfit-warning')?.hidden,
		).toBe(true);
	});
});

describe('showModeWarnings', () => {
	it.each([
		[STANDING_MODE, 'true', [STANDING_MODE]],
		[STANDING_MODE, 'false', []],
		[PERSONALISED_MODE, 'false', [PERSONALISED_MODE]],
		[PERSONALISED_MODE, 'true', []],
		[STANDING_MODE, '', []],
		[PERSONALISED_MODE, '', []],
	])(
		'in mode %s with offersProperties "%s" shows %j',
		(mode, offers, shown) => {
			const side = modeSide(mode, offers);

			showModeWarnings(side);

			expect(shownWarnings(side)).toEqual(shown);
		},
	);

	it('follows the mode as it is changed', () => {
		const side = modeSide(PERSONALISED_MODE, 'true');
		showModeWarnings(side);
		const box = side.querySelector<HTMLInputElement>('.pixonaut-side-mode');
		if (box) {
			box.checked = false;
		}

		showModeWarnings(side);

		expect(shownWarnings(side)).toEqual([STANDING_MODE]);
	});
});

describe('showSideName', () => {
	/** A side with its name field, print position and template as chosen. */
	function side(typed: string, placement: string, template: string) {
		const element = document.createElement('div');
		element.innerHTML = `
			<span class="pixonaut-side__name" data-placeholder="Side"></span>
			<select class="pixonaut-side-placement"><option value="">Choose</option><option value="p1">Front</option></select>
			<select class="pixonaut-side-template"><option value=""></option><option value="kids-poster@2">Kids poster, hearts</option></select>
			<input class="pixonaut-side-label" placeholder="Name customers see" />`;
		const select = (selector: string, value: string) => {
			const field = element.querySelector<HTMLSelectElement>(selector);
			if (field) {
				field.value = value;
			}
		};
		select('.pixonaut-side-placement', placement);
		select('.pixonaut-side-template', template);
		const label = element.querySelector<HTMLInputElement>(
			'.pixonaut-side-label',
		);
		if (label) {
			label.value = typed;
		}
		return {
			element,
			label,
			name: () => element.querySelector('.pixonaut-side__name')?.textContent,
		};
	}

	it('names the side as typed', () => {
		const { element, name } = side('Chest', 'p1', 'kids-poster@2');

		showSideName(element);

		expect(name()).toBe('Chest');
	});

	it('follows the print position, and shows it as the placeholder', () => {
		const { element, label, name } = side('', 'p1', 'kids-poster@2');

		showSideName(element);

		expect(name()).toBe('Front');
		expect(label?.placeholder).toBe('Front');
	});

	it('follows the template without a print position, never its id', () => {
		const { element, name } = side('', '', 'kids-poster@2');

		showSideName(element);

		expect(name()).toBe('Kids poster, hearts');
	});

	it('says Side, and puts the placeholder back, with nothing chosen', () => {
		const { element, label, name } = side('', 'p1', '');
		showSideName(element);
		const placement = element.querySelector<HTMLSelectElement>(
			'.pixonaut-side-placement',
		);
		if (placement) {
			placement.value = '';
		}

		showSideName(element);

		expect(name()).toBe('Side');
		expect(label?.placeholder).toBe('Name customers see');
	});
});
