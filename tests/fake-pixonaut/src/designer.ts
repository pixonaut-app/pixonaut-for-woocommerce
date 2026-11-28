// The hosted designer a designer link opens, `GET /designer/{token}`. The
// fake renders no designer: its page offers to approve or cancel, as a test
// would after filling the form. Approving makes each side's proof and sends
// the shopper back to the link's `returnUrl`, keeping its query, with the
// link's `state` if it has one and the proof tokens (`proofToken`, or
// `proofToken[<key>]` with `sides`); cancelling sends no token. A `state` the
// store adds to the hosted address for one trip is carried through the page
// and returned in place of the link's, unchecked and unstored; one outside the
// allowed form, or given more than once, is ignored. Each side says the variant it starts on, by its
// options, and the views it is shown in.
//
// A test approves a side as a book of pages by adding `pages=<count>` (the
// only side of a link of one side) or `pages[<key>]=<count>` to the approve
// address; else it makes as many as the link offers fewest. The approval then makes a proof per page, keeps every proof of the
// approval as a proof set, and returns `proofSet` in place of tokens. So
// does an approval of more than `MAX_SIDES_RETURNED_AS_TOKENS` sides, whose
// tokens would outgrow the address a store's server takes.
import type { Context, Hono } from 'hono';
import type { Fake } from './app';
import {
	DESIGNER_LINKS,
	DESIGNER_PATH,
	type DesignerLink,
} from './handlers/designer-links';
import { mintProofSet } from './handlers/proof-sets';
import { escapeHtml } from './proof-pages';
import { mintProof, type Proof } from './proofs';
import { allOwned, pageLanguage, type State } from './state';

/** The link `token` names while it works, or undefined. */
function openLink(fake: Fake, token: string): DesignerLink | undefined {
	const link = allOwned<DesignerLink>(fake.state, DESIGNER_LINKS).get(
		token,
	)?.value;
	return link === undefined ||
		(link.expiresAt !== null && link.expiresAt <= fake.clock.now())
		? undefined
		: link;
}

/** The query parameter carrying a trip's own `state`, there and back. */
const TRIP_STATE_PARAM = 'state';

/** The most sides an approval returns as a `proofToken[<key>]` each; past
 *  it, one proof set. */
const MAX_SIDES_RETURNED_AS_TOKENS = 10;

/** The form a trip's `state` takes: 1 to 200 unreserved URL characters. */
const TRIP_STATE_FORM = /^[A-Za-z0-9._~-]{1,200}$/;

/**
 * The trip's own `state` on a hosted page request, when it is given once and
 * well formed; a repeated one counts as missing.
 */
function tripState(c: Context): string | undefined {
	const values = c.req.queries(TRIP_STATE_PARAM) ?? [];
	const [value] = values;
	return values.length === 1 &&
		value !== undefined &&
		TRIP_STATE_FORM.test(value)
		? value
		: undefined;
}

function back(link: DesignerLink, trip: string | undefined): URL {
	const url = new URL(link.returnUrl ?? '');
	// The store's own query stays; `state` is the trip's, else the link's, or none.
	const state = trip ?? link.state;
	if (state === null) {
		url.searchParams.delete(TRIP_STATE_PARAM);
	} else {
		url.searchParams.set(TRIP_STATE_PARAM, state);
	}
	return url;
}

/** What the page says of a side: the variant it starts on and its views. */
function sideText(state: State, side: DesignerLink['sides'][number]): string {
	const variant =
		side.blankId === null || side.initialVariant === null
			? undefined
			: state.blanks
					.get(side.blankId)
					?.variants.find((v) => v.ref === side.initialVariant);
	const lines = [
		variant === undefined
			? 'Variant: any'
			: `Variant: ${Object.values(variant.options).join(', ')}`,
		`Views: ${side.views.length === 0 ? 'all' : side.views.join(', ')}`,
	];
	const label = side.label === null ? '' : `${side.label}: `;
	return lines.map((line) => `<p>${escapeHtml(label + line)}</p>`).join('\n');
}

