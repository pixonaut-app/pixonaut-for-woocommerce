// The fake's design record. A store's own `POST /v1/designs` makes one from a
// proof; `POST /__fake/designs` seeds one for a store, on a proof made up for
// it, so a test can start from any status or revision.
import { randomUUID } from 'node:crypto';
import type { Proof } from '../proofs';
import { findOwned, putOwned, type State, templateId } from '../state';

/** The `records` collection designs are kept in. */
export const DESIGNS = 'designs';

/** The `records` collection naming the designs a store deleted, which an
 *  erasure still answers `204`. */
const DELETED_DESIGNS = 'deleted-designs';

export const DESIGN_STATUSES = ['committed', 'approved', 'rejected'] as const;
export type DesignStatus = (typeof DESIGN_STATUSES)[number];

/** How a customer agreed to a design, as `POST .../approve` records it. */
export type DesignConsent = { kind: string; at: number; ref?: string | null };

export type Design = {
	id: string;
	tenantId: string;
	/** The proof it was committed from: template version, blank, placement, print size. */
	proof: Proof;
	status: DesignStatus;
	/** Goes up by one on every edit; a design is committed at revision 1. */
	revision: number;
	/** The revision it was approved at; null until it is approved. */
	approvedRevision: number | null;
	consent: DesignConsent | null;
	externalRef: string | null;
	/** Whether its preview was opened before the approval box was ticked;
	 *  absent where no box asked, and once corrected. */
	previewOpened?: boolean;
	/** The fulfilment keys its commit carried; absent when it carried none. */
	fulfilmentPayload?: Record<string, unknown>;
	committedAt: number;
	/** When its personal data was erased, epoch ms; null until it is. */
	personalDataErasedAt: number | null;
	/** Why it was rejected, as the rejection said; null when it is not
	 *  rejected, or was rejected without a reason. */
	rejectionReason: string | null;
	/** The design it was duplicated from; absent on one committed from a proof. */
	duplicatedFrom?: string;
	/** A standing design's identity, its version, blank, placement, row and
	 *  size, as `POST /v1/designs/standing` keeps one per; absent on a
	 *  customer's. */
	standing?: string;
	/** The row of the blank's `areasVaryBy` options a standing design is
	 *  sized for; null or absent on a blank whose areas do not vary. */
	variantRow?: Record<string, string> | null;
	/** The store's own key a standing design was asked for by; null or
	 *  absent for none. */
	standingKey?: string | null;
	/** Whether a standing design was made from a layout adjustment's proof. */
	adjusted?: boolean;
	/** A standing design's identity without its layout: what a layout proof
	 *  of it must be redeemed for. */
	standingSource?: string;
};

/** The revision a design is committed at. */
export const FIRST_REVISION = 1;

/** The template version `design` was made from, `baseId@version`. */
export function designTemplateId(design: Design): string {
	const { baseId, version } = design.proof.template;
	return templateId(baseId, version);
}

export type DesignSeed = {
	id?: string;
	revision?: number;
	status?: DesignStatus;
	blankId?: string | null;
	placementId?: string | null;
};

/** Seeds a design for `storeId` on `templateId`, which must be its tenant's:
 *  committed at revision 1 unless `seed` says otherwise; one seeded
 *  `approved` is approved at its revision. */
export function seedDesign(
	state: State,
	storeId: string,
	tenantId: string,
	templateId: string,
	now: number,
	seed: DesignSeed = {},
): Design {
	const template = state.templates.get(templateId);
	if (template === undefined) {
		throw new Error(`No template ${templateId}.`);
	}
	const revision = seed.revision ?? FIRST_REVISION;
	const status = seed.status ?? 'committed';
	const id = seed.id ?? `dsn_${randomUUID()}`;
	const proof: Proof = {
		token: `prf.${randomUUID()}`,
		id: `prf_${randomUUID()}`,
		tenantId,
		template,
		blankId: seed.blankId ?? null,
		placementId: seed.placementId ?? null,
		printSize: null,
		variant: null,
		viewId: null,
		expiresAt: now,
		values: {},
		language: state.tenants.get(tenantId)?.workspace.defaultLanguage ?? 'en',
		docHash: '',
		sessionId: null,
		designId: id,
	};
	const design: Design = {
		id,
		tenantId,
		proof,
		status,
		revision,
		approvedRevision: status === 'approved' ? revision : null,
		consent: null,
		externalRef: null,
		committedAt: now,
		personalDataErasedAt: null,
		rejectionReason: null,
	};
	putOwned(state, DESIGNS, design.id, storeId, design);
	return design;
}

