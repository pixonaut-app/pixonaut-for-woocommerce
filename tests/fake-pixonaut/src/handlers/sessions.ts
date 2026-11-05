// `DELETE /v1/sessions/{id}`: a "clear my design" button wipes the uploads
// and proofs one designer session made, keeping what a design holds.
import { PROOFS, type Proof } from '../proofs';
import { allOwned, type Owned } from '../state';
import { ASSETS, type AssetRecord } from './assets';
import { callerOf, type Handler } from './types';

type SessionMade = { sessionId: string | null };

/** Deletes the records `storeId`'s `sessionId` made that are not `held`, and
 *  counts them. */
function wipe<T extends SessionMade>(
	records: Map<string, Owned<T>>,
	storeId: string,
	sessionId: string,
	held: (value: T) => boolean,
): number {
	let deleted = 0;
	for (const [id, record] of records) {
		if (
			record.storeId === storeId &&
			record.value.sessionId === sessionId &&
			!held(record.value)
		) {
			records.delete(id);
			deleted += 1;
		}
	}
	return deleted;
}

export const deleteSession: Handler = (context) => {
	const { state } = context;
	const { storeId } = callerOf(context);
	const sessionId = context.params.id ?? '';
	return {
		status: 200,
		body: {
			assetsDeleted: wipe(
				allOwned<AssetRecord>(state, ASSETS),
				storeId,
				sessionId,
				(asset) => asset.retained,
			),
			proofsDeleted: wipe(
				allOwned<Proof>(state, PROOFS),
				storeId,
				sessionId,
				// A committed design holds its proof.
				(proof) => proof.designId !== undefined,
			),
		},
	};
};