function page(
	state: State,
	token: string,
	link: DesignerLink,
	lang: string | undefined,
	trip: string | undefined,
): string {
	const base = `${DESIGNER_PATH}/${encodeURIComponent(token)}`;
	// The trip's state holds for the whole visit, so each way back carries it.
	const query =
		trip === undefined
			? ''
			: `?${new URLSearchParams({ [TRIP_STATE_PARAM]: trip })}`;
	return `<!doctype html>
<html lang="${pageLanguage(state, link.tenantId, lang, link.locale)}">
<head><meta charset="utf-8"><title>Pixonaut designer</title></head>
<body>
<h1>Pixonaut designer</h1>
${link.sides.map((side) => sideText(state, side)).join('\n')}
<p>Layout: ${link.layout ?? 'template'}</p>
<p><a href="${base}/approve${query}">${APPROVE_TEXT[link.afterApprove]}</a></p>
<p><a href="${base}/cancel${query}">Cancel</a></p>
</body>
</html>`;
}

/** The approve button's words, by the link's `afterApprove`. */
const APPROVE_TEXT: Record<DesignerLink['afterApprove'], string> = {
	return: 'Approve and go back',
	add_to_cart: 'Approve and add to cart',
};

/** The page count a test asked a side to be approved at, or undefined for a
 *  side of no pages. */
function pagesAsked(c: Context, key: string | null): number | undefined {
	const asked = c.req.query(key === null ? 'pages' : `pages[${key}]`);
	const count = asked === undefined ? Number.NaN : Number(asked);
	return Number.isInteger(count) && count > 0 ? count : undefined;
}

export function registerHostedDesigner(app: Hono, fake: Fake): void {
	const gone = (c: Context) =>
		c.text('This designer link no longer works.', 410);

	app.get(`${DESIGNER_PATH}/:token`, (c) => {
		const token = c.req.param('token');
		const link = openLink(fake, token);
		return link === undefined
			? gone(c)
			: c.html(
					page(fake.state, token, link, c.req.query('lang'), tripState(c)),
				);
	});

	app.get(`${DESIGNER_PATH}/:token/cancel`, (c) => {
		const link = openLink(fake, c.req.param('token'));
		if (link === undefined) {
			return gone(c);
		}
		return link.returnUrl === null
			? c.text('Cancelled.')
			: c.redirect(back(link, tripState(c)).toString(), 302);
	});

	app.get(`${DESIGNER_PATH}/:token/approve`, (c) => {
		const link = openLink(fake, c.req.param('token'));
		if (link === undefined) {
			return gone(c);
		}
		if (link.returnUrl === null) {
			// Pixonaut commits the design itself then; no store test takes this path.
			return c.text('Approved.');
		}
		const url = back(link, tripState(c));
		const made: { key: string | null; proof: Proof }[] = [];
		for (const side of link.sides) {
			const pageCount = pagesAsked(c, side.key) ?? side.pages?.min;
			const pages =
				pageCount === undefined
					? [undefined]
					: Array.from({ length: pageCount }, (_, index) => ({
							page: index + 1,
							pageCount,
						}));
			for (const page of pages) {
				const proof = mintProof(fake.state, fake.clock, {
					storeId: link.storeId,
					templateId: side.templateId,
					blankId: side.blankId,
					placementId: side.placementId,
					printSize: side.printSize,
					...(page === undefined ? {} : { page }),
				});
				if (typeof proof === 'string') {
					throw new Error(`The designer link's side was refused: ${proof}`);
				}
				made.push({ key: side.key, proof });
			}
		}
		if (
			made.some(({ proof }) => proof.page !== undefined) ||
			link.sides.length > MAX_SIDES_RETURNED_AS_TOKENS
		) {
			const set = mintProofSet(fake.state, link.storeId, made);
			url.searchParams.set('proofSet', set.ref);
		} else {
			for (const { key, proof } of made) {
				url.searchParams.set(
					key === null ? 'proofToken' : `proofToken[${key}]`,
					proof.token,
				);
			}
		}
		return c.redirect(url.toString(), 302);
	});
}
