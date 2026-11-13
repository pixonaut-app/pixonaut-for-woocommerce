// `POST /v1/proofs`: the proof a customer approves, minted as `mintProof`
// mints every proof so a store's `POST /v1/designs` can commit its token, and
// `GET /p/{token}`, the image its `proofUrl` opens.
import { reaches } from '../auth';
import { mintProof, PROOFS, type Proof } from '../proofs';
import { allOwned, type TemplateVersion } from '../state';
import { WHITE_JPEG } from './images';
import { type Box, resolvePlacement } from './placements';
import { sessionOf } from './session';
import { callerOf, type Handler, refusal } from './types';

/** As Pixonaut sends a proof image: the browser that opened it may keep it
 *  for an hour. */
const PROOF_IMAGE_CACHE = 'private, max-age=3600';

type ProofBody = {
	templateId?: unknown;
	values?: unknown;
	blankId?: string;
	placementId?: string;
	widthMm?: number;
	heightMm?: number;
	/** Kept with the proof for display, never checked against the blank. */
	variant?: string;
	/** Kept with the proof, and with the design committed from it. */
	pickedColours?: Record<string, string>;
	viewId?: string;
};

/** Whether exactly one of two values is given: Pixonaut takes both or
 *  neither, and refuses half a pair as `bad_request`. */
function half(a: unknown, b: unknown): boolean {
	return (a === undefined) !== (b === undefined);
}

type FormField = { layerId: string; prop: string; default?: unknown };

/** The value `values` gives `field`, or its form's default when the
 *  customer left it alone. */
function fieldValue(values: unknown, field: FormField): unknown {
	const layer =
		typeof values === 'object' && values !== null
			? (values as Record<string, unknown>)[field.layerId]
			: undefined;
	const given =
		typeof layer === 'object' && layer !== null
			? (layer as Record<string, unknown>)[field.prop]
			: undefined;
	return given === undefined ? field.default : given;
}

/** The fields `template` holds to a change that `values` leave as their
 *  default, as Pixonaut refuses each: rule `unchanged`. */
function unchanged(template: TemplateVersion, values: unknown) {
	const required = new Set(template.requireChange ?? []);
	const steps =
		(template.formSchema as { steps?: { fields: FormField[] }[] }).steps ?? [];
	return steps
		.flatMap((step) => step.fields)
		.filter((field) => required.has(`${field.layerId}.${field.prop}`))
		.filter(
			(field) =>
				JSON.stringify(fieldValue(values, field)) ===
				JSON.stringify(field.default),
		)
		.map((field) => ({
			layerId: field.layerId,
			prop: field.prop,
			rule: 'unchanged',
			given: field.default ?? null,
			severity: 'error',
		}));
}

export const createProof: Handler = (context) => {
	const { state, clock } = context;
	const caller = callerOf(context);
	const request = context.body as ProofBody;
	if (
		half(request.blankId, request.placementId) ||
		half(request.widthMm, request.heightMm)
	) {
		return refusal(400, 'bad_request');
	}
	const id = typeof request.templateId === 'string' ? request.templateId : '';
	// A designer link reaches its own template only.
	if (!reaches(caller, { kind: 'template', id })) {
		return refusal(404, 'not_found');
	}
	const template = state.templates.get(id);
	const tenant = state.tenants.get(caller.tenantId);
	if (template === undefined || template.tenantId !== caller.tenantId) {
		// Pixonaut judges a template it does not have as the payload's fault.
		return {
			status: 422,
			body: {
				error: 'constraint_violation',
				violations: [
					{
						layerId: '',
						prop: 'templateId',
						rule: 'unknown_template',
						given: request.templateId ?? null,
						severity: 'error',
					},
				],
			},
		};
	}
	if (tenant === undefined) {
		throw new Error(`The caller names no tenant ${caller.tenantId}.`);
	}
	const violations = unchanged(template, request.values);
	if (violations.length > 0) {
		return {
			status: 422,
			body: { error: 'constraint_violation', violations },
		};
	}

	const { blankId, placementId } = request;
	if (
		blankId !== undefined &&
		placementId !== undefined &&
		resolvePlacement(state, tenant.id, blankId, placementId) === undefined
	) {
		return refusal(422, 'unknown_placement');
	}
	const named: Box | undefined =
		request.widthMm !== undefined && request.heightMm !== undefined
			? { widthMm: request.widthMm, heightMm: request.heightMm }
			: undefined;
	const proof = mintProof(state, clock, {
		storeId: caller.storeId,
		templateId: id,
		blankId: blankId ?? null,
		placementId: placementId ?? null,
		// Without a placement nothing fits a size: the proof keeps none.
		printSize: named ?? (blankId === undefined ? null : undefined),
		variant: request.variant ?? null,
		pickedColours: request.pickedColours ?? null,
		viewId: request.viewId ?? null,
		values: request.values,
		sessionId: sessionOf(context.headers),
		language: context.query.get('lang'),
	});
	if (typeof proof === 'string') {
		throw new Error(`The checked proof request was refused: ${proof}`);
	}
	return {
		status: 201,
		body: {
			proofToken: proof.token,
			proofUrl: `${context.origin}/p/${proof.token}`,
			docHash: proof.docHash,
			expiresAt: proof.expiresAt,
			printFloorDpi: tenant.minPrintDpi,
			// The fake judges no picture's resolution.
			lowResolution: [],
		},
	};
};

/** `GET /p/{token}`: keyless, the token is the credential. */
export const readProofImage: Handler = (context) => {
	const proof = allOwned<Proof>(context.state, PROOFS).get(
		context.params.token ?? '',
	)?.value;
	if (proof === undefined || proof.expiresAt <= context.clock.now()) {
		return { status: 404 };
	}
	return {
		status: 200,
		body: new Uint8Array(WHITE_JPEG),
		contentType: 'image/jpeg',
		headers: { 'cache-control': PROOF_IMAGE_CACHE },
	};
};
