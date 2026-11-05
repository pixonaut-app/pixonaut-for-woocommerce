// Designs committed from proof tokens: commit, read, approve, reject, move
// the store's reference, delete. A design's template, blank, placement and
// print size come from its proof, so a test checks a store against what the
// browser sent rather than what the store asked for.
import { randomUUID } from 'node:crypto';
import { isRecord } from '../field-keys';
import { findProof } from '../proofs';
import { putOwned } from '../state';
import { tenantBlank } from './catalogue';
import {
	applyApproval,
	applyRejection,
	DESIGNS,
	type Design,
	type DesignConsent,
	deleteOwnedDesign,
	designEditable,
	designTemplateId,
	FIRST_REVISION,
	findDesign,
	type Judgement,
	judgeDecision,
} from './designs-store';
import { WHITE_JPEG } from './images';
import { WHITE_PNG } from './template-images';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

const CONFLICT_STATUS = 409;

/** As Pixonaut answers a proof `v` names: its bytes never change. */
const IMMUTABLE = 'public, max-age=31536000, immutable';

/** What Pixonaut takes as `width`: a whole number of pixels. */
const WIDTH = /^\d+$/;

type ApproveRequest = {
	consent?: DesignConsent;
	expectedRevision?: number;
	afterDays?: number;
} | null;

type RejectRequest = { expectedRevision?: number; reason?: string } | null;

function body(context: HandlerContext): Record<string, unknown> {
	return (context.body ?? {}) as Record<string, unknown>;
}

/** The caller's design `id`, or undefined. */
function ownDesign(context: HandlerContext): Design | undefined {
	return findDesign(
		context.state,
		context.params.id ?? '',
		callerOf(context).storeId,
	);
}

function designBody(
	context: HandlerContext,
	design: Design,
): Record<string, unknown> {
	const { proof } = design;
	const { storeId } = callerOf(context);
	const { template } = proof;
	return {
		id: design.id,
		tenantId: design.tenantId,
		templateId: designTemplateId(design),
		templateBaseId: template.baseId,
		templateVersion: template.version,
		templateName: template.template.name ?? null,
		templateDeleted: false,
		status: design.status,
		apiKeyId: null,
		committedAt: design.committedAt,
		approveAfter: null,
		approvedAt: null,
		approvedVia: null,
		fulfilmentPayload: design.fulfilmentPayload ?? null,
		proofId: proof.id,
		proofRevision: design.revision,
		proofVariant: proof.variant,
		proofViewId: proof.viewId,
		// The fake draws every design as a template draws it for light garments.
		garmentTone: 'light',
		revision: design.revision,
		duplicatedFrom: design.duplicatedFrom ?? null,
		blankId: proof.blankId,
		placementId: proof.placementId,
		printSize: proof.printSize,
		// Only a standing design names its row; the fake sizes no other by one.
		variantRow: design.variantRow ?? null,
		rejectionReason: design.rejectionReason,
		externalRef: design.externalRef,
		// Null where no approval box asked, and on a correction.
		previewOpened: design.previewOpened ?? null,
		deletable: design.status === 'committed',
		approvalConsent:
			design.consent === null
				? null
				: { ...design.consent, ref: design.consent.ref ?? null },
		personalDataErasedAt: design.personalDataErasedAt,
		// The fake makes no design through a share link.
		shareLinkId: null,
		// Every design the fake holds was committed by its store.
		origin: {
			kind: 'store',
			id: context.state.stores.get(storeId)?.siteUrl ?? null,
		},
		doc: template.template,
		language: proof.language,
		canDecide: true,
		hasOrder: false,
		hasPrint: false,
		editable: designEditable(design),
		canEdit: designEditable(design),
		changeRequest: null,
		correctionForm: null,
		// With no correction form there is nothing to read the choices from.
		choices: null,
		// The fake draws no revision.
		pictureUrl: null,
		standing: design.standing !== undefined,
	};
}

