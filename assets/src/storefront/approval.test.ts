// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SplitMountOptions } from '../types/sdk';
import { ProductDesigner } from './designer';
import {
	fakeSdk,
	modalDialogs,
	PROOF,
	pageDeps,
	productPage,
	requestError,
	settle,
} from './test-support';

/** A second proof, for a design approved again after it changed. */
const CHANGED_PROOF = {
	...PROOF,
	proofToken: 'prf_tok_changed',
	proofUrl: 'http://localhost/i/prf_tok_changed',
};

/** The side's mount options, as the designer handed them to the SDK. */
function mounted(sdk: ReturnType<typeof fakeSdk>): SplitMountOptions {
	return sdk.mount.mock.calls[0]?.[0] as SplitMountOptions;
}

/** Tells the designer the design changed, as the SDK does. */
function change(sdk: ReturnType<typeof fakeSdk>): void {
	mounted(sdk).onChange?.({ values: {} } as never);
}

/** The `blob:` address the proof image is shown from. */
const BLOB_URL = 'blob:http://localhost/proof-image';

/**
 * What the image a proof link answers, as fetched. The body is a string, not
 * a `Blob`: jsdom's `Blob` has no `stream()`, which Node 22's `Response` needs.
 */
function proofImage(): Response {
	return new Response('jpeg', { headers: { 'Content-Type': 'image/jpeg' } });
}

function start(sdk = fakeSdk(), mounts?: object) {
	const page = productPage(mounts, { checkbox: true });
	const fetchProof = vi.fn((_url: string, _signal: AbortSignal) =>
		Promise.resolve(proofImage()),
	);
	const designer = new ProductDesigner(page.container, {
		loadSdk: () => Promise.resolve(sdk.sdk),
		label: (key) => `label:${key}`,
		report: vi.fn(),
		...pageDeps(),
		fetchProof,
	});
	designer.start();
	const box = page.container.querySelector<HTMLInputElement>(
		'.pixonaut-approval__box',
	);
	const proofButton = page.container.querySelector<HTMLButtonElement>(
		'.pixonaut-approval__proof',
	);
	if (box === null || proofButton === null) {
		throw new Error('The approval markup is missing.');
	}
	return { ...page, box, proofButton, sdk, designer, fetchProof };
}

/** The proof dialog, once a proof was opened. */
function proofDialog(): HTMLDialogElement {
	const dialog = document.querySelector<HTMLDialogElement>(
		'.pixonaut-proof-dialog',
	);
	if (dialog === null) {
		throw new Error('No proof dialog was made.');
	}
	return dialog;
}

function dialogImage(): HTMLImageElement | null {
	return proofDialog().querySelector('img');
}

function tick(box: HTMLInputElement, checked = true): void {
	box.checked = checked;
	box.dispatchEvent(new Event('change'));
}

/** The proof tokens of each submit no handler stopped. */
function submitted(form: HTMLFormElement): string[] {
	const sent: string[] = [];
	form.addEventListener('submit', (event) => {
		if (!event.defaultPrevented) {
			sent.push(String(new FormData(form).get('pixonaut_designs[front]')));
			event.preventDefault();
		}
	});
	return sent;
}

