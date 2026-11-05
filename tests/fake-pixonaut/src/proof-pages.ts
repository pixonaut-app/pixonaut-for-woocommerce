// The hosted proof page a store's proof page link opens, standing in for
// Pixonaut's: the design's proof and, on a deciding link, an "Approve" and a
// "Reject" button and a "Request changes" form. A decision is delivered to the
// store's webhook endpoint as `design.approved` or `design.rejected`, through
// the same deliveries an emit makes. A request for changes leaves the design
// committed and is delivered as `design.changes_requested` with the
// customer's words; the page then sends the customer to the link's
// `returnUrl` with its `state`, as Pixonaut's does, or says the request was
// sent when the link has none. It is a page, not an operation of the
// reference, so it is served outside validation, like the studio's consent
// screen.
//
// The control API takes the same two decisions and the request for changes
// under `/__fake/proof-pages/:token/`, for a test that decides without a
// browser.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';
import {
	applyApproval,
	applyRejection,
	type Decision,
	type Design,
	type DesignConsent,
	type DesignStatus,
	findDesign,
	judgeDecision,
} from './handlers/designs-store';
import { WHITE_JPEG } from './handlers/images';
import { PROOF_PAGE_PATH } from './handlers/share-links';
import { type ProofPage, pageLanguage, type ShareLink } from './state';
import type { Attempt } from './webhooks/deliveries';

const PAGE = `${PROOF_PAGE_PATH}/:token`;
const CONTROL = `${PREFIX}/proof-pages/:token`;

const BAD_REQUEST_STATUS = 400;
const FORBIDDEN_STATUS = 403;
const NOT_FOUND_STATUS = 404;
const CONFLICT_STATUS = 409;
const GONE_STATUS = 410;
/** The fake failed to deliver a decision it made: a fake bug, not a refusal. */
const FAKE_FAILED_STATUS = 500;
/** Where the page sends the customer once they asked for changes: a GET of
 *  the store's page, whatever the form was posted with. */
const SEE_OTHER_STATUS = 303;

/** The path segment, after the page's own, that takes a request for changes. */
const REQUEST_CHANGES = 'request-changes';

/** The bounds `POST /v1/designs/{id}/request-changes` puts on `message`, in
 *  characters. */
const MESSAGE_MIN_LENGTH = 1;
export const MESSAGE_MAX_LENGTH = 1000;

/** The query parameters the page adds to `returnUrl`: the link's `state`, and
 *  a proof token, which a proof page never brings back but drops from the
 *  store's own query all the same. */
const STATE_PARAM = 'state';
const PROOF_TOKEN_PARAM = 'proofToken';

const DECISIONS: readonly Decision[] = ['approve', 'reject'];

/** The page a decision leaves the customer on. */
const DECIDED_HEADING: Record<Decision, string> = {
	approve: 'Design approved',
	reject: 'Design rejected',
};

/** What the page says about a design it cannot decide. */
const STATUS_TEXT: Record<DesignStatus, string> = {
	committed: 'Waiting for your decision.',
	approved: 'Design approved',
	rejected: 'Design rejected',
};

type Refusal = {
	ok: false;
	status: number;
	error: string;
	/** The revision the design has now, with `revision_changed`. */
	revision?: number;
};

type Opened =
	| { ok: true; link: ShareLink; page: ProofPage; design: Design }
	| Refusal;

export type Decided =
	| {
			ok: true;
			designId: string;
			status: DesignStatus;
			revision: number;
			/** False when it was already decided so: no event is sent again. */
			emitted: boolean;
			attempts: Attempt[];
	  }
	| Refusal;

export type ChangesRequested =
	| {
			ok: true;
			designId: string;
			revision: number;
			emitted: true;
			attempts: Attempt[];
			/** Where the page sends the customer; null when the link names no
			 *  `returnUrl`, and the page keeps them. */
			redirect: string | null;
	  }
	| Refusal;

/** The proof page `token` opens and its design, or why it opens nothing. */
function open(fake: Fake, token: string): Opened {
	const { state } = fake;
	const link = state.shareLinks.get(token);
	const page = link?.proofPage;
	if (link === undefined || page === undefined) {
		return { ok: false, status: NOT_FOUND_STATUS, error: 'not_found' };
	}
	if (link.expiresAt <= fake.clock.now()) {
		return { ok: false, status: GONE_STATUS, error: 'link_expired' };
	}
	const design = findDesign(state, link.subject.id, link.storeId);
	if (design === undefined) {
		return { ok: false, status: NOT_FOUND_STATUS, error: 'not_found' };
	}
	return { ok: true, link, page, design };
}

