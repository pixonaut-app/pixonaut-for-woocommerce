import type { PixonautDesigner } from '../types/sdk';
import type { PixonautSdk } from './sdk-loader';
import type { Variations } from './variations';

/**
 * The variant ref the designer shows, the one the order is placed with: on a
 * variable product the chosen variation's (none before one is chosen, or
 * when it has none); else the product's own, from the form container's
 * `data-pixonaut-variant` (none on a product without one).
 */
export function variantRef(
	container: HTMLElement,
	variations: Variations | null,
	id: string,
): string | undefined {
	if (variations === null) {
		const own = container.dataset.pixonautVariant;
		return own === '' ? undefined : own;
	}
	const variation = variations[id];
	return variation?.state === 'personalised' ? variation.variant : undefined;
}

/**
 * Shows variant `ref` in each designer that does not show it yet. False
 * once Pixonaut cannot show it (the SDK's `VariantUnavailableError`: an
 * unknown or retired variant, or one without a photo); any other failure
 * rejects.
 */
export async function showVariant(
	designers: Iterable<PixonautDesigner>,
	ref: string,
	sdk: PixonautSdk,
): Promise<boolean> {
	// The sides as they are now: one remounted meanwhile is not visited twice.
	for (const designer of [...designers]) {
		if (designer.getVariant() === ref) {
			continue;
		}
		try {
			await designer.setVariant(ref);
		} catch (error) {
			if (!(error instanceof sdk.VariantUnavailableError)) {
				throw error;
			}
			console.error(
				`Pixonaut: variant ${ref} cannot be shown (${error.reason}).`,
				error,
			);
			return false;
		}
	}
	return true;
}

/**
 * The variant a designer mounted without an `initialVariant` starts on: the
 * first it offers with a photo.
 */
export function startingVariant(
	designer: PixonautDesigner,
): string | undefined {
	return designer.getVariants().find((variant) => variant.hasPhoto)?.ref;
}
