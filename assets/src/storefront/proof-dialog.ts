/** The dialog's class; its parts are `__content`, `__loading`, `__image`
 *  and `__close`. */
const DIALOG_CLASS = 'pixonaut-proof-dialog';

/** Marks the dialog while its proof is on its way. */
const LOADING_CLASS = 'is-loading';

/** Fetches a proof's image, given up once `signal` aborts; the browser's
 *  `fetch` on a page. */
export type ProofFetch = (
	url: string,
	signal: AbortSignal,
) => Promise<Response>;

/** What the dialog says, and the theme's class for its button. */
export type ProofDialogText = {
	/** Names the dialog and is the image's alternative text. */
	name: string;
	/** Shown and read out while the proof is made. */
	loading: string;
	/** The close button's label. */
	close: string;
	/** A block theme's button class; empty under a classic theme. */
	buttonClass: string;
};

/**
 * The proof of a side's design, in a modal dialog over the product page: it
 * opens at the click, says the proof is on its way until it is, and closes
 * with its button, Escape or a click outside it, handing focus back to the
 * control that opened it. The image is fetched and shown from a `blob:`
 * address, so Pixonaut's own address for it is nowhere on the page.
 */
export class ProofDialog {
	/** The control focus goes back to once the dialog closes. */
	private opener: HTMLElement | null = null;

	/** The shown image's `blob:` address, released when the dialog closes. */
	private objectUrl: string | null = null;

	/** Counts openings, so a proof that arrives after its dialog was closed
	 *  (and maybe opened again) is not shown. */
	private opening = 0;

	/** Aborts the download of the proof the open dialog waits for. */
	private download: AbortController | null = null;

	private readonly dialog: HTMLDialogElement;

	private readonly image: HTMLImageElement;

	private readonly status: HTMLElement;

	/** Builds the dialog at the end of `doc`'s body, outside the add-to-cart
	 *  form and the approval box's label, so a click in it is no click of
	 *  theirs. */
	constructor(
		doc: Document,
		text: ProofDialogText,
		private readonly fetchProof: ProofFetch,
	) {
		const dialog = doc.createElement('dialog');
		dialog.className = DIALOG_CLASS;
		dialog.setAttribute('aria-label', text.name);
		const content = doc.createElement('div');
		content.className = `${DIALOG_CLASS}__content`;
		this.status = doc.createElement('p');
		this.status.className = `${DIALOG_CLASS}__loading`;
		this.status.setAttribute('role', 'status');
		this.status.textContent = text.loading;
		this.image = doc.createElement('img');
		this.image.className = `${DIALOG_CLASS}__image`;
		this.image.alt = text.name;
		const close = doc.createElement('button');
		close.type = 'button';
		close.className = `${DIALOG_CLASS}__close ${text.buttonClass}`.trim();
		close.textContent = text.close;
		close.addEventListener('click', () => {
			this.close();
		});
		content.append(this.status, this.image, close);
		dialog.append(content);
		doc.body.append(dialog);
		this.dialog = dialog;
		// A click on the backdrop lands on the dialog itself; one on its
		// content lands on the content.
		dialog.addEventListener('click', (event) => {
			if (event.target === dialog) {
				this.close();
			}
		});
		// Escape closes the dialog natively; this runs for every way it closes.
		dialog.addEventListener('close', () => {
			this.closed();
		});
	}

	/** Opens the dialog, waiting for its proof, from `opener`. */
	open(opener: HTMLElement): void {
		this.opening++;
		this.opener = opener;
		this.release();
		this.setLoading(true);
		if (!this.dialog.open) {
			this.dialog.showModal();
		}
	}

	/**
	 * Shows the proof at `url` in the open dialog; nothing once the customer
	 * closed it, which also gives up its download. Rejects when the image
	 * could not be fetched or is no image the browser can draw. Resolves
	 * whether the customer was shown it.
	 */
	async show(url: string): Promise<boolean> {
		const opening = this.opening;
		if (!this.dialog.open) {
			return false;
		}
		const download = new AbortController();
		this.download = download;
		try {
			const response = await this.fetchProof(url, download.signal);
			if (!response.ok) {
				throw new Error(`The proof image answered ${response.status}.`);
			}
			const blob = await response.blob();
			if (opening !== this.opening) {
				return false;
			}
			this.objectUrl = URL.createObjectURL(blob);
			this.image.src = this.objectUrl;
			// A 200 holding no image (truncated, or a proxy's page) fails here
			// rather than showing a broken image as the proof.
			await this.image.decode();
		} catch (error) {
			if (opening !== this.opening) {
				return false;
			}
			this.release();
			throw error;
		} finally {
			if (this.download === download) {
				this.download = null;
			}
		}
		// `opening` moves in the `close` event, queued after `close()` clears
		// `open`; a dialog already closed shows nothing either way.
		if (opening !== this.opening || !this.dialog.open) {
			return false;
		}
		this.setLoading(false);
		return true;
	}

	close(): void {
		if (this.dialog.open) {
			this.dialog.close();
		}
	}

	isOpen(): boolean {
		return this.dialog.open;
	}

	private closed(): void {
		this.opening++;
		this.download?.abort();
		this.download = null;
		this.release();
		const opener = this.opener;
		this.opener = null;
		opener?.focus();
	}

	private setLoading(loading: boolean): void {
		this.dialog.classList.toggle(LOADING_CLASS, loading);
		this.dialog.setAttribute('aria-busy', String(loading));
		this.image.hidden = loading;
		this.status.hidden = !loading;
	}

	private release(): void {
		this.image.removeAttribute('src');
		if (this.objectUrl !== null) {
			URL.revokeObjectURL(this.objectUrl);
			this.objectUrl = null;
		}
	}
}
