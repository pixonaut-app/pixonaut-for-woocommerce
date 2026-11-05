import { PRICE_EVENT, type PriceDetail } from './events';
import { holdsChoice } from './variations';

/** The price targets a theme prints inside the add-to-cart form. */
const TARGET_SELECTOR = '[data-pixonaut-price]';

/** The product summary's price, as the classic and the block templates
 *  print it, for a theme that prints no target. */
const SUMMARY_PRICE_SELECTOR =
	'.summary .price, .wc-block-components-product-price';

/** The element around one product's page parts: the classic template's
 *  and the block template's `.product`, or a Single Product block. */
const PRODUCT_SELECTOR = '.product, .wp-block-woocommerce-single-product';

/** Lists of other products (related, upsells, collections), whose prices
 *  are not this product's. */
const PRODUCT_LIST_SELECTOR =
	'.products, .wp-block-woocommerce-product-template, .wp-block-woocommerce-product-collection';

/** What the price is shown for: the product, its side and the print size
 *  on that side. */
export type PriceContext = Omit<PriceDetail, 'priceHtml' | 'variationId'>;

/** The key of the original price, which every target shows at first. */
const ORIGINAL = '';

/** The key of the opening variation's price the page printed in place of
 *  the product's own (`data-pixonaut-product-price`). */
const PRINTED_OPENING = 'printed';

/**
 * The price shown for one product's add-to-cart form. A chosen variation
 * puts its price in each target; choosing none, one without a price, or one
 * priced as the original puts the price each target first showed back, or
 * the product's own price when the page printed the opening variation's
 * in its place (`data-pixonaut-product-price`). Only
 * a target's inner HTML changes, never the element or its attributes.
 *
 * The printed opening price stands while the form holds the choice the server
 * printed it for (`data-pixonaut-opening-choice`): the form holds no variation
 * until WooCommerce's script has run and found it, which is no reason to show
 * the range.
 *
 * The targets are every `[data-pixonaut-price]` inside the form; without
 * one, the summary price of the form's own product, outside the form and
 * outside any list of products.
 */
export class PriceDisplay {
	/** The HTML each target showed before its first change. */
	private readonly originals = new Map<HTMLElement, string>();

	/** The variation and price shown: `ORIGINAL`, `PRINTED_OPENING` or
	 *  `<variationId>:<priceHtml>`. */
	private shown: string;

	/** The product's own price, when the page printed its opening
	 *  variation's in the targets instead (`data-pixonaut-product-price`). */
	private readonly productPrice: string | undefined;

	/** The attribute values the server printed the opening price for, by
	 *  field name; empty when it printed none, which holds nothing. */
	private readonly openingChoice: Record<string, string>;

	constructor(
		private readonly container: HTMLElement,
		private readonly form: HTMLFormElement,
	) {
		this.productPrice = container.dataset.pixonautProductPrice;
		this.shown = this.productPrice === undefined ? ORIGINAL : PRINTED_OPENING;
		this.openingChoice = readOpeningChoice(container);
	}

	/** Whether the page printed an opening variation's price, which the
	 *  targets show until a variation, or none, is shown. */
	get printedOpening(): boolean {
		return this.productPrice !== undefined;
	}

	/**
	 * Shows the price of variation `variationId`, or the original one, then
	 * dispatches `pixonaut:price` on the form container; does nothing when
	 * that price is shown already.
	 */
	show(
		variationId: number | null,
		priceHtml: string | undefined,
		context: PriceContext,
	): void {
		if (variationId === null && this.awaitsOpeningVariation()) {
			return;
		}
		const targets = this.targets();
		for (const target of targets) {
			if (!this.originals.has(target)) {
				this.originals.set(target, this.productPrice ?? target.innerHTML);
			}
		}
		const first = targets[0];
		const original =
			this.productPrice ??
			(first === undefined ? undefined : this.originals.get(first));
		const restored =
			variationId === null ||
			priceHtml === undefined ||
			priceHtml === '' ||
			priceHtml === original;
		const key = restored ? ORIGINAL : `${variationId}:${priceHtml}`;
		if (key === this.shown) {
			return;
		}
		this.shown = key;
		for (const target of targets) {
			const html = restored ? (this.originals.get(target) ?? '') : priceHtml;
			if (target.innerHTML !== html) {
				target.innerHTML = html;
			}
		}
		const detail: PriceDetail = {
			...context,
			variationId: restored ? null : variationId,
			priceHtml: restored ? (original ?? '') : priceHtml,
		};
		this.container.dispatchEvent(
			new CustomEvent(PRICE_EVENT, { detail, bubbles: true }),
		);
	}

	/** Whether the form still holds the choice the printed price is for, so
	 *  that no variation means none was found yet. */
	private awaitsOpeningVariation(): boolean {
		return (
			this.shown === PRINTED_OPENING &&
			holdsChoice(this.form, this.openingChoice)
		);
	}

	private targets(): HTMLElement[] {
		const own = Array.from(
			this.form.querySelectorAll<HTMLElement>(TARGET_SELECTOR),
		);
		if (own.length > 0) {
			return own;
		}
		// The form's own product; a price inside another product there (a
		// related one, a nested Single Product block) is that product's.
		const product = this.form.closest(PRODUCT_SELECTOR);
		const scope = product ?? this.form.ownerDocument;
		const summary = Array.from(
			scope.querySelectorAll<HTMLElement>(SUMMARY_PRICE_SELECTOR),
		).find(
			(element) =>
				!this.form.contains(element) &&
				element.closest(PRODUCT_LIST_SELECTOR) === null &&
				(product === null || element.closest(PRODUCT_SELECTOR) === product),
		);
		return summary === undefined ? [] : [summary];
	}
}

/** The choice the server printed the opening price for; empty when it printed
 *  none, or JSON the page mangled. */
function readOpeningChoice(container: HTMLElement): Record<string, string> {
	try {
		const parsed: unknown = JSON.parse(
			container.dataset.pixonautOpeningChoice ?? '',
		);
		return typeof parsed === 'object' && parsed !== null
			? (parsed as Record<string, string>)
			: {};
	} catch {
		return {};
	}
}
