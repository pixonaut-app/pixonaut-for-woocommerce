import { type Mock, vi } from 'vitest';
import { CartEdit } from './cart-edit';
import type { PixonautSdk } from './sdk-loader';

/** Tasks an add to cart waits through: the approval, then the one it lets
 *  the submit event that started it finish in. */
const ADD_TO_CART_TASKS = 2;

/** Lets every pending promise, and the tasks an add to cart queues, settle. */
export async function settle(): Promise<void> {
	for (let task = 0; task < ADD_TO_CART_TASKS; task++) {
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
}

/** The SDK's error classes, as the IIFE build exposes them. */
export class WasmBootError extends Error {}
export class WasmIntegrityError extends Error {}
export class WasmVersionMismatchError extends Error {}
export class PlacementChangedError extends Error {}
export class TemplateChangedError extends Error {}
export class UnreadableDocumentError extends Error {}
export class VariantUnavailableError extends Error {
	constructor(
		readonly ref: string,
		readonly reason: 'unknown_variant' | 'variant_retired' | 'no_photo',
	) {
		super(`Variant ${ref} is unavailable: ${reason}.`);
	}
}

/** A request error as the SDK rejects with: not exported, read by `code`. */
export function requestError(status: number, code: string): Error {
	return Object.assign(new Error(`${status} ${code}`), { status, code });
}

export type FakeDesigner = {
	approve: ReturnType<typeof vi.fn>;
	getState: ReturnType<typeof vi.fn>;
	getFormSchema: ReturnType<typeof vi.fn>;
	getValue: ReturnType<typeof vi.fn>;
	setLocked: ReturnType<typeof vi.fn>;
	destroy: ReturnType<typeof vi.fn>;
	setPrintSize: ReturnType<typeof vi.fn>;
	setTemplate: ReturnType<typeof vi.fn>;
	setHostItemComplete: ReturnType<typeof vi.fn>;
	reportPreviewOpened: Mock<() => void>;
	wasPreviewOpened: Mock<() => boolean>;
	getVariant: Mock<() => string | undefined>;
	getVariants: Mock<() => Array<{ ref: string; hasPhoto: boolean }>>;
	setVariant: ReturnType<typeof vi.fn>;
	settled: ReturnType<typeof vi.fn>;
	preload: ReturnType<typeof vi.fn>;
	on: ReturnType<typeof vi.fn>;
	/** Mounts with `options`: shows their `initialVariant`, as a mount does,
	 *  or without one the variant it starts on. */
	mounted: (options: { initialVariant?: string }) => void;
	/** Shows variant `ref` as the customer picking it in the designer does. */
	pick: (ref: string) => void;
};

export const PROOF = {
	proofToken: 'prf_tok_front',
	proofUrl: 'http://localhost/i/prf_tok_front',
	docHash: 'hash',
	expiresAt: 1_800_000_000_000,
	printFloorDpi: 150,
	lowResolution: [],
};

/** A designer that shows the variant it mounted with or `setVariant()`
 *  last switched it to, and reports each switch to `on('variant')`. With
 *  `refusesInitialVariant`, it mounts showing `startsOn` (none without), as
 *  the SDK does with an `initialVariant` it cannot show; with `pickedWhileMounting`, it mounts
 *  showing that variant, as when the customer picks one before `mount()`
 *  resolves; with `startsOn`, it mounts without an `initialVariant` showing
 *  that variant, the first it offers with a photo, as the SDK does. */
export function fakeDesigner({
	refusesInitialVariant = false,
	pickedWhileMounting,
	startsOn,
}: {
	refusesInitialVariant?: boolean;
	pickedWhileMounting?: string;
	startsOn?: string;
} = {}): FakeDesigner {
	let variant: string | undefined;
	let previewOpened = false;
	const shown: Array<(event: { ref: string }) => void> = [];
	const show = (ref: string) => {
		variant = ref;
		for (const handler of shown) {
			handler({ ref });
		}
	};
	return {
		approve: vi.fn().mockResolvedValue(PROOF),
		reportPreviewOpened: vi.fn(() => {
			previewOpened = true;
		}),
		wasPreviewOpened: vi.fn(() => previewOpened),
		getState: vi.fn().mockReturnValue({ templateId: 'mini-tee@2', values: {} }),
		getFormSchema: vi.fn().mockReturnValue({ steps: [] }),
		getValue: vi.fn(),
		setLocked: vi.fn(),
		destroy: vi.fn(),
		setPrintSize: vi.fn(),
		setTemplate: vi.fn().mockResolvedValue(undefined),
		setHostItemComplete: vi.fn(),
		getVariant: vi.fn(() => variant),
		getVariants: vi.fn(() =>
			startsOn === undefined ? [] : [{ ref: startsOn, hasPhoto: true }],
		),
		setVariant: vi.fn((ref: string) => {
			show(ref);
			return Promise.resolve();
		}),
		settled: vi.fn().mockResolvedValue(undefined),
		preload: vi.fn().mockResolvedValue(undefined),
		on: vi.fn((event: string, handler: (event: { ref: string }) => void) => {
			if (event === 'variant') {
				shown.push(handler);
			}
			return () => undefined;
		}),
		mounted: ({ initialVariant }) => {
			variant =
				pickedWhileMounting ??
				(refusesInitialVariant || initialVariant === undefined
					? startsOn
					: initialVariant);
			shown.length = 0;
		},
		pick: show,
	};
}

/** The mount options that hand the designer elements of the host's page. */
type LendingOptions = {
	initialVariant?: string;
	formTarget?: HTMLElement;
	optionsStep?: { content?: HTMLElement };
	lastStepContent?: HTMLElement;
};

/** Draws the elements `options` lend in the form target, in place of its
 *  content, as the SDK draws them in its steps, and puts them back where
 *  they were when `designer` is destroyed. */
function lend(designer: FakeDesigner, options: LendingOptions): void {
	const target = options.formTarget;
	const lent = [options.optionsStep?.content, options.lastStepContent].filter(
		(element): element is HTMLElement => element !== undefined,
	);
	if (target === undefined || lent.length === 0) {
		return;
	}
	const places = lent.map((element) => {
		const marker = document.createComment('lent');
		element.before(marker);
		return [element, marker] as const;
	});
	// The SDK replaces the target's content with its form.
	target.replaceChildren(...lent);
	designer.destroy.mockImplementation(() => {
		for (const [element, marker] of places) {
			marker.replaceWith(element);
		}
		places.length = 0;
	});
}

export function fakeSdk(designer: FakeDesigner = fakeDesigner()) {
	const mount = vi.fn().mockResolvedValue(designer);
	const sdk = {
		// Each designer mounted takes its options, as the SDK's does, and
		// draws the host's elements in its form until it is destroyed.
		mount: async (options: LendingOptions) => {
			const mounted = (await mount(options)) as FakeDesigner;
			mounted.mounted(options);
			lend(mounted, options);
			return mounted;
		},
		version: '0.5.0',
		WasmBootError,
		WasmIntegrityError,
		WasmVersionMismatchError,
		PlacementChangedError,
		TemplateChangedError,
		UnreadableDocumentError,
		VariantUnavailableError,
	} as unknown as PixonautSdk;
	return { sdk, mount, designer };
}

export const MOUNTS = {
	front: {
		publishableKey: 'pk_live_fixture_main',
		apiBase: 'http://localhost:8954',
		templateId: 'mini-tee@2',
		blankId: 'blank_tee',
		placementId: 'front',
		printSizes: [{ widthMm: 300, heightMm: 360, label: '30×36' }],
		selectedPrintSizeIndex: 0,
		locale: 'en-US',
	},
};

/** A product printed on its front and its back. */
export const TWO_SIDES = {
	front: MOUNTS.front,
	back: { ...MOUNTS.front, placementId: 'back' },
};

/** Prints `part` for each side, with ` hidden` after the first side's
 *  attributes when the product has tabs, as the server does. */
function eachSide(
	sides: string[],
	part: (side: string, hidden: string) => string,
): string {
	return sides
		.map((side, index) => part(side, index > 0 ? ' hidden' : ''))
		.join('');
}

/** The side tabs the server prints for a product with several sides. */
function sideTabs(sides: string[]): string {
	if (sides.length < 2) {
		return '';
	}
	const tabs = sides
		.map(
			(side, index) =>
				`<button type="button" role="tab" id="pixonaut-tab-${side}" class="pixonaut-tabs__tab" data-pixonaut-side="${side}" aria-controls="pixonaut-form-${side}" aria-selected="${index === 0}" tabindex="${index === 0 ? 0 : -1}">${side}</button>`,
		)
		.join('');
	return `<div class="pixonaut-tabs" role="tablist" aria-label="Sides">${tabs}</div>`;
}

/** The approval controls the server prints in the checkbox approval mode. */
function checkboxApproval(sides: string[]): string {
	return `
				<div class="pixonaut-approval" data-pixonaut-button-class="wp-element-button">
					<label><input type="checkbox" class="pixonaut-approval__box" name="pixonaut_approve" value="yes" required> <span>I approve the ${eachSide(sides, (side, hidden) => `<button type="button" class="pixonaut-approval__proof pixonaut-approval__proof--link" data-pixonaut-side="${side}"${hidden}>preview</button>`)}</span></label>
					<p class="pixonaut-approval__error" role="alert" hidden></p>
				</div>`;
}

/** The markup the server prints: the preview, and the form inside an
 *  add-to-cart form with its button, a part of each for every side in
 *  `mounts`; with `checkbox`, the approval box; with `variant`, the
 *  product's own variant ref. */
export function productPage(
	mounts: object = MOUNTS,
	{
		checkbox = false,
		variations,
		variant,
	}: { checkbox?: boolean; variations?: object; variant?: string } = {},
): {
	form: HTMLFormElement;
	container: HTMLElement;
	button: HTMLButtonElement;
} {
	// A form without mounts still prints the designs field of the one side.
	const keys = Object.keys(mounts);
	const sides = keys.length === 0 ? ['front'] : keys;
	document.body.innerHTML = `
		<div class="pixonaut-preview" data-pixonaut-product="42">
			${eachSide(sides, (side, hidden) => `<div id="pixonaut-preview-${side}" class="pixonaut-preview__side" data-pixonaut-side="${side}"${hidden}></div>`)}
			<div class="pixonaut-preview__images" hidden><img src="product.png" alt=""></div>
			<p class="pixonaut-preview__note">Note</p>
		</div>
		<form class="cart" method="post">
			<div class="pixonaut-form" data-pixonaut-product="42" data-pixonaut-field="pixonaut_designs">
				<p class="pixonaut-form__unavailable" hidden>Unavailable</p>
				<p class="pixonaut-form__error" hidden></p>
				${sideTabs(sides)}
				${eachSide(sides, (side, hidden) => `<div id="pixonaut-form-${side}" class="pixonaut-form__side" data-pixonaut-side="${side}"${hidden}></div>`)}
				${eachSide(sides, (side) => `<input type="hidden" name="pixonaut_designs[${side}]" value="">`)}${checkbox ? checkboxApproval(sides) : ''}
			</div>
			${variations === undefined ? '' : VARIATION_FIELDS}
			<button type="submit" name="add-to-cart" value="42">Add to cart</button>
		</form>`;
	const container = document.querySelector<HTMLElement>('.pixonaut-form');
	const form = document.querySelector<HTMLFormElement>('form');
	const button = document.querySelector<HTMLButtonElement>(
		'button[name="add-to-cart"]',
	);
	if (container === null || form === null || button === null) {
		throw new Error('The product page markup is incomplete.');
	}
	container.dataset.pixonautMounts = JSON.stringify(mounts);
	if (variations !== undefined) {
		container.dataset.pixonautVariations = JSON.stringify(variations);
	}
	if (variant !== undefined) {
		container.dataset.pixonautVariant = variant;
	}
	return { form, container, button };
}

/** A classic variations form's attribute table and the field it fills
 *  with the chosen variation's id. */
const VARIATION_FIELDS = `
			<table class="variations"><tbody><tr><td>
				<select name="attribute_size">
					<option value="">Choose</option>
					<option value="S">S</option>
					<option value="L">L</option>
					<option value="XL">XL</option>
					<option value="P">P</option>
				</select>
			</td></tr></tbody></table>
			<input type="hidden" name="variation_id" value="0">`;

/** The designer's page dependencies, with a navigation that is recorded
 *  rather than made. */
/**
 * Gives jsdom what the proof dialog needs and it lacks: `showModal()` opens
 * a `<dialog>`, `close()` closes it and fires `close`, as a browser does,
 * `URL` makes and releases `blob:` addresses, and an image decodes.
 */
export function modalDialogs(): void {
	URL.createObjectURL = vi.fn(() => 'blob:http://localhost/proof');
	URL.revokeObjectURL = vi.fn();
	HTMLImageElement.prototype.decode = vi.fn(() => Promise.resolve());
	const proto = HTMLDialogElement.prototype;
	proto.showModal = function (this: HTMLDialogElement) {
		this.setAttribute('open', '');
	};
	proto.close = function (this: HTMLDialogElement) {
		if (this.hasAttribute('open')) {
			this.removeAttribute('open');
			this.dispatchEvent(new Event('close'));
		}
	};
}

export function pageDeps(send: typeof fetch = vi.fn()) {
	return {
		cartEdit: (settings: ConstructorParameters<typeof CartEdit>[0]) =>
			new CartEdit(settings, send),
		navigate: vi.fn(),
		fetchProof: vi.fn((_url: string) =>
			// A string body: jsdom's `Blob` has no `stream()`, which Node 22's `Response` needs.
			Promise.resolve(new Response('jpeg')),
		),
	};
}
