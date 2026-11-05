import { PriceDisplay } from './price';
import { followChoices, NO_VARIATION, watchVariation } from './variations';

/** What the Personalise button does for a variation: shows, says
 *  personalisation is unavailable, or gives way to add to cart, as it is or
 *  with the customer's design the page shows. */
type ButtonState = 'personalised' | 'unavailable' | 'plain' | 'designed';

/** WooCommerce's marks on a form's add-to-cart buttons until a variation is chosen. */
const NEEDS_SELECTION = 'wc-variation-selection-needed';
const WC_DISABLED = 'disabled';

/** The parts of `storefront/button.php` the script switches, siblings of its
 *  state element, so the form lays the button out as its own. */
type Parts = {
	buttons: HTMLButtonElement[];
	unavailable: HTMLElement | null;
};

/**
 * The Personalise button of a variable product, following the variation the
 * customer chooses. Until one is chosen the button keeps the state the page
 * printed it in, the product's own.
 */
export class PersonaliseButton {
	private readonly parts: Parts;

	private readonly states: Record<string, ButtonState>;

	private readonly prices: Record<string, string>;

	private readonly initial: ButtonState;

	private price: PriceDisplay | null = null;

	/** The variation the form holds. */
	private variation = NO_VARIATION;

	constructor(private readonly container: HTMLElement) {
		const row = container.parentElement;
		this.parts = {
			buttons: [
				...(row?.querySelectorAll<HTMLButtonElement>(
					':scope > .pixonaut-personalise__button',
				) ?? []),
			],
			unavailable:
				row?.querySelector<HTMLElement>(
					':scope > .pixonaut-personalise__unavailable',
				) ?? null,
		};
		this.states = readMap<ButtonState>(container.dataset.pixonautVariations);
		this.prices = readMap<string>(container.dataset.pixonautPrices);
		this.initial = this.current();
		// The button carries WooCommerce's class, so its variation script marks
		// it too and alerts on a press; the store asks for the options itself.
		for (const button of this.parts.buttons) {
			button.addEventListener('click', () => {
				if (button.classList.contains(NEEDS_SELECTION)) {
					button.classList.remove(NEEDS_SELECTION, WC_DISABLED);
				}
			});
		}
	}

	/** Follows the form's variation; a button outside a form stays as it is. */
	start(): void {
		const form = this.container.closest('form');
		if (form === null) {
			return;
		}
		if (this.container.dataset.pixonautPrices !== undefined) {
			this.price = new PriceDisplay(this.container, form);
		}
		watchVariation(form, (id) => this.show(id));
		// A page printed at a variation's price that the form did not open on
		// shows the product's own once the form settles without one.
		if (this.price?.printedOpening === true) {
			followChoices(form, () => {
				if (this.variation === NO_VARIATION) {
					this.showPrice();
				}
			});
		}
	}

	private showPrice(): void {
		const id = this.variation;
		this.price?.show(
			id === NO_VARIATION ? null : Number(id),
			id === NO_VARIATION ? undefined : this.prices[id],
			{
				productId: Number(this.container.dataset.pixonautProduct),
				side: this.container.dataset.pixonautSide ?? '',
				printSizeIndex: null,
			},
		);
	}

	private show(id: string): void {
		this.variation = id;
		this.showPrice();
		const state =
			id === NO_VARIATION ? this.initial : (this.states[id] ?? this.initial);
		this.container.hidden = state === 'plain' || state === 'designed';
		this.showDesign(state === 'designed' ? id : null);
		for (const button of this.parts.buttons) {
			const showing = state === 'personalised';
			button.hidden = !showing;
			// Hidden buttons stay in the form and, ahead of add to cart, would be
			// the default button Enter submits; only the shown ones submit.
			button.type = showing ? 'submit' : 'button';
		}
		if (this.parts.unavailable !== null) {
			this.parts.unavailable.hidden = state !== 'unavailable';
		}
	}

	/** Shows the design of a variation, in the gallery's place and above the row. */
	private showDesign(variation: string | null): void {
		const product = this.container.dataset.pixonautProduct ?? '';
		const shown = variation === null ? null : `${product}:${variation}`;
		for (const part of document.querySelectorAll<HTMLElement>(
			`[data-pixonaut-design^="${product}:"]`,
		)) {
			part.hidden = part.dataset.pixonautDesign !== shown;
		}
	}

	private current(): ButtonState {
		if (this.container.hidden) {
			return this.container.dataset.pixonautOpensDesigned === undefined
				? 'plain'
				: 'personalised';
		}
		return this.parts.buttons.some((button) => !button.hidden)
			? 'personalised'
			: 'unavailable';
	}
}

/** A data attribute's JSON object by variation id; empty when it has none. */
function readMap<T>(raw: string | undefined): Record<string, T> {
	if (raw === undefined || raw === '') {
		return {};
	}
	try {
		const parsed: unknown = JSON.parse(raw);
		return typeof parsed === 'object' && parsed !== null
			? (parsed as Record<string, T>)
			: {};
	} catch {
		return {};
	}
}
