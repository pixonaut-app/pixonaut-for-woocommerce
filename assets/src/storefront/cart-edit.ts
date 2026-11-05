import type { FieldValue } from './field-values';
import type { SideOptions } from './settings';

/** What the server prints on a product page opened to edit a design, a
 *  cart line's or one whose proof the customer asked to change, as the form
 *  container's `data-pixonaut-edit`. */
export type EditSettings = {
	/** What names the line to the routes: the cart item's key, or the signed
	 *  `state` the proof page carried back. */
	key: string;
	/** Saves a side's correction on the line. */
	updateUrl: string;
	/** Mints a side's correction link again. */
	linkUrl: string;
	/** The REST nonce, so a signed-in customer's request is made as them. */
	nonce: string;
};

/** Why the server refused an update, as its route names it. */
export const PROOF_EXPIRED = 'proof_expired';
export const REVISION_CHANGED = 'revision_changed';
export const NOT_EDITABLE = 'not_editable';
/** The order line's design was approved since the page opened. */
export const EDIT_CLOSED = 'edit_closed';

export type SaveAnswer =
	/** `redirectUrl`: the page the customer goes on to, the cart or the order. */
	| { ok: true; redirectUrl: string }
	| { ok: false; code: string; message: string };

/** The fields a line's details carry that pick its product: the variation's
 *  attributes and the quantity, which an update keeps as they are. */
const LINE_FIELDS = '[name^="attribute_"], [name="quantity"]';

/**
 * A side's correction mount on its design alone, less the options that open
 * it on the blank it was made on; undefined for a side not corrected on a
 * blank. The SDK refuses a blank or placement the design was not made on,
 * and a design the page read as made on its product may have moved since.
 */
export function designAlone(
	options: SideOptions | undefined,
): SideOptions | undefined {
	if (
		options === undefined ||
		!('correctionLink' in options) ||
		options.blankId === undefined
	) {
		return undefined;
	}
	const {
		blankId: _blank,
		placementId: _placement,
		panelLayout: _panelLayout,
		placements: _placements,
		views: _views,
		initialView: _initialView,
		variants: _variants,
		...alone
	} = options;
	return alone;
}

export function readEdit(container: HTMLElement): EditSettings | null {
	const raw = container.dataset.pixonautEdit;
	if (raw === undefined || raw === '') {
		return null;
	}
	try {
		const parsed = JSON.parse(raw) as Partial<EditSettings>;
		return typeof parsed.key === 'string' &&
			typeof parsed.updateUrl === 'string' &&
			typeof parsed.linkUrl === 'string' &&
			typeof parsed.nonce === 'string'
			? (parsed as EditSettings)
			: null;
	} catch {
		return null;
	}
}

/**
 * The server side of editing a design, a cart line's or an order line's:
 * saving a side's correction, and minting its link again once it lapsed.
 */
export class CartEdit {
	constructor(
		private readonly settings: EditSettings,
		private readonly send: typeof fetch = (...args) => fetch(...args),
	) {}

	/** Keeps the variation and quantity the line was bought with: a
	 *  different one is another line. */
	lockLine(form: HTMLFormElement): void {
		for (const field of form.querySelectorAll<
			HTMLInputElement | HTMLSelectElement
		>(LINE_FIELDS)) {
			field.disabled = true;
		}
	}

	/** Saves the side's correction, approved as `proofToken`, on the line,
	 *  with the values the customer chose for its fields. */
	async save(
		side: string,
		proofToken: string,
		approved: boolean,
		fields: FieldValue[] = [],
	): Promise<SaveAnswer> {
		const response = await this.post(this.settings.updateUrl, {
			side,
			proofToken,
			approved,
			fields,
		});
		const body = await readJson(response);
		if (response.ok && typeof body.redirectUrl === 'string') {
			return { ok: true, redirectUrl: body.redirectUrl };
		}
		return {
			ok: false,
			code: typeof body.code === 'string' ? body.code : '',
			message: typeof body.message === 'string' ? body.message : '',
		};
	}

	/** The side's mount options with a fresh correction link; null when the
	 *  server could not mint one. */
	async relink(side: string): Promise<SideOptions | null> {
		const response = await this.post(this.settings.linkUrl, { side });
		const body = await readJson(response);
		const mounts = body.mounts as Record<string, SideOptions> | undefined;
		return response.ok && mounts?.[side] !== undefined ? mounts[side] : null;
	}

	private post(url: string, body: Record<string, unknown>): Promise<Response> {
		return this.send(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.settings.nonce,
			},
			body: JSON.stringify({ key: this.settings.key, ...body }),
			credentials: 'same-origin',
		});
	}
}

async function readJson(response: Response): Promise<Record<string, unknown>> {
	try {
		const body: unknown = await response.json();
		return typeof body === 'object' && body !== null
			? (body as Record<string, unknown>)
			: {};
	} catch {
		return {};
	}
}
