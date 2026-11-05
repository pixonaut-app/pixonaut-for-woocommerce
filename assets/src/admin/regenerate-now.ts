// Regenerate now, on the products list and the product's Pixonaut tab. A
// dialog first says what it means; once confirmed, the start route queues
// every product's pictures, as Regenerate in background does, and lists the
// pictures each product has to draw. Then the draw route is asked once per
// picture, one after another, each drawn while its request runs, with each
// product's progress shown. Stopping, or closing the tab, leaves whatever is
// not drawn yet to the background queue the start route filled.
import './regenerate-now.css';

/** The dialog's id, printed by `admin/regenerate-now.php`. */
export const ROOT_ID = 'pixonaut-regenerate-now';

/** How one product's or variation's pictures stand after its draw request. */
export type DrawState = 'drawn' | 'failed' | 'kept' | 'drawing';

/** How a product's line ends: as its draw requests answered, or `error`
 *  when one of them got no answer. */
type Outcome = DrawState | 'error';

const DRAW_STATES: readonly string[] = [
	'drawn',
	'failed',
	'kept',
	'drawing',
] satisfies DrawState[];

/** A product the start route queued: its pictures to draw (`items`), and
 *  how they stand already when there is none. */
type Started = {
	id: number;
	name: string;
	items: number[];
	state: string;
};

/** Which of two outcomes a product's line says: a request that got no
 *  answer outweighs a picture Pixonaut could not draw, which outweighs one
 *  left to the background, which outweighs a kept image, which outweighs a
 *  drawn one. */
const WEIGHT: Record<Outcome, number> = {
	drawn: 0,
	kept: 1,
	drawing: 2,
	failed: 3,
	error: 4,
};

/** The dialog's text each outcome's line says; `error` is the summary's
 *  when nothing could be queued. */
const LINE_TEXT: Record<Outcome, string> = {
	drawn: 'drawn',
	kept: 'kept',
	drawing: 'drawing',
	failed: 'failed',
	error: 'unreached',
};

/** The start route's `state` for nothing queued, said as the background's. */
function asDrawState(state: string): DrawState {
	return DRAW_STATES.includes(state) ? (state as DrawState) : 'drawing';
}

function text(dialog: HTMLElement, key: string, ...values: number[]): string {
	let said = dialog.getAttribute(`data-${key}-text`) ?? '';
	values.forEach((value, index) => {
		said = said.replace(`%${index + 1}$s`, String(value));
	});
	return said;
}

async function post<T>(
	url: string,
	nonce: string,
	body: Record<string, unknown>,
): Promise<T | null> {
	try {
		const response = await fetch(url, {
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
		});
		return response.ok ? ((await response.json()) as T) : null;
	} catch {
		return null;
	}
}

function part<T extends HTMLElement>(dialog: HTMLElement, name: string): T {
	const element = dialog.querySelector<T>(`.pixonaut-now__${name}`);
	if (!element) {
		throw new Error(`Regenerate now's dialog has no ${name}`);
	}
	return element;
}

function open(dialog: HTMLDialogElement): void {
	if (typeof dialog.showModal === 'function') {
		dialog.showModal();
	} else {
		dialog.setAttribute('open', '');
	}
}

function shut(dialog: HTMLDialogElement): void {
	if (typeof dialog.close === 'function') {
		dialog.close();
	} else {
		dialog.removeAttribute('open');
	}
}

/** Resolves true once the store manager confirms, false when they cancel. */
function confirmed(dialog: HTMLDialogElement): Promise<boolean> {
	return new Promise((resolve) => {
		const start = part<HTMLButtonElement>(dialog, 'start');
		const cancel = part<HTMLButtonElement>(dialog, 'cancel');
		const done = (answer: boolean): void => {
			start.removeEventListener('click', yes);
			cancel.removeEventListener('click', no);
			dialog.removeEventListener('cancel', no);
			resolve(answer);
		};
		const yes = (): void => done(true);
		const no = (): void => done(false);
		start.addEventListener('click', yes);
		cancel.addEventListener('click', no);
		// Escape closes a modal dialog: the same as Cancel.
		dialog.addEventListener('cancel', no);
	});
}

/** Shows the confirmation again, as the dialog was printed. */
function reset(dialog: HTMLDialogElement, title: string): void {
	part(dialog, 'title').textContent = title;
	part(dialog, 'confirm').hidden = false;
	part(dialog, 'progress').hidden = true;
	part(dialog, 'products').replaceChildren();
	part(dialog, 'summary').textContent = '';
	part<HTMLProgressElement>(dialog, 'bar').value = 0;
	part(dialog, 'cancel').hidden = false;
	part(dialog, 'start').hidden = false;
	part(dialog, 'stop').hidden = true;
	part(dialog, 'close').hidden = true;
}

