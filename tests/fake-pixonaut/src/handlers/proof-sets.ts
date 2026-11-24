// Proof sets: what the hosted designer returns, as `proofSet`, in place of
// proof tokens when its approval holds a side printed as pages. The store
// reads a set with its own key and commits each proof as it commits a side's
// token. A set expires with its proofs: once any of them is gone or past its
// expiry, the set answers `410 proof_set_expired`.
import { randomUUID } from 'node:crypto';
import { findProof, type Proof } from '../proofs';
import { findOwned, putOwned, type State } from '../state';
import { callerOf, type Handler, refusal } from './types';

export const PROOF_SETS = 'proofSets';

const NOT_FOUND_STATUS = 404;

const GONE_STATUS = 410;

/** One proof of a set: a side's, or one page's of a side of pages. */
export type ProofSetEntry = {
	/** The side's key; null on a link of one side. */
	key: string | null;
	token: string;
};

export type ProofSet = {
	ref: string;
	storeId: string;
	entries: ProofSetEntry[];
	expiresAt: number;
};

/** Keeps a set of proofs the store made, expiring with the first of them. */
export function mintProofSet(
	state: State,
	storeId: string,
	entries: { key: string | null; proof: Proof }[],
): ProofSet {
	const set: ProofSet = {
		ref: `pset_${randomUUID().replaceAll('-', '')}`,
		storeId,
		entries: entries.map(({ key, proof }) => ({ key, token: proof.token })),
		expiresAt: Math.min(...entries.map(({ proof }) => proof.expiresAt)),
	};
	putOwned(state, PROOF_SETS, set.ref, storeId, set);
	return set;
}

/** `GET /v1/proof-sets/{proofSet}`: the set's proofs, by side in the link's
 *  order, each side's pages from 1. */
export const readProofSet: Handler = (context) => {
	const { storeId } = callerOf(context);
	const set = findOwned<ProofSet>(
		context.state,
		PROOF_SETS,
		context.params.proofSet ?? '',
		storeId,
	);
	if (set === undefined) {
		return refusal(NOT_FOUND_STATUS, 'unknown_proof_set');
	}
	const now = context.clock.now();
	const proofs = set.entries.map((entry) =>
		findProof(context.state, storeId, entry.token),
	);
	if (
		set.expiresAt <= now ||
		proofs.some((proof) => proof === undefined || proof.expiresAt <= now)
	) {
		return refusal(GONE_STATUS, 'proof_set_expired');
	}
	return {
		status: 200,
		body: {
			proofs: set.entries.map((entry, index) => {
				const proof = proofs[index] as Proof;
				return {
					key: entry.key,
					page: proof.page?.page ?? null,
					proofToken: proof.token,
					proofUrl: `${context.origin}/v1/proofs/${encodeURIComponent(proof.token)}`,
					expiresAt: proof.expiresAt,
				};
			}),
			expiresAt: set.expiresAt,
		},
	};
};
