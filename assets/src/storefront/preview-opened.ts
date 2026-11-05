import type { PixonautDesigner } from '../types/sdk';

/** The field add to cart reads each side's answer from, as
 *  `pixonaut_preview_opened[<side>]`. */
export const PREVIEW_OPENED_NAME = 'pixonaut_preview_opened';

/** How the field says the preview was opened. */
const OPENED = '1';

/** How the field says the preview was not opened. */
const NOT_OPENED = '0';

/**
 * Tells the designer the customer was shown the proof, for the design's
 * record and the SDK's `previewOpened` event. The proof is on screen by now:
 * a side the designer no longer takes leaves it there, unreported.
 */
export function reportPreviewOpened(designer: PixonautDesigner): void {
	try {
		designer.reportPreviewOpened();
	} catch {
		// The design then records that its preview was not opened.
	}
}

/** The field's value for `opened`; empty says nothing. */
export function previewOpenedValue(opened: boolean | null): string {
	if (opened === null) {
		return '';
	}
	return opened ? OPENED : NOT_OPENED;
}
