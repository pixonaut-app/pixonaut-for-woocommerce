// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ROOT_ID, regenerateNow } from './regenerate-now';

const START = 'http://localhost/wp-json/pixonaut/v1/pictures/now';

const DRAW = 'http://localhost/wp-json/pixonaut/v1/pictures/draw';

function dialog(): HTMLElement {
	document.body.innerHTML = `
		<dialog id="${ROOT_ID}" data-start-url="${START}" data-draw-url="${DRAW}" data-rest-nonce="n"
			data-pictures-text="%1$s of %2$s pictures" data-products-text="%1$s of %2$s products done"
			data-waiting-text="Waiting" data-drawn-text="Done" data-failed-text="Failed" data-kept-text="Kept"
			data-drawing-text="Background" data-done-text="All done" data-stopped-text="Stopped"
			data-error-text="Error" data-none-text="None" data-running-text="Running"
			data-unreached-text="Unreached" data-partial-text="Partly done">
			<h2 class="pixonaut-now__title">Regenerate now?</h2>
			<div class="pixonaut-now__confirm"><p>Busier</p></div>
			<div class="pixonaut-now__progress" hidden>
				<p class="pixonaut-now__summary"></p>
				<progress class="pixonaut-now__bar" value="0" max="1"></progress>
				<ul class="pixonaut-now__products"></ul>
			</div>
			<button class="pixonaut-now__cancel">Cancel</button>
			<button class="pixonaut-now__start">Regenerate now</button>
			<button class="pixonaut-now__stop" hidden>Stop</button>
			<button class="pixonaut-now__close" hidden>Close</button>
		</dialog>`;
	const element = document.getElementById(ROOT_ID);
	if (!element) {
		throw new Error('no dialog');
	}
	return element;
}

function click(root: HTMLElement, name: string): void {
	root.querySelector<HTMLButtonElement>(`.pixonaut-now__${name}`)?.click();
}

function visible(root: HTMLElement, name: string): boolean {
	return !root.querySelector<HTMLElement>(`.pixonaut-now__${name}`)?.hidden;
}

function lines(root: HTMLElement): string[] {
	return Array.from(root.querySelectorAll('.pixonaut-now__product')).map(
		(line) => line.textContent ?? '',
	);
}

function summary(root: HTMLElement): string {
	return root.querySelector('.pixonaut-now__summary')?.textContent ?? '';
}

type Handler = (url: string, body: Record<string, unknown>) => unknown;

/** Answers each request from the handler; null for a failed request. */
function server(handler: Handler): ReturnType<typeof vi.fn> {
	const fetch = vi.fn(async (url: string, init: RequestInit) => {
		const answer = handler(url, JSON.parse(String(init.body)));
		return answer === null
			? new Response('{}', { status: 500 })
			: new Response(JSON.stringify(answer), { status: 200 });
	});
	vi.stubGlobal('fetch', fetch);
	return fetch;
}

