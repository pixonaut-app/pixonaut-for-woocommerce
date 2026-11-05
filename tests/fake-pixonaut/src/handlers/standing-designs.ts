// Standing designs: a store's own design of a template version for a fixed
// product, one per (store, version, blank, placement, print size), found or
// created by `POST /v1/designs/standing` and ordered any number of times,
// each order reusing its print. On a blank whose print areas change with an
// option, one per row of those options as well, which the call must name.
// A store's `key` keeps a design per product, and a layout adjustment's
// `proofToken` makes a standing design of its own from that proof.
import { findProof, mintProof, type Proof } from '../proofs';
import type { Blank } from '../state';
import { ownedBy, putOwned } from '../state';
import {
	applyApproval,
	DESIGNS,
	type Design,
	FIRST_REVISION,
	findDesign,
} from './designs-store';
import { type Box, fittedPrintSize, usable } from './placements';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

const OK_STATUS = 200;
const CREATED_STATUS = 201;
const BAD_REQUEST_STATUS = 400;
const NOT_FOUND_STATUS = 404;
const GONE_STATUS = 410;
const UNPROCESSABLE_STATUS = 422;

/** `<baseId>@<version>`: a base id alone names no version. The version is
 *  read as a number, so leading zeros name the same one. */
const VERSIONED_ID = /^(.+)@0*([1-9][0-9]*)$/;

type StandingRequest = {
	templateId?: string;
	blankId?: string;
	placementId?: string;
	widthMm?: number;
	heightMm?: number;
	approve?: boolean;
	variant?: string;
	variantRow?: Record<string, string>;
	key?: string;
	proofToken?: string;
};

/** Whether both of two optional fields are given, or neither. */
const paired = (a: unknown, b: unknown) =>
	(a === undefined) === (b === undefined);

/** What makes a standing design one within its store: its version, blank,
 *  placement, row and resolved print size, then the store's key and a
 *  layout adjustment's document when it names either. */
function identity(
	templateId: string,
	blankId: string | null,
	placementId: string | null,
	row: Record<string, string> | null,
	size: Box,
	key: string | null,
	layout: string | null = null,
): string {
	// A row's values in option order, so the same row always keys alike.
	const canonical =
		row === null
			? null
			: Object.keys(row)
					.sort()
					.map((option) => [option, row[option]]);
	const named = [templateId, blankId, placementId, canonical, size];
	return JSON.stringify(
		key === null && layout === null ? named : [...named, key, layout],
	);
}

/** The layout adjustment's proof `token` names, when it was made through an
 *  `adjustLayout` correction link of the store's standing design `source`
 *  identifies, or why it is refused. */
function layoutProof(
	context: HandlerContext,
	storeId: string,
	token: string,
	source: string,
): { proof: Proof } | { refused: HandlerResult } {
	const proof = findProof(context.state, storeId, token);
	if (proof === undefined) {
		return { refused: refusal(NOT_FOUND_STATUS, 'unknown_proof') };
	}
	if (proof.adjustsLayout !== true || proof.correctionOf === undefined) {
		return { refused: refusal(UNPROCESSABLE_STATUS, 'not_a_layout_proof') };
	}
	const from = findDesign(context.state, proof.correctionOf.designId, storeId);
	if (
		from === undefined ||
		from.status === 'rejected' ||
		from.personalDataErasedAt !== null
	) {
		return { refused: refusal(NOT_FOUND_STATUS, 'unknown_proof') };
	}
	if ((from.standingSource ?? from.standing) !== source) {
		return {
			refused: refusal(UNPROCESSABLE_STATUS, 'layout_proof_mismatch'),
		};
	}
	if (proof.expiresAt <= context.clock.now()) {
		return { refused: refusal(GONE_STATUS, 'proof_expired') };
	}
	return { proof };
}

/** Whether two rows name the same values, in whatever order. */
function sameRow(a: Record<string, string>, b: Record<string, string>) {
	const keys = Object.keys(a);
	return (
		keys.length === Object.keys(b).length &&
		keys.every((key) => a[key] === b[key])
	);
}

/** The row a request names on a blank whose areas vary by `varies`: its
 *  variant's values of those options, or its `variantRow`, which must hold
 *  a value of each of them and nothing else. */
function rowOf(
	request: StandingRequest,
	blank: Blank,
	varies: string[],
): { row: Record<string, string> } | { refused: HandlerResult } {
	if (request.variant !== undefined) {
		const variant = blank.variants.find((v) => v.ref === request.variant);
		if (variant === undefined) {
			return { refused: refusal(UNPROCESSABLE_STATUS, 'variant_not_of_blank') };
		}
		return {
			row: Object.fromEntries(
				varies.map((option) => [option, variant.options[option] ?? '']),
			),
		};
	}
	const row = request.variantRow;
	if (row === undefined) {
		return {
			refused: {
				status: UNPROCESSABLE_STATUS,
				body: {
					error: 'variant_required',
					reason: `The blank's print areas change with ${varies.join(', ')}.`,
					areasVaryBy: [...varies],
				},
			},
		};
	}
	const named = Object.keys(row);
	if (
		named.length !== varies.length ||
		!varies.every((option) => typeof row[option] === 'string')
	) {
		return { refused: refusal(UNPROCESSABLE_STATUS, 'variant_row_invalid') };
	}
	return { row: { ...row } };
}