/** The event a decision about `design` is delivered as. */
function event(decision: Decision, design: Design) {
	return decision === 'approve'
		? {
				type: 'design.approved',
				payload: {
					designId: design.id,
					approvedVia: 'explicit',
					consent: design.consent,
					revision: design.revision,
				},
			}
		: {
				type: 'design.rejected',
				// A proof page link may not give a reason.
				payload: {
					designId: design.id,
					revision: design.revision,
					reason: null,
				},
			};
}

/**
 * Takes the customer's `decision` through the proof page `token` opens, about
 * `shownRevision` (the revision whose proof they were shown; the current one
 * when absent), and delivers it to the store's endpoint.
 */
export async function decide(
	fake: Fake,
	token: string,
	decision: Decision,
	shownRevision: number | undefined,
): Promise<Decided> {
	const opened = open(fake, token);
	if (!opened.ok) {
		return opened;
	}
	const { link, page, design } = opened;
	if (!page.mayDecide) {
		return { ok: false, status: FORBIDDEN_STATUS, error: 'forbidden' };
	}
	const judgement = judgeDecision(design, decision, shownRevision);
	if (judgement.kind === 'not_committed') {
		return { ok: false, status: CONFLICT_STATUS, error: 'not_committed' };
	}
	if (judgement.kind === 'revision_changed') {
		return {
			ok: false,
			status: CONFLICT_STATUS,
			error: 'revision_changed',
			revision: judgement.revision,
		};
	}
	const decided = {
		ok: true as const,
		designId: design.id,
		revision: design.revision,
	};
	if (judgement.kind === 'unchanged') {
		return { ...decided, status: design.status, emitted: false, attempts: [] };
	}
	if (decision === 'approve') {
		const consent: DesignConsent = {
			kind: 'proof_page',
			at: fake.clock.now(),
			ref: null,
		};
		applyApproval(design, consent);
	} else {
		applyRejection(design);
	}
	const delivered = await deliver(
		fake,
		link.storeId,
		decision,
		event(decision, design),
	);
	if (!delivered.ok) {
		return delivered;
	}
	return {
		...decided,
		status: design.status,
		emitted: true,
		attempts: delivered.attempts,
	};
}

/**
 * Delivers the `event` the page's `action` made to `storeId`'s endpoint. The
 * page has already acted, so an undelivered event is the fake's own failure:
 * a violation, answered 500.
 */
async function deliver(
	fake: Fake,
	storeId: string,
	action: string,
	event: { type: string; payload: Record<string, unknown> },
): Promise<{ ok: true; attempts: Attempt[] } | Refusal> {
	const emitted = await fake.webhooks.emit(storeId, [event]);
	if (emitted.ok) {
		return { ok: true, attempts: emitted.attempts };
	}
	const message = `the ${action} was not delivered: ${emitted.problems.join('; ')}`;
	fake.violations.push({
		operation: `POST ${PROOF_PAGE_PATH}/{token}/${action} (hosted page)`,
		direction: 'response',
		at: 'webhook',
		message,
	});
	return { ok: false, status: FAKE_FAILED_STATUS, error: message };
}

/** Whether `raw`, a query parameter's name as sent, is `name` or one of its
 *  bracketed keys (`proofToken[front]`), decoded as PHP reads it. */
function namesParam(raw: string, name: string): boolean {
	let decoded: string;
	try {
		decoded = decodeURIComponent(raw.replace(/\+/g, ' '));
	} catch {
		decoded = raw;
	}
	return decoded === name || decoded.startsWith(`${name}[`);
}

/**
 * `returnUrl` with the link's `state`, as Pixonaut sends the customer back:
 * the store's own query is kept as it was, a `proofToken` in it is dropped,
 * and a `state` in it is replaced by the link's, or dropped when the link
 * carries none.
 */
export function returnAddress(returnUrl: string, state: string | null): string {
	const url = new URL(returnUrl);
	const kept = url.search
		.replace(/^\?/, '')
		.split('&')
		.filter((pair) => {
			const name = pair.split('=')[0] ?? '';
			return (
				pair !== '' &&
				!namesParam(name, STATE_PARAM) &&
				!namesParam(name, PROOF_TOKEN_PARAM)
			);
		});
	if (state !== null) {
		kept.push(`${STATE_PARAM}=${encodeURIComponent(state)}`);
	}
	url.search = kept.join('&');
	return url.toString();
}

