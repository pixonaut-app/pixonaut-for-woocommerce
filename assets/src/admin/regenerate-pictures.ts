// The Regenerate in background button in the Pixonaut tab. It posts to the
// product's pictures route, which queues the drawing and starts it, then
// asks the route until every picture is drawn, with a spinner turning, and
// says how it went in the line beside it. Regenerate now beside it draws
// them in the page (`regenerate-now.ts`), then says how they stand.
import { regenerateNow } from './regenerate-now';

/** How the product's pictures stand, as the route answers; `error` when
 *  nothing was queued to draw them again. */
export type PictureState = 'drawing' | 'drawn' | 'failed' | 'kept' | 'error';

/** The line's text when the route could not be asked. */
const ERROR: PictureState = 'error';

export const ROOT_SELECTOR = '.pixonaut-regenerate';

/** How often the route is asked while the pictures are drawn: the runners
 *  the route starts draw them in seconds. */
export const POLL_MS = 1000;

/** How long the route is asked every POLL_MS: a drawing no runner reached
 *  runs with the store's background jobs, which may wait. */
export const POLL_LIMIT_MS = 5 * 60 * 1000;

/** How often the route is asked after POLL_LIMIT_MS, until the pictures
 *  are no longer drawing. */
export const SLOW_POLL_MS = 30 * 1000;

type Options = {
	nonce: string;
	wait?: (ms: number) => Promise<void>;
};

function waitFor(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

/** How the pictures stand, as the route answers: `failed` names the
 *  pictures Pixonaut could not draw. */
type Answer = { state: PictureState; failed?: string };

/** The route's answer, or null when it could not be asked. */
async function ask(
	url: string,
	method: 'GET' | 'POST',
	nonce: string,
): Promise<Answer | null> {
	try {
		const response = await fetch(url, {
			method,
			headers: { 'X-WP-Nonce': nonce },
		});
		if (!response.ok) {
			return null;
		}
		const answer = (await response.json()) as Partial<Answer>;
		return answer.state ? { state: answer.state, failed: answer.failed } : null;
	} catch {
		return null;
	}
}

function say(root: HTMLElement, answer: Answer): void {
	const status = root.querySelector<HTMLElement>(
		'.pixonaut-regenerate__status',
	);
	if (status) {
		status.textContent =
			answer.failed ?? root.getAttribute(`data-${answer.state}-text`) ?? '';
	}
	root
		.querySelector('.pixonaut-regenerate__spinner')
		?.classList.toggle('is-active', answer.state === 'drawing');
}

/** Turns Regenerate in background off while the pictures are drawn, back
 *  on after. Regenerate now stays on: it draws pictures the background
 *  jobs have not reached, and its dialog is modal while it runs. */
function setDisabled(root: HTMLElement, disabled: boolean): void {
	const button = root.querySelector<HTMLButtonElement>(
		'.pixonaut-regenerate__button',
	);
	if (button) {
		button.disabled = disabled;
	}
}

/** Asks the route until the pictures are no longer drawing, less often after the limit. */
export async function follow(
	root: HTMLElement,
	options: Options,
	first: Answer | null,
): Promise<void> {
	const wait = options.wait ?? waitFor;
	const url = root.dataset.url ?? '';
	let answer = first;
	let waited = 0;
	while (answer?.state === 'drawing') {
		say(root, answer);
		const interval = waited < POLL_LIMIT_MS ? POLL_MS : SLOW_POLL_MS;
		await wait(interval);
		waited += interval;
		answer = await ask(url, 'GET', options.nonce);
	}
	say(root, answer ?? { state: ERROR });
	setDisabled(root, false);
}

/** Queues the drawing, then follows it. */
export async function regenerate(
	root: HTMLElement,
	options: Options,
): Promise<void> {
	setDisabled(root, true);
	say(root, { state: 'drawing' });
	await follow(
		root,
		options,
		await ask(root.dataset.url ?? '', 'POST', options.nonce),
	);
}

/** Draws the product's pictures in the page, then says how they stand. */
export async function regenerateHere(
	root: HTMLElement,
	options: Options,
): Promise<void> {
	const now = root.querySelector<HTMLButtonElement>(
		'.pixonaut-regenerate__now',
	);
	const id = Number.parseInt(now?.dataset.productId ?? '', 10);
	const ran = await regenerateNow(root.ownerDocument, id > 0 ? [id] : []);
	if (!ran) {
		await regenerate(root, options);
		return;
	}
	await follow(
		root,
		options,
		await ask(root.dataset.url ?? '', 'GET', options.nonce),
	);
}

export function initRegeneratePictures(
	container: ParentNode,
	options: Options,
): void {
	const root = container.querySelector<HTMLElement>(ROOT_SELECTOR);
	if (!root) {
		return;
	}
	root
		.querySelector('.pixonaut-regenerate__button')
		?.addEventListener('click', () => void regenerate(root, options));
	root
		.querySelector('.pixonaut-regenerate__now')
		?.addEventListener('click', () => void regenerateHere(root, options));
	// A drawing queued before the page loaded, as by the last save, is followed too.
	if (
		root.querySelector<HTMLButtonElement>('.pixonaut-regenerate__button')
			?.disabled
	) {
		void follow(root, options, { state: 'drawing' });
	}
}
