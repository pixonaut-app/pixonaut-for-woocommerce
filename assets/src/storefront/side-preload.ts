import type {
	PixonautDesigner,
	PreloadMode,
	SplitMountOptions,
} from '../types/sdk';

/** What a mount without a `preload` option preloads in, as the SDK reads it. */
const DEFAULT_MODE: PreloadMode = 'auto';

const OFF: PreloadMode = 'off';

/**
 * Keeps a side whose tab is hidden from preloading, so the side the
 * customer sees gets the bandwidth: the side mounts with `preload` off, and
 * preloads in the mode it would have mounted with once its tab is shown.
 */
export class SidePreloads {
	/** The mode each side mounted while hidden would have preloaded in. */
	private readonly deferred = new Map<string, PreloadMode>();

	/** The options to mount `side` with: preloading off while it is hidden. */
	options(
		side: string,
		options: SplitMountOptions,
		hidden: boolean,
	): SplitMountOptions {
		const mode = options.preload ?? DEFAULT_MODE;
		if (!hidden || mode === OFF) {
			this.deferred.delete(side);
			return options;
		}
		this.deferred.set(side, mode);
		return { ...options, preload: OFF };
	}

	/** Once `side` is shown: preloads what its mount held back, once. */
	shown(side: string, designer: PixonautDesigner | undefined): void {
		const mode = this.deferred.get(side);
		if (mode === undefined || designer === undefined) {
			return;
		}
		this.deferred.delete(side);
		designer.preload('all', { mode }).catch((error: unknown) => {
			console.error(`Pixonaut: side ${side} could not preload.`, error);
		});
	}
}
