// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';

const regenerateNow = vi.fn(async () => true);
vi.mock('./regenerate-now', () => ({ regenerateNow }));

const {
	initRegeneratePictures,
	POLL_LIMIT_MS,
	POLL_MS,
	regenerate,
	regenerateHere,
	SLOW_POLL_MS,
} = await import('./regenerate-pictures');
type PictureState = import('./regenerate-pictures').PictureState;

const URL = 'http://localhost/wp-json/pixonaut/v1/products/7/pictures';

const NONCE = 'nonce-1';

function root(drawing = false): HTMLElement {
	document.body.innerHTML = `
		<div class="pixonaut-regenerate" data-url="${URL}"
			data-drawing-text="Drawing" data-drawn-text="Drawn" data-failed-text="Failed"
			data-kept-text="Kept" data-error-text="Error">
			<button type="button" class="pixonaut-regenerate__button"${drawing ? ' disabled' : ''}>Regenerate in background</button>
			<button type="button" class="pixonaut-regenerate__now" data-product-id="7">Regenerate now</button>
			<span class="spinner pixonaut-regenerate__spinner"></span>
			<span class="pixonaut-regenerate__status"></span>
		</div>`;
	const element = document.querySelector<HTMLElement>('.pixonaut-regenerate');
	if (!element) {
		throw new Error('no root');
	}
	return element;
}

/** Answers each request with the next state, or the next whole answer;
 *  null for a failed request. */
function answers(
	...states: (PictureState | { state: PictureState; failed: string } | null)[]
): ReturnType<typeof vi.fn> {
	const fetch = vi.fn(async () => {
		const state = states.shift() ?? null;
		return state === null
			? new Response('{}', { status: 409 })
			: new Response(
					JSON.stringify(typeof state === 'string' ? { state } : state),
					{ status: 200 },
				);
	});
	vi.stubGlobal('fetch', fetch);
	return fetch;
}

function status(element: HTMLElement): string {
	return (
		element.querySelector('.pixonaut-regenerate__status')?.textContent ?? ''
	);
}

function spinning(element: HTMLElement): boolean {
	return (
		element
			.querySelector('.pixonaut-regenerate__spinner')
			?.classList.contains('is-active') ?? false
	);
}

function button(element: HTMLElement): HTMLButtonElement | null {
	return element.querySelector('.pixonaut-regenerate__button');
}

function nowButton(element: HTMLElement): HTMLButtonElement | null {
	return element.querySelector('.pixonaut-regenerate__now');
}

const wait = vi.fn(async () => {});

afterEach(() => {
	vi.unstubAllGlobals();
	wait.mockClear();
	regenerateNow.mockClear();
	document.body.innerHTML = '';
});

describe('Regenerate in background', () => {
	it('posts to the route, then asks it until the pictures are drawn', async () => {
		const fetch = answers('drawing', 'drawing', 'drawn');
		const element = root();

		await regenerate(element, { nonce: NONCE, wait });

		expect(fetch.mock.calls.map(([, init]) => init?.method)).toEqual([
			'POST',
			'GET',
			'GET',
		]);
		expect(fetch.mock.calls[0]?.[1]?.headers).toEqual({ 'X-WP-Nonce': NONCE });
		expect(wait).toHaveBeenCalledWith(POLL_MS);
		expect(status(element)).toBe('Drawn');
		expect(button(element)?.disabled).toBe(false);
	});

	it('turns the spinner while the pictures are drawn, and stops it once they are', async () => {
		answers('drawing', 'drawn');
		const element = root();
		const spun: boolean[] = [];
		wait.mockImplementationOnce(async () => {
			spun.push(spinning(element));
		});

		await regenerate(element, { nonce: NONCE, wait });

		expect(spun).toEqual([true]);
		expect(spinning(element)).toBe(false);
	});

	it('says when Pixonaut could not draw them', async () => {
		answers('drawing', 'failed');
		const element = root();

		await regenerate(element, { nonce: NONCE, wait });

		expect(status(element)).toBe('Failed');
		expect(spinning(element)).toBe(false);
	});

	it('names the pictures Pixonaut could not draw', async () => {
		answers('drawing', {
			state: 'failed',
			failed: 'Pixonaut could not draw the pictures of “Tee - M”.',
		});
		const element = root();

		await regenerate(element, { nonce: NONCE, wait });

		expect(status(element)).toBe(
			'Pixonaut could not draw the pictures of “Tee - M”.',
		);
	});

	it('says when the own image is kept', async () => {
		answers('kept');
		const element = root();

		await regenerate(element, { nonce: NONCE, wait });

		expect(status(element)).toBe('Kept');
		expect(wait).not.toHaveBeenCalled();
	});

	it('says when the route could not be asked', async () => {
		answers(null);
		const element = root();

		await regenerate(element, { nonce: NONCE, wait });

		expect(status(element)).toBe('Error');
		expect(button(element)?.disabled).toBe(false);
	});

	it('slows down after the limit and still sees the drawing finish', async () => {
		const fetch = answers(
			...Array<PictureState>(POLL_LIMIT_MS / POLL_MS + 2).fill('drawing'),
			'drawn',
		);
		const element = root();

		await regenerate(element, { nonce: NONCE, wait });

		expect(fetch).toHaveBeenCalledTimes(POLL_LIMIT_MS / POLL_MS + 3);
		expect(wait).toHaveBeenCalledWith(SLOW_POLL_MS);
		expect(status(element)).toBe('Drawn');
		expect(button(element)?.disabled).toBe(false);
	});

	it('says when nothing was queued', async () => {
		answers('error');
		const element = root();

		await regenerate(element, { nonce: NONCE, wait });

		expect(status(element)).toBe('Error');
	});

	it('follows a drawing queued before the page loaded', async () => {
		const fetch = answers('drawn');
		const element = root(true);

		initRegeneratePictures(document, { nonce: NONCE, wait });
		await vi.waitFor(() => expect(status(element)).toBe('Drawn'));

		expect(fetch.mock.calls[0]?.[1]?.method).toBe('GET');
	});

	it('keeps Regenerate now on while a drawing is followed', async () => {
		answers('drawing', 'drawn');
		const element = root(true);
		const disabled: (boolean | undefined)[] = [];
		wait.mockImplementationOnce(async () => {
			disabled.push(button(element)?.disabled, nowButton(element)?.disabled);
		});

		initRegeneratePictures(document, { nonce: NONCE, wait });
		await vi.waitFor(() => expect(status(element)).toBe('Drawn'));

		expect(disabled).toEqual([true, false]);
	});

	it('posts when the button is clicked', async () => {
		const fetch = answers('drawn');
		const element = root();
		initRegeneratePictures(document, { nonce: NONCE, wait });

		button(element)?.click();
		await vi.waitFor(() => expect(status(element)).toBe('Drawn'));

		expect(fetch.mock.calls[0]?.[1]?.method).toBe('POST');
	});

	it('runs Regenerate now in the page, then says how the pictures stand', async () => {
		const fetch = answers('drawn');
		const element = root();

		await regenerateHere(element, { nonce: NONCE, wait });

		expect(regenerateNow).toHaveBeenCalledWith(document, [7]);
		expect(fetch.mock.calls[0]?.[1]?.method).toBe('GET');
		expect(status(element)).toBe('Drawn');
	});

	it('queues the pictures in the background when Regenerate now has no dialog', async () => {
		regenerateNow.mockResolvedValueOnce(false);
		const fetch = answers('drawn');
		const element = root();

		await regenerateHere(element, { nonce: NONCE, wait });

		expect(fetch.mock.calls[0]?.[1]?.method).toBe('POST');
	});
});
