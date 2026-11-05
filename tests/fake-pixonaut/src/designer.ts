// The hosted designer a designer link opens, `GET /designer/{token}`. The
// fake renders no designer: its page offers to approve or cancel, as a test
// would after filling the form. Approving makes each side's proof and sends
// the shopper back to the link's `returnUrl`, keeping its query, with the
// link's `state` if it has one and the proof tokens (`proofToken`, or
// `proofToken[<key>]` with `sides`); cancelling sends no token. Each side
// says the variant it starts on, by its options, and the views it is shown in.
import type { Context, Hono } from 'hono';
import type { Fake } from './app';
import {
	DESIGNER_LINKS,
	DESIGNER_PATH,
	type DesignerLink,
} from './handlers/designer-links';
import { escapeHtml } from './proof-pages';
import { mintProof } from './proofs';
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

function back(link: DesignerLink): URL {
	const url = new URL(link.returnUrl ?? '');
	// The store's own query stays; `state` is the link's, or none.
	if (link.state === null) {
		url.searchParams.delete('state');
	} else {
		url.searchParams.set('state', link.state);
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
): string {
	const base = `${DESIGNER_PATH}/${encodeURIComponent(token)}`;
	return `<!doctype html>
<html lang="${pageLanguage(state, link.tenantId, lang, link.locale)}">
<head><meta charset="utf-8"><title>Pixonaut designer</title></head>
<body>
<h1>Pixonaut designer</h1>
${link.sides.map((side) => sideText(state, side)).join('\n')}
<p>Layout: ${link.layout ?? 'template'}</p>
<p><a href="${base}/approve">${APPROVE_TEXT[link.afterApprove]}</a></p>
<p><a href="${base}/cancel">Cancel</a></p>
</body>
</html>`;
}

/** The approve button's words, by the link's `afterApprove`. */
const APPROVE_TEXT: Record<DesignerLink['afterApprove'], string> = {
	return: 'Approve and go back',
	add_to_cart: 'Approve and add to cart',
};

export function registerHostedDesigner(app: Hono, fake: Fake): void {
	const gone = (c: Context) =>
		c.text('This designer link no longer works.', 410);

	app.get(`${DESIGNER_PATH}/:token`, (c) => {
		const token = c.req.param('token');
		const link = openLink(fake, token);
		return link === undefined
			? gone(c)
			: c.html(page(fake.state, token, link, c.req.query('lang')));
	});

	app.get(`${DESIGNER_PATH}/:token/cancel`, (c) => {
		const link = openLink(fake, c.req.param('token'));
		if (link === undefined) {
			return gone(c);
		}
		return link.returnUrl === null
			? c.text('Cancelled.')
			: c.redirect(back(link).toString(), 302);
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
		const url = back(link);
		for (const side of link.sides) {
			const proof = mintProof(fake.state, fake.clock, {
				storeId: link.storeId,
				templateId: side.templateId,
				blankId: side.blankId,
				placementId: side.placementId,
				printSize: side.printSize,
			});
			if (typeof proof === 'string') {
				throw new Error(`The designer link's side was refused: ${proof}`);
			}
			url.searchParams.set(
				side.key === null ? 'proofToken' : `proofToken[${side.key}]`,
				proof.token,
			);
		}
		return c.redirect(url.toString(), 302);
	});
}