/** `message` when `POST /v1/designs/{id}/request-changes` would take it. */
function messageOf(value: unknown): string | undefined {
	if (typeof value !== 'string') {
		return undefined;
	}
	const length = [...value].length;
	return length >= MESSAGE_MIN_LENGTH && length <= MESSAGE_MAX_LENGTH
		? value
		: undefined;
}

/**
 * Takes the customer's request for changes, `message`, through the proof page
 * `token` opens, about `shownRevision` (the current one when absent), and
 * delivers it to the store's endpoint. The design stays committed: the fake
 * keeps no scheduled approval or open review for the request to touch.
 */
export async function requestChanges(
	fake: Fake,
	token: string,
	message: unknown,
	shownRevision: number | undefined,
): Promise<ChangesRequested> {
	const words = messageOf(message);
	if (words === undefined) {
		return { ok: false, status: BAD_REQUEST_STATUS, error: 'bad_request' };
	}
	const opened = open(fake, token);
	if (!opened.ok) {
		return opened;
	}
	const { link, page, design } = opened;
	if (!page.mayDecide) {
		return { ok: false, status: FORBIDDEN_STATUS, error: 'forbidden' };
	}
	if (design.status !== 'committed') {
		return { ok: false, status: CONFLICT_STATUS, error: 'not_committed' };
	}
	if (shownRevision !== undefined && shownRevision !== design.revision) {
		return {
			ok: false,
			status: CONFLICT_STATUS,
			error: 'revision_changed',
			revision: design.revision,
		};
	}
	const delivered = await deliver(fake, link.storeId, REQUEST_CHANGES, {
		type: 'design.changes_requested',
		payload: {
			designId: design.id,
			revision: design.revision,
			message: words,
		},
	});
	if (!delivered.ok) {
		return delivered;
	}
	return {
		ok: true,
		designId: design.id,
		revision: design.revision,
		emitted: true,
		attempts: delivered.attempts,
		redirect:
			page.returnUrl === null
				? null
				: returnAddress(page.returnUrl, page.state),
	};
}

const HTML_ESCAPES: Record<string, string> = {
	'&': '&amp;',
	'<': '&lt;',
	'>': '&gt;',
	'"': '&quot;',
	"'": '&#39;',
};

