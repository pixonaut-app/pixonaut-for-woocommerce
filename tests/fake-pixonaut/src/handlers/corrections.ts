// Corrections of a committed design: the correction link a store hands its
// customer's browser, the designer that link opens, the proof it makes, and
// the store's `PATCH` that redeems that proof as the design's next revision.
import { randomUUID } from 'node:crypto';
import { LINK_BEARER_PREFIX, reaches } from '../auth';
import type { Clock } from '../clock';
import { findProof, mintProof, type Proof } from '../proofs';
import type { State } from '../state';
import { type Design, designTemplateId, findDesign } from './designs-store';
import { tenantOrigins, webAddress } from './share-links';
import { templateRead } from './templates';
import { callerOf, type Handler, type HandlerContext, refusal } from './types';

/** How long a correction link works after it was handed out: 2 hours. */
export const CORRECTION_LINK_LIFETIME_MS = 2 * 60 * 60 * 1000;

const CONFLICT_STATUS = 409;

/** Where a correction link with a `returnUrl` opens the hosted page. */
export const CORRECTION_PAGE_PATH = '/h/correct';

/** The caller's design `id`, which a correction link reaches only when it
 *  was handed out for it. */
function reachedDesign(context: HandlerContext): Design | undefined {
	const caller = callerOf(context);
	const id = context.params.id ?? '';
	const design = findDesign(context.state, id, caller.storeId);
	return design !== undefined && reaches(caller, { kind: 'design', id })
		? design
		: undefined;
}

/** Whether the design takes a correction: the fake's designs have no order,
 *  so only a rejected one refuses. */
export function editable(design: Design): boolean {
	return design.status !== 'rejected';
}

/** A design's values: each layer's props by layer id. */
type LayerValues = Record<string, Record<string, unknown>>;

function isRecord(value: unknown): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** The layers of `values` that hold props; anything else is dropped. */
function layerValues(values: unknown): LayerValues {
	const layers: LayerValues = {};
	if (isRecord(values)) {
		for (const [layerId, props] of Object.entries(values)) {
			if (isRecord(props)) {
				layers[layerId] = props;
			}
		}
	}
	return layers;
}

/** The design's full values once a correction is made: the correction holds
 *  only what changed, by layer id and then prop. */
function correctedValues(current: unknown, correction: unknown): LayerValues {
	const merged = structuredClone(layerValues(current));
	for (const [layerId, props] of Object.entries(layerValues(correction))) {
		merged[layerId] = { ...merged[layerId], ...props };
	}
	return merged;
}

/** The template's exported document with the design's values written onto
 *  each layer's `kind`, where that layer has the prop. The fake has no core:
 *  it resolves no layout, so a value that would move or refit a layer only
 *  replaces the prop. */
function documentWithValues(
	doc: Record<string, unknown>,
	values: unknown,
): Record<string, unknown> {
	const filled = structuredClone(doc);
	const byLayer = layerValues(values);
	const layers = Array.isArray(filled.layers) ? filled.layers : [];
	for (const layer of layers) {
		if (!isRecord(layer) || typeof layer.id !== 'string') {
			continue;
		}
		const kind = layer.kind;
		if (!isRecord(kind)) {
			continue;
		}
		for (const [prop, value] of Object.entries(byLayer[layer.id] ?? {})) {
			if (Object.hasOwn(kind, prop)) {
				kind[prop] = value;
			}
		}
	}
	return filled;
}

function revisionChanged(design: Design) {
	return {
		status: CONFLICT_STATUS,
		body: { error: 'revision_changed', revision: design.revision },
	};
}

/** `POST /v1/designs/{id}/correction-links`. */
export const mintCorrectionLink: Handler = (context) => {
	const caller = callerOf(context);
	const design = reachedDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	if (!editable(design)) {
		return refusal(CONFLICT_STATUS, 'not_editable');
	}
	const { returnUrl, state, name, adjustLayout } = (context.body ?? {}) as {
		returnUrl?: string;
		state?: string;
		name?: string;
		adjustLayout?: boolean;
	};
	if (adjustLayout === true && design.standing === undefined) {
		return refusal(CONFLICT_STATUS, 'not_standing');
	}
	if (state !== undefined && returnUrl === undefined) {
		return refusal(400, 'invalid_input');
	}
	if (returnUrl !== undefined && !webAddress(returnUrl)) {
		return refusal(400, 'invalid_input');
	}
	if (
		returnUrl !== undefined &&
		!tenantOrigins(context.state, caller.tenantId).includes(
			new URL(returnUrl).origin,
		)
	) {
		return refusal(400, 'return_url_off_store');
	}
	const token = randomUUID().replaceAll('-', '');
	const bearer = `${LINK_BEARER_PREFIX}${token}`;
	const expiresAt = context.clock.now() + CORRECTION_LINK_LIFETIME_MS;
	context.state.shareLinks.set(bearer, {
		token: bearer,
		tenantId: caller.tenantId,
		storeId: caller.storeId,
		subject: { kind: 'design', id: design.id },
		expiresAt,
		...(returnUrl === undefined
			? {}
			: { correctionPage: { returnUrl, state: state ?? null } }),
		...(adjustLayout === true ? { adjustsLayout: true } : {}),
	});
	return {
		status: 201,
		body: {
			id: `shl_${randomUUID()}`,
			token,
			url:
				returnUrl === undefined
					? null
					: `${context.publicOrigin}${CORRECTION_PAGE_PATH}/${token}`,
			expiresAt,
			name: name ?? null,
			// The fake's workspaces have one brand, the default.
			brandId: null,
		},
	};
};

