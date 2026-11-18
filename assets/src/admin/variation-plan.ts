// Variations from the blank, above a variable product's variations: the
// blanks its variations are printed on, added and removed here, and the
// Preview and Generate buttons, which post the plan to the product's
// variation plan route. The route answers the section drawn again with what
// it found or did; once Generate changed variations, WooCommerce's
// variations panel loads them again.

export const ROOT_SELECTOR = '.pixonaut-variation-plan';

const ENTRY_SELECTOR = '.pixonaut-variation-plan__entry';

/** WooCommerce's variations panel, which loads its variations again on `reload`. */
const VARIATIONS_PANEL = '#variable_product_options';

/** One blank of the plan, as the route takes it. */
export type Entry = {
	blank_id: string;
	when: Record<string, string>;
	fixed: Record<string, string>;
};

/** The route's answer: the section drawn again, what it says happened, and
 *  whether any variation changed. */
type Answer = {
	html: string;
	message: string;
	changed: boolean;
};

function isAnswer(value: unknown): value is Answer {
	const answer = value as Partial<Answer> | null;
	return typeof answer?.html === 'string' && typeof answer.message === 'string';
}

/** Fixed options as typed, `Orientation: Vertical, Finish: Matte`, by option. */
export function parseFixed(typed: string): Record<string, string> {
	const fixed: Record<string, string> = {};
	for (const pair of typed.split(/[,;\n]/)) {
		const colon = pair.indexOf(':');
		if (colon < 0) {
			continue;
		}
		const option = pair.slice(0, colon).trim();
		const value = pair.slice(colon + 1).trim();
		if (option !== '' && value !== '') {
			fixed[option] = value;
		}
	}
	return fixed;
}

/** The plan as the section's fields hold it; a row without a blank is left out. */
export function entries(root: HTMLElement): Entry[] {
	const plan: Entry[] = [];
	for (const row of root.querySelectorAll<HTMLElement>(ENTRY_SELECTOR)) {
		const blank =
			row
				.querySelector<HTMLInputElement>('.pixonaut-variation-plan__blank')
				?.value.trim() ?? '';
		if (blank === '') {
			continue;
		}
		const when: Record<string, string> = {};
		for (const select of row.querySelectorAll<HTMLSelectElement>(
			'.pixonaut-variation-plan__when',
		)) {
			if (select.value !== '' && select.dataset.attribute) {
				when[select.dataset.attribute] = select.value;
			}
		}
		const fixed = parseFixed(
			row.querySelector<HTMLInputElement>('.pixonaut-variation-plan__fixed')
				?.value ?? '',
		);
		plan.push({ blank_id: blank, when, fixed });
	}
	return plan;
}

/** Gives a row's fields and labels ids of their own, by its place. */
function renumber(root: HTMLElement): void {
	root.querySelectorAll<HTMLElement>(ENTRY_SELECTOR).forEach((row, index) => {
		for (const label of row.querySelectorAll<HTMLLabelElement>('label[for]')) {
			const field = row.querySelector<HTMLElement>(`[id="${label.htmlFor}"]`);
			const next = label.htmlFor.replace(/-\d+(-|$)/, `-${index}$1`);
			label.htmlFor = next;
			if (field) {
				field.id = next;
			}
		}
	});
}

/** Adds an empty blank row, a copy of the last one. */
export function addEntry(root: HTMLElement): void {
	const rows = root.querySelectorAll<HTMLElement>(ENTRY_SELECTOR);
	const last = rows[rows.length - 1];
	if (!last) {
		return;
	}
	const row = last.cloneNode(true) as HTMLElement;
	for (const input of row.querySelectorAll<HTMLInputElement>('input')) {
		input.value = '';
	}
	for (const select of row.querySelectorAll<HTMLSelectElement>('select')) {
		select.value = '';
	}
	last.after(row);
	renumber(root);
	row.querySelector<HTMLInputElement>('input')?.focus();
}

/** Removes a blank row; the last one left is emptied instead. */
export function removeEntry(root: HTMLElement, row: HTMLElement): void {
	if (root.querySelectorAll(ENTRY_SELECTOR).length > 1) {
		row.remove();
		renumber(root);
		return;
	}
	for (const field of row.querySelectorAll<
		HTMLInputElement | HTMLSelectElement
	>('input, select')) {
		field.value = '';
	}
}

function say(root: HTMLElement, text: string, failed = false): void {
	const status = root.querySelector<HTMLElement>(
		'.pixonaut-variation-plan__status',
	);
	if (status) {
		status.textContent = text;
		status.classList.toggle('is-error', failed);
	}
}

function setBusy(root: HTMLElement, busy: boolean): void {
	root
		.querySelector('.pixonaut-variation-plan__spinner')
		?.classList.toggle('is-active', busy);
	for (const button of root.querySelectorAll<HTMLButtonElement>('button')) {
		button.disabled = busy;
	}
}

/** Posts the plan, as a preview or to generate, and draws the section answered. */
export async function act(root: HTMLElement, generate: boolean): Promise<void> {
	setBusy(root, true);
	say(root, '');
	let answer: unknown = null;
	let refused = '';
	try {
		const response = await fetch(root.dataset.url ?? '', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': root.dataset.restNonce ?? '',
			},
			body: JSON.stringify({ entries: entries(root), dry_run: !generate }),
		});
		answer = await response.json();
		if (!response.ok) {
			const message = (answer as { message?: unknown } | null)?.message;
			refused = typeof message === 'string' ? message : '';
			answer = null;
		}
	} catch {
		answer = null;
	}
	if (!isAnswer(answer)) {
		setBusy(root, false);
		say(root, refused || (root.dataset.failedText ?? ''), true);
		return;
	}
	const drawn = root.ownerDocument.createElement('template');
	drawn.innerHTML = answer.html.trim();
	const next = drawn.content.querySelector<HTMLElement>(ROOT_SELECTOR);
	if (!next) {
		setBusy(root, false);
		return;
	}
	root.replaceWith(next);
	say(next, answer.message);
	if (answer.changed) {
		const jQuery = (
			window as {
				jQuery?: (selector: string) => { trigger(event: string): unknown };
			}
		).jQuery;
		jQuery?.(VARIATIONS_PANEL).trigger('reload');
	}
}

export function initVariationPlan(container: ParentNode): void {
	// The section is drawn again after each action: its clicks are heard on what holds it.
	container.addEventListener('click', (event) => {
		const target = event.target instanceof Element ? event.target : null;
		const root = target?.closest<HTMLElement>(ROOT_SELECTOR);
		const button = target?.closest<HTMLButtonElement>('button');
		if (!root || !button) {
			return;
		}
		if (button.matches('.pixonaut-variation-plan__add')) {
			addEntry(root);
		} else if (button.matches('.pixonaut-variation-plan__remove')) {
			const row = button.closest<HTMLElement>(ENTRY_SELECTOR);
			if (row) {
				removeEntry(root, row);
			}
		} else if (
			button.dataset.action === 'preview' ||
			button.dataset.action === 'generate'
		) {
			void act(root, button.dataset.action === 'generate');
		}
	});
}
