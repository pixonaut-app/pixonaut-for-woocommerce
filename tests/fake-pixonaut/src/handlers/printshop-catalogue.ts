// An integrated printshop's catalogue, which a store browses and imports
// from with its integration key: a page of the products, each marked with the
// blank it was imported as, and an import that makes a blank of one, or
// answers the one made before. An import is whole at once, or stays `importing` while the control API holds
// imports (`POST /__fake/catalogue/imports`) until it finishes it
// (`POST /__fake/blanks/finish-import`), as Pixonaut's background job does.
import {
	type Blank,
	type CatalogueProduct,
	CUSTOM_ADAPTER,
	type State,
} from '../state';
import { blankBody, holds } from './catalogue';
import {
	callerOf,
	type Handler,
	type HandlerContext,
	type HandlerResult,
	refusal,
} from './types';

/** A page's size when the request names none, and the most it may name. */
const DEFAULT_LIMIT = 20;

const NOT_FOUND = refusal(404, 'not_found');

/** The tenant's integrated printshop the request names, or why not. */
function catalogueConnection(
	context: HandlerContext,
): { ok: true; id: string } | { ok: false; refused: HandlerResult } {
	const id = context.query.get('connectionId') ?? '';
	const printshop = context.state.printshops.get(id);
	if (printshop?.tenantId !== callerOf(context).tenantId) {
		return { ok: false, refused: NOT_FOUND };
	}
	if (printshop.adapter === CUSTOM_ADAPTER) {
		return { ok: false, refused: refusal(409, 'not_a_catalogue') };
	}
	return { ok: true, id };
}

/** The blank `product` was last imported as through its connection, while
 *  it is not deleted. */
export function importedBlank(
	state: State,
	tenantId: string,
	product: CatalogueProduct,
): Blank | undefined {
	return [...state.blanks.values()]
		.filter(
			(blank) =>
				blank.tenantId === tenantId &&
				blank.deletedAt === null &&
				blank.importedFrom?.connectionId === product.connectionId &&
				blank.importedFrom.productId === product.productId,
		)
		.sort((a, b) => b.createdAt - a.createdAt)[0];
}

/** `GET /v1/printshop-catalogue-products`. */
export const listCatalogueProducts: Handler = (context) => {
	const connection = catalogueConnection(context);
	if (!connection.ok) {
		return connection.refused;
	}
	const { tenantId } = callerOf(context);
	const search = context.query.get('search');
	const limit = Number(context.query.get('limit') ?? DEFAULT_LIMIT);
	const offset = Number(context.query.get('offset') ?? 0);
	const all = [...context.state.catalogue.values()].filter(
		(product) => product.connectionId === connection.id,
	);
	const matching = all.filter((product) =>
		holds(product.name, search === '' ? null : search),
	);
	return {
		status: 200,
		body: {
			total: matching.length,
			catalogueTotal: all.length,
			searchComplete: true,
			searchedProducts: null,
			products: matching.slice(offset, offset + limit).map((product) => {
				const imported = importedBlank(context.state, tenantId, product);
				return {
					productId: product.productId,
					name: product.name,
					brand: product.brand,
					imageUrl: null,
					categoryId: null,
					holds: { colours: null, sizes: null, variants: product.variantCount },
					hasImportedBlank: imported !== undefined,
					importedBlankId: imported?.id ?? null,
				};
			}),
		},
	};
};

/** `POST /v1/printshop-catalogue-products/{productId}/blank`. */
export const importCatalogueBlank: Handler = (context) => {
	const connection = catalogueConnection(context);
	if (!connection.ok) {
		return connection.refused;
	}
	const product = context.state.catalogue.get(context.params.productId ?? '');
	if (product?.connectionId !== connection.id) {
		return NOT_FOUND;
	}
	const { tenantId } = callerOf(context);
	// An integration key is answered the blank imported before, not a second one.
	const imported = importedBlank(context.state, tenantId, product);
	if (imported !== undefined && imported.status !== 'failed') {
		return { status: 200, body: importAnswer(imported) };
	}
	const body = (context.body ?? {}) as { name?: unknown };
	const name = typeof body.name === 'string' ? body.name : product.name;
	const taken = [...context.state.blanks.values()].some(
		(blank) =>
			blank.tenantId === tenantId &&
			blank.deletedAt === null &&
			blank.name === name,
	);
	if (taken) {
		return refusal(409, 'duplicate_name');
	}
	const id = `blank_${product.productId}_${context.state.blanks.size + 1}`;
	const made = product.makes(id);
	if (made.placements.length === 0) {
		return refusal(422, 'no_printable_placement');
	}
	const now = context.clock.now();
	const held = context.state.catalogueImportsHeld;
	const blank: Blank = {
		id,
		tenantId,
		name,
		defaultPrintshopId: connection.id,
		partnerLocked: true,
		machineProfileId: null,
		// While importing, it has its name and nothing else yet.
		...(held
			? { categoryId: null, placements: [], axes: [], variants: [] }
			: made),
		createdAt: now,
		updatedAt: now,
		deletedAt: null,
		status: held ? 'importing' : 'ready',
		importedFrom: { connectionId: connection.id, productId: product.productId },
	};
	context.state.blanks.set(id, blank);
	return {
		status: 202,
		// The fake's products name no kind a picture is drawn from: nothing to ask.
		body: { ...importAnswer(blank), productModelMissing: [] },
	};
};

/** The import's answer: the blank and the job filling it in. */
function importAnswer(blank: Blank): Record<string, unknown> {
	const finished = blank.status === 'importing' ? null : blank.updatedAt;
	return {
		blank: blankBody(blank),
		job: {
			id: `job_import_${blank.id}`,
			kind: 'blank.import',
			subjectId: blank.id,
			state: finished === null ? 'queued' : 'done',
			attempts: finished === null ? 0 : 1,
			runAt: blank.createdAt,
			parkReason: null,
			lastError: null,
			lastRunAt: finished,
			createdAt: blank.createdAt,
			finishedAt: finished,
		},
	};
}