/** Lets the pending promises run. */
async function settle(): Promise<void> {
	for (let turn = 0; turn < 20; turn++) {
		await Promise.resolve();
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
}

const SIGN = { id: 7, name: 'Sign', items: [7, 8, 9], state: 'drawing' };

const TEE = { id: 12, name: 'Tee', items: [12], state: 'drawing' };

afterEach(() => {
	vi.unstubAllGlobals();
	document.body.innerHTML = '';
});

describe('Regenerate now', () => {
	it('asks first, and does nothing when cancelled', async () => {
		const root = dialog();
		const fetch = server(() => ({ products: [] }));

		const run = regenerateNow(document, [7]);
		expect(root.hasAttribute('open')).toBe(true);
		expect(visible(root, 'confirm')).toBe(true);
		click(root, 'cancel');

		await expect(run).resolves.toBe(true);
		expect(fetch).not.toHaveBeenCalled();
		expect(root.hasAttribute('open')).toBe(false);
	});

	it('draws each picture in a request of its own and shows each product as it goes', async () => {
		const root = dialog();
		const drawn: unknown[] = [];
		const fetch = server((url, body) => {
			if (url === START) {
				return { products: [SIGN, TEE] };
			}
			drawn.push(body.item);
			return { state: body.item === 12 ? 'failed' : 'drawn' };
		});
		const onClose = vi.fn();

		const run = regenerateNow(document, [7, 12], { onClose });
		click(root, 'start');
		await settle();

		expect(fetch.mock.calls[0]?.[0]).toBe(START);
		expect(JSON.parse(String(fetch.mock.calls[0]?.[1]?.body))).toEqual({
			product_ids: [7, 12],
		});
		expect(drawn).toEqual([7, 8, 9, 12]);
		expect(lines(root)).toEqual(['SignDone', 'TeeFailed']);
		expect(summary(root)).toBe('Partly done');
		expect(visible(root, 'close')).toBe(true);
		expect(visible(root, 'stop')).toBe(false);

		click(root, 'close');
		await run;
		expect(onClose).toHaveBeenCalledWith(true);
	});

	it('says when every product is drawn', async () => {
		const root = dialog();
		server((url) =>
			url === START ? { products: [SIGN, TEE] } : { state: 'drawn' },
		);

		const run = regenerateNow(document, [7, 12]);
		click(root, 'start');
		await settle();

		expect(lines(root)).toEqual(['SignDone', 'TeeDone']);
		expect(summary(root)).toBe('All done');
		click(root, 'close');
		await run;
	});

	it('says a picture whose draw request failed as left to the background, not drawn there', async () => {
		const root = dialog();
		server((url, body) => {
			if (url === START) {
				return { products: [SIGN, TEE] };
			}
			return body.item === 8 ? null : { state: 'drawn' };
		});

		const run = regenerateNow(document, [7, 12]);
		click(root, 'start');
		await settle();

		expect(lines(root)).toEqual(['SignUnreached', 'TeeDone']);
		expect(root.querySelector('.pixonaut-now__product')?.classList).toContain(
			'is-error',
		);
		expect(summary(root)).toBe('Partly done');
		click(root, 'close');
		await run;
	});

	it('stops after the picture being drawn, leaving the rest to the background', async () => {
		const root = dialog();
		let release: (() => void) | null = null;
		const drawn: unknown[] = [];
		vi.stubGlobal(
			'fetch',
			vi.fn(async (url: string, init: RequestInit) => {
				const body = JSON.parse(String(init.body));
				if (url === START) {
					return new Response(JSON.stringify({ products: [SIGN, TEE] }));
				}
				drawn.push(body.item);
				await new Promise<void>((resolve) => {
					release = resolve;
				});
				return new Response(JSON.stringify({ state: 'drawn' }));
			}),
		);

		const run = regenerateNow(document, [7, 12]);
		click(root, 'start');
		await settle();
		expect(lines(root)[0]).toBe('Sign0 of 3 pictures');
		click(root, 'stop');
		(release as (() => void) | null)?.();
		await settle();

		expect(drawn).toEqual([7]);
		expect(summary(root)).toBe('Stopped');
		expect(lines(root)[1]).toBe('TeeWaiting');
		click(root, 'close');
		await run;
	});

	it('says when the products could not be queued, or none shows a picture', async () => {
		const root = dialog();
		server(() => null);
		const failed = regenerateNow(document, [7]);
		click(root, 'start');
		await settle();
		expect(summary(root)).toBe('Error');
		click(root, 'close');
		await failed;

		server(() => ({ products: [] }));
		const none = regenerateNow(document, [7]);
		expect(visible(root, 'confirm')).toBe(true);
		click(root, 'start');
		await settle();
		expect(summary(root)).toBe('None');
		click(root, 'close');
		await none;
	});

	it('says a product with nothing to draw as the start route found it', async () => {
		const root = dialog();
		server(() => ({
			products: [{ id: 3, name: 'Mug', items: [], state: 'kept' }],
		}));

		const run = regenerateNow(document, [3]);
		click(root, 'start');
		await settle();

		expect(lines(root)).toEqual(['MugKept']);
		click(root, 'close');
		await run;
	});

	it('is not run without its dialog', async () => {
		await expect(regenerateNow(document, [7])).resolves.toBe(false);
	});
});