export function escapeHtml(value: string): string {
	return value.replace(/[&<>"']/g, (char) => HTML_ESCAPES[char] ?? char);
}

export function html(title: string, body: string, lang = 'en'): string {
	return `<!doctype html>
<html lang="${escapeHtml(lang)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(title)}</title>
</head>
<body>
${body}
</body>
</html>
`;
}

/** The page's own path for `token`, plus `suffix`: a path, so the page works
 *  under whichever host the browser reached the fake by. */
function pagePath(token: string, suffix = ''): string {
	return `${PROOF_PAGE_PATH}/${encodeURIComponent(token)}${suffix}`;
}

const REFUSAL_TEXT: Record<string, string> = {
	not_found: 'This proof link does not exist.',
	link_expired: 'This proof link has expired.',
	bad_request: `Say what should change, in at most ${MESSAGE_MAX_LENGTH} characters.`,
	forbidden: 'This proof link cannot approve or reject the design.',
	not_committed: 'This design has already been decided.',
	revision_changed:
		'This design has changed since this proof was shown. Reload the page.',
};

export function htmlResponse(markup: string, status = 200): Response {
	return new Response(markup, {
		status,
		headers: { 'content-type': 'text/html; charset=utf-8' },
	});
}

function refusalPage(refusal: Refusal): Response {
	const text = REFUSAL_TEXT[refusal.error] ?? refusal.error;
	return htmlResponse(
		html('Proof', `<h1>Proof unavailable</h1>\n<p>${escapeHtml(text)}</p>`),
		refusal.status,
	);
}

function proofPage(
	token: string,
	page: ProofPage,
	design: Design,
	language: string,
): string {
	const name = design.proof.template.template.name ?? 'Your design';
	const decidable = page.mayDecide && design.status === 'committed';
	const form = (decision: Decision, label: string) =>
		`<form method="post" action="${escapeHtml(pagePath(token, `/${decision}`))}">
<input type="hidden" name="revision" value="${design.revision}">
<button type="submit">${label}</button>
</form>`;
	const changes = `<form method="post" action="${escapeHtml(pagePath(token, `/${REQUEST_CHANGES}`))}">
<input type="hidden" name="revision" value="${design.revision}">
<label for="message">What should change?</label>
<textarea id="message" name="message" required minlength="${MESSAGE_MIN_LENGTH}" maxlength="${MESSAGE_MAX_LENGTH}"></textarea>
<button type="submit">Request changes</button>
</form>`;
	const actions = decidable
		? `${form('approve', 'Approve')}\n${form('reject', 'Reject')}\n${changes}`
		: `<p>${escapeHtml(STATUS_TEXT[design.status])}</p>`;
	return html(
		`Proof: ${name}`,
		`<h1>${escapeHtml(name)}</h1>
<img src="${escapeHtml(pagePath(token, '/image'))}" alt="Proof of the design" width="320">
<p>Revision ${design.revision}</p>
${actions}`,
		language,
	);
}

/** The revision a form or a control command names, or undefined. */
function revisionOf(value: unknown): number | undefined {
	const revision = typeof value === 'string' ? Number(value) : value;
	return Number.isInteger(revision) && (revision as number) > 0
		? (revision as number)
		: undefined;
}

export function registerProofPages(app: Hono, fake: Fake): void {
	app.get(PAGE, (c) => {
		const token = c.req.param('token');
		const opened = open(fake, token);
		return opened.ok
			? htmlResponse(
					proofPage(
						token,
						opened.page,
						opened.design,
						pageLanguage(
							fake.state,
							opened.link.tenantId,
							c.req.query('lang'),
							opened.page.locale,
						),
					),
				)
			: refusalPage(opened);
	});

	app.get(`${PAGE}/image`, (c) => {
		const opened = open(fake, c.req.param('token'));
		if (!opened.ok) {
			return new Response(null, { status: opened.status });
		}
		return new Response(new Uint8Array(WHITE_JPEG), {
			headers: { 'content-type': 'image/jpeg' },
		});
	});

	for (const decision of DECISIONS) {
		app.post(`${PAGE}/${decision}`, async (c) => {
			const form = await c.req.parseBody();
			const decided = await decide(
				fake,
				c.req.param('token'),
				decision,
				revisionOf(form.revision),
			);
			if (!decided.ok) {
				return refusalPage(decided);
			}
			const heading = DECIDED_HEADING[decision];
			return htmlResponse(
				html(heading, `<h1>${heading}</h1>\n<p>Thank you.</p>`),
			);
		});

		app.post(`${CONTROL}/${decision}`, async (c) => {
			const body = await jsonBody(c.req.raw);
			if (!body.ok) {
				return body.response;
			}
			const { revision } = body.value;
			if (revision !== undefined && revisionOf(revision) === undefined) {
				return refused('`revision` is not a positive integer').response;
			}
			const decided = await decide(
				fake,
				c.req.param('token'),
				decision,
				revisionOf(revision),
			);
			if (!decided.ok) {
				const { ok: _, status, ...refusal } = decided;
				return Response.json(refusal, { status });
			}
			const { ok: _, ...outcome } = decided;
			return Response.json(outcome);
		});
	}

	app.post(`${PAGE}/${REQUEST_CHANGES}`, async (c) => {
		const form = await c.req.parseBody();
		const requested = await requestChanges(
			fake,
			c.req.param('token'),
			form.message,
			revisionOf(form.revision),
		);
		if (!requested.ok) {
			return refusalPage(requested);
		}
		if (requested.redirect !== null) {
			return new Response(null, {
				status: SEE_OTHER_STATUS,
				headers: { location: requested.redirect },
			});
		}
		return htmlResponse(
			html(
				'Request sent',
				'<h1>Request sent</h1>\n<p>Your request was sent to the store.</p>',
			),
		);
	});

	app.post(`${CONTROL}/${REQUEST_CHANGES}`, async (c) => {
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { message, revision } = body.value;
		if (revision !== undefined && revisionOf(revision) === undefined) {
			return refused('`revision` is not a positive integer').response;
		}
		const requested = await requestChanges(
			fake,
			c.req.param('token'),
			message,
			revisionOf(revision),
		);
		if (!requested.ok) {
			const { ok: _, status, ...refusal } = requested;
			return Response.json(refusal, { status });
		}
		const { ok: _, ...outcome } = requested;
		return Response.json(outcome);
	});
}
