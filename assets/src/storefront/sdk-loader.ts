import type Pixonaut from '@pixonaut/sdk';

export type PixonautSdk = typeof Pixonaut;

/** Where the SDK's IIFE build puts itself. */
const SDK_GLOBAL = 'Pixonaut';

let loading: Promise<PixonautSdk> | null = null;

/**
 * The SDK script did not load or did not define the SDK: blocked by the
 * site's Content Security Policy, refused by its SRI, or the CDN unreachable.
 * The browser does not say which, so a later try may still succeed.
 */
export class SdkLoadError extends Error {}

/**
 * Loads the pinned SDK once, from Pixonaut's CDN, checked by its SRI (or a
 * custom script the developer tools name, unchecked). The URL
 * carries no query: the SDK resolves its wasm beside the script under the
 * same query, and a query would split the browser's cache.
 */
export function loadSdk(
	sdk: { url: string; integrity: string },
	doc: Document = document,
): Promise<PixonautSdk> {
	loading ??= new Promise<PixonautSdk>((resolve, reject) => {
		const script = doc.createElement('script');
		script.src = sdk.url;
		// A custom script from the developer tools comes without one.
		if (sdk.integrity !== '') {
			script.setAttribute('integrity', sdk.integrity);
			script.setAttribute('crossorigin', 'anonymous');
		}
		script.async = true;
		script.addEventListener('load', () => {
			const loaded = (doc.defaultView as unknown as Record<string, unknown>)?.[
				SDK_GLOBAL
			];
			if (loaded === undefined) {
				reject(
					new SdkLoadError(
						'The Pixonaut SDK loaded without defining Pixonaut.',
					),
				);
				return;
			}
			resolve(loaded as PixonautSdk);
		});
		script.addEventListener('error', () => {
			reject(
				new SdkLoadError(
					`The Pixonaut SDK could not be loaded from ${sdk.url}.`,
				),
			);
		});
		doc.head.append(script);
	}).catch((error: unknown) => {
		// A failed load may succeed on the next try, after a network blip.
		loading = null;
		throw error;
	});
	return loading;
}

/** Forgets a loaded SDK, for tests. */
export function resetSdkLoader(): void {
	loading = null;
}