/** Whether a correction may still change `design`: not once it is rejected
 *  or its personal data is erased. */
export function designEditable(design: Design): boolean {
	return design.status !== 'rejected' && design.personalDataErasedAt === null;
}

/** What `POST /__fake/designs` answers about a seeded design. */
export function seededView(design: Design): Record<string, unknown> {
	return {
		id: design.id,
		tenantId: design.tenantId,
		revision: design.revision,
		status: design.status,
		approvedRevision: design.approvedRevision,
		consent: design.consent,
		templateId: designTemplateId(design),
		blankId: design.proof.blankId,
		placementId: design.proof.placementId,
		externalRef: design.externalRef,
		previewOpened: design.previewOpened ?? null,
	};
}

export type Decision = 'approve' | 'reject';

/** Whether a decision about `design` may be made: `open` to make it,
 *  `unchanged` when it was already made at the revision asked about (or at
 *  any, when none is) and is answered success again with nothing more
 *  recorded, else why it is refused. A design takes one decision: an
 *  approved one is never rejected, a rejected one never approved. */
export type Judgement =
	| { kind: 'open' }
	| { kind: 'unchanged' }
	| { kind: 'not_committed' }
	| { kind: 'revision_changed'; revision: number };

export function judgeDecision(
	design: Design,
	decision: Decision,
	expectedRevision: number | undefined,
): Judgement {
	if (design.status !== 'committed') {
		const decided = decision === 'approve' ? 'approved' : 'rejected';
		// A rejected design takes no correction: it stays at the revision it
		// was rejected at.
		const decidedAt =
			design.status === 'approved' ? design.approvedRevision : design.revision;
		const same =
			design.status === decided &&
			(expectedRevision === undefined || expectedRevision === decidedAt);
		return { kind: same ? 'unchanged' : 'not_committed' };
	}
	if (expectedRevision !== undefined && expectedRevision !== design.revision) {
		return { kind: 'revision_changed', revision: design.revision };
	}
	return { kind: 'open' };
}

/** Approves `design` at its current revision, as `consent` records it. */
export function applyApproval(
	design: Design,
	consent: DesignConsent | null,
): void {
	design.status = 'approved';
	design.approvedRevision = design.revision;
	design.consent = consent;
}

/** Rejects `design`, keeping `reason`: it keeps no approval and no
 *  consent. */
export function applyRejection(
	design: Design,
	reason: string | null = null,
): void {
	design.status = 'rejected';
	design.approvedRevision = null;
	design.consent = null;
	design.rejectionReason = reason;
}

/** `storeId`'s design `id`, or undefined when there is none or another store
 *  made it. */
export function findDesign(
	state: State,
	id: string,
	storeId: string,
): Design | undefined {
	return findOwned<Design>(state, DESIGNS, id, storeId);
}

/** Deletes `storeId`'s design `id`, remembering that it did. */
export function deleteOwnedDesign(
	state: State,
	id: string,
	storeId: string,
): void {
	state.records.get(DESIGNS)?.delete(id);
	putOwned(state, DELETED_DESIGNS, id, storeId, true);
}

/** Whether `storeId` deleted its design `id`. */
export function wasDeleted(state: State, id: string, storeId: string): boolean {
	return findOwned<boolean>(state, DELETED_DESIGNS, id, storeId) === true;
}
