// The hosted correction page a correction link minted with a `returnUrl`
// opens, `GET /h/correct/{token}`. The fake renders no designer: its page
// takes new wording for the design's first text layer and offers to approve
// or cancel, as a test would after editing the design. Approving makes a
// correction proof of the design as it is now, which writes nothing to the
// design, and sends the customer back to the link's `returnUrl` with `state`
// and the proof's `proofToken`; cancelling sends `state` alone. The store
// redeems the token with `PATCH /v1/designs/{id}`. A link minted with
// `adjustLayout` offers no wording: approving makes a layout adjustment's
// proof, which the store redeems with `POST /v1/designs/standing`.
import type { Context, Hono } from 'hono';
import type { Fake } from './app';
import { LINK_BEARER_PREFIX } from './auth';
import {
	CORRECTION_PAGE_PATH,
	editable,
	mintCorrectionProof,
} from './handlers/corrections';
import { type Design, findDesign } from './handlers/designs-store';
import { escapeHtml, html, htmlResponse } from './proof-pages';
import type { CorrectionPage, ShareLink } from './state';

const GONE_STATUS = 410;
const CONFLICT_STATUS = 409;

/** The form field holding the new wording. */
const TEXT_FIELD = 'text';

/** The text layer prop a correction's wording replaces. */
const TEXT_PROP = 'content';

const TEXT_LAYER_TYPE = 'text';

type Opened = { link: ShareLink; page: CorrectionPage; design: Design };

/** The page `token` opens while its link works, with its design. */
function open(fake: Fake, token: string): Opened | undefined {
	const link = fake.state.shareLinks.get(`${LINK_BEARER_PREFIX}${token}`);
	if (
		link === undefined ||
		link.correctionPage === undefined ||
		link.expiresAt <= fake.clock.now()
	) {
		return undefined;
	}
	const design = findDesign(fake.state, link.subject.id, link.storeId);
	return design === undefined
		? undefined
		: { link, page: link.correctionPage, design };
}

/** The id of the design's first text layer, which the page's wording fills. */
function firstTextLayer(design: Design): string | undefined {
	const layers = design.proof.template.designDoc.layers;
	for (const layer of Array.isArray(layers) ? layers : []) {
		const kind = (layer as { kind?: { type?: unknown } }).kind;
		const id = (layer as { id?: unknown }).id;
		if (kind?.type === TEXT_LAYER_TYPE && typeof id === 'string') {
			return id;
		}
	}
	return undefined;
}

function back(page: CorrectionPage): URL {
	const url = new URL(page.returnUrl);
	if (page.state !== null) {
		url.searchParams.set('state', page.state);
	}
	return url;
}

function pagePath(token: string, suffix = ''): string {
	return `${CORRECTION_PAGE_PATH}/${encodeURIComponent(token)}${suffix}`;
}

function correctionPage(
	token: string,
	design: Design,
	adjustsLayout: boolean,
): string {
	const name = design.proof.template.template.name ?? 'Your design';
	// A layout adjustment offers nothing to fill in: the fake moves nothing either.
	const field = adjustsLayout
		? '<p>Layout</p>'
		: `<label for="${TEXT_FIELD}">Text</label>
<input id="${TEXT_FIELD}" name="${TEXT_FIELD}" type="text">`;
	return html(
		`Correct: ${name}`,
		`<h1>${escapeHtml(name)}</h1>
<p>Revision ${design.revision}</p>
<form method="get" action="${escapeHtml(pagePath(token, '/approve'))}">
${field}
<button type="submit">Approve design</button>
</form>
<p><a href="${escapeHtml(pagePath(token, '/cancel'))}">Cancel</a></p>`,
	);
}

export function registerCorrectionPage(app: Hono, fake: Fake): void {
	const gone = () =>
		htmlResponse(
			html('Correction', '<p>This correction link no longer works.</p>'),
			GONE_STATUS,
		);
	const closed = () =>
		htmlResponse(
			html('Correction', '<p>This design can no longer be changed.</p>'),
			CONFLICT_STATUS,
		);

	app.get(`${CORRECTION_PAGE_PATH}/:token`, (c) => {
		const token = c.req.param('token');
		const opened = open(fake, token);
		if (opened === undefined) {
			return gone();
		}
		return editable(opened.design)
			? htmlResponse(
					correctionPage(
						token,
						opened.design,
						opened.link.adjustsLayout === true,
					),
				)
			: closed();
	});

	app.get(`${CORRECTION_PAGE_PATH}/:token/cancel`, (c: Context) => {
		const opened = open(fake, c.req.param('token') ?? '');
		return opened === undefined
			? gone()
			: c.redirect(back(opened.page).toString(), 302);
	});

	app.get(`${CORRECTION_PAGE_PATH}/:token/approve`, (c: Context) => {
		const opened = open(fake, c.req.param('token') ?? '');
		if (opened === undefined) {
			return gone();
		}
		const { design, link, page } = opened;
		if (!editable(design)) {
			return closed();
		}
		const text = c.req.query(TEXT_FIELD) ?? '';
		const layer = firstTextLayer(design);
		const values =
			text === '' || layer === undefined || link.adjustsLayout === true
				? {}
				: { [layer]: { [TEXT_PROP]: text } };
		const proof = mintCorrectionProof(
			fake.state,
			fake.clock,
			design,
			link.storeId,
			values,
			link.adjustsLayout === true,
		);
		const url = back(page);
		url.searchParams.set('proofToken', proof.token);
		return c.redirect(url.toString(), 302);
	});
}
