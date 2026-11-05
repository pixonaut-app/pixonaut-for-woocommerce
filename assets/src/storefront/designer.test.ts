// @vitest-environment jsdom
import {
	addAction,
	addFilter,
	removeAction,
	removeFilter,
} from '@wordpress/hooks';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ProductDesigner } from './designer';
import {
	APPROVED_ACTION,
	APPROVED_EVENT,
	BEFORE_SUBMIT_ACTION,
	BEFORE_SUBMIT_EVENT,
	MOUNT_OPTIONS_FILTER,
} from './events';
import { type PixonautSdk, SdkLoadError } from './sdk-loader';
import { BUSY_CLASS } from './submit-hold';
import {
	fakeDesigner,
	fakeSdk,
	MOUNTS,
	PROOF,
	pageDeps,
	productPage,
	requestError,
	settle,
	TWO_SIDES,
	VariantUnavailableError,
	WasmIntegrityError,
} from './test-support';
import { VARIATION_SETTLE_MS } from './variations';

/** The variant ref a simple product is sold as. */
const VARIANT = 'v_tee_black_m';

const NAMESPACE = 'pixonaut/test';

/** When the page in these tests was read, in Unix seconds. */
const READ_AT = 1_790_000_000;

const MS_PER_S = 1000;

/** Longer than the server's reads may be used for: 31 minutes. */
const STALE_READS_S = 31 * 60;

/** An SDK release whose `createDesigner` draws a split mount. */
const SPLIT_SDK = '0.6.0';

function start(
	sdk = fakeSdk(),
	loadSdk: () => Promise<PixonautSdk> = () => Promise.resolve(sdk.sdk),
	mounts: object = MOUNTS,
	variant?: string,
) {
	const page = productPage(mounts, { variant });
	const report = vi.fn();
	const designer = new ProductDesigner(page.container, {
		loadSdk,
		label: (key) => `label:${key}`,
		report,
		...pageDeps(),
	});
	designer.start();
	return {
		...page,
		mount: sdk.mount,
		sdkDesigner: sdk.designer,
		report,
		designer,
	};
}

/** Records what the browser would send for each submit of `form` that no
 *  handler stopped, and stops it there, as a theme's AJAX add to cart does. */
function submitted(form: HTMLFormElement): string[] {
	const sent: string[] = [];
	const listener = (event: Event) => {
		if (event.target === form && !event.defaultPrevented) {
			sent.push(String(new FormData(form).get('pixonaut_designs[front]')));
			event.preventDefault();
		}
	};
	window.addEventListener('submit', listener);
	listeners.push(() => window.removeEventListener('submit', listener));
	return sent;
}

const listeners: Array<() => void> = [];

