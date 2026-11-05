// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ProductDesigner } from './designer';
import {
	fakeDesigner,
	fakeSdk,
	modalDialogs,
	PROOF,
	pageDeps,
	productPage,
	requestError,
	settle,
} from './test-support';

/** The edited line's key and the routes the server printed. */
const EDIT = {
	key: 'a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5',
	updateUrl: 'http://shop.test/wp-json/pixonaut/v1/cart/design',
	linkUrl: 'http://shop.test/wp-json/pixonaut/v1/cart/design-link',
	nonce: 'nonce123',
};

const CORRECTION_MOUNTS = {
	front: {
		correctionLink: 'link_first',
		design: 'dsn_1',
		apiBase: 'http://localhost:8954',
		locale: 'en-US',
	},
};

/** The front opened on the blank the design was made on, as the product page
 *  mounts it. */
const ON_BLANK = {
	front: {
		...CORRECTION_MOUNTS.front,
		blankId: 'blank_tee',
		placementId: 'front',
		variants: ['v_tee'],
	},
};

/** The variant the line was bought in, which the page names. */
const LINE_VARIANT = 'v_tee';

const CART_URL = 'http://shop.test/cart/';

/** A second proof, for a side approved again. */
const SECOND_PROOF = { ...PROOF, proofToken: 'prf_tok_second' };

/** A body given as text is sent as it is, as a proxy's error page is. */
type Answer = { status: number; body: unknown };

/** A `fetch` that answers each request with the next of `answers`. */
function server(...answers: Answer[]) {
	return vi.fn<typeof fetch>(async () => {
		const answer = answers.shift();
		if (answer === undefined) {
			throw new Error('No answer left.');
		}
		return typeof answer.body === 'string'
			? new Response(answer.body, {
					status: answer.status,
					headers: { 'Content-Type': 'text/html' },
				})
			: new Response(JSON.stringify(answer.body), {
					status: answer.status,
					headers: { 'Content-Type': 'application/json' },
				});
	});
}

const SAVED: Answer = {
	status: 200,
	body: { revision: 2, redirectUrl: CART_URL },
};

function refused(status: number, code: string): Answer {
	return { status, body: { code, message: `message:${code}` } };
}

/** The side's options with a correction link minted again. */
const RELINKED = { ...CORRECTION_MOUNTS.front, correctionLink: 'link_second' };

const RELINK: Answer = { status: 200, body: { mounts: { front: RELINKED } } };

/** The customer's unsaved changes, as `getState()` holds them. */
const CHANGED_VALUES = { slogan: { content: 'Changed wording' } };

/** A designer whose correction link lapsed, holding the customer's changes. */
function lapsedDesigner() {
	const designer = fakeDesigner();
	designer.approve.mockRejectedValue(requestError(401, 'unauthorized'));
	designer.getState.mockReturnValue({
		templateId: 'mini-tee@2',
		values: CHANGED_VALUES,
	});
	return designer;
}

function start(
	send: ReturnType<typeof server>,
	sdk = fakeSdk(),
	{
		checkbox = false,
		mounts = CORRECTION_MOUNTS,
		variant,
	}: { checkbox?: boolean; mounts?: object; variant?: string } = {},
) {
	const page = productPage(mounts, { checkbox, variant });
	page.container.dataset.pixonautEdit = JSON.stringify(EDIT);
	const quantity = document.createElement('input');
	quantity.name = 'quantity';
	const attribute = document.createElement('select');
	attribute.name = 'attribute_pa_size';
	page.form.prepend(quantity, attribute);
	const deps = pageDeps(send);
	new ProductDesigner(page.container, {
		loadSdk: () => Promise.resolve(sdk.sdk),
		label: (key) => `label:${key}`,
		report: vi.fn(),
		...deps,
	}).start();
	return { ...page, ...sdk, navigate: deps.navigate, quantity, attribute };
}

/** The JSON bodies `send` posted. */
function posted(send: ReturnType<typeof server>): unknown[] {
	return send.mock.calls.map(([, init]) => JSON.parse(String(init?.body)));
}

function errorText(container: HTMLElement): string | null {
	const element = container.querySelector<HTMLElement>('.pixonaut-form__error');
	return element === null || element.hidden ? null : element.textContent;
}

afterEach(() => {
	document.body.innerHTML = '';
});

