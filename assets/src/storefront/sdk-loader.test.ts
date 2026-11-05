// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { loadSdk, resetSdkLoader, SdkLoadError } from './sdk-loader';
import { fakeSdk } from './test-support';

const SDK = {
	url: 'https://cdn.pixonaut.app/sdk/0.1.0/pixonaut.iife.js',
	integrity: 'sha384-test',
};

function sdkScript(): HTMLScriptElement {
	const script = document.head.querySelector<HTMLScriptElement>('script');
	if (script === null) {
		throw new Error('No script was added.');
	}
	return script;
}

describe('loadSdk', () => {
	afterEach(() => {
		resetSdkLoader();
		document.head.innerHTML = '';
		Reflect.deleteProperty(window, 'Pixonaut');
	});

	it('adds one script with SRI, CORS and no query string', () => {
		void loadSdk(SDK);
		void loadSdk(SDK);

		expect(document.head.querySelectorAll('script')).toHaveLength(1);
		const script = sdkScript();
		expect(script.getAttribute('src')).toBe(SDK.url);
		expect(new URL(script.src).search).toBe('');
		expect(script.getAttribute('integrity')).toBe(SDK.integrity);
		expect(script.getAttribute('crossorigin')).toBe('anonymous');
	});

	it('adds a custom script without SRI or CORS', () => {
		void loadSdk({
			url: 'http://localhost:5173/pixonaut.iife.js',
			integrity: '',
		});

		const script = sdkScript();
		expect(script.getAttribute('src')).toBe(
			'http://localhost:5173/pixonaut.iife.js',
		);
		expect(script.hasAttribute('integrity')).toBe(false);
		expect(script.hasAttribute('crossorigin')).toBe(false);
	});

	it('resolves the SDK the script defined', async () => {
		const { sdk } = fakeSdk();
		const loaded = loadSdk(SDK);
		Object.assign(window, { Pixonaut: sdk });

		sdkScript().dispatchEvent(new Event('load'));

		await expect(loaded).resolves.toBe(sdk);
	});

	it('tries again after a failed load', async () => {
		const first = loadSdk(SDK);
		sdkScript().dispatchEvent(new Event('error'));
		await expect(first).rejects.toThrow('could not be loaded');
		await expect(first).rejects.toBeInstanceOf(SdkLoadError);

		void loadSdk(SDK);

		expect(document.head.querySelectorAll('script')).toHaveLength(2);
	});
});