/** `GET /v1/designs/{id}/designer`: the design's document, with its
 *  committed values, its template version and the blank and placement it
 *  was made on, for the correction mount. */
export const readDesigner: Handler = (context) => {
	const design = reachedDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	if (!editable(design)) {
		return refusal(CONFLICT_STATUS, 'not_editable');
	}
	const { template, values, blankId, placementId } = design.proof;
	return {
		status: 200,
		body: {
			...templateRead(context, template),
			doc: documentWithValues(template.designDoc, values),
			revision: design.revision,
			// The pair the design was made on, which a mount naming another refuses.
			blankId,
			placementId,
		},
	};
};

/** `POST /v1/designs/{id}/correction-proofs`: a proof of the correction,
 *  which writes nothing to the design. */
export const proofCorrection: Handler = (context) => {
	const caller = callerOf(context);
	const design = reachedDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	if (!editable(design)) {
		return refusal(CONFLICT_STATUS, 'not_editable');
	}
	const { values, expectedRevision } = context.body as {
		values: unknown;
		expectedRevision: number;
	};
	if (expectedRevision !== design.revision) {
		return revisionChanged(design);
	}
	const tenant = context.state.tenants.get(caller.tenantId);
	if (tenant === undefined) {
		throw new Error(`The caller names no tenant ${caller.tenantId}.`);
	}
	const proof = mintCorrectionProof(
		context.state,
		context.clock,
		design,
		caller.storeId,
		values,
		caller.link?.adjustsLayout === true,
	);
	return {
		status: 201,
		body: {
			proofToken: proof.token,
			proofUrl: `${context.origin}/p/${proof.token}`,
			docHash: proof.docHash,
			expiresAt: proof.expiresAt,
			revision: design.revision,
			printFloorDpi: tenant.minPrintDpi,
			lowResolution: [],
		},
	};
};

/** A proof of `values` applied on top of `design` as it is now, which
 *  `PATCH /v1/designs/{id}` redeems as its next revision. Writes nothing to
 *  the design. */
export function mintCorrectionProof(
	state: State,
	clock: Clock,
	design: Design,
	storeId: string,
	values: unknown,
	adjustsLayout = false,
): Proof {
	const { proof: current } = design;
	const proof = mintProof(state, clock, {
		storeId,
		templateId: designTemplateId(design),
		blankId: current.blankId,
		placementId: current.placementId,
		printSize: current.printSize,
		// A correction is previewed where the customer last saw the design.
		variant: current.variant,
		viewId: current.viewId,
		// The design keeps every value, so the next correction starts from them.
		values: correctedValues(current.values, values),
	});
	if (typeof proof === 'string') {
		throw new Error(`The design's own proof cannot be made again: ${proof}`);
	}
	proof.correctionOf = { designId: design.id, revision: design.revision };
	if (adjustsLayout) {
		proof.adjustsLayout = true;
	}
	return proof;
}

/** `PATCH /v1/designs/{id}` with a correction's `proofToken`: makes that
 *  proof the design's next revision and clears any approval. */
export const correctDesign: Handler = (context) => {
	const caller = callerOf(context);
	const design = reachedDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	const { proofToken } = (context.body ?? {}) as { proofToken?: unknown };
	if (typeof proofToken !== 'string') {
		// Values sent straight to the design are a studio path the store never takes.
		return refusal(400, 'bad_request');
	}
	const proof = findProof(context.state, caller.storeId, proofToken);
	if (proof === undefined) {
		return refusal(404, 'unknown_proof');
	}
	if (proof.correctionOf?.designId !== design.id) {
		return refusal(CONFLICT_STATUS, 'proof_not_for_design');
	}
	if (proof.adjustsLayout === true) {
		return refusal(CONFLICT_STATUS, 'proof_adjusts_layout');
	}
	if (!editable(design)) {
		return refusal(CONFLICT_STATUS, 'not_editable');
	}
	if (proof.correctionOf.revision !== design.revision) {
		return revisionChanged(design);
	}
	if (proof.expiresAt <= context.clock.now()) {
		return refusal(410, 'proof_expired');
	}
	const tenant = context.state.tenants.get(caller.tenantId);
	if (tenant === undefined) {
		throw new Error(`The caller names no tenant ${caller.tenantId}.`);
	}
	const edited = proof.docHash !== design.proof.docHash;
	if (edited) {
		proof.designId = design.id;
		design.proof = proof;
		design.revision += 1;
		design.status = 'committed';
		design.approvedRevision = null;
		design.consent = null;
		delete design.previewOpened;
	}
	return {
		status: 200,
		body: {
			revision: design.revision,
			edited,
			proofId: design.proof.id,
			printFloorDpi: tenant.minPrintDpi,
			lowResolution: [],
		},
	};
};
