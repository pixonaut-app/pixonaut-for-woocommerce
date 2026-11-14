// `POST /__fake/blanks/:id/costs`: sets what the printshop charges for some
// of a blank's variants, as when a printshop changes its price list, so a
// test can see the store follow a cost: `{costs: {<ref>: {product, shipping?,
// currency?}}}`, `product` a decimal string, or null for a variant the
// printshop publishes no price for. A variant left out keeps its cost. The
// next reset brings back the fixture's costs.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { type Body, jsonBody, PREFIX, type Read, refused } from './control';
import { COST_CURRENCY, costOf } from './handlers/variant-costs';
import type { Blank, VariantCost } from './state';

const NOT_FOUND_STATUS = 404;

/** A decimal amount, as Pixonaut writes one. */
const AMOUNT = /^\d+(\.\d+)?$/;

function amount(value: unknown): value is string {
	return typeof value === 'string' && AMOUNT.test(value);
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
		const cost = (value ?? {}) as Record<string, unknown>;
		const before = costOf(blank, ref);
		const shipping = cost.shipping ?? before.shipping;
		const currency = cost.currency ?? before.currency ?? COST_CURRENCY;
		if (
			typeof value !== 'object' ||
			value === null ||
			!(cost.product === null || amount(cost.product)) ||
			!(shipping === null || amount(shipping)) ||
			typeof currency !== 'string'
		) {
			return refused(`the cost of \`${ref}\` is not a product price`);
		}
		costs[ref] = {
			product: cost.product as string | null,
			shipping: shipping as string | null,
			currency,
			changedAt: now,
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
