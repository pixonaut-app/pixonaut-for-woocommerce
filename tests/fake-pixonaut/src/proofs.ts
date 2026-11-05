// Proofs a customer approved in the SDK, which a store commits as designs.
// `POST /__fake/proofs` mints one directly, as if the storefront had made it,
// with the template version, placement, print size and previewed variant and
// view a test names (or tampers with). The SDK's own `POST /v1/proofs` (handlers/proofs.ts) mints
// them through the same `mintProof`.
import { createHash, randomUUID } from 'node:crypto';
import type { Hono } from 'hono';
import type { Fake } from './app';
import type { Clock } from './clock';
import {
	type Body,
	finite,
	jsonBody,
	optionalString,
	PREFIX,
	type Read,
	refused,
} from './control';
import { FIXTURE } from './fixture-ids';
import { type Box, fittedPrintSize, usable } from './handlers/placements';
import {
	findOwned,
	type HostedLocale,
	isHostedLocale,
	putOwned,
	type State,
	type TemplateVersion,
} from './state';

export const PROOFS = 'proofs';

/** How long a proof token can be committed after it was made. */
export const PROOF_LIFETIME_MS = 60 * 60 * 1000;

export type Proof = {
	token: string;
	id: string;
	tenantId: string;
	/** The template version the proof was made from, `baseId@version`. */
	template: TemplateVersion;
	blankId: string | null;
	placementId: string | null;
	/** Null only when a test tampers with it: a proof always names one. */
	printSize: Box | null;
	/** The variant the customer previewed the proof on, by ref, as the
	 *  request named it; null when it named none. Never checked against the
	 *  blank: it is for display only. */
	variant: string | null;
	/** The view the customer previewed the proof in, by id; null when the
	 *  request named none. */
	viewId: string | null;
	/** The layer values the customer filled in. */
	values: unknown;
	/** The language its template was read in: the request's `lang`, else
	 *  the workspace's default. */
	language: HostedLocale;
	/** Fingerprint of the template, values, size and placement. */
	docHash: string;
	expiresAt: number;
	/** The designer session that made it, when the request named one. */
	sessionId: string | null;
	/** The design it was committed as, which holds it past a session wipe. */
	designId?: string;
	/** For a correction's proof: the design it corrects and the revision it
	 *  was made from, which `PATCH /v1/designs/{id}` redeems it against. */
	correctionOf?: { designId: string; revision: number };
	/** A layout adjustment's proof, made through a correction link minted
	 *  with `adjustLayout`: `POST /v1/designs/standing` makes a standing
	 *  design of its own from it, and `PATCH` refuses it. */
	adjustsLayout?: boolean;
};

export type ProofRequest = {
	storeId: string;
	templateId: string;
	blankId: string | null;
	placementId: string | null;
	/** Absent: the size the template fits into the placement's default box. */
	printSize?: Box | null;
	/** Absent: none previewed. */
	variant?: string | null;
	viewId?: string | null;
	lifetimeMs?: number;
	/** Absent: none, as a proof minted by a test fills no layer. */
	values?: unknown;
	sessionId?: string | null;
	/** The request's `lang`; absent or unknown: the workspace's default. */
	language?: string | null;
};

/** The proof `token` the store made, or undefined. */
export function findProof(
	state: State,
	storeId: string,
	token: string,
): Proof | undefined {
	return findOwned<Proof>(state, PROOFS, token, storeId);
}