describe('checkbox approval', () => {
	beforeEach(() => {
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
		modalDialogs();
		URL.createObjectURL = vi.fn(() => BLOB_URL);
	});

	afterEach(() => {
		vi.restoreAllMocks();
		for (const dialog of document.querySelectorAll('dialog')) {
			dialog.remove();
		}
	});

	it('leaves add to cart enabled while the box is not ticked', () => {
		const { button } = start();

		expect(button.disabled).toBe(false);
	});

	it('shows the error under the box when adding to the cart without it', async () => {
		const { box, button, container, sdk, designer } = start();
		const invalid = vi.fn();
		box.addEventListener('invalid', invalid);
		// Drawn in the designer, as it is by the time the customer clicks.
		await designer.mount();

		button.click();
		await settle();

		const controls = container.querySelector('.pixonaut-approval');
		const error = container.querySelector<HTMLElement>(
			'.pixonaut-approval__error',
		);
		expect(controls?.classList.contains('is-invalid')).toBe(true);
		expect(box.getAttribute('aria-invalid')).toBe('true');
		expect(error?.hidden).toBe(false);
		expect(error?.getAttribute('role')).toBe('alert');
		expect(error?.textContent).toBe('label:approval_required');
		expect(invalid.mock.calls[0]?.[0].defaultPrevented).toBe(true);
		expect(box.ownerDocument.activeElement).toBe(box);
		expect(sdk.designer.approve).not.toHaveBeenCalled();
	});

	it('clears the error once the box is ticked', async () => {
		const { box, button, container } = start();
		button.click();
		await settle();

		tick(box);

		const error = container.querySelector<HTMLElement>(
			'.pixonaut-approval__error',
		);
		expect(
			container
				.querySelector('.pixonaut-approval')
				?.classList.contains('is-invalid'),
		).toBe(false);
		expect(box.hasAttribute('aria-invalid')).toBe(false);
		expect(error?.hidden).toBe(true);
		expect(error?.textContent).toBe('');
	});

	it('leaves the proof button enabled while the box is not ticked', () => {
		const { proofButton } = start();

		expect(proofButton.disabled).toBe(false);
	});

	it('sends no design while the box is not ticked', async () => {
		const { form, sdk } = start();
		const sent = submitted(form);

		form.requestSubmit();
		await settle();

		expect(sdk.designer.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
	});

	it('sends the design once the box is ticked', async () => {
		const { form, box, button } = start();
		const sent = submitted(form);

		tick(box);
		button.click();
		await settle();

		expect(sent).toEqual([PROOF.proofToken]);
	});

	it('opens the proof in a dialog at the click, and no tab', async () => {
		const { proofButton, sdk, fetchProof } = start();
		const open = vi.spyOn(window, 'open');

		proofButton.click();
		const dialog = proofDialog();
		expect(dialog.open).toBe(true);
		expect(dialog.getAttribute('aria-label')).toBe('label:proof_alt');
		expect(dialog.getAttribute('aria-busy')).toBe('true');
		expect(dialog.querySelector('[role="status"]')?.textContent).toBe(
			'label:proof_loading',
		);
		expect(dialogImage()?.hidden).toBe(true);
		await settle();

		expect(open).not.toHaveBeenCalled();
		expect(sdk.designer.approve).toHaveBeenCalledOnce();
		expect(fetchProof.mock.calls[0]?.[0]).toBe(PROOF.proofUrl);
		expect(dialogImage()?.getAttribute('src')).toBe(BLOB_URL);
		expect(dialogImage()?.alt).toBe('label:proof_alt');
		expect(dialogImage()?.hidden).toBe(false);
		expect(dialog.getAttribute('aria-busy')).toBe('false');
		expect(document.body.innerHTML).not.toContain(PROOF.proofUrl);
		expect(sdk.designer.setLocked.mock.calls).toEqual([[true], [false]]);
	});

	it("opens the proof from the word in the box's label without ticking the box", async () => {
		const { box, proofButton, sdk } = start();

		expect(proofButton.closest('label')?.contains(box)).toBe(true);
		proofButton.click();
		await settle();

		expect(sdk.designer.approve).toHaveBeenCalledOnce();
		expect(box.checked).toBe(false);
	});

	it('puts the dialog outside the form, with the theme button class on its close button', async () => {
		const { form, proofButton } = start();

		proofButton.click();
		await settle();

		const dialog = proofDialog();
		expect(form.contains(dialog)).toBe(false);
		const close = dialog.querySelector('button');
		expect(close?.textContent).toBe('label:proof_close');
		expect(close?.classList.contains('wp-element-button')).toBe(true);
	});

	it.each([
		['its button', () => proofDialog().querySelector('button')?.click()],
		['a click outside it', () => proofDialog().click()],
		// The browser closes a modal dialog on Escape.
		['Escape', () => proofDialog().close()],
	])('closes with %s and hands focus back to the link', async (_way, close) => {
		const { proofButton } = start();
		proofButton.focus();

		proofButton.click();
		await settle();
		close();

		expect(proofDialog().open).toBe(false);
		expect(document.activeElement).toBe(proofButton);
		expect(dialogImage()?.hasAttribute('src')).toBe(false);
		expect(URL.revokeObjectURL).toHaveBeenCalledWith(BLOB_URL);
	});

	it('stays open for a click on the proof', async () => {
		const { proofButton } = start();

		proofButton.click();
		await settle();
		dialogImage()?.click();

		expect(proofDialog().open).toBe(true);
	});

	it('sends the proof the customer opened', async () => {
		const { form, box, button, proofButton, sdk } = start();
		const sent = submitted(form);

		proofButton.click();
		await settle();
		proofDialog().close();
		tick(box);
		button.click();
		await settle();

		expect(sdk.designer.approve).toHaveBeenCalledOnce();
		expect(sent).toEqual([PROOF.proofToken]);
	});

	describe('the record of a preview opened', () => {
		/** A designer and its report of a preview opened. */
		function recording(sdk = fakeSdk()) {
			return { sdk, reportPreviewOpened: sdk.designer.reportPreviewOpened };
		}

		/** What the form sends of the front side's preview, at each submit. */
		function sentPreviews(form: HTMLFormElement): unknown[] {
			const sent: unknown[] = [];
			form.addEventListener('submit', (event) => {
				if (!event.defaultPrevented) {
					sent.push(new FormData(form).get('pixonaut_preview_opened[front]'));
					event.preventDefault();
				}
			});
			return sent;
		}

		it('reports a proof the customer was shown, and sends that it was opened', async () => {
			const { sdk, reportPreviewOpened } = recording();
			const { form, box, button, proofButton } = start(sdk);
			const sent = sentPreviews(form);

			proofButton.click();
			await settle();
			expect(reportPreviewOpened).toHaveBeenCalledOnce();
			proofDialog().close();
			tick(box);
			button.click();
			await settle();

			expect(sent).toEqual(['1']);
		});

		it('sends that the preview was not opened when it never was', async () => {
			const { sdk, reportPreviewOpened } = recording();
			const { form, box, button } = start(sdk);
			const sent = sentPreviews(form);

			tick(box);
			button.click();
			await settle();

			expect(reportPreviewOpened).not.toHaveBeenCalled();
			expect(sent).toEqual(['0']);
		});

		it('reports no proof that could not be fetched', async () => {
			const { sdk, reportPreviewOpened } = recording();
			const { proofButton, fetchProof } = start(sdk);
			fetchProof.mockRejectedValue(new Error('offline'));

			proofButton.click();
			await settle();

			expect(reportPreviewOpened).not.toHaveBeenCalled();
		});

		it('reports no proof whose dialog was closed before it came', async () => {
			const { sdk, reportPreviewOpened } = recording();
			const { proofButton, fetchProof } = start(sdk);
			let answer: (response: Response) => void = () => undefined;
			fetchProof.mockReturnValue(
				new Promise((resolve) => {
					answer = resolve;
				}),
			);

			proofButton.click();
			await settle();
			proofDialog().close();
			answer(proofImage());
			await settle();

			expect(reportPreviewOpened).not.toHaveBeenCalled();
		});

		it('reports no proof of a design changed while it downloaded', async () => {
			const { sdk, reportPreviewOpened } = recording();
			const { proofButton, fetchProof } = start(sdk);
			let answer: (response: Response) => void = () => undefined;
			fetchProof.mockReturnValue(
				new Promise((resolve) => {
					answer = resolve;
				}),
			);

			proofButton.click();
			await settle();
			change(sdk);
			answer(proofImage());
			await settle();

			expect(reportPreviewOpened).not.toHaveBeenCalled();
		});

		it('reports no proof whose dialog closed before the browser announced it', async () => {
			const { sdk, reportPreviewOpened } = recording();
			const { proofButton } = start(sdk);
			let decoded: () => void = () => undefined;
			HTMLImageElement.prototype.decode = vi.fn(
				() =>
					new Promise<void>((resolve) => {
						decoded = resolve;
					}),
			);

			proofButton.click();
			await settle();
			// The browser queues the close event after clearing `open`.
			const dialog = proofDialog();
			dialog.close = () => dialog.removeAttribute('open');
			dialog.close();
			decoded();
			await settle();

			expect(reportPreviewOpened).not.toHaveBeenCalled();
		});

		it('reports no proof of a variant changed while it downloaded', async () => {
			const { sdk, reportPreviewOpened } = recording();
			const { proofButton, fetchProof } = start(sdk);
			let answer: (response: Response) => void = () => undefined;
			fetchProof.mockReturnValue(
				new Promise((resolve) => {
					answer = resolve;
				}),
			);

			proofButton.click();
			await settle();
			sdk.designer.pick('other-variant');
			answer(proofImage());
			await settle();

			expect(reportPreviewOpened).not.toHaveBeenCalled();
		});

		it('keeps the proof on screen when the report is refused', async () => {
			const { sdk, reportPreviewOpened } = recording();
			reportPreviewOpened.mockImplementation(() => {
				throw new TypeError('no side');
			});
			const { proofButton } = start(sdk);

			proofButton.click();
			await settle();

			expect(proofDialog().open).toBe(true);
		});

		it('sends no answer where no box asks', async () => {
			const sdk = fakeSdk();
			const { container, form, button } = productPage();
			new ProductDesigner(container, {
				loadSdk: () => Promise.resolve(sdk.sdk),
				label: (key) => `label:${key}`,
				report: vi.fn(),
				...pageDeps(),
			}).start();
			const sent = sentPreviews(form);

			button.click();
			await settle();

			expect(sent).toEqual(['']);
			expect(sdk.designer.wasPreviewOpened).not.toHaveBeenCalled();
		});
	});

	it('keeps the approval of a proof whose dialog was closed before it came', async () => {
		const { form, box, button, proofButton, sdk, fetchProof } = start();
		let finishApprove: (value: typeof PROOF) => void = () => undefined;
		sdk.designer.approve.mockReturnValueOnce(
			new Promise((resolve) => {
				finishApprove = resolve;
			}),
		);
		const sent = submitted(form);

		proofButton.click();
		await settle();
		proofDialog().close();
		finishApprove(PROOF);
		await settle();

		expect(fetchProof).not.toHaveBeenCalled();
		expect(dialogImage()?.hasAttribute('src')).toBe(false);
		tick(box);
		button.click();
		await settle();
		expect(sdk.designer.approve).toHaveBeenCalledOnce();
		expect(sent).toEqual([PROOF.proofToken]);
	});

	it('approves again when the design changed after its proof was opened', async () => {
		const { form, box, button, proofButton, sdk } = start();
		sdk.designer.approve
			.mockResolvedValueOnce(PROOF)
			.mockResolvedValueOnce(CHANGED_PROOF);
		const sent = submitted(form);

		proofButton.click();
		await settle();
		proofDialog().close();
		change(sdk);
		tick(box);
		button.click();
		await settle();

		expect(sdk.designer.approve).toHaveBeenCalledTimes(2);
		expect(sent).toEqual([CHANGED_PROOF.proofToken]);
	});

	it('closes the dialog and says so when the proof cannot be made', async () => {
		const { container, proofButton, sdk } = start();
		sdk.designer.approve.mockRejectedValue(requestError(422, 'invalid'));

		proofButton.click();
		await settle();

		expect(proofDialog().open).toBe(false);
		const error = container.querySelector<HTMLElement>('.pixonaut-form__error');
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toBe('label:design_failed');
	});

	it('closes the dialog and says so when the proof image cannot be fetched', async () => {
		const { container, proofButton, fetchProof } = start();
		fetchProof.mockResolvedValueOnce(new Response(null, { status: 404 }));

		proofButton.click();
		await settle();

		expect(proofDialog().open).toBe(false);
		const error = container.querySelector<HTMLElement>('.pixonaut-form__error');
		expect(error?.hidden).toBe(false);
	});

	it('closes the dialog and says so when the proof is no image the browser can draw', async () => {
		const { container, proofButton } = start();
		vi.mocked(HTMLImageElement.prototype.decode).mockRejectedValueOnce(
			new DOMException('The source image cannot be decoded.', 'EncodingError'),
		);

		proofButton.click();
		await settle();

		expect(proofDialog().open).toBe(false);
		expect(URL.revokeObjectURL).toHaveBeenCalledWith(BLOB_URL);
		const error = container.querySelector<HTMLElement>('.pixonaut-form__error');
		expect(error?.hidden).toBe(false);
		expect(error?.textContent).toBe('label:design_failed');
	});

	it('frees the form while the proof downloads, and gives the download up once the dialog closes', async () => {
		const { form, box, button, proofButton, sdk, fetchProof } = start();
		let signal: AbortSignal | undefined;
		fetchProof.mockImplementationOnce((_url, aborted) => {
			signal = aborted;
			return new Promise<Response>(() => undefined);
		});
		const sent = submitted(form);

		proofButton.click();
		await settle();
		expect(sdk.designer.setLocked.mock.calls).toEqual([[true], [false]]);
		proofDialog().close();

		expect(signal?.aborted).toBe(true);
		const error = form.querySelector<HTMLElement>('.pixonaut-form__error');
		expect(error?.hidden).toBe(true);
		tick(box);
		button.click();
		await settle();
		expect(sdk.designer.approve).toHaveBeenCalledOnce();
		expect(sent).toEqual([PROOF.proofToken]);
	});

	it('unticks the box when the design changes after it was ticked', async () => {
		const { box, designer, sdk } = start();
		await designer.mount();
		tick(box);

		change(sdk);

		expect(box.checked).toBe(false);
	});

	it('approves again once a kept proof is about to expire', async () => {
		const { form, box, button, proofButton, sdk } = start();
		sdk.designer.approve
			.mockResolvedValueOnce(PROOF)
			.mockResolvedValueOnce(CHANGED_PROOF);
		const sent = submitted(form);

		proofButton.click();
		await settle();
		proofDialog().close();
		vi.spyOn(Date, 'now').mockReturnValue(PROOF.expiresAt);
		tick(box);
		button.click();
		await settle();

		expect(sdk.designer.approve).toHaveBeenCalledTimes(2);
		expect(sent).toEqual([CHANGED_PROOF.proofToken]);
	});

	it('does not keep a proof whose design changed while it was made', async () => {
		const { box, proofButton, sdk, fetchProof } = start();
		let finishFirst: (value: typeof PROOF) => void = () => undefined;
		sdk.designer.approve
			.mockReturnValueOnce(
				new Promise((resolve) => {
					finishFirst = resolve;
				}),
			)
			.mockResolvedValueOnce(CHANGED_PROOF);

		tick(box);
		proofButton.click();
		await settle();
		change(sdk);
		finishFirst(PROOF);
		await settle();

		expect(sdk.designer.approve).toHaveBeenCalledTimes(2);
		expect(fetchProof.mock.calls.map(([url]) => url)).toEqual([
			CHANGED_PROOF.proofUrl,
		]);
	});

	it('keeps the approval controls after a passing failure, and retries from them', async () => {
		const sdk = fakeSdk();
		sdk.mount.mockRejectedValueOnce(new TypeError('Failed to fetch'));
		const { container, proofButton, fetchProof } = start(sdk);

		proofButton.click();
		await settle();
		expect(container.classList.contains('is-closed')).toBe(false);
		expect(proofDialog().open).toBe(false);

		proofButton.click();
		await settle();
		expect(sdk.mount).toHaveBeenCalledTimes(2);
		expect(fetchProof.mock.calls[0]?.[0]).toBe(PROOF.proofUrl);
		expect(proofDialog().open).toBe(true);
	});

	it('hides the approval controls once personalisation is unavailable for good', async () => {
		const sdk = fakeSdk();
		sdk.mount.mockRejectedValueOnce(requestError(422, 'unknown_placement'));
		const { container, designer } = start(sdk);

		await designer.mount();

		expect(container.classList.contains('is-closed')).toBe(true);
	});
});