describe('ProductDesigner', () => {
	beforeEach(() => {
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
	});

	afterEach(() => {
		for (const remove of listeners.splice(0)) {
			remove();
		}
		removeFilter(MOUNT_OPTIONS_FILTER, NAMESPACE);
		removeAction(APPROVED_ACTION, NAMESPACE);
		removeAction(BEFORE_SUBMIT_ACTION, NAMESPACE);
		vi.restoreAllMocks();
		vi.unstubAllGlobals();
	});

	it('mounts each side with the server options and its two targets', async () => {
		const { designer, mount } = start();

		await designer.mount();

		expect(mount).toHaveBeenCalledOnce();
		expect(mount.mock.calls[0]?.[0]).toEqual({
			showVariantPicker: false,
			...MOUNTS.front,
			previewTarget: document.getElementById('pixonaut-preview-front'),
			formTarget: document.getElementById('pixonaut-form-front'),
			optionsStep: {},
			lastStepContent: document.querySelector('.pixonaut-cart-row'),
			onChange: expect.any(Function),
		});
	});

	it('draws the cart row on the last step, where its button still adds to the cart', async () => {
		const { designer, mount, button, form } = start();
		const sent = vi.fn((event: SubmitEvent) => event.preventDefault());
		form.addEventListener('submit', sent);

		await designer.mount();
		const row = mount.mock.calls[0]?.[0].lastStepContent as HTMLElement;
		expect(row.contains(button)).toBe(true);
		expect(document.getElementById('pixonaut-form-front')?.contains(row)).toBe(
			true,
		);
		button.click();
		await settle();

		expect(sent).toHaveBeenCalledOnce();
		expect(sent.mock.calls[0]?.[0].submitter).toBe(button);
	});

	it('puts the cart row in the printed form’s slot until the designer draws it there', async () => {
		const sdk = fakeSdk();
		let load: (loaded: PixonautSdk) => void = () => undefined;
		const loading = new Promise<PixonautSdk>((resolve) => {
			load = resolve;
		});
		const page = productPage();
		const target = document.getElementById('pixonaut-form-front');
		target?.insertAdjacentHTML(
			'afterbegin',
			'<div class="pixonaut-form__placeholder"><div class="pixonaut-placeholder__slot" data-pixonaut-slot="cart"></div></div>',
		);
		const designer = new ProductDesigner(page.container, {
			loadSdk: () => loading,
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();

		const row = document.querySelector('.pixonaut-cart-row');
		expect(row?.contains(page.button)).toBe(true);
		expect(row?.parentElement?.className).toBe('pixonaut-placeholder__slot');

		load(sdk.sdk);
		await designer.mount();
		// Lent through a stand-in: the SDK warns of an element inside its target.
		const lent = sdk.mount.mock.calls[0]?.[0].lastStepContent;
		expect(lent).not.toBe(row);
		expect(row?.parentElement).toBe(lent);
		expect(lent?.parentElement).toBe(target);
		expect(document.querySelector('.pixonaut-placeholder__slot')).toBeNull();
	});

	it('puts the cart row back in the form before mounting when a mount filter keeps it out', async () => {
		addFilter(
			MOUNT_OPTIONS_FILTER,
			NAMESPACE,
			({ lastStepContent: _row, ...options }: { lastStepContent?: unknown }) =>
				options,
		);
		const page = productPage();
		document
			.getElementById('pixonaut-form-front')
			?.insertAdjacentHTML(
				'afterbegin',
				'<div class="pixonaut-form__placeholder"><div class="pixonaut-placeholder__slot" data-pixonaut-slot="cart"></div></div>',
			);
		const sdk = fakeSdk();
		const designer = new ProductDesigner(page.container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();

		await designer.mount();

		expect(sdk.mount.mock.calls[0]?.[0]).not.toHaveProperty('lastStepContent');
		const row = document.querySelector('.pixonaut-cart-row');
		expect(page.container.nextElementSibling).toBe(row);
		expect(row?.contains(page.button)).toBe(true);
	});

	it('puts the cart row back in the form when the designer cannot load', async () => {
		const page = productPage();
		document
			.getElementById('pixonaut-form-front')
			?.insertAdjacentHTML(
				'afterbegin',
				'<div class="pixonaut-form__placeholder"><div class="pixonaut-placeholder__slot" data-pixonaut-slot="cart"></div></div>',
			);
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
		const designer = new ProductDesigner(page.container, {
			loadSdk: () => Promise.reject(new Error('offline')),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();

		await designer.mount();

		const row = document.querySelector('.pixonaut-cart-row');
		expect(page.container.nextElementSibling).toBe(row);
		expect(row?.contains(page.button)).toBe(true);
		expect(document.querySelector('.pixonaut-placeholder__slot')).toBeNull();
	});

	it('draws no part of the form in the steps of a product with several sides', async () => {
		const { designer, mount } = start(fakeSdk(), undefined, TWO_SIDES);

		await designer.mount();

		for (const [options] of mount.mock.calls) {
			expect(options).not.toHaveProperty('optionsStep');
			expect(options).not.toHaveProperty('lastStepContent');
		}
		expect(document.querySelector('.pixonaut-cart-row')).toBeNull();
	});

	it('passes the placements and views the product sells through to the mount', async () => {
		const mounts = {
			front: {
				...MOUNTS.front,
				placements: [{ placementId: 'front' }, { placementId: 'back' }],
				views: ['front_flat', 'back_flat'],
			},
		};
		const { designer, mount } = start(fakeSdk(), undefined, mounts);

		await designer.mount();

		expect(mount.mock.calls[0]?.[0]).toMatchObject({
			placements: mounts.front.placements,
			views: mounts.front.views,
		});
	});

	it('draws the colour and size picker only when the product is sold in more than one variant', async () => {
		const mounts = (variants?: string[], own?: object) => ({
			front: {
				...MOUNTS.front,
				...(variants === undefined ? {} : { variants }),
				...own,
			},
		});
		const picker = async (options: object) => {
			const { designer, mount } = start(fakeSdk(), undefined, options);
			await designer.mount();
			return mount.mock.calls[0]?.[0].showVariantPicker;
		};

		expect(await picker(mounts(['v_white', 'v_black']))).toBe(true);
		expect(await picker(mounts([VARIANT]))).toBe(false);
		expect(await picker(mounts())).toBe(false);
		// The server's own choice, or a filter's, stands.
		expect(await picker(mounts([VARIANT], { showVariantPicker: true }))).toBe(
			true,
		);
	});

	it('takes the theme’s accent once when the store follows it, unless the options name one', async () => {
		const themeOptions = vi.fn(() => ({ accent: '#1e73be' }));
		const page = productPage({
			front: MOUNTS.front,
			back: { ...MOUNTS.front, accent: '#ff0000' },
		});
		const sdk = fakeSdk();
		const designer = new ProductDesigner(page.container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			themeOptions,
			...pageDeps(),
		});
		designer.start();

		await designer.mount();

		expect(sdk.mount.mock.calls.map(([options]) => [options.accent])).toEqual([
			['#1e73be'],
			['#ff0000'],
		]);
		expect(themeOptions).toHaveBeenCalledOnce();
		expect(themeOptions).toHaveBeenCalledWith(page.container, page.form);
	});

	it('mounts showing the product’s variant, and does not switch to it again on the add to cart', async () => {
		const { designer, form, button, sdkDesigner, mount } = start(
			fakeSdk(),
			undefined,
			MOUNTS,
			VARIANT,
		);
		const sent = submitted(form);

		await designer.mount();
		expect(mount.mock.calls[0]?.[0]).toMatchObject({ initialVariant: VARIANT });

		button.click();
		await settle();

		expect(sdkDesigner.setVariant).not.toHaveBeenCalled();
		expect(sent).toEqual([PROOF.proofToken]);
	});

	it('shows no variant on a product sold without one', async () => {
		const { designer, form, button, sdkDesigner } = start();
		const sent = submitted(form);

		await designer.mount();
		button.click();
		await settle();

		expect(sent).toEqual([PROOF.proofToken]);
		expect(sdkDesigner.setVariant).not.toHaveBeenCalled();
	});

	it('holds the add to cart of a product whose variant Pixonaut cannot show, unreported', async () => {
		// It mounted showing none, as with a variant it cannot show.
		const sdk = fakeSdk(fakeDesigner({ refusesInitialVariant: true }));
		sdk.designer.setVariant.mockRejectedValue(
			new VariantUnavailableError(VARIANT, 'variant_retired'),
		);
		const { designer, container, form, button, report } = start(
			sdk,
			undefined,
			MOUNTS,
			VARIANT,
		);
		const sent = submitted(form);

		await designer.mount();
		button.click();
		await settle();

		expect(container.classList.contains('is-unavailable')).toBe(true);
		expect(
			container.querySelector<HTMLElement>('.pixonaut-form__unavailable')
				?.hidden,
		).toBe(false);
		expect(sdk.designer.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
		expect(report).not.toHaveBeenCalled();
	});

	it('shows the variant again on the add to cart after a passing failure', async () => {
		// It mounted showing none, as with a variant it cannot show.
		const sdk = fakeSdk(fakeDesigner({ refusesInitialVariant: true }));
		sdk.designer.setVariant.mockRejectedValueOnce(
			requestError(503, 'service_unavailable'),
		);
		const { designer, container, form, button } = start(
			sdk,
			undefined,
			MOUNTS,
			VARIANT,
		);
		const sent = submitted(form);

		await designer.mount();
		expect(container.classList.contains('is-unavailable')).toBe(false);
		button.click();
		await settle();

		expect(sdk.designer.setVariant).toHaveBeenCalledTimes(2);
		expect(sdk.designer.setVariant.mock.invocationCallOrder[1]).toBeLessThan(
			sdk.designer.approve.mock.invocationCallOrder[0] ?? 0,
		);
		expect(sent).toEqual([PROOF.proofToken]);
	});

	it('runs the mount options through pixonaut.designer.mountOptions', async () => {
		addFilter(
			MOUNT_OPTIONS_FILTER,
			NAMESPACE,
			(options: object, context: { productId: number; side: string }) => ({
				...options,
				locale: `sv-SE:${context.productId}:${context.side}`,
			}),
		);
		const { designer, mount } = start();

		await designer.mount();

		expect(mount.mock.calls[0]?.[0].locale).toBe('sv-SE:42:front');
	});

	it('mounts above the form when the theme printed no preview', async () => {
		const { designer, mount } = start();
		document.querySelector('.pixonaut-preview')?.remove();

		await designer.mount();

		const preview = mount.mock.calls[0]?.[0].previewTarget as HTMLElement;
		expect(preview.id).toBe('pixonaut-preview-front');
		expect(preview.nextElementSibling?.id).toBe('pixonaut-form-front');
	});

	it('approves on submit, locked meanwhile, and sends the token on', async () => {
		const { form, button, sdkDesigner } = start();
		let finishApprove: (value: typeof PROOF) => void = () => undefined;
		sdkDesigner.approve.mockReturnValue(
			new Promise((resolve) => {
				finishApprove = resolve;
			}),
		);
		const sent = submitted(form);

		button.click();
		await settle();
		expect(sdkDesigner.setLocked).toHaveBeenLastCalledWith(true);
		expect(sent).toEqual([]);

		finishApprove(PROOF);
		await settle();
		expect(sent).toEqual([PROOF.proofToken]);
	});

	it('sends the values of the approved design’s fields beside its token', async () => {
		const { form, button, sdkDesigner } = start();
		sdkDesigner.getFormSchema.mockReturnValue({
			steps: [
				{
					fields: [
						{
							layerId: 'name',
							prop: 'content',
							type: 'text',
							label: 'Name',
							constraints: {},
						},
					],
				},
			],
		});
		sdkDesigner.getValue.mockReturnValue('Anna');
		const sent: string[] = [];
		const listener = (event: Event) => {
			if (event.target === form && !event.defaultPrevented) {
				sent.push(String(new FormData(form).get('pixonaut_fields[front]')));
				event.preventDefault();
			}
		};
		window.addEventListener('submit', listener);
		listeners.push(() => window.removeEventListener('submit', listener));

		button.click();
		await settle();

		expect(sent.map((json) => JSON.parse(json))).toEqual([
			[{ label: 'Name', type: 'text', value: 'Anna' }],
		]);
	});

	it('makes a new proof for each add to cart', async () => {
		const { form, button, sdkDesigner } = start();
		submitted(form);

		button.click();
		await settle();
		button.click();
		await settle();

		expect(sdkDesigner.approve).toHaveBeenCalledTimes(2);
	});

	it('keeps an onChange the mount options filter added', async () => {
		const onChange = vi.fn();
		addFilter(MOUNT_OPTIONS_FILTER, NAMESPACE, (options: object) => ({
			...options,
			onChange,
		}));
		const { designer, mount } = start();

		await designer.mount();
		mount.mock.calls[0]?.[0].onChange({ values: {} });

		expect(onChange).toHaveBeenCalledWith({ values: {} });
	});

	it('unlocks when another handler keeps the form from being sent', async () => {
		const { form, button, sdkDesigner } = start();
		submitted(form);

		button.click();
		await settle();

		expect(sdkDesigner.setLocked.mock.calls).toEqual([[true], [false]]);
	});

	it('fires the approved and before-submit hooks and events', async () => {
		const { form, button, container } = start();
		submitted(form);
		const approved = vi.fn();
		const beforeSubmit = vi.fn();
		const approvedEvent = vi.fn();
		const beforeSubmitEvent = vi.fn();
		addAction(APPROVED_ACTION, NAMESPACE, approved);
		addAction(BEFORE_SUBMIT_ACTION, NAMESPACE, beforeSubmit);
		container.addEventListener(APPROVED_EVENT, (event) =>
			approvedEvent((event as CustomEvent).detail),
		);
		container.addEventListener(BEFORE_SUBMIT_EVENT, (event) =>
			beforeSubmitEvent((event as CustomEvent).detail),
		);

		button.click();
		await settle();

		const side = { productId: 42, side: 'front', result: PROOF };
		expect(approved).toHaveBeenCalledWith(side);
		expect(approvedEvent).toHaveBeenCalledWith(side);
		const submit = {
			productId: 42,
			form,
			designs: { front: PROOF.proofToken },
		};
		expect(beforeSubmit).toHaveBeenCalledWith(submit);
		expect(beforeSubmitEvent).toHaveBeenCalledWith(submit);
	});

	it('keeps the form when a before-submit listener cancels', async () => {
		const { form, button, container } = start();
		const sent = submitted(form);
		container.addEventListener(BEFORE_SUBMIT_EVENT, (event) =>
			event.preventDefault(),
		);

		button.click();
		await settle();

		expect(sent).toEqual([]);
	});

	it('ignores a submit from a button the SDK drew', async () => {
		const { form, container, sdkDesigner } = start();
		const sent = submitted(form);
		const sdkButton = document.createElement('button');
		container.querySelector('#pixonaut-form-front')?.append(sdkButton);

		sdkButton.click();
		await settle();

		expect(sdkDesigner.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
	});

	it('holds the add-to-cart button busy from the click until the form is sent, and takes no second click', async () => {
		const { form, button, sdkDesigner } = start();
		let finishApprove: (value: typeof PROOF) => void = () => undefined;
		sdkDesigner.approve.mockReturnValue(
			new Promise((resolve) => {
				finishApprove = resolve;
			}),
		);
		const sent: string[] = [];
		const record = (event: Event) => {
			if (event.target === form && !event.defaultPrevented) {
				sent.push(String(new FormData(form).get('pixonaut_designs[front]')));
			}
		};
		window.addEventListener('submit', record);
		listeners.push(() => window.removeEventListener('submit', record));

		button.click();
		await settle();
		expect(button.classList).toContain(BUSY_CLASS);
		expect(button.getAttribute('aria-busy')).toBe('true');
		button.click();
		await settle();
		expect(sdkDesigner.approve).toHaveBeenCalledTimes(1);

		finishApprove(PROOF);
		await settle();
		expect(sent).toEqual([PROOF.proofToken]);
		expect(button.classList).toContain(BUSY_CLASS);
		expect(button.disabled).toBe(true);
		form.requestSubmit();
		await settle();
		expect(sent).toEqual([PROOF.proofToken]);
	});

	it('frees the add-to-cart button when the proof is refused', async () => {
		const { button, sdkDesigner } = start();
		sdkDesigner.approve.mockRejectedValue(
			requestError(422, 'constraint_violation'),
		);

		button.click();
		await settle();

		expect(button.classList).not.toContain(BUSY_CLASS);
		expect(button.hasAttribute('aria-busy')).toBe(false);
		expect(button.disabled).toBe(false);
	});

	it('makes the mark target under the preview of a theme’s older copy of the preview template', async () => {
		const sdk = fakeSdk();
		productPage(MOUNTS);
		const preview = document.getElementById('pixonaut-preview-front');
		const frame = document.createElement('div');
		frame.className = 'pixonaut-preview__frame';
		frame.dataset.pixonautSide = 'front';
		frame.innerHTML = '<div class="pixonaut-preview__stage"></div>';
		preview?.replaceWith(frame);
		frame.firstElementChild?.append(preview as HTMLElement);
		const container = document.querySelector('.pixonaut-form') as HTMLElement;
		const designer = new ProductDesigner(container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();
		await designer.mount();

		const mark = sdk.mount.mock.calls[0]?.[0].markTarget as HTMLElement;
		expect(mark.id).toBe('pixonaut-mark-front');
		expect(mark.classList).toContain('pixonaut-preview__mark');
		expect(mark.previousElementSibling).toBe(frame.firstElementChild);
		expect(preview?.contains(mark)).toBe(false);
	});

	it('mounts the views and the mark under the preview the server printed, and takes the spinner off once the side is drawn', async () => {
		const sdk = fakeSdk();
		let finishMount: (value: unknown) => void = () => undefined;
		sdk.mount.mockReturnValue(
			new Promise((resolve) => {
				finishMount = resolve;
			}),
		);
		productPage(MOUNTS);
		const preview = document.getElementById('pixonaut-preview-front');
		const frame = document.createElement('div');
		frame.className = 'pixonaut-preview__frame';
		frame.dataset.pixonautSide = 'front';
		frame.innerHTML =
			'<div class="pixonaut-preview__stage"></div><div id="pixonaut-mark-front" class="pixonaut-preview__mark" data-pixonaut-side="front"></div><div id="pixonaut-views-front" class="pixonaut-views" data-pixonaut-side="front"></div>';
		preview?.replaceWith(frame);
		frame.firstElementChild?.append(preview as HTMLElement);
		const container = document.querySelector('.pixonaut-form') as HTMLElement;
		const designer = new ProductDesigner(container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();
		const mounting = designer.mount();
		await settle();

		expect(sdk.mount.mock.calls[0]?.[0].viewsTarget).toBe(
			document.getElementById('pixonaut-views-front'),
		);
		expect(sdk.mount.mock.calls[0]?.[0].markTarget).toBe(
			document.getElementById('pixonaut-mark-front'),
		);
		expect(frame.classList).not.toContain('is-drawn');
		finishMount(sdk.designer);
		await mounting;
		expect(frame.classList).toContain('is-drawn');
	});

	it('keeps the spinner over a mounted side until the SDK replaces the preview placeholder', async () => {
		const sdk = fakeSdk();
		productPage(MOUNTS);
		const preview = document.getElementById(
			'pixonaut-preview-front',
		) as HTMLElement;
		preview.innerHTML = '<span class="pixonaut-preview__placeholder"></span>';
		const frame = document.createElement('div');
		frame.className = 'pixonaut-preview__frame';
		preview.replaceWith(frame);
		frame.append(preview);
		const container = document.querySelector('.pixonaut-form') as HTMLElement;
		const designer = new ProductDesigner(container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();

		await designer.mount();
		expect(frame.classList).not.toContain('is-drawn');

		preview.replaceChildren(document.createElement('div'));
		await settle();
		expect(frame.classList).toContain('is-drawn');
	});

	it('takes the spinner off when the side cannot be drawn', async () => {
		const sdk = fakeSdk();
		sdk.mount.mockRejectedValue(new WasmIntegrityError('bad wasm'));
		productPage(MOUNTS);
		const preview = document.getElementById('pixonaut-preview-front');
		const frame = document.createElement('div');
		frame.className = 'pixonaut-preview__frame';
		preview?.replaceWith(frame);
		frame.append(preview as HTMLElement);
		const container = document.querySelector('.pixonaut-form') as HTMLElement;
		const designer = new ProductDesigner(container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();

		await designer.mount();

		expect(frame.classList).toContain('is-drawn');
	});

	it('takes the spinner off every side when the SDK does not load', async () => {
		productPage(TWO_SIDES);
		const frames = Object.keys(TWO_SIDES).map((side) => {
			const preview = document.getElementById(`pixonaut-preview-${side}`);
			const frame = document.createElement('div');
			frame.className = 'pixonaut-preview__frame';
			preview?.replaceWith(frame);
			frame.append(preview as HTMLElement);
			return frame;
		});
		const container = document.querySelector('.pixonaut-form') as HTMLElement;
		const designer = new ProductDesigner(container, {
			loadSdk: () => Promise.reject(new SdkLoadError('blocked')),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();

		await designer.mount();

		for (const frame of frames) {
			expect(frame.classList).toContain('is-drawn');
		}
	});

	it("reads in the browser when the server's reads grew too old before the side mounted", async () => {
		const now = vi.spyOn(Date, 'now').mockReturnValue(READ_AT * MS_PER_S);
		const sdk = fakeSdk();
		const createDesigner = vi.fn().mockResolvedValue(sdk.designer);
		Object.assign(sdk.sdk, {
			version: SPLIT_SDK,
			createDesigner,
			designerUi: {},
		});
		const { container } = productPage(MOUNTS);
		container.insertAdjacentHTML(
			'beforeend',
			`<script type="application/json" class="pixonaut-reads" data-pixonaut-read-at="${READ_AT}">${JSON.stringify({ front: { template: { status: 'live' } } })}</script>`,
		);
		const designer = new ProductDesigner(container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => `label:${key}`,
			report: vi.fn(),
			...pageDeps(),
		});
		designer.start();
		now.mockReturnValue((READ_AT + STALE_READS_S) * MS_PER_S);

		await designer.mount();

		expect(createDesigner).not.toHaveBeenCalled();
		expect(sdk.mount).toHaveBeenCalledOnce();
	});

	describe('with a printed form', () => {
		const MARKER = 'data-pixonaut-field';

		const OTHER_TEMPLATE = 'mini-poster@1';

		/** A product the customer can switch to `OTHER_TEMPLATE`, with one
		 *  printed input left on its default and one they typed in, and the
		 *  server's reads, which the clock has just made. */
		function printedPage() {
			vi.spyOn(Date, 'now').mockReturnValue(READ_AT * MS_PER_S);
			const page = productPage(MOUNTS, {
				variations: {
					'14': {
						state: 'personalised',
						attributes: { attribute_size: 'P' },
						approvalMode: 'add_to_cart',
						sides: {
							front: {
								...MOUNTS.front,
								templateId: OTHER_TEMPLATE,
								printSizes: [0],
							},
						},
					},
				},
			});
			page.container.insertAdjacentHTML(
				'beforeend',
				`<script type="application/json" class="pixonaut-reads" data-pixonaut-read-at="${READ_AT}">${JSON.stringify({ front: { template: { status: 'live' } } })}</script>`,
			);
			const target = document.getElementById(
				'pixonaut-form-front',
			) as HTMLElement;
			target.innerHTML = `<input class="untouched" value="Hello" ${MARKER}="layer-1:content"><input class="typed" value="Hello" ${MARKER}="layer-2:content">`;
			const untouched = target.querySelector('.untouched') as HTMLInputElement;
			const typed = target.querySelector('.typed') as HTMLInputElement;
			typed.value = 'Table 12';
			const sdk = fakeSdk();
			const designer = new ProductDesigner(page.container, {
				loadSdk: () => Promise.resolve(sdk.sdk),
				label: (key) => `label:${key}`,
				report: vi.fn(),
				...pageDeps(),
			});
			return { ...page, sdk, designer, untouched, typed };
		}

		it('hands the SDK every printed input for the template the page printed', async () => {
			const { designer, untouched, typed } = printedPage();

			designer.start();
			await designer.mount();

			expect(untouched.hasAttribute(MARKER)).toBe(true);
			expect(typed.hasAttribute(MARKER)).toBe(true);
		});

		it('hands the SDK only what the customer changed once the server’s reads grew too old', async () => {
			const { designer, untouched, typed } = printedPage();
			vi.mocked(Date.now).mockReturnValue((READ_AT + STALE_READS_S) * MS_PER_S);

			designer.start();
			await designer.mount();

			expect(untouched.hasAttribute(MARKER)).toBe(false);
			expect(typed.hasAttribute(MARKER)).toBe(true);
		});

		it('hands the SDK only what the customer changed after a switch to another template before it mounted', async () => {
			// The designer stays off screen, so the switch comes first.
			vi.stubGlobal(
				'IntersectionObserver',
				class {
					observe(): void {}
					disconnect(): void {}
				},
			);
			const { designer, form, sdk, untouched, typed } = printedPage();
			designer.start();
			const field = form.querySelector(
				'[name="variation_id"]',
			) as HTMLInputElement;
			field.value = '14';
			form.dispatchEvent(new Event('change', { bubbles: true }));
			await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
			await settle();
			expect(sdk.mount).not.toHaveBeenCalled();

			await designer.mount();

			expect(sdk.mount.mock.calls[0]?.[0].templateId).toBe(OTHER_TEMPLATE);
			expect(untouched.hasAttribute(MARKER)).toBe(false);
			expect(typed.hasAttribute(MARKER)).toBe(true);
		});
	});

	it('shows an error and unlocks when the proof is refused', async () => {
		const { form, button, container, report, sdkDesigner } = start();
		const sent = submitted(form);
		sdkDesigner.approve.mockRejectedValue(
			requestError(422, 'constraint_violation'),
		);

		button.click();
		await settle();

		const error = container.querySelector<HTMLElement>('.pixonaut-form__error');
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toBe('label:design_failed');
		expect(sdkDesigner.setLocked).toHaveBeenLastCalledWith(false);
		expect(report).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
	});

	describe('a design refused for its fields', () => {
		/** A page whose labels keep `%s`, as the server prints them. */
		function startNaming(sdk = fakeSdk()) {
			const page = productPage(MOUNTS);
			const report = vi.fn();
			const designer = new ProductDesigner(page.container, {
				loadSdk: () => Promise.resolve(sdk.sdk),
				label: (key) => `label:${key} %s`,
				report,
				...pageDeps(),
			});
			designer.start();
			return { ...page, report, sdkDesigner: sdk.designer };
		}

		/** The form heads the photo and the name; the class has no field. */
		const FIELD_TEXTS = [
			{ layerId: 'photo', prop: 'src', label: 'Photo', value: '' },
			{ layerId: 'name', prop: 'content', label: 'Name', value: 'Your name' },
		];

		function refused(...fields: Array<[string, string]>): Error {
			return Object.assign(requestError(422, 'constraint_violation'), {
				violations: fields.map(([layerId, prop]) => ({
					layerId,
					prop,
					rule: 'unchanged',
				})),
			});
		}

		it('names the fields to change and sends nothing', async () => {
			const { form, button, container, report, sdkDesigner } = startNaming();
			Object.assign(sdkDesigner, {
				getFieldTexts: vi.fn().mockReturnValue(FIELD_TEXTS),
			});
			const sent = submitted(form);
			sdkDesigner.approve.mockRejectedValue(
				refused(['name', 'content'], ['photo', 'src'], ['name', 'content']),
			);

			button.click();
			await settle();

			const error = container.querySelector<HTMLElement>(
				'.pixonaut-form__error',
			);
			expect(error?.hidden).toBe(false);
			expect(error?.textContent).toBe(
				'label:design_fields_to_change Name and Photo',
			);
			expect(sdkDesigner.setLocked).toHaveBeenLastCalledWith(false);
			expect(report).not.toHaveBeenCalled();
			expect(sent).toEqual([]);
		});

		it('names a field whose label reads like a replacement pattern as written', async () => {
			const { button, container, sdkDesigner } = startNaming();
			Object.assign(sdkDesigner, {
				getFieldTexts: vi.fn().mockReturnValue([
					{
						layerId: 'price',
						prop: 'content',
						label: 'Price ($&)',
						value: '',
					},
				]),
			});
			sdkDesigner.approve.mockRejectedValue(refused(['price', 'content']));

			button.click();
			await settle();

			expect(
				container.querySelector('.pixonaut-form__error')?.textContent,
			).toBe('label:design_fields_to_change Price ($&)');
		});

		it('says the design could not be prepared when no field it names is headed', async () => {
			const { button, container, sdkDesigner } = startNaming();
			Object.assign(sdkDesigner, {
				getFieldTexts: vi.fn().mockReturnValue(FIELD_TEXTS),
			});
			sdkDesigner.approve.mockRejectedValue(refused(['class', 'content']));

			button.click();
			await settle();

			expect(
				container.querySelector('.pixonaut-form__error')?.textContent,
			).toBe('label:design_failed %s');
		});
	});

	it('reports an SDK that cannot boot and says personalisation is unavailable', async () => {
		const sdk = fakeSdk();
		sdk.mount.mockRejectedValue(new WasmIntegrityError('tampered'));
		const { designer, container, report } = start(sdk);

		expect(await designer.mount()).toBe(false);

		const message = container.querySelector<HTMLElement>(
			'.pixonaut-form__unavailable',
		);
		expect(message?.hidden).toBe(false);
		expect(message?.textContent).toBe('label:personalisation_unavailable');
		expect(report).toHaveBeenCalledWith(42, 'front', 'wasm_integrity');
	});

	it('mounts again on the next add to cart after a passing failure', async () => {
		const sdk = fakeSdk();
		sdk.mount.mockRejectedValueOnce(new TypeError('Failed to fetch'));
		const { form, button, container, report, sdkDesigner } = start(sdk);
		const sent = submitted(form);
		const message = container.querySelector<HTMLElement>(
			'.pixonaut-form__unavailable',
		);

		button.click();
		await settle();
		expect(sent).toEqual([]);
		expect(message?.hidden).toBe(false);

		button.click();
		await settle();
		expect(sdkDesigner.approve).toHaveBeenCalledOnce();
		expect(sent).toEqual([PROOF.proofToken]);
		expect(message?.hidden).toBe(true);
		expect(report).not.toHaveBeenCalled();
	});

	it('reports an SDK script that did not load and tries it again', async () => {
		const sdk = fakeSdk();
		const loadSdk = vi
			.fn<() => Promise<PixonautSdk>>()
			// The mount the page starts, and the one the add to cart tries again.
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockResolvedValue(sdk.sdk);
		const { form, button, container, report } = start(sdk, loadSdk);
		const sent = submitted(form);

		button.click();
		await settle();
		expect(report).toHaveBeenCalledWith(42, 'front', 'sdk_load');
		expect(
			container.querySelector<HTMLElement>('.pixonaut-form__unavailable')
				?.textContent,
		).toBe('label:personalisation_unavailable');
		expect(sent).toEqual([]);

		button.click();
		await settle();
		expect(sent).toEqual([PROOF.proofToken]);
		expect(report).toHaveBeenCalledOnce();
	});

	it('reports a placement Pixonaut refuses on approve', async () => {
		const { form, button, report, sdkDesigner } = start();
		const sent = submitted(form);
		sdkDesigner.approve.mockRejectedValue(
			requestError(422, 'unknown_placement'),
		);

		button.click();
		await settle();
		button.click();
		await settle();

		expect(report).toHaveBeenCalledWith(42, 'front', 'unknown_placement');
		expect(sdkDesigner.destroy).toHaveBeenCalled();
		expect(sdkDesigner.approve).toHaveBeenCalledOnce();
		expect(sent).toEqual([]);
	});

	it('mounts into its own product preview and form only', async () => {
		const { designer, container, mount } = start();
		const other = document.createElement('div');
		other.className = 'pixonaut-preview';
		other.dataset.pixonautProduct = '7';
		other.innerHTML = '<div id="pixonaut-preview-front"></div>';
		document.body.prepend(other);
		const stray = document.createElement('div');
		stray.id = 'pixonaut-form-front';
		document.body.prepend(stray);

		await designer.mount();

		const options = mount.mock.calls[0]?.[0];
		expect(options.previewTarget.closest('.pixonaut-preview')).not.toBe(other);
		expect(container.contains(options.formTarget)).toBe(true);
	});

	it('holds the form when the server printed no mounts', async () => {
		const page = productPage({});
		const loadSdk = vi.fn();
		new ProductDesigner(page.container, {
			loadSdk,
			label: (key) => key,
			report: vi.fn(),
			...pageDeps(),
		}).start();
		const sent = submitted(page.form);

		page.button.click();
		await settle();

		expect(loadSdk).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
	});

	describe('with a front and a back', () => {
		/** Starts a two-sided product whose sides mount their own designers,
		 *  each approving with a token named after its side. */
		function startTwoSided() {
			const front = fakeDesigner();
			const back = fakeDesigner();
			back.approve.mockResolvedValue({
				...PROOF,
				proofToken: 'prf_tok_back',
			});
			const sdk = fakeSdk(front);
			sdk.mount.mockImplementation((options: { placementId: string }) =>
				Promise.resolve(options.placementId === 'back' ? back : front),
			);
			return { ...start(sdk, undefined, TWO_SIDES), front, back };
		}

		/** What each submit the browser would send carried in both fields. */
		function submittedSides(form: HTMLFormElement): string[][] {
			const sent: string[][] = [];
			const listener = (event: Event) => {
				if (event.target === form && !event.defaultPrevented) {
					const data = new FormData(form);
					sent.push([
						String(data.get('pixonaut_designs[front]')),
						String(data.get('pixonaut_designs[back]')),
					]);
					event.preventDefault();
				}
			};
			window.addEventListener('submit', listener);
			listeners.push(() => window.removeEventListener('submit', listener));
			return sent;
		}

		it('mounts each side into its own targets', async () => {
			const { designer, mount } = startTwoSided();

			await designer.mount();

			expect(mount.mock.calls.map(([options]) => options.placementId)).toEqual([
				'front',
				'back',
			]);
			expect(mount.mock.calls[1]?.[0].previewTarget).toBe(
				document.getElementById('pixonaut-preview-back'),
			);
			expect(mount.mock.calls[1]?.[0].formTarget).toBe(
				document.getElementById('pixonaut-form-back'),
			);
		});

		it('starts a mounted side from its values after another side failed to mount', async () => {
			const { designer, mount, front } = startTwoSided();
			const values = { 'layer-1:content': 'Table 12' };
			front.getState.mockReturnValue({ templateId: 'mini-tee@2', values });
			mount
				.mockResolvedValueOnce(front)
				.mockRejectedValueOnce(new TypeError('Failed to fetch'));
			await settle();

			await designer.mount();

			expect(
				mount.mock.calls.map(([options]) => [
					options.placementId,
					options.initialValues,
				]),
			).toEqual([
				['front', undefined],
				['back', undefined],
				['front', values],
				['back', undefined],
			]);
		});

		it('mounts the hidden side without preloading and preloads it once its tab is shown', async () => {
			const { designer, mount, back } = startTwoSided();

			await designer.mount();

			expect(mount.mock.calls.map(([options]) => options.preload)).toEqual([
				undefined,
				'off',
			]);
			expect(back.preload).not.toHaveBeenCalled();
			document.getElementById('pixonaut-tab-back')?.click();
			document.getElementById('pixonaut-tab-front')?.click();
			document.getElementById('pixonaut-tab-back')?.click();

			expect(back.preload).toHaveBeenCalledOnce();
			expect(back.preload).toHaveBeenCalledWith('all', { mode: 'auto' });
		});

		it("preloads a hidden side whose tab is shown while it mounts in the store's mode", async () => {
			const { designer, mount, front } = startTwoSided();
			addFilter(MOUNT_OPTIONS_FILTER, NAMESPACE, (options: object) => ({
				...options,
				preload: 'background',
			}));
			const back = fakeDesigner();
			let finishBack: (value: typeof back) => void = () => undefined;
			mount.mockImplementation((options: { placementId: string }) =>
				options.placementId === 'back'
					? new Promise((resolve) => {
							finishBack = resolve;
						})
					: Promise.resolve(front),
			);

			const mounting = designer.mount();
			await settle();
			document.getElementById('pixonaut-tab-back')?.click();
			finishBack(back);
			await mounting;

			expect(back.preload).toHaveBeenCalledWith('all', { mode: 'background' });
		});

		it('never preloads a hidden side when the store turned preloading off', async () => {
			const { designer, mount, back } = startTwoSided();
			addFilter(MOUNT_OPTIONS_FILTER, NAMESPACE, (options: object) => ({
				...options,
				preload: 'off',
			}));

			await designer.mount();
			document.getElementById('pixonaut-tab-back')?.click();

			expect(mount.mock.calls.map(([options]) => options.preload)).toEqual([
				'off',
				'off',
			]);
			expect(back.preload).not.toHaveBeenCalled();
		});

		it("hides a preview made above the form while another side's tab is selected", async () => {
			const { designer, mount, front } = startTwoSided();
			document.querySelector('.pixonaut-preview')?.remove();
			let finishBack: (value: typeof front) => void = () => undefined;
			mount.mockImplementation((options: { placementId: string }) =>
				options.placementId === 'back'
					? new Promise((resolve) => {
							finishBack = resolve;
						})
					: Promise.resolve(front),
			);

			const mounting = designer.mount();
			await settle();

			expect(mount).toHaveBeenCalledTimes(2);
			expect(document.getElementById('pixonaut-preview-front')?.hidden).toBe(
				false,
			);
			expect(document.getElementById('pixonaut-preview-back')?.hidden).toBe(
				true,
			);
			finishBack(fakeDesigner());
			await mounting;
			expect(document.getElementById('pixonaut-preview-back')?.hidden).toBe(
				true,
			);
		});

		it("keeps a preview made above the form hidden when its side's mount fails", async () => {
			const { designer, mount, front } = startTwoSided();
			document.querySelector('.pixonaut-preview')?.remove();
			mount.mockImplementation((options: { placementId: string }) =>
				options.placementId === 'back'
					? Promise.reject(new TypeError('Failed to fetch'))
					: Promise.resolve(front),
			);

			expect(await designer.mount()).toBe(false);

			expect(document.getElementById('pixonaut-preview-back')?.hidden).toBe(
				true,
			);
		});

		it('approves each side with its own designer and sends both tokens', async () => {
			const { form, button, front, back } = startTwoSided();
			const sent = submittedSides(form);

			button.click();
			await settle();

			expect(front.approve).toHaveBeenCalledOnce();
			expect(back.approve).toHaveBeenCalledOnce();
			expect(sent).toEqual([['prf_tok_front', 'prf_tok_back']]);
		});

		it('sends nothing while one side is not approved, and shows that side', async () => {
			const { form, button, container, back } = startTwoSided();
			back.approve.mockRejectedValue(requestError(422, 'constraint_violation'));
			const sent = submittedSides(form);

			button.click();
			await settle();

			expect(sent).toEqual([]);
			const fields = Array.from(
				container.querySelectorAll<HTMLInputElement>(
					'input[name^="pixonaut_designs"]',
				),
				(field) => field.value,
			);
			expect(fields).toEqual(['', '']);
			expect(
				container.querySelector<HTMLElement>('.pixonaut-form__error')?.hidden,
			).toBe(false);
			expect(
				document
					.getElementById('pixonaut-tab-back')
					?.getAttribute('aria-selected'),
			).toBe('true');
			expect(document.getElementById('pixonaut-form-back')?.hidden).toBe(false);
			expect(document.getElementById('pixonaut-form-front')?.hidden).toBe(true);
		});

		it('keeps the approved side and approves the other again on the next add to cart', async () => {
			const { form, button, front, back } = startTwoSided();
			const sent = submittedSides(form);
			back.approve.mockRejectedValueOnce(
				requestError(422, 'constraint_violation'),
			);
			button.click();
			await settle();

			button.click();
			await settle();

			expect(front.approve).toHaveBeenCalledOnce();
			expect(back.approve).toHaveBeenCalledTimes(2);
			expect(sent).toEqual([['prf_tok_front', 'prf_tok_back']]);
		});
	});
});