describe('editing a cart line’s design', () => {
	it('keeps the line’s variation and quantity', () => {
		const page = start(server());

		expect(page.quantity.disabled).toBe(true);
		expect(page.attribute.disabled).toBe(true);
	});

	it('saves the approved correction on the line instead of adding to the cart, then goes to the cart', async () => {
		const send = server(SAVED);
		const page = start(send);
		const submitted = vi.fn();
		window.addEventListener('submit', submitted);

		page.button.click();
		await settle();
		window.removeEventListener('submit', submitted);

		expect(page.mount.mock.calls[0]?.[0]).toMatchObject(
			CORRECTION_MOUNTS.front,
		);
		expect(send.mock.calls[0]?.[0]).toBe(EDIT.updateUrl);
		expect(send.mock.calls[0]?.[1]?.headers).toMatchObject({
			'X-WP-Nonce': EDIT.nonce,
		});
		expect(posted(send)).toEqual([
			{
				key: EDIT.key,
				side: 'front',
				proofToken: PROOF.proofToken,
				approved: false,
				fields: [],
			},
		]);
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
		expect(
			submitted.mock.calls.filter(([event]) => !event.defaultPrevented),
		).toEqual([]);
	});

	it('opens a side on its blank in the variant the line was bought in', async () => {
		const page = start(server(SAVED), fakeSdk(), {
			mounts: ON_BLANK,
			variant: LINE_VARIANT,
		});

		page.button.click();
		await settle();

		expect(page.mount.mock.calls[0]?.[0]).toMatchObject({
			blankId: 'blank_tee',
			initialVariant: LINE_VARIANT,
		});
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
	});

	it('opens a side on its design alone once the SDK refuses the blank it was opened on', async () => {
		const designer = fakeDesigner();
		const sdk = fakeSdk(designer);
		// As the SDK refuses a pair the design was not made on.
		sdk.mount.mockRejectedValueOnce(
			new TypeError('design dsn_1 was made on blank blank_hoodie'),
		);
		const error = vi.spyOn(console, 'error').mockImplementation(() => {});
		const page = start(server(SAVED), sdk, {
			mounts: ON_BLANK,
			variant: LINE_VARIANT,
		});

		page.button.click();
		await settle();

		expect(page.mount).toHaveBeenCalledTimes(2);
		const alone = page.mount.mock.calls[1]?.[0] as Record<string, unknown>;
		expect(alone).toMatchObject({
			correctionLink: 'link_first',
			design: 'dsn_1',
		});
		for (const option of [
			'blankId',
			'placementId',
			'variants',
			'initialVariant',
		]) {
			expect(alone).not.toHaveProperty(option);
		}
		expect(designer.setVariant).not.toHaveBeenCalled();
		expect(error).toHaveBeenCalled();
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
	});

	it('saves a side opened on its design alone without showing it in a variant', async () => {
		const designer = fakeDesigner();
		// As the SDK refuses a variant on a mount with no blank.
		designer.setVariant.mockRejectedValue(new TypeError('no variants'));
		const page = start(server(SAVED), fakeSdk(designer), {
			variant: LINE_VARIANT,
		});

		page.button.click();
		await settle();

		expect(page.mount.mock.calls[0]?.[0]).not.toHaveProperty('initialVariant');
		expect(designer.setVariant).not.toHaveBeenCalled();
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
	});

	it('saves the values of the corrected design’s fields with it', async () => {
		const send = server(SAVED);
		const page = start(send);
		page.designer.getFormSchema.mockReturnValue({
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
		page.designer.getValue.mockReturnValue('Bo');

		page.button.click();
		await settle();

		expect(posted(send)).toMatchObject([
			{ fields: [{ label: 'Name', type: 'text', value: 'Bo' }] },
		]);
	});

	it('approves again when the proof expired before it was saved', async () => {
		const send = server(refused(410, 'proof_expired'), SAVED);
		const designer = fakeDesigner();
		designer.approve
			.mockResolvedValueOnce(PROOF)
			.mockResolvedValueOnce(SECOND_PROOF);
		const page = start(send, fakeSdk(designer));

		page.button.click();
		await settle();

		expect(
			posted(send).map((body) => (body as { proofToken: string }).proofToken),
		).toEqual([PROOF.proofToken, SECOND_PROOF.proofToken]);
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
	});

	it('asks for a reload when the design changed elsewhere', async () => {
		const page = start(server(refused(409, 'revision_changed')));

		page.button.click();
		await settle();

		expect(errorText(page.container)).toBe('message:revision_changed');
		expect(page.navigate).not.toHaveBeenCalled();
		expect(page.designer.setLocked).toHaveBeenLastCalledWith(false);
	});

	it.each([
		['the design takes no more changes', 'not_editable'],
		['the order line was approved meanwhile', 'edit_closed'],
	])('closes editing once %s', async (_, code) => {
		const page = start(server(refused(409, code)));

		page.button.click();
		await settle();

		expect(page.designer.destroy).toHaveBeenCalled();
		expect(page.container.classList.contains('is-closed')).toBe(true);
		const message = page.container.querySelector<HTMLElement>(
			'.pixonaut-form__unavailable',
		);
		expect(message?.hidden).toBe(false);
		expect(message?.textContent).toBe(`message:${code}`);
		expect(page.navigate).not.toHaveBeenCalled();
	});

	it('shows the label when a refusal carries no message', async () => {
		const page = start(
			server({ status: 502, body: '<html><body>Bad gateway</body></html>' }),
		);

		page.button.click();
		await settle();

		expect(errorText(page.container)).toBe('label:design_failed');
		expect(page.navigate).not.toHaveBeenCalled();
	});

	it('asks for a reload when the approval says the design changed elsewhere', async () => {
		const send = server();
		const designer = fakeDesigner();
		designer.approve.mockRejectedValue(requestError(409, 'revision_changed'));
		const page = start(send, fakeSdk(designer));

		page.button.click();
		await settle();

		expect(errorText(page.container)).toBe('label:design_changed_elsewhere');
		expect(send).not.toHaveBeenCalled();
		expect(page.navigate).not.toHaveBeenCalled();
	});

	it('closes editing when the approval says the design takes no more changes', async () => {
		const send = server();
		const designer = fakeDesigner();
		designer.approve.mockRejectedValue(requestError(409, 'not_editable'));
		const page = start(send, fakeSdk(designer));

		page.button.click();
		await settle();

		expect(designer.destroy).toHaveBeenCalled();
		expect(page.container.classList.contains('is-closed')).toBe(true);
		expect(
			page.container.querySelector<HTMLElement>('.pixonaut-form__unavailable')
				?.textContent,
		).toBe('label:design_not_editable');
		expect(send).not.toHaveBeenCalled();
	});

	it('closes editing when opening the proof says the design takes no more changes', async () => {
		const designer = fakeDesigner();
		designer.approve.mockRejectedValue(requestError(409, 'not_editable'));
		const page = start(server(), fakeSdk(designer), { checkbox: true });
		modalDialogs();

		page.container
			.querySelector<HTMLButtonElement>('.pixonaut-approval__proof')
			?.click();
		await settle();
		vi.restoreAllMocks();

		expect(
			document.querySelector<HTMLDialogElement>('.pixonaut-proof-dialog')?.open,
		).toBe(false);
		expect(page.container.classList.contains('is-closed')).toBe(true);
		expect(
			page.container.querySelector<HTMLElement>('.pixonaut-form__unavailable')
				?.textContent,
		).toBe('label:design_not_editable');
	});

	it('keeps the customer’s changes when it remounts on a new link', async () => {
		const send = server(RELINK, SAVED);
		const lapsed = lapsedDesigner();
		const remounted = fakeDesigner();
		remounted.approve.mockResolvedValue(SECOND_PROOF);
		const sdk = fakeSdk(lapsed);
		sdk.mount.mockResolvedValueOnce(lapsed).mockResolvedValueOnce(remounted);
		const page = start(send, sdk);

		page.button.click();
		await settle();

		expect(lapsed.destroy).toHaveBeenCalled();
		expect(page.mount.mock.calls[1]?.[0]).toMatchObject({
			...RELINKED,
			initialValues: CHANGED_VALUES,
		});
		expect(posted(send)[1]).toMatchObject({
			proofToken: SECOND_PROOF.proofToken,
		});
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
	});

	it('mounts the side again on the next submit when its remount failed', async () => {
		const send = server(RELINK, SAVED);
		const lapsed = lapsedDesigner();
		const remounted = fakeDesigner();
		remounted.approve.mockResolvedValue(SECOND_PROOF);
		const sdk = fakeSdk(lapsed);
		sdk.mount
			.mockResolvedValueOnce(lapsed)
			.mockRejectedValueOnce(new Error('network'))
			.mockResolvedValueOnce(remounted);
		const page = start(send, sdk);

		page.button.click();
		await settle();
		expect(errorText(page.container)).toBe('label:design_failed');

		page.button.click();
		await settle();

		expect(page.mount).toHaveBeenCalledTimes(3);
		expect(page.mount.mock.calls[2]?.[0]).toMatchObject({
			...RELINKED,
			initialValues: CHANGED_VALUES,
		});
		expect(posted(send)[1]).toMatchObject({
			proofToken: SECOND_PROOF.proofToken,
		});
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
	});

	it('mints the link again and remounts when it lapsed', async () => {
		const send = server(RELINK, SAVED);
		const designer = fakeDesigner();
		designer.approve.mockRejectedValueOnce(requestError(401, 'unauthorized'));
		const page = start(send, fakeSdk(designer));

		page.button.click();
		await settle();

		expect(send.mock.calls[0]?.[0]).toBe(EDIT.linkUrl);
		expect(posted(send)[0]).toEqual({ key: EDIT.key, side: 'front' });
		expect(designer.destroy).toHaveBeenCalled();
		expect(page.mount.mock.calls[1]?.[0]).toMatchObject(RELINKED);
		expect(page.navigate).toHaveBeenCalledWith(CART_URL);
	});

	it('sends the approval box with the update in the checkbox mode', async () => {
		const send = server(SAVED);
		const page = productPage(CORRECTION_MOUNTS, { checkbox: true });
		page.container.dataset.pixonautEdit = JSON.stringify(EDIT);
		const sdk = fakeSdk();
		new ProductDesigner(page.container, {
			loadSdk: () => Promise.resolve(sdk.sdk),
			label: (key) => key,
			report: vi.fn(),
			...pageDeps(send),
		}).start();
		const box = page.container.querySelector<HTMLInputElement>(
			'.pixonaut-approval__box',
		);
		box?.click();

		page.button.click();
		await settle();

		expect(posted(send)[0]).toMatchObject({ approved: true });
	});
});
