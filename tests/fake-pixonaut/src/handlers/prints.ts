// Prints: the print files of an approved design, which an order naming the
// design makes (`POST /v1/orders` with `designs`), each with its job ticket.
// The fake renders nothing: a print is `completed` the moment it is made.
import { randomUUID } from 'node:crypto';
import { findOwned, ownedBy, putOwned, type State } from '../state';
import { type Design, designTemplateId } from './designs-store';

/** The `records` collection prints are kept in. */
export const PRINTS = 'prints';

/** What the fake's renderer claims to have rendered with. */
const RENDERER_VERSION = 'fake-1';
const ICC_PROFILE = 'ISOcoated_v2_300_eci.icc';
const COLOR_SPACE = 'DeviceCMYK';
/** The resolution every fake print file is said to reach. */
const EFFECTIVE_DPI = 300;

const PRINT_STATUSES = ['created', 'completed', 'failed'] as const;

export type PrintStatus = (typeof PRINT_STATUSES)[number];

/** Digits in a print number after its year, as in `PR-2026-381042`. */
const PRINT_NUMBER_DIGITS = 6;

export type Print = {
	id: string;
	tenantId: string;
	designId: string;
	designRevision: number;
	status: PrintStatus;
	createdAt: number;
	completedAt: number | null;
	/** The print number its files are named after, such as `PR-2026-381042`. */
	number: string;
	templateName: string;
	templateVersion: number;
	widthMm: number;
	heightMm: number;
	/** Null for a template that names no blank. */
	bleedMm: number | null;
	/** The distinct printshops its orders went to, by name. */
	printshops: string[];
	/** How many orders hold it. */
	orderCount: number;
	jobTicket: Record<string, unknown>;
};

/** The print number of the year `at` falls in for the store's print after
 *  `ahead` more not yet recorded: the store's prints counted, so numbers never
 *  repeat within a fake. */
function printNumber(
	state: State,
	storeId: string,
	at: number,
	ahead: number,
): string {
	const sequence = ownedBy<Print>(state, PRINTS, storeId).length + ahead + 1;
	const year = new Date(at).getUTCFullYear();
	return `PR-${year}-${String(sequence).padStart(PRINT_NUMBER_DIGITS, '0')}`;
}

/** `storeId`'s print `id`, or undefined when there is none or another store
 *  made it. */
export function findPrint(
	state: State,
	id: string,
	storeId: string,
): Print | undefined {
	return findOwned<Print>(state, PRINTS, id, storeId);
}

/** The template's ratio, read as millimetres: the size the fake prints at. */
function printSize(design: Design): { widthMm: number; heightMm: number } {
	const { ratio } = design.proof.template.template;
	return { widthMm: ratio.w, heightMm: ratio.h };
}

/** The tenant's bleed, which the fake prints every design with. */
function tenantBleedMm(state: State, design: Design): number {
	return state.tenants.get(design.tenantId)?.printMargins.bleedMm ?? 0;
}

function jobTicket(
	state: State,
	design: Design,
	printId: string,
	renderedAt: number,
): Record<string, unknown> {
	const { template } = design.proof;
	const { widthMm, heightMm } = printSize(design);
	const bleedMm = tenantBleedMm(state, design);
	return {
		product: {
			sku: design.proof.blankId ?? designTemplateId(design),
			tenantId: design.tenantId,
		},
		artwork: { templateId: designTemplateId(design) },
		printArea: {
			widthMm,
			heightMm,
			bleedMm,
			cutShape: { type: 'path', d: `M0 0H${widthMm}V${heightMm}H0Z` },
		},
		artifact: {
			file: `prints/${printId}.pdf`,
			format: 'pdf',
			colorSpace: COLOR_SPACE,
			iccProfile: ICC_PROFILE,
			effectiveDpi: EFFECTIVE_DPI,
			includesBleed: bleedMm > 0,
			trimMarks: false,
			spotColors: [],
			spotOutput: 'process',
		},
		manifest: {
			docHash: `doc_${design.id}_${design.revision}`,
			templateVersion: String(template.version),
			rendererVersion: RENDERER_VERSION,
			fontSetHash: 'fonts_fixture',
			iccProfile: ICC_PROFILE,
			renderedAt: new Date(renderedAt).toISOString(),
		},
	};
}

/** New prints of `designs`' approved revisions for `storeId`, numbered in
 *  turn but not recorded until `recordPrint`: what an order naming the designs
 *  makes, once nothing refuses it. The fake renders each at once, so it is
 *  `completed` the moment it is made. The caller has checked every design is
 *  approved. */
export function printDesigns(
	state: State,
	designs: Design[],
	storeId: string,
	now: number,
): Print[] {
	return designs.map((design, ahead) => {
		if (design.approvedRevision === null) {
			throw new Error(`Design ${design.id} is not approved.`);
		}
		const reused = standingPrint(state, design, storeId);
		if (reused !== undefined) {
			return reused;
		}
		const id = `prt_${randomUUID()}`;
		const { template } = design.proof;
		return {
			id,
			tenantId: design.tenantId,
			designId: design.id,
			designRevision: design.approvedRevision,
			status: 'completed',
			createdAt: now,
			completedAt: now,
			number: printNumber(state, storeId, now, ahead),
			templateName: template.template.name,
			templateVersion: template.version,
			...printSize(design),
			bleedMm:
				design.proof.blankId === null ? null : tenantBleedMm(state, design),
			printshops: [],
			orderCount: 0,
			jobTicket: jobTicket(state, design, id, now),
		};
	});
}

/** A standing design's print of its approved revision, which every order of
 *  it reuses; undefined for a customer's design, or before its first order. */
function standingPrint(
	state: State,
	design: Design,
	storeId: string,
): Print | undefined {
	if (design.standing === undefined) {
		return undefined;
	}
	return ownedBy<Print>(state, PRINTS, storeId).find(
		(print) =>
			print.designId === design.id &&
			print.designRevision === design.approvedRevision &&
			print.status === 'completed',
	);
}

/** Records `print` as `storeId`'s. */
export function recordPrint(state: State, storeId: string, print: Print): void {
	putOwned(state, PRINTS, print.id, storeId, print);
}