export const commitDesign: Handler = (context) => {
	const caller = callerOf(context);
	const { proofToken, externalRef, fulfilmentPayload, previewOpened } =
		body(context);
	if (typeof proofToken !== 'string') {
		// The template-and-values commit is a studio path the store never takes.
		return refusal(400, 'bad_request');
	}
	const proof = findProof(context.state, caller.storeId, proofToken);
	if (proof === undefined) {
		return refusal(404, 'unknown_proof');
	}
	if (proof.designId !== undefined) {
		return {
			status: 409,
			body: { error: 'proof_already_committed', designId: proof.designId },
		};
	}
	if (proof.expiresAt <= context.clock.now()) {
		return refusal(410, 'proof_expired');
	}
	const tenant = context.state.tenants.get(caller.tenantId);
	if (tenant === undefined) {
		throw new Error(`Store ${caller.storeId} has no tenant.`);
	}
	const design: Design = {
		id: randomUUID(),
		tenantId: caller.tenantId,
		proof,
		status: 'committed',
		revision: FIRST_REVISION,
		approvedRevision: null,
		consent: null,
		externalRef: typeof externalRef === 'string' ? externalRef : null,
		...(isRecord(fulfilmentPayload) ? { fulfilmentPayload } : {}),
		...(typeof previewOpened === 'boolean' ? { previewOpened } : {}),
		committedAt: context.clock.now(),
		personalDataErasedAt: null,
		rejectionReason: null,
	};
	proof.designId = design.id;
	putOwned(context.state, DESIGNS, design.id, caller.storeId, design);
	const created: HandlerResult = {
		status: 201,
		body: {
			designId: design.id,
			printFloorDpi: tenant.minPrintDpi,
			lowResolution: [],
		},
	};
	return { ...created, replay: () => ({ status: 201, body: created.body }) };
};

/** `POST /v1/designs/{id}/duplicate`: a committed copy of the design, with
 *  no store reference, at revision 1; the source is left as it was. */
export const duplicateDesign: Handler = (context) => {
	const source = ownDesign(context);
	if (source === undefined) {
		return refusal(404, 'not_found');
	}
	if (source.personalDataErasedAt !== null) {
		return refusal(CONFLICT_STATUS, 'design_erased');
	}
	const caller = callerOf(context);
	const copy: Design = {
		id: randomUUID(),
		tenantId: source.tenantId,
		proof: source.proof,
		status: 'committed',
		revision: FIRST_REVISION,
		approvedRevision: null,
		consent: null,
		externalRef: null,
		...(source.fulfilmentPayload === undefined
			? {}
			: { fulfilmentPayload: source.fulfilmentPayload }),
		committedAt: context.clock.now(),
		personalDataErasedAt: null,
		rejectionReason: null,
		duplicatedFrom: source.id,
	};
	putOwned(context.state, DESIGNS, copy.id, caller.storeId, copy);
	return { status: 201, body: { designId: copy.id } };
};

export const readDesign: Handler = (context) => {
	const design = ownDesign(context);
	return design === undefined
		? refusal(404, 'not_found')
		: { status: 200, body: designBody(context, design) };
};

/** `GET /v1/designs/{id}/proof`: the proof's image, whatever `width` asks
 *  for, since the fake renders nothing. An erased design's proof file is
 *  deleted with its personal data (within hours in Pixonaut; at once here). */
export const readDesignProof: Handler = (context) => {
	const design = ownDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	if (design.personalDataErasedAt !== null) {
		return refusal(404, 'proof_not_found');
	}
	const width = context.query.get('width');
	if (width !== null && !WIDTH.test(width)) {
		return refusal(422, 'invalid_query');
	}
	const v = context.query.get('v');
	if (v !== null && v !== design.proof.id) {
		return refusal(404, 'proof_changed');
	}
	return {
		status: 200,
		body: new Uint8Array(WHITE_JPEG),
		contentType: 'image/jpeg',
		// Without `v`, the proof's id is the answer's etag.
		headers:
			v === null
				? { etag: `"${design.proof.id}"` }
				: { 'cache-control': IMMUTABLE },
	};
};

