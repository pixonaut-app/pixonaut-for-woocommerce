// The Prices section of the Pixonaut tab: its buttons post to the product's
// prices route, which reads the printshop's costs again, sets prices by the
// pricing rule or gives a price edited by hand back to the rule, and answers
// the section drawn again. The product form's own price fields are then
// brought in line, so that saving the product does not put an old price back.

export const ROOT_SELECTOR = '.pixonaut-prices';

/** What a button asks the route for, as `PricingPanel::ACTIONS`. */
export type PriceAction = 'refresh' | 'apply' | 'follow';

type Options = {
	nonce: string;
};

/** The route's answer: the section drawn again, what it says happened, how
 *  many prices changed, and each regular price as the product form shows
 *  it, by product or variation id. */
type Answer = {
	html: string;
	message: string;
	changed: number;
	prices: Record<string, string>;
};

/** WooCommerce's regular price field of a simple product. */
const SIMPLE_PRICE_FIELD = '#_regular_price';

/** WooCommerce's variations panel, which loads its variations again on `reload`. */
const VARIATIONS_PANEL = '#variable_product_options';

function isAnswer(value: unknown): value is Answer {
	const answer = value as Partial<Answer> | null;
	return typeof answer?.html === 'string' && typeof answer.prices === 'object';
}

/** The parts of the rule the product changes, as typed, by part. */
function rule(root: HTMLElement): Record<string, string> {
	const parts: Record<string, string> = {};
	for (const input of root.querySelectorAll<HTMLInputElement>(
		'.pixonaut-prices__input',
	)) {
		parts[input.dataset.part ?? ''] = input.value;
	}
	return parts;
}

function say(root: HTMLElement, text: string, failed = false): void {
	const status = root.querySelector<HTMLElement>('.pixonaut-prices__status');
	if (status) {
		status.textContent = text;
		status.classList.toggle('is-error', failed);
	}
}

function setBusy(root: HTMLElement, busy: boolean): void {
	root
		.querySelector('.pixonaut-prices__spinner')
		?.classList.toggle('is-active', busy);
	for (const button of root.querySelectorAll<HTMLButtonElement>(
		'button[data-action]',
	)) {
		button.disabled = busy;
	}
}

/** Shows the product form the prices the rule set: a simple product's price
 *  field, and a variable product's variations, loaded again. */
function showPrices(doc: Document, root: HTMLElement, answer: Answer): void {
	const rows = root.querySelectorAll<HTMLElement>('.pixonaut-prices__row');
	const field = doc.querySelector<HTMLInputElement>(SIMPLE_PRICE_FIELD);
	// Only a price the action set: one the store manager typed and has not saved stays.
	if (answer.changed === 0) {
		return;
	}
	if (root.hasAttribute('data-simple') && rows.length === 1 && field) {
		field.value = answer.prices[rows[0].dataset.item ?? ''] ?? field.value;
		return;
	}
	const jQuery = (
		window as {
			jQuery?: (selector: string) => { trigger(event: string): unknown };
		}
	).jQuery;
	jQuery?.(VARIATIONS_PANEL).trigger('reload');
}

/** Asks the route for an action, and draws the section it answers. */
export async function act(
	root: HTMLElement,
	action: PriceAction,
	options: Options,
	item?: number,
): Promise<void> {
	setBusy(root, true);
	say(root, '');
	let answer: unknown = null;
	let refused = '';
	try {
		const response = await fetch(root.dataset.url ?? '', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': options.nonce,
			},
			body: JSON.stringify({
				action,
				...(item === undefined ? {} : { ids: [item] }),
				...(action === 'apply' ? { rule: rule(root) } : {}),
			}),
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
	// The section stays as open as it was, and keeps what was typed in its
	// rule's fields, which only Apply and saving the product store.
	next.toggleAttribute('open', root.hasAttribute('open'));
	const typed = rule(root);
	for (const input of next.querySelectorAll<HTMLInputElement>(
		'.pixonaut-prices__input',
	)) {
		input.value = typed[input.dataset.part ?? ''] ?? input.value;
	}
	root.replaceWith(next);
	say(next, answer.message);
	showPrices(next.ownerDocument, next, answer);
}

/** Reads the costs of a section that has none yet, once it is open. */
function readWhenOpen(root: HTMLElement, options: Options): void {
	if (root.hasAttribute('data-unread') && root.hasAttribute('open')) {
		root.removeAttribute('data-unread');
		void act(root, 'refresh', options);
	}
}

export function initPricing(container: HTMLElement, options: Options): void {
	// The section is drawn again after each action: its events are heard on what holds it.
	container.addEventListener('click', (event) => {
		const button =
			event.target instanceof Element
				? event.target.closest<HTMLButtonElement>(
						`${ROOT_SELECTOR} button[data-action]`,
					)
				: null;
		const root = button?.closest<HTMLElement>(ROOT_SELECTOR);
		if (!button || !root) {
			return;
		}
		const item = Number.parseInt(button.dataset.item ?? '', 10);
		void act(
			root,
			button.dataset.action as PriceAction,
			options,
			item > 0 ? item : undefined,
		);
	});
	container.addEventListener(
		'toggle',
		(event) => {
			if (
				event.target instanceof HTMLElement &&
				event.target.matches(ROOT_SELECTOR)
			) {
				readWhenOpen(event.target, options);
			}
		},
		true,
	);
	const root = container.querySelector<HTMLElement>(ROOT_SELECTOR);
	if (root) {
		readWhenOpen(root, options);
	}
}