/** Mints a proof, or says why the request names nothing printable. */
export function mintProof(
	state: State,
	clock: Clock,
	request: ProofRequest,
): Proof | string {
	const store = state.stores.get(request.storeId);
	const template = state.templates.get(request.templateId);
	if (store === undefined || template?.tenantId !== store.tenantId) {
		return `no template ${request.templateId} for store ${request.storeId}`;
	}
	let printSize = request.printSize;
	if (request.blankId !== null || request.placementId !== null) {
		const placement = state.blanks
			.get(request.blankId ?? '')
			?.placements.find((p) => p.id === request.placementId);
		if (placement === undefined || !usable(placement)) {
			return `no usable placement ${request.blankId}/${request.placementId}`;
		}
		if (printSize === undefined) {
			// Null is a tampered proof and stays null.
			printSize = fittedPrintSize(template.template.ratio, {
				widthMm: placement.defaultWidthMm,
				heightMm: placement.defaultHeightMm,
			});
		}
	}
	if (printSize === undefined) {
		return 'a proof without a placement needs a `printSize`';
	}
	const token = `prf.${randomUUID()}`;
	const values = request.values ?? {};
	const { blankId, placementId } = request;
	const proof: Proof = {
		token,
		id: `prf_${randomUUID().replaceAll('-', '')}`,
		tenantId: store.tenantId,
		template,
		blankId,
		placementId,
		printSize,
		variant: request.variant ?? null,
		viewId: request.viewId ?? null,
		values,
		language: isHostedLocale(request.language)
			? request.language
			: (state.tenants.get(store.tenantId)?.workspace.defaultLanguage ?? 'en'),
		docHash: createHash('sha256')
			.update(
				JSON.stringify([
					request.templateId,
					values,
					printSize,
					blankId,
					placementId,
				]),
			)
			.digest('hex'),
		expiresAt: clock.now() + (request.lifetimeMs ?? PROOF_LIFETIME_MS),
		sessionId: request.sessionId ?? null,
	};
	putOwned(state, PROOFS, token, store.id, proof);
	return proof;
}

function nullableString(body: Body, name: string): Read<string | null> {
	const value = body[name];
	if (value === null || typeof value === 'string') {
		return { ok: true, value };
	}
	return refused(`\`${name}\` is neither a string nor null`);
}

function readPrintSize(value: unknown): Read<Box | null | undefined> {
	if (value === undefined || value === null) {
		return { ok: true, value };
	}
	const size = value as Body;
	if (
		typeof value !== 'object' ||
		!finite(size.widthMm) ||
		!finite(size.heightMm)
	) {
		return refused('`printSize` is not `{widthMm, heightMm}` or null');
	}
	return {
		ok: true,
		value: { widthMm: size.widthMm, heightMm: size.heightMm },
	};
}

function readProofRequest(body: Body): Read<ProofRequest> {
	const store = optionalString(body, 'store');
	const templateId = optionalString(body, 'templateId');
	if (!store.ok) {
		return store;
	}
	if (!templateId.ok) {
		return templateId;
	}
	const blankId =
		body.blankId === undefined
			? { ok: true as const, value: FIXTURE.blank.tee }
			: nullableString(body, 'blankId');
	if (!blankId.ok) {
		return blankId;
	}
	const placementId =
		body.placementId === undefined
			? { ok: true as const, value: FIXTURE.placement.front }
			: nullableString(body, 'placementId');
	if (!placementId.ok) {
		return placementId;
	}
	const printSize = readPrintSize(body.printSize);
	if (!printSize.ok) {
		return printSize;
	}
	const variant = optionalString(body, 'variant');
	if (!variant.ok) {
		return variant;
	}
	const viewId = optionalString(body, 'viewId');
	if (!viewId.ok) {
		return viewId;
	}
	if (
		body.lifetimeMs !== undefined &&
		!(finite(body.lifetimeMs) && body.lifetimeMs > 0)
	) {
		return refused('`lifetimeMs` is not a positive duration');
	}
	return {
		ok: true,
		value: {
			storeId: store.value ?? FIXTURE.store.main.id,
			templateId: templateId.value ?? FIXTURE.template.live,
			blankId: blankId.value,
			placementId: placementId.value,
			printSize: printSize.value,
			variant: variant.value ?? null,
			viewId: viewId.value ?? null,
			lifetimeMs: body.lifetimeMs as number | undefined,
		},
	};
}

export function registerProofControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/proofs`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const read = body.ok ? readProofRequest(body.value) : body;
		if (!read.ok) {
			return read.response;
		}
		const proof = mintProof(fake.state, fake.clock, read.value);
		if (typeof proof === 'string') {
			return refused(proof).response;
		}
		return c.json({
			proofToken: proof.token,
			proofId: proof.id,
			expiresAt: proof.expiresAt,
			printSize: proof.printSize,
		});
	});
}