/** `GET /v1/designs/{id}/mockup.png`: the design on its blank's photo, in
 *  its own placement unless `placementId` names another, and in the view
 *  `viewId` names, else the one its proof was previewed in, else the
 *  placement's first. The fake checks the ids as Pixonaut does and draws
 *  one white picture for all. */
export const readDesignMockup: Handler = (context) => {
	const { query } = context;
	const design = ownDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	const size = query.get('size');
	if (size !== null && !WIDTH.test(size)) {
		return refusal(422, 'invalid_query');
	}
	if (design.personalDataErasedAt !== null) {
		return refusal(404, 'proof_not_found');
	}
	const v = query.get('v');
	if (v !== null && v !== design.proof.id) {
		return refusal(404, 'proof_changed');
	}
	const blank = tenantBlank(context, design.proof.blankId ?? '');
	const placement = blank?.placements.find(
		(p) => p.id === (query.get('placementId') ?? design.proof.placementId),
	);
	const viewId = query.get('viewId') ?? design.proof.viewId;
	const view =
		viewId === null
			? placement?.views[0]
			: placement?.views.find((candidate) => candidate.id === viewId);
	if (view === undefined || view.photos.length === 0) {
		return refusal(404, 'not_found');
	}
	return {
		status: 200,
		body: new Uint8Array(WHITE_PNG),
		contentType: 'image/png',
		headers:
			v === null
				? { etag: `"${design.proof.id}"` }
				: { 'cache-control': IMMUTABLE },
	};
};

export const deleteDesign: Handler = (context) => {
	const design = ownDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	if (design.status === 'approved') {
		return refusal(409, 'design_approved');
	}
	deleteOwnedDesign(context.state, design.id, callerOf(context).storeId);
	return { status: 204 };
};

export const setDesignExternalRef: Handler = (context) => {
	const design = ownDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	const { externalRef } = body(context);
	design.externalRef = typeof externalRef === 'string' ? externalRef : null;
	return {
		status: 200,
		body: { designId: design.id, externalRef: design.externalRef },
	};
};

/** A judgement that refuses the decision, as the decision routes answer it. */
function refusedDecision(judgement: Judgement): HandlerResult | null {
	switch (judgement.kind) {
		case 'not_committed':
			return refusal(CONFLICT_STATUS, 'not_committed');
		case 'revision_changed':
			return {
				status: CONFLICT_STATUS,
				body: { error: 'revision_changed', revision: judgement.revision },
			};
		default:
			return null;
	}
}

export const approveDesign: Handler = (context) => {
	const design = ownDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	const request = (context.body ?? null) as ApproveRequest;
	const judgement = judgeDecision(design, 'approve', request?.expectedRevision);
	const refused = refusedDecision(judgement);
	if (refused !== null) {
		return refused;
	}
	if (judgement.kind === 'unchanged') {
		return { status: 200, body: { status: 'approved' } };
	}
	if (request?.afterDays !== undefined) {
		// The customer's window to change their mind: approved once it closes,
		// which the fake never reaches on its own.
		return { status: 200, body: { status: 'scheduled' } };
	}
	applyApproval(design, request?.consent ?? null);
	return { status: 200, body: { status: 'approved' } };
};

/** Rejecting takes no consent: the design keeps no approval and is never
 *  printed. A repeat at the same revision, or with none named, answers
 *  success again. */
export const rejectDesign: Handler = (context) => {
	const design = ownDesign(context);
	if (design === undefined) {
		return refusal(404, 'not_found');
	}
	const request = (context.body ?? null) as RejectRequest;
	const reason = request?.reason?.trim();
	if (reason === '') {
		return refusal(400, 'bad_request');
	}
	const judgement = judgeDecision(design, 'reject', request?.expectedRevision);
	const refused = refusedDecision(judgement);
	if (refused !== null) {
		return refused;
	}
	if (judgement.kind === 'open') {
		applyRejection(design, reason ?? null);
	}
	return { status: 200, body: { ok: true } };
};
