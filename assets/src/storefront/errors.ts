import { type PixonautSdk, SdkLoadError } from './sdk-loader';

/** Why a side cannot be personalised, as the server's report route names it. */
export type UnavailableReason =
	| 'wasm_integrity'
	| 'wasm_boot'
	| 'wasm_version_mismatch'
	| 'unknown_placement'
	| 'sdk_outdated'
	| 'sdk_load';

/** Reasons that hold for the product until the page is reloaded, so the
 *  designer stops trying; a script that did not load may load on a retry. */
const LASTING_REASONS: ReadonlySet<UnavailableReason> = new Set([
	'wasm_integrity',
	'wasm_boot',
	'wasm_version_mismatch',
	'unknown_placement',
	'sdk_outdated',
]);

/** Whether a reason holds for the rest of the page's life. */
export function isLasting(reason: UnavailableReason): boolean {
	return LASTING_REASONS.has(reason);
}

/** Pixonaut's refusal of a blank and placement pair it cannot resolve. */
const UNKNOWN_PLACEMENT = 'unknown_placement';

/** Pixonaut's refusal of a template the pinned SDK's renderer cannot draw. */
const SDK_OUTDATED = 'sdk_outdated';

/**
 * The reportable reason a mount or proof failed, or null for a failure that
 * says nothing about the product or the site (a network blip, a refused
 * design). `SdkRequestError` is not exported by name, so a request error is
 * read by its `code`.
 */
export function unavailableReason(
	error: unknown,
	sdk: PixonautSdk | null,
): UnavailableReason | null {
	if (error instanceof SdkLoadError) {
		return 'sdk_load';
	}
	if (sdk !== null) {
		if (error instanceof sdk.WasmIntegrityError) {
			return 'wasm_integrity';
		}
		if (error instanceof sdk.WasmVersionMismatchError) {
			return 'wasm_version_mismatch';
		}
		if (error instanceof sdk.WasmBootError) {
			return 'wasm_boot';
		}
		// A document that reached the renderer although it cannot read it.
		if (error instanceof sdk.UnreadableDocumentError) {
			return 'sdk_outdated';
		}
	}
	const code =
		typeof error === 'object' && error !== null
			? (error as { code?: unknown }).code
			: undefined;
	if (code === UNKNOWN_PLACEMENT) {
		return 'unknown_placement';
	}
	if (code === SDK_OUTDATED) {
		return 'sdk_outdated';
	}
	return null;
}