/** A product's line in the progress: its name and how its pictures stand. */
function line(dialog: HTMLElement, product: Started): HTMLElement {
	const item = dialog.ownerDocument.createElement('li');
	item.className = 'pixonaut-now__product';
	item.dataset.productId = String(product.id);
	const name = dialog.ownerDocument.createElement('span');
	name.className = 'pixonaut-now__name';
	name.textContent = product.name;
	const state = dialog.ownerDocument.createElement('span');
	state.className = 'pixonaut-now__state';
	state.textContent = text(dialog, 'waiting');
	item.append(name, state);
	return item;
}

type RunOptions = {
	/** Called once the dialog is closed, with whether any picture was drawn. */
	onClose?: (drawn: boolean) => void;
};

/** Waits for the Close button, then shuts the dialog. */
function closing(dialog: HTMLDialogElement): Promise<void> {
	return new Promise((resolve) => {
		const close = part<HTMLButtonElement>(dialog, 'close');
		const done = (event?: Event): void => {
			event?.preventDefault();
			close.removeEventListener('click', done);
			dialog.removeEventListener('cancel', done);
			shut(dialog);
			resolve();
		};
		close.addEventListener('click', done);
		dialog.addEventListener('cancel', done);
	});
}

/**
 * Asks for confirmation, then draws the products' pictures one request at a
 * time. Resolves once the dialog is closed; false when there is no dialog,
 * so the caller falls back to the background.
 */
export async function regenerateNow(
	doc: Document,
	productIds: number[],
	options: RunOptions = {},
): Promise<boolean> {
	const dialog = doc.getElementById(ROOT_ID);
	if (!(dialog instanceof HTMLElement) || productIds.length === 0) {
		return false;
	}
	const modal = dialog as HTMLDialogElement;
	const title =
		dialog.dataset.confirmTitle ?? part(dialog, 'title').textContent ?? '';
	dialog.dataset.confirmTitle = title;
	reset(modal, title);
	open(modal);
	if (!(await confirmed(modal))) {
		shut(modal);
		return true;
	}

	const nonce = dialog.dataset.restNonce ?? '';
	const summary = part(dialog, 'summary');
	const bar = part<HTMLProgressElement>(dialog, 'bar');
	const list = part(dialog, 'products');
	const stop = part<HTMLButtonElement>(dialog, 'stop');
	let stopped = false;
	const onStop = (): void => {
		stopped = true;
		stop.disabled = true;
	};
	// Escape while drawing stops, as Stop does, rather than hiding the progress.
	const onEscape = (event: Event): void => {
		event.preventDefault();
		onStop();
	};
	part(dialog, 'title').textContent = text(dialog, 'running');
	part(dialog, 'confirm').hidden = true;
	part(dialog, 'progress').hidden = false;
	part(dialog, 'cancel').hidden = true;
	part(dialog, 'start').hidden = true;
	stop.hidden = false;
	stop.disabled = false;
	stop.addEventListener('click', onStop);
	dialog.addEventListener('cancel', onEscape);

	let drawn = false;
	// Whether a product ended with pictures Pixonaut or the request did not draw.
	let partial = false;
	const started = await post<{ products: Started[] }>(
		dialog.dataset.startUrl ?? '',
		nonce,
		{ product_ids: productIds },
	);
	const products = started?.products ?? null;
	if (products === null || products.length === 0) {
		summary.textContent = text(dialog, products === null ? 'error' : 'none');
	} else {
		const lines = products.map((product) => line(dialog, product));
		list.replaceChildren(...lines);
		bar.max = products.length;
		summary.textContent = text(dialog, 'products', 0, products.length);
		for (const [index, product] of products.entries()) {
			if (stopped) {
				break;
			}
			const state = lines[index]?.querySelector('.pixonaut-now__state');
			let outcome: Outcome =
				product.items.length === 0 ? asDrawState(product.state) : 'drawn';
			for (const [done, item] of product.items.entries()) {
				if (stopped) {
					break;
				}
				if (state) {
					state.textContent = text(
						dialog,
						'pictures',
						done,
						product.items.length,
					);
				}
				const answer = await post<{ state: DrawState }>(
					dialog.dataset.drawUrl ?? '',
					nonce,
					{ item },
				);
				const each: Outcome =
					answer === null ? 'error' : asDrawState(answer.state);
				drawn = drawn || each === 'drawn';
				outcome = WEIGHT[each] > WEIGHT[outcome] ? each : outcome;
			}
			if (stopped) {
				break;
			}
			if (state) {
				state.textContent = text(dialog, LINE_TEXT[outcome]);
			}
			partial = partial || WEIGHT[outcome] >= WEIGHT.failed;
			lines[index]?.classList.add(`is-${outcome}`);
			bar.value = index + 1;
			summary.textContent = text(
				dialog,
				'products',
				index + 1,
				products.length,
			);
		}
		summary.textContent = text(
			dialog,
			stopped ? 'stopped' : partial ? 'partial' : 'done',
		);
	}

	stop.removeEventListener('click', onStop);
	dialog.removeEventListener('cancel', onEscape);
	stop.hidden = true;
	part(dialog, 'close').hidden = false;
	await closing(modal);
	options.onClose?.(drawn);
	return true;
}
