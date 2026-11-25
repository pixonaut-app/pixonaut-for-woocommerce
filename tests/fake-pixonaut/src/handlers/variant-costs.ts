// What a blank's variants cost at their printshop, as Pixonaut reads it from
// the printshop's price list: a product price and the cheapest shipping to a
// region per variant, as decimal strings with their currency, and how long
// the printshop says that shipping takes.
import type { Blank, DeliveryDays, VariantCost } from '../state';
import { tenantBlank } from './catalogue';
import { type Handler, type HandlerResult, refusal } from './types';

/** The currency the fixture printshops charge in: WooCommerce's default,
 *  so a fresh tests site prices from them. */
export const COST_CURRENCY = 'USD';

/** The first variant's product price, in cents. */
const FIRST_PRICE_CENTS = 12000;
/** What each later variant costs more than the one before it, in cents. */
const PRICE_STEP_CENTS = 1550;
/** The cheapest shipping of every variant. */
const SHIPPING = '4.90';

/** How long the fixture printshops say every variant takes from the order
 *  to the door, in days: the one span they tell. */
export const DELIVERY_DAYS = { minDays: 4, maxDays: 7 };

/** The regions the fixture printshops ship to. */
export const COST_REGIONS = ['SE', 'US', 'DE', 'NO', 'FI', 'DK', 'GB'];

const CENTS_PER_UNIT = 100;
const NOT_FOUND = refusal(404, 'not_found');

function decimal(cents: number): string {
	return (cents / CENTS_PER_UNIT).toFixed(2);
}

/** A variant's cost: the one a test gave it, else one that rises with its
 *  place among the blank's variants, so no two cost the same. */
export function costOf(blank: Blank, ref: string): VariantCost {
	const given = blank.costs?.[ref];
	if (given !== undefined) {
		return given;
	}
	const index = blank.variants.findIndex((variant) => variant.ref === ref);
	return {
		product: decimal(FIRST_PRICE_CENTS + Math.max(0, index) * PRICE_STEP_CENTS),
		shipping: SHIPPING,
		currency: COST_CURRENCY,
		changedAt: null,
	};
}

/** A cost's `times`: a time is told of what is shipped, by that shipping. */
function timesOf(
	product: string | null,
	shipping: string | null,
	delivery: DeliveryDays | null,
) {
	return {
		production: null,
		shipping: null,
		delivery: product === null || shipping === null ? null : delivery,
	};
}

/** `GET /v1/blanks/{id}/variants/costs`: every variant's cost with shipping
 *  to `region`, every variant read (`read`), a variant without a published
 *  price with `product` null and named in `missing`, a book with its cost at
 *  each page count (`pages`);
 *  refused `unknown_region` for a region no printshop ships to. */
export const listVariantCosts: Handler = (context): HandlerResult => {
	const blank = tenantBlank(context, context.params.id ?? '');
	if (blank === undefined) {
		return NOT_FOUND;
	}
	const region = context.query.get('region') ?? COST_REGIONS[0];
	if (!COST_REGIONS.includes(region)) {
		return refusal(422, 'unknown_region');
	}
	const readAt = new Date(context.clock.now()).toISOString();
	// The fake prices shipping for everything it sells, so a variant lacks
	// only its product price.
	const missing = blank.variants
		.filter((variant) => costOf(blank, variant.ref).product === null)
		.map((variant) => ({
			variant: variant.ref,
			reason: 'no_product' as const,
		}));
	return {
		status: 200,
		body: {
			// The fake holds every variant's cost at once, so a read is whole.
			read: true,
			complete: missing.length === 0,
			missing,
			// Every cost the fake holds names its own currency.
			currencyNeeded: false,
			costs: blank.variants.map((variant) => {
				const cost = costOf(blank, variant.ref);
				const money = (amount: string | null) =>
					amount === null ? null : { amount, currency: cost.currency };
				const delivery =
					cost.delivery === undefined ? DELIVERY_DAYS : cost.delivery;
				return {
					variant: variant.ref,
					product: money(cost.product),
					// Shipping is priced only for something the printshop sells.
					shipping: cost.product === null ? null : money(cost.shipping),
					// A time is told of what is shipped, by that shipping.
					times: timesOf(cost.product, cost.shipping, delivery),
					// A book's cost at each page count; empty for anything else.
					pages: (cost.pages ?? []).map((at) => ({
						pages: at.pages,
						product: money(at.product),
						shipping: at.product === null ? null : money(at.shipping),
						times: timesOf(at.product, at.shipping, delivery),
					})),
					region,
					readAt,
					changedAt:
						cost.changedAt === null
							? null
							: new Date(cost.changedAt).toISOString(),
				};
			}),
		},
	};
};