/** The store's standing design of that identity, rejected ones left out. */
function standingOf(
	context: HandlerContext,
	storeId: string,
	key: string,
): Design | undefined {
	return ownedBy<Design>(context.state, DESIGNS, storeId).find(
		(design) => design.standing === key && design.status !== 'rejected',
	);
}

function standingBody(
	design: Design,
	created: boolean,
): Record<string, unknown> {
	const { proof } = design;
	return {
		designId: design.id,
		created,
		standing: true,
		status: design.status === 'approved' ? 'approved' : 'committed',
		templateId: `${proof.template.baseId}@${proof.template.version}`,
		blankId: proof.blankId,
		placementId: proof.placementId,
		widthMm: proof.printSize?.widthMm,
		heightMm: proof.printSize?.heightMm,
		key: design.standingKey ?? null,
		adjusted: design.adjusted === true,
		revision: design.revision,
	};
}

export const findOrCreateStandingDesign: Handler = (context): HandlerResult => {
	const caller = callerOf(context);
	const request = (context.body ?? {}) as StandingRequest;
	const versioned = VERSIONED_ID.exec(request.templateId ?? '');
	if (versioned === null) {
		return refusal(BAD_REQUEST_STATUS, 'template_version_required');
	}
	const templateId = `${versioned[1]}@${versioned[2]}`;
	if (
		!paired(request.blankId, request.placementId) ||
		!paired(request.widthMm, request.heightMm)
	) {
		return refusal(BAD_REQUEST_STATUS, 'bad_request');
	}
	const template = context.state.templates.get(templateId);
	if (template === undefined || template.tenantId !== caller.tenantId) {
		return refusal(UNPROCESSABLE_STATUS, 'constraint_violation');
	}
	const blankId = request.blankId ?? null;
	const placementId = request.placementId ?? null;
	// No size is the placement's default size, or the template's own ratio on
	// no blank, and finds the design that names it.
	const { ratio } = template.template;
	let size: Box = { widthMm: ratio.w, heightMm: ratio.h };
	let row: Record<string, string> | null = null;
	if (blankId !== null) {
		const blank = context.state.blanks.get(blankId);
		const placement = blank?.placements.find((p) => p.id === placementId);
		if (blank === undefined || placement === undefined || !usable(placement)) {
			return refusal(UNPROCESSABLE_STATUS, 'unknown_placement');
		}
		let box: Box = {
			widthMm: placement.defaultWidthMm,
			heightMm: placement.defaultHeightMm,
		};
		const varies = blank.areasVaryBy ?? [];
		if (varies.length > 0) {
			const named = rowOf(request, blank, varies);
			if ('refused' in named) {
				return named.refused;
			}
			const wanted = named.row;
			row = wanted;
			const area = placement.areas?.find((a) => sameRow(a.row, wanted));
			if (area !== undefined) {
				box = { widthMm: area.usualWidthMm, heightMm: area.usualHeightMm };
			}
		}
		size = fittedPrintSize(ratio, box);
	}
	if (request.widthMm !== undefined && request.heightMm !== undefined) {
		size = { widthMm: request.widthMm, heightMm: request.heightMm };
	}
	const storeKey = request.key ?? null;
	const source = identity(
		templateId,
		blankId,
		placementId,
		row,
		size,
		storeKey,
	);
	let adjustment: Proof | null = null;
	if (request.proofToken !== undefined) {
		const named = layoutProof(
			context,
			caller.storeId,
			request.proofToken,
			source,
		);
		if ('refused' in named) {
			return named.refused;
		}
		adjustment = named.proof;
	}
	const key =
		adjustment === null
			? source
			: identity(
					templateId,
					blankId,
					placementId,
					row,
					size,
					storeKey,
					adjustment.docHash,
				);

	const found = standingOf(context, caller.storeId, key);
	if (found !== undefined) {
		if (request.approve === true && found.status === 'committed') {
			applyApproval(found, null);
		}
		return { status: OK_STATUS, body: standingBody(found, false) };
	}

	const proof =
		adjustment ??
		mintProof(context.state, context.clock, {
			storeId: caller.storeId,
			templateId,
			blankId,
			placementId,
			printSize: size,
		});
	if (typeof proof === 'string') {
		return refusal(UNPROCESSABLE_STATUS, 'constraint_violation');
	}
	const design: Design = {
		id: `dsn_${proof.id.slice('prf_'.length)}`,
		tenantId: caller.tenantId,
		proof,
		status: 'committed',
		revision: FIRST_REVISION,
		approvedRevision: null,
		consent: null,
		externalRef: null,
		committedAt: context.clock.now(),
		personalDataErasedAt: null,
		rejectionReason: null,
		standing: key,
		variantRow: row,
		standingKey: storeKey,
		adjusted: adjustment !== null,
		standingSource: source,
	};
	proof.designId = design.id;
	if (request.approve === true) {
		applyApproval(design, null);
	}
	putOwned(context.state, DESIGNS, design.id, caller.storeId, design);
	return { status: CREATED_STATUS, body: standingBody(design, true) };
};
