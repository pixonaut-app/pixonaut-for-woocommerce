// The pricing rule's example on the settings page's Prices tab: what the rule
// prices a few costs at, worked out again as its fields change. The server
// draws it from the rule as saved (`PriceExample`); this repeats the rule's
// arithmetic (`PriceRule`, `Amount`) on the fields as typed, in thousandths.

/** Thousandths in one unit of money, as `Amount` counts. */
const PER_UNIT = 1000;

/** A margin is a share of the price, so it stays under all of it. */
const WHOLE_PRICE_PERCENT = 100;

/** An amount or a percentage as written: digits, a point or a comma, digits. */
const WRITTEN = /^(\d+)(?:[.,](\d+))?$/;

/** The decimals an amount keeps, and the one after them that rounds. */
const DECIMALS = 3;

/** A rounding digit from which the amount rounds up. */
const ROUND_UP_FROM = 5;

/** The rule's parts as written, by part, as `PriceRule::PARTS` names them. */
export type Parts = Partial<
	Record<
		'margin' | 'fixed' | 'tolerance' | 'step' | 'ending' | 'min' | 'max',
		string
	>
>;

/** The rule, as `PriceRule::from_parts()` reads it. */
export interface Rule {
	margin: number | null;
	fixed: number;
	step: number;
	ending: number;
	min: number | null;
	max: number | null;
}

/** How the store shows money and a margin. */
export interface Format {
	symbol: string;
	position: string;
	decimals: number;
	decimal: string;
	thousand: string;
	number_decimal: string;
}

/** What the rule prices a cost at, and what that price leaves. */
export interface Example {
	price: number;
	contribution: number;
	/** Null for a price of nothing, as `PriceRule::margin_of` says. */
	margin: number | null;
}

/** An amount as written, in thousandths; null when it is not one (`Amount::parse`). */
export function parseAmount(written: string | undefined): number | null {
	const parts = WRITTEN.exec((written ?? '').trim());
	if (!parts) {
		return null;
	}
	const decimals = (parts[2] ?? '').padEnd(DECIMALS + 1, '0');
	const amount =
		Number(parts[1]) * PER_UNIT + Number(decimals.slice(0, DECIMALS));
	return Number(decimals[DECIMALS]) >= ROUND_UP_FROM ? amount + 1 : amount;
}

/** The rule its parts give (`PriceRule::from_parts`). */
export function ruleOf(parts: Parts): Rule {
	const margin = parseAmount(parts.margin);
	const step = parseAmount(parts.step);
	const steps = step === null || step === 0 ? PER_UNIT : step;
	const ending = parseAmount(parts.ending) ?? 0;
	const percent = margin === null ? null : margin / PER_UNIT;
	return {
		margin:
			percent !== null && percent > 0 && percent < WHOLE_PRICE_PERCENT
				? percent
				: null,
		fixed: parseAmount(parts.fixed) ?? 0,
		step: steps,
		ending: ending < steps ? ending : 0,
		min: parseAmount(parts.min),
		max: parseAmount(parts.max),
	};
}

/** PHP's `intdiv`: the division, rounded toward nothing. */
const intdiv = (a: number, b: number): number => Math.trunc(a / b);

/** The step nearest a price, within the minimum and maximum (`PriceRule::step_near`). */
export function stepNear(rule: Rule, price: number): number | null {
	const { step, ending, min, max } = rule;
	const steps = intdiv(2 * (price - ending) + step, 2 * step);
	let near = ending + steps * step;
	const floor = Math.max(1, min ?? 1);
	if (near < floor) {
		const distance = floor - ending;
		near =
			ending + (distance <= 0 ? 0 : intdiv(distance + step - 1, step)) * step;
	}
	if (max !== null && near > max) {
		near = ending + intdiv(max - ending, step) * step;
	}
	return near >= floor && (max === null || near <= max) ? near : null;
}

/**
 * What the rule prices a cost at, with a tax rate in the price as entered,
 * and the contribution and margin the price leaves; null when it sets none.
 */
export function example(
	rule: Rule,
	cost: number,
	vatPercent: number,
): Example | null {
	if (rule.margin === null) {
		return null;
	}
	const factor = 1 + vatPercent / WHOLE_PRICE_PERCENT;
	const excluding =
		Math.round(cost / (1 - rule.margin / WHOLE_PRICE_PERCENT)) + rule.fixed;
	const price = stepNear(rule, Math.round(excluding * factor));
	if (price === null) {
		return null;
	}
	const net = Math.round(price / factor);
	return {
		price,
		contribution: net - cost,
		margin: net > 0 ? ((net - cost) / net) * WHOLE_PRICE_PERCENT : null,
	};
}

/** An amount as the store shows prices, as `wc_price` does. */
export function money(amount: number, format: Format): string {
	const fixed = (Math.abs(amount) / PER_UNIT).toFixed(format.decimals);
	const [whole = '0', part] = fixed.split('.');
	const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, format.thousand);
	const number = `${grouped}${part === undefined ? '' : format.decimal + part}`;
	// The sign goes before the symbol too, as wc_price puts it.
	return `${amount < 0 ? '-' : ''}${placed(number, format)}`;
}

/** A positive amount with the currency symbol where the store puts it. */
function placed(number: string, format: Format): string {
	switch (format.position) {
		case 'right':
			return `${number}${format.symbol}`;
		case 'left_space':
			return `${format.symbol}\u00a0${number}`;
		case 'right_space':
			return `${number}\u00a0${format.symbol}`;
		default:
			return `${format.symbol}${number}`;
	}
}

/** A margin to a tenth, as `PricingPanel::percent` shows it. */
export function percent(margin: number, format: Format): string {
	return `${margin.toFixed(1).replace('.', format.number_decimal)} %`;
}

/** Draws the example again from the fields as typed. */
export function update(table: HTMLElement): void {
	const ids = JSON.parse(table.dataset.parts ?? '{}') as Record<string, string>;
	const format = JSON.parse(table.dataset.format ?? '{}') as Format;
	const vat = Number(table.dataset.vat ?? '0');
	const parts: Record<string, string> = {};
	for (const [part, id] of Object.entries(ids)) {
		const field = document.getElementById(id);
		parts[part] = field instanceof HTMLInputElement ? field.value : '';
	}
	const rule = ruleOf(parts);
	for (const row of table.querySelectorAll<HTMLElement>('tr[data-cost]')) {
		const shown = example(rule, parseAmount(row.dataset.cost) ?? 0, vat);
		const cells: Record<string, string> = {
			price: shown === null ? '—' : money(shown.price, format),
			contribution: shown === null ? '—' : money(shown.contribution, format),
			margin: shown?.margin == null ? '—' : percent(shown.margin, format),
		};
		for (const cell of row.querySelectorAll<HTMLElement>('[data-shows]')) {
			cell.textContent = cells[cell.dataset.shows ?? ''] ?? cell.textContent;
		}
	}
}

/** Sets up the example in `root`: drawn again whenever a part changes. */
export function initPriceExample(root: ParentNode): void {
	const table = root.querySelector<HTMLElement>(
		'[data-pixonaut-price-example]',
	);
	if (!table) {
		return;
	}
	const ids = Object.values(
		JSON.parse(table.dataset.parts ?? '{}') as Record<string, string>,
	);
	for (const id of ids) {
		document.getElementById(id)?.addEventListener('input', () => update(table));
	}
}
