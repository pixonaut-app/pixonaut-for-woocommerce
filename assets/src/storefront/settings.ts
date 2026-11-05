import type { MountOptions } from '../types/sdk';

/** What every designer on the page shares, printed by the server as
 *  `window.pixonautStorefront`. */
export type StorefrontSettings = {
	/** The SDK script, and its SRI; an empty integrity for a custom script
	 *  the developer tools load instead of the pinned release. */
	sdk: { url: string; integrity: string };
	/** Whether mounts are logged to the console (the developer tools' SDK
	 *  debug, for a store manager). */
	debug?: boolean;
	/** Customer-facing text by label key, already filtered in PHP. */
	labels: Record<string, string>;
	/** Where a side that cannot be personalised is reported. */
	reportUrl: string;
	/** Whether a designer whose mount options name no `accent` takes the
	 *  colour of the theme's buttons (the store's accent setting). */
	themeAccent?: boolean;
	/** Whether the variation attributes the designer draws in its form show
	 *  as swatches (the store's `attribute_options` setting). */
	attributeSwatches?: boolean;
};

/** One side's mount options, as the server built them: everything but the
 *  targets. A page opened to edit a cart line's design mounts each side on
 *  its committed design, through a correction link. */
export type SideOptions = Extract<
	MountOptions,
	{ publishableKey: string } | { correctionLink: string }
>;

/** Mount options by side key, in display order. */
export type ProductMounts = Record<string, SideOptions>;

/** The global the server prints the settings into. */
export const SETTINGS_GLOBAL = 'pixonautStorefront';

export function readSettings(
	scope: Record<string, unknown> = globalThis as Record<string, unknown>,
): StorefrontSettings | null {
	const settings = scope[SETTINGS_GLOBAL];
	if (typeof settings !== 'object' || settings === null) {
		return null;
	}
	return settings as StorefrontSettings;
}

/** A form container's mount options; empty when the product cannot be
 *  personalised right now. */
export function readMounts(container: HTMLElement): ProductMounts {
	const raw = container.dataset.pixonautMounts;
	if (raw === undefined || raw === '') {
		return {};
	}
	try {
		const parsed: unknown = JSON.parse(raw);
		return typeof parsed === 'object' && parsed !== null
			? (parsed as ProductMounts)
			: {};
	} catch {
		return {};
	}
}
