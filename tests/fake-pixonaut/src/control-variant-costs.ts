// `POST /__fake/blanks/:id/costs`: sets what the printshop charges for some
// of a blank's variants, as when a printshop changes its price list, so a
// test can see the store follow a cost: `{costs: {<ref>: {product?, shipping?,
// currency?, delivery?, pages?}}}`, `product` a decimal string, or null for a
// variant the printshop publishes no price for, `delivery` the days from the
// order to the door (`{minDays, maxDays}`), or null for none told, and `pages`
// a book's cost at each page count, `[{pages, product, shipping}]`. A field
// left out keeps its value, and a variant left out its cost. The next reset
// brings back the fixture's costs.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { type Body, jsonBody, PREFIX, type Read, refused } from './control';
import { COST_CURRENCY, costOf } from './handlers/variant-costs';
import type { Blank, DeliveryDays, PageCost, VariantCost } from './state';

const NOT_FOUND_STATUS = 404;

/** A decimal amount, as Pixonaut writes one. */
const AMOUNT = /^\d+(\.\d+)?$/;

function amount(value: unknown): value is string {
	return typeof value === 'string' && AMOUNT.test(value);
}

function days(value: unknown): value is DeliveryDays {
	const span = value as Partial<DeliveryDays> | null;
	return (
		typeof span === 'object' &&
		span !== null &&
		Number.isInteger(span.minDays) &&
		Number.isInteger(span.maxDays)
	);
}

/** A book's costs by page count as a test gives them; null for anything else. */
function pageCosts(value: unknown): PageCost[] | null {
	if (!Array.isArray(value)) {
		return null;
	}
	const read: PageCost[] = [];
	for (const at of value as Record<string, unknown>[]) {
		const { pages, product, shipping } = at ?? {};
		if (
			!Number.isInteger(pages) ||
			(pages as number) < 1 ||
			!(product === null || amount(product)) ||
			!(shipping === null || amount(shipping))
		) {
			return null;
		}
		read.push({ pages: pages as number, product, shipping });
	}
	return read.sort((a, b) => a.pages - b.pages);
}

function readCosts(
	body: Body,
	blank: Blank,
	now: number,
): Read<Record<string, VariantCost>> {
	const given = body.costs;
	if (given === null || typeof given !== 'object' || Array.isArray(given)) {
		return refused('`costs` is not an object of variants');
	}
	const costs: Record<string, VariantCost> = {};
	for (const [ref, value] of Object.entries(given)) {
		if (!blank.variants.some((variant) => variant.ref === ref)) {
			return refused(`the blank has no variant \`${ref}\``);
		}
		if (typeof value !== 'object' || value === null || Array.isArray(value)) {
			return refused(`the cost of \`${ref}\` is not a product price`);
		}
		const cost = value as Record<string, unknown>;
		const before = costOf(blank, ref);
		const product = 'product' in cost ? cost.product : before.product;
		const shipping = cost.shipping ?? before.shipping;
		const currency = cost.currency ?? before.currency ?? COST_CURRENCY;
		const delivery = 'delivery' in cost ? cost.delivery : before.delivery;
		const pages = 'pages' in cost ? pageCosts(cost.pages) : before.pages;
		if (
			pages === null ||
			!(product === null || amount(product)) ||
			!(shipping === null || amount(shipping)) ||
			typeof currency !== 'string' ||
			!(delivery === undefined || delivery === null || days(delivery))
		) {
			return refused(`the cost of \`${ref}\` is not a product price`);
		}
		costs[ref] = {
			product,
			shipping: shipping as string | null,
			currency,
			changedAt: now,
			...(delivery === undefined ? {} : { delivery }),
			...(pages === undefined ? {} : { pages }),
		};
	}
	return { ok: true, value: costs };
}

export function registerVariantCostsControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/blanks/:id/costs`, async (c) => {
		const blank = fake.state.blanks.get(c.req.param('id'));
		if (blank === undefined) {
			return c.json({ error: 'there is no such blank' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		const costs = body.ok
			? readCosts(body.value, blank, fake.clock.now())
			: body;
		if (!costs.ok) {
			return costs.response;
		}
		blank.costs = { ...blank.costs, ...costs.value };
		return c.json({ costs: blank.costs });
	});
}
