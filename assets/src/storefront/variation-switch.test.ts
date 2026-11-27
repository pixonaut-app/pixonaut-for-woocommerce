// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	APPLY_CHOICES_EVENT,
	NOT_APPLICABLE_ATTRIBUTE,
} from './applicable-form';
import { ProductDesigner } from './designer';
import { ReadsAhead } from './reads-ahead';
import { type PixonautSdk, SdkLoadError } from './sdk-loader';
import {
	fakeDesigner,
	fakeSdk,
	MOUNTS,
	modalDialogs,
	PlacementChangedError,
	PROOF,
	pageDeps,
	productPage,
	requestError,
	settle,
	TemplateChangedError,
	TWO_SIDES,
	VariantUnavailableError,
} from './test-support';
import { VARIATION_SETTLE_MS } from './variations';

/** An SDK release whose `createDesigner` draws a split mount. */
const SPLIT_SDK = '0.6.0';

const SMALL = { widthMm: 210, heightMm: 270, label: 'Small' };

/** The front mounted with the menu of every variation: the product's size,
 *  then the small one. */
const MOUNTED = {
	front: { ...MOUNTS.front, printSizes: [...MOUNTS.front.printSizes, SMALL] },
};

const FRONT = {
	templateId: 'mini-tee@2',
	blankId: 'blank_tee',
	placementId: 'front',
	printSizes: [0],
};

/** Value sets of the template's own values, one per recipient. */
const FARMOR = 'vs_3f2e9a1b7c4d5e6f8a9b0c1d';

const MORMOR = 'vs_0a1b2c3d4e5f6a7b8c9d0e1f';

function personalised(
	size: string,
	sides: object,
	approvalMode = 'add_to_cart',
) {
	return {
		state: 'personalised',
		attributes: { attribute_size: size },
		approvalMode,
		sides,
	};
}

/** A variation per switch path, and one bought without a design. */
const VARIATIONS = {
	'11': personalised('S', { front: { ...FRONT, printSizes: [1] } }),
	'12': {
		...personalised('L', { front: FRONT }),
		variant: 'v_tee_dark',
	},
	'13': {
		...personalised('XL', { front: { ...FRONT, blankId: 'blank_hoodie' } }),
		variant: 'v_hoodie',
	},
	'14': personalised('P', { front: { ...FRONT, templateId: 'mini-poster@1' } }),
	'28': personalised('Q', {
		front: { ...FRONT, templateId: 'mini-poster@1', printSizes: [1] },
	}),
	'15': { state: 'plain', attributes: { attribute_size: '' } },
	'16': personalised('S', { front: { ...FRONT, views: ['front_flat'] } }),
	'17': personalised('S', {
		front: {
			...FRONT,
			placements: [{ placementId: 'front' }, { placementId: 'sleeve' }],
		},
	}),
	'18': personalised('S', { front: { ...FRONT, layout: 'steps' } }),
	'19': personalised('T', {
		front: { templateId: 'mini-tee@2', printSizes: [1] },
	}),
	'20': personalised('S', { front: { ...FRONT, drawAtTemplateRatio: true } }),
	'23': personalised('T', {
		front: {
			templateId: 'mini-tee@2',
			printSizes: [1],
			drawAtTemplateRatio: true,
		},
	}),
	'25': personalised('U', {
		front: { templateId: 'mini-tee@2', printSizes: [0] },
	}),
	'26': personalised('U', {
		front: {
			templateId: 'mini-tee@2',
			printSizes: [0],
			drawAtTemplateRatio: true,
		},
	}),
	'27': personalised('W', {
		front: { templateId: 'mini-tee@2', printSizes: [2] },
	}),
	'24': personalised('T', {
		front: {
			templateId: 'mini-poster@1',
			printSizes: [1],
			drawAtTemplateRatio: true,
		},
	}),
	'29': personalised('V', { front: { ...FRONT, valueSet: FARMOR } }),
	'30': personalised('V', { front: { ...FRONT, valueSet: MORMOR } }),
	'33': personalised('V', {
		front: { ...FRONT, printSizes: [1], valueSet: MORMOR },
	}),
};

/** Two variations alike but for the variant they are sold as, both
 *  approved with the box. */
const COLOURS = {
	'21': {
		...personalised('White', { front: FRONT }, 'checkbox'),
		variant: 'v_tee_white',
	},
	'22': {
		...personalised('Black', { front: FRONT }, 'checkbox'),
		variant: 'v_tee_black',
	},
};

/** A proof of the design in the white variant. */
const WHITE_PROOF = { ...PROOF, proofToken: 'prf_tok_white' };

/** Variations sold in the tee's white and black, by size. */
const PICKED = {
	'31': { ...personalised('S', { front: FRONT }), variant: 'v_tee_white' },
	'32': { ...personalised('L', { front: FRONT }), variant: 'v_tee_black' },
};

/** Fills the form's variation field from its size, as WooCommerce does
 *  once the attributes name a variation. */
function findVariations(form: HTMLFormElement, variations: object): void {
	const size = form.querySelector<HTMLSelectElement>('[name="attribute_size"]');
	const field = form.querySelector<HTMLInputElement>('[name="variation_id"]');
	size?.addEventListener('change', () => {
		const found = Object.entries(variations).find(
			([, variation]) =>
				(variation as { attributes: Record<string, string> }).attributes
					.attribute_size === size.value,
		);
		if (field !== null) {
			field.value = found?.[0] ?? '0';
		}
	});
}

function start({
	sdk = fakeSdk(),
	loadSdk = (): Promise<PixonautSdk> => Promise.resolve(sdk.sdk),
	mounts = MOUNTED as object,
	variations = VARIATIONS as object,
	checkbox = false,
	before = (_form: HTMLFormElement): void => undefined,
	readsAhead = undefined as ReadsAhead | undefined,
} = {}) {
	const page = productPage(mounts, { checkbox, variations });
	before(page.form);
	const report = vi.fn();
	const designer = new ProductDesigner(page.container, {
		loadSdk,
		label: (key) => `label:${key}`,
		report,
		...pageDeps(),
		...(readsAhead === undefined ? {} : { readsAhead }),
	});
	designer.start();
	return { ...page, ...sdk, sdkDesigner: sdk.designer, report, designer };
}

/** Puts the variation in the form, as WooCommerce does once its
 *  attributes are chosen, and lets the designer follow. */
async function choose(form: HTMLFormElement, id: string): Promise<void> {
	const field = form.querySelector<HTMLInputElement>('[name="variation_id"]');
	if (field === null) {
		throw new Error('The form has no variation field.');
	}
	field.value = id;
	form.dispatchEvent(new Event('change', { bubbles: true }));
	await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
	await settle();
}

/** The designs each submit no handler stopped would send, by side. */
function submitted(form: HTMLFormElement): Array<Record<string, string>> {
	const sent: Array<Record<string, string>> = [];
	const listener = (event: Event) => {
		if (event.target === form && !event.defaultPrevented) {
			const data = new FormData(form);
			sent.push({
				front: String(data.get('pixonaut_designs[front]')),
				back: String(data.get('pixonaut_designs[back]')),
			});
			event.preventDefault();
		}
	};
	window.addEventListener('submit', listener);
	listeners.push(() => window.removeEventListener('submit', listener));
	return sent;
}

/** Views with a photo, as `getViews()` lists them. */
function views(...ids: string[]) {
	return ids.map((viewId) => ({ viewId, hasPhoto: true }));
}

const listeners: Array<() => void> = [];

function options(mount: ReturnType<typeof vi.fn>, call = 0) {
	return mount.mock.calls[call]?.[0] as Record<string, unknown>;
}

describe('ProductDesigner on a variable product', () => {
	beforeEach(() => {
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
	});

	afterEach(() => {
		for (const remove of listeners.splice(0)) {
			remove();
		}
		vi.restoreAllMocks();
	});

	it('draws as swatches only the variation fields it lends, and takes them away when it gives them back', async () => {
		const arranged = {
			front: {
				...MOUNTED.front,
				formGroups: {
					groups: [{ id: 'text', items: [{ attribute: 'size' }] }],
				},
			},
		};
		const drawn = async (mounts: object) => {
			const page = productPage(mounts, { variations: VARIATIONS });
			const removed = vi.fn();
			const attributeSwatches = vi.fn(() => removed);
			const sdk = fakeSdk();
			new ProductDesigner(page.container, {
				loadSdk: () => Promise.resolve(sdk.sdk),
				label: (key) => `label:${key}`,
				report: vi.fn(),
				attributeSwatches,
				...pageDeps(),
			}).start();
			await settle();
			return {
				...page,
				attributeSwatches,
				removed,
				mounted: options(sdk.mount),
			};
		};

		// Arranged: the lent row alone, not the rest of the form's fields.
		const grouped = await drawn(arranged);
		const [group] = (
			grouped.mounted.formGroups as {
				groups: Array<{ items: Array<{ element?: HTMLElement }> }>;
			}
		).groups;
		expect(grouped.attributeSwatches).toHaveBeenCalledOnce();
		expect(grouped.attributeSwatches).toHaveBeenCalledWith(
			group?.items[0]?.element,
		);

		const first = await drawn(MOUNTED);
		expect(first.attributeSwatches).toHaveBeenCalledOnce();
		expect(first.attributeSwatches).toHaveBeenCalledWith(
			(first.mounted.optionsStep as { content: HTMLElement }).content,
		);
		// A variation bought without a design takes the fields back as the
		// theme rendered them; the next one personalised lends them again.
		await choose(first.form, '15');
		expect(first.removed).toHaveBeenCalledOnce();
		await choose(first.form, '11');
		expect(first.attributeSwatches).toHaveBeenCalledTimes(2);
	});

	it('places the size where the arrangement puts it, and marks it complete once chosen', async () => {
		const arranged = {
			front: {
				...MOUNTED.front,
				formGroups: {
					groups: [
						{
							id: 'text',
							items: [{ attribute: 'size' }, { field: 'slogan:content' }],
						},
					],
				},
			},
		};
		const { form, mount, sdkDesigner } = start({ mounts: arranged });
		await settle();

		const mounted = options(mount);
		expect(mounted).not.toHaveProperty('optionsStep');
		expect(mounted.lastStepContent).toBeInstanceOf(HTMLElement);
		const [group] = (
			mounted.formGroups as {
				groups: Array<{
					items: Array<{ host?: string; element?: HTMLElement }>;
				}>;
			}
		).groups;
		expect(group?.items[0]?.host).toBe('attribute_size');
		const size = group?.items[0]?.element?.querySelector('select');
		expect(size?.name).toBe('attribute_size');
		expect(group?.items[1]).toEqual({ field: 'slogan:content' });
		expect(sdkDesigner.setHostItemComplete).toHaveBeenLastCalledWith(
			'attribute_size',
			false,
		);

		if (size) {
			size.value = 'S';
		}
		size?.dispatchEvent(new Event('change', { bubbles: true }));
		await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
		await settle();

		expect(form.contains(size ?? null)).toBe(true);
		expect(sdkDesigner.setHostItemComplete).toHaveBeenLastCalledWith(
			'attribute_size',
			true,
		);
	});

	it('hides the group of an attribute that stops applying in place, and shows it again once it applies, without mounting again', async () => {
		const arranged = {
			front: {
				...MOUNTED.front,
				formGroups: {
					groups: [
						{ id: 'text', items: [{ field: 'slogan:content' }] },
						{
							id: 'wc-option-size',
							title: 'Size',
							items: [{ attribute: 'size' }],
						},
					],
				},
			},
		};
		const { form, mount, sdkDesigner } = start({ mounts: arranged });
		await settle();
		const groupIds = (call: number) =>
			(
				options(mount, call).formGroups as { groups: Array<{ id: string }> }
			).groups.map((group) => group.id);
		expect(groupIds(0)).toEqual(['text', 'wc-option-size']);
		const row = form
			.querySelector('select[name="attribute_size"]')
			?.closest('tr');

		row?.setAttribute(NOT_APPLICABLE_ATTRIBUTE, '');
		// In the same task: the designer is told before the next paint.
		await Promise.resolve();

		expect(sdkDesigner.setHostItemHidden).toHaveBeenLastCalledWith(
			'attribute_size',
			true,
		);
		await settle();
		expect(mount).toHaveBeenCalledOnce();
		expect(sdkDesigner.destroy).not.toHaveBeenCalled();
		expect(form.contains(row ?? null)).toBe(true);

		// Marked again as the form settles: nothing changed.
		sdkDesigner.setHostItemHidden.mockClear();
		row?.setAttribute(NOT_APPLICABLE_ATTRIBUTE, '');
		await settle();
		expect(sdkDesigner.setHostItemHidden).not.toHaveBeenCalled();

		row?.removeAttribute(NOT_APPLICABLE_ATTRIBUTE);
		await settle();

		expect(sdkDesigner.setHostItemHidden).toHaveBeenLastCalledWith(
			'attribute_size',
			false,
		);
		expect(mount).toHaveBeenCalledOnce();
	});

	/** The front with the size in a group of its own, after the template's. */
	const SIZE_GROUP = {
		groups: [
			{ id: 'text', items: [{ field: 'slogan:content' }] },
			{ id: 'wc-option-size', title: 'Size', items: [{ attribute: 'size' }] },
		],
	};

	const sizeRow = (form: HTMLFormElement) =>
		form.querySelector('select[name="attribute_size"]')?.closest('tr');

	it('tells a side that mounted an attribute stopped applying while it mounted', async () => {
		const sdk = fakeSdk();
		let finish: () => void = () => undefined;
		sdk.mount.mockImplementationOnce(
			() =>
				new Promise((resolve) => {
					finish = () => resolve(sdk.designer);
				}),
		);
		const { form, mount } = start({
			sdk,
			mounts: { front: { ...MOUNTED.front, formGroups: SIZE_GROUP } },
		});
		await settle();
		expect(mount).toHaveBeenCalledOnce();

		sizeRow(form)?.setAttribute(NOT_APPLICABLE_ATTRIBUTE, '');
		await settle();
		finish();
		await settle();

		expect(mount).toHaveBeenCalledOnce();
		expect(sdk.designer.setHostItemHidden).toHaveBeenLastCalledWith(
			'attribute_size',
			true,
		);
	});

	it('lends an attribute that does not apply as it mounts hidden', async () => {
		const { form, mount } = start({
			mounts: { front: { ...MOUNTED.front, formGroups: SIZE_GROUP } },
			before: (shop) =>
				sizeRow(shop)?.setAttribute(NOT_APPLICABLE_ATTRIBUTE, ''),
		});
		await settle();

		const size = (
			options(mount).formGroups as {
				groups: Array<{ items: Array<{ host?: string; hidden?: boolean }> }>;
			}
		).groups[1]?.items[0];
		expect(size).toMatchObject({ host: 'attribute_size', hidden: true });
		expect(form.contains(sizeRow(form) ?? null)).toBe(true);
	});

	it('has the attributes that no longer apply hidden before it reads a choice', async () => {
		const sdk = fakeSdk();
		sdk.mount
			.mockResolvedValueOnce(sdk.designer)
			.mockResolvedValue(fakeDesigner());
		const { form, mount } = start({ sdk });
		await settle();
		// What the form holds as the attributes are hidden: the choice, and
		// the designers mounted.
		const asked: Array<{ variation: string; mounted: number }> = [];
		form.addEventListener(APPLY_CHOICES_EVENT, () =>
			asked.push({
				variation: (
					form.querySelector('[name="variation_id"]') as HTMLInputElement
				).value,
				mounted: mount.mock.calls.length,
			}),
		);

		await choose(form, '13');

		expect(asked[0]).toEqual({ variation: '13', mounted: 1 });
		expect(mount).toHaveBeenCalledTimes(2);
	});

	it('leaves the sides of a product with several mounted, which are lent no attribute', async () => {
		const side = { ...MOUNTED.front, formGroups: SIZE_GROUP };
		const { form, mount } = start({
			mounts: { front: side, back: { ...side, placementId: 'back' } },
		});
		await settle();
		const mounted = mount.mock.calls.length;

		sizeRow(form)?.setAttribute(NOT_APPLICABLE_ATTRIBUTE, '');
		await settle();
		sizeRow(form)?.setAttribute(NOT_APPLICABLE_ATTRIBUTE, '');
		await settle();

		expect(mount).toHaveBeenCalledTimes(mounted);
	});

	it('mounts each variation with its own arrangement, or none', async () => {
		const groups = {
			groups: [
				{ id: 'text', items: [{ field: 'slogan:content', label: 'Name' }] },
			],
		};
		const variations = {
			'41': personalised('S', { front: { ...FRONT, formGroups: groups } }),
			'42': personalised('L', { front: FRONT }),
		};
		const { form, mount } = start({
			mounts: { front: { ...MOUNTED.front, formGroups: groups } },
			variations,
		});
		await settle();
		expect(options(mount).formGroups).toEqual(groups);

		await choose(form, '42');
		expect(mount).toHaveBeenCalledTimes(2);
		expect(options(mount, 1)).not.toHaveProperty('formGroups');

		await choose(form, '41');
		expect(mount).toHaveBeenCalledTimes(3);
		expect(options(mount, 2).formGroups).toEqual(groups);
	});

	it('mounts once with the menu of every variation, and no variant before one is chosen', async () => {
		const { mount, sdkDesigner } = start();
		await settle();

		expect(mount).toHaveBeenCalledOnce();
		expect(options(mount)).toMatchObject({
			printSizes: MOUNTED.front.printSizes,
		});
		expect(sdkDesigner.setVariant).not.toHaveBeenCalled();
	});

	it('switches only the print size between variations that differ by size', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();

		await choose(form, '11');

		expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(1);
		expect(sdkDesigner.setVariant).not.toHaveBeenCalled();
		expect(mount).toHaveBeenCalledOnce();
	});

	it('shows the variation’s variant without mounting again, once', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();

		await choose(form, '12');
		await choose(form, '11');
		await choose(form, '12');

		expect(sdkDesigner.setVariant).toHaveBeenCalledExactlyOnceWith(
			'v_tee_dark',
		);
		expect(mount).toHaveBeenCalledOnce();
	});

	it('mounts the side again on the customer’s values for another blank, showing its variant', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(hoodie);
		const { form, mount, sdkDesigner } = start({ sdk });
		await settle();
		const values = { headline: { text: 'Hello' } };
		sdkDesigner.getState.mockReturnValue({ templateId: 'mini-tee@2', values });

		await choose(form, '13');

		expect(sdkDesigner.destroy).toHaveBeenCalledOnce();
		expect(mount).toHaveBeenCalledTimes(2);
		expect(options(mount, 1)).toMatchObject({
			templateId: 'mini-tee@2',
			blankId: 'blank_hoodie',
			placementId: 'front',
			initialVariant: 'v_hoodie',
			initialValues: values,
		});
		expect(hoodie.setVariant).not.toHaveBeenCalled();
	});

	it('mounts a side on another blank from the reads asked ahead for it, once the page is shown', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		const createDesigner = vi.fn().mockResolvedValue(hoodie);
		Object.assign(sdk.sdk, {
			version: SPLIT_SDK,
			createDesigner,
			designerUi: {},
		});
		const template = { status: 'live', id: 'mini-tee@2' };
		const fetcher = vi.fn(
			async () =>
				new Response(
					JSON.stringify({
						targets: [
							{
								templateId: 'mini-tee@2',
								blankId: 'blank_hoodie',
								placementId: 'front',
								reads: { template: 0 },
							},
						],
						parts: [template],
					}),
				),
		);
		// The page falls idle at once.
		vi.stubGlobal('requestIdleCallback', (run: () => void) =>
			setTimeout(run, 0),
		);
		const readsAhead = new ReadsAhead('https://shop.test/reads', 7, fetcher);
		const { form, mount } = start({ sdk, readsAhead });
		await settle();
		// Asked once the first side is shown, when the page is idle.
		await settle();
		vi.unstubAllGlobals();
		expect(fetcher).toHaveBeenCalledOnce();

		await choose(form, '13');

		expect(mount).toHaveBeenCalledOnce();
		expect(createDesigner).toHaveBeenCalledWith(
			expect.objectContaining({ blankId: 'blank_hoodie', template }),
		);
	});

	it('mounts the side again on the view the customer was on', async () => {
		const sdk = fakeSdk();
		sdk.mount
			.mockResolvedValueOnce(sdk.designer)
			.mockResolvedValue(fakeDesigner());
		const { form, mount, sdkDesigner } = start({ sdk });
		await settle();
		sdkDesigner.getView.mockReturnValue('scn_wall');
		sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

		await choose(form, '13');

		expect(options(mount, 1)).toMatchObject({
			blankId: 'blank_hoodie',
			initialView: 'scn_wall',
			initialViewIndex: 1,
		});
	});

	it('mounts the side again on the variation’s own starting view when its views leave out the one the customer was on', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		hoodie.getView.mockReturnValue('own_b');
		hoodie.getViews.mockReturnValue(views('own_a', 'own_b'));
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(hoodie);
		const { form, mount, sdkDesigner } = start({
			sdk,
			variations: {
				'41': personalised('S', { front: FRONT }),
				'42': personalised('L', {
					front: {
						...FRONT,
						blankId: 'blank_hoodie',
						views: ['own_a', 'own_b'],
						initialView: 'own_b',
					},
				}),
			},
		});
		await settle();
		sdkDesigner.getView.mockReturnValue('scn_wall');
		sdkDesigner.getViews.mockReturnValue(views('scn_wall'));

		await choose(form, '42');

		expect(options(mount, 1)).toMatchObject({ initialView: 'own_b' });
		expect(options(mount, 1)).not.toHaveProperty('initialViewIndex');
		expect(hoodie.setView).not.toHaveBeenCalled();
	});

	it('mounts the side again with the place of the view the customer was on, and corrects no view after it, when the other blank lacks that view', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		hoodie.getView.mockReturnValue('own_a');
		hoodie.getViews.mockReturnValue(views('own_a', 'own_b'));
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(hoodie);
		const { form, mount, sdkDesigner } = start({ sdk });
		await settle();
		sdkDesigner.getView.mockReturnValue('scn_wall');
		sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

		await choose(form, '13');

		expect(options(mount, 1)).toMatchObject({
			blankId: 'blank_hoodie',
			initialViewIndex: 1,
		});
		expect(hoodie.setView).not.toHaveBeenCalled();
	});

	it('passes the place again on a mount tried again after one that failed', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		sdk.mount
			.mockResolvedValueOnce(sdk.designer)
			.mockRejectedValueOnce(requestError(503, 'unavailable'))
			.mockResolvedValue(hoodie);
		const { form, mount, designer, sdkDesigner } = start({ sdk });
		await settle();
		sdkDesigner.getView.mockReturnValue('scn_wall');
		sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

		await choose(form, '13');
		await designer.mount();

		expect(mount).toHaveBeenCalledTimes(3);
		expect(options(mount, 2)).toMatchObject({ initialViewIndex: 1 });
		expect(hoodie.setView).not.toHaveBeenCalled();
	});

	it('passes the place alone when the side’s views leave out the view the customer was on', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(hoodie);
		const { form, mount, sdkDesigner } = start({
			sdk,
			variations: {
				'41': personalised('S', { front: FRONT }),
				'42': personalised('L', {
					front: {
						...FRONT,
						blankId: 'blank_hoodie',
						views: ['own_a', 'own_b'],
					},
				}),
			},
		});
		await settle();
		sdkDesigner.getView.mockReturnValue('scn_wall');
		sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

		await choose(form, '42');

		expect(options(mount, 1)).toMatchObject({ initialViewIndex: 1 });
		expect(options(mount, 1)).not.toHaveProperty('initialView');
		expect(hoodie.setView).not.toHaveBeenCalled();
	});

	it('mounts the side again on the view the customer was on when its views are every view', async () => {
		const sdk = fakeSdk();
		sdk.mount
			.mockResolvedValueOnce(sdk.designer)
			.mockResolvedValue(fakeDesigner());
		const { form, mount, sdkDesigner } = start({
			sdk,
			variations: {
				'41': personalised('S', { front: FRONT }),
				'42': personalised('L', {
					front: { ...FRONT, blankId: 'blank_hoodie', views: [] },
				}),
			},
		});
		await settle();
		sdkDesigner.getView.mockReturnValue('scn_wall');
		sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

		await choose(form, '42');

		expect(options(mount, 1)).toMatchObject({
			initialView: 'scn_wall',
			initialViewIndex: 1,
		});
	});

	describe('a variation that names its starting view and shows every view', () => {
		const variations = {
			'41': personalised('S', { front: FRONT }),
			'42': personalised('L', {
				front: {
					...FRONT,
					blankId: 'blank_hoodie',
					views: [],
					initialView: 'scn_studio',
				},
			}),
		};

		it('opens on its own view, then shows the view the customer was on where its blank has it', async () => {
			const sdk = fakeSdk();
			const hoodie = fakeDesigner();
			hoodie.getView.mockReturnValue('scn_studio');
			hoodie.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));
			sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(hoodie);
			const { form, mount, sdkDesigner } = start({ sdk, variations });
			await settle();
			sdkDesigner.getView.mockReturnValue('scn_wall');
			sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

			await choose(form, '42');

			expect(options(mount, 1)).toMatchObject({ initialView: 'scn_studio' });
			expect(options(mount, 1)).not.toHaveProperty('initialViewIndex');
			expect(hoodie.setView).toHaveBeenCalledExactlyOnceWith('scn_wall');
		});

		it('stays on its own view on a blank without the view the customer was on', async () => {
			const sdk = fakeSdk();
			const hoodie = fakeDesigner();
			hoodie.getView.mockReturnValue('scn_studio');
			hoodie.getViews.mockReturnValue(views('scn_studio', 'own_b'));
			sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(hoodie);
			const { form, mount, sdkDesigner } = start({ sdk, variations });
			await settle();
			sdkDesigner.getView.mockReturnValue('scn_wall');
			sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

			await choose(form, '42');

			expect(options(mount, 1)).toMatchObject({ initialView: 'scn_studio' });
			expect(hoodie.setView).not.toHaveBeenCalled();
		});
	});

	it('names no place when the view the customer was on is not among the views with a photo', async () => {
		const sdk = fakeSdk();
		sdk.mount
			.mockResolvedValueOnce(sdk.designer)
			.mockResolvedValue(fakeDesigner());
		const { form, mount, sdkDesigner } = start({ sdk });
		await settle();
		sdkDesigner.getView.mockReturnValue('design');
		sdkDesigner.getViews.mockReturnValue(views('scn_studio', 'scn_wall'));

		await choose(form, '13');

		expect(options(mount, 1)).toMatchObject({ initialView: 'design' });
		expect(options(mount, 1)).not.toHaveProperty('initialViewIndex');
	});

	it('shows a variation chosen while the designer mounted only once it is on that variation’s blank', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		let mounted: (designer: typeof sdk.designer) => void = () => undefined;
		sdk.mount
			.mockReturnValueOnce(
				new Promise((resolve) => {
					mounted = resolve;
				}),
			)
			.mockResolvedValue(hoodie);
		const { form, container, sdkDesigner } = start({ sdk });

		// The mount finishes once the form holds the variation.
		setTimeout(() => mounted(sdkDesigner), VARIATION_SETTLE_MS + 1);
		await choose(form, '13');
		await settle();

		expect(sdkDesigner.setVariant).not.toHaveBeenCalled();
		expect(options(sdk.mount, 1)).toMatchObject({ initialVariant: 'v_hoodie' });
		expect(hoodie.setVariant).not.toHaveBeenCalled();
		expect(container.classList.contains('is-unavailable')).toBe(false);
	});

	it('mounts the side again on the customer’s values for other views or placements', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();
		const values = { headline: { text: 'Hello' } };
		sdkDesigner.getState.mockReturnValue({ templateId: 'mini-tee@2', values });

		await choose(form, '16');
		await choose(form, '17');

		expect(mount).toHaveBeenCalledTimes(3);
		expect(options(mount, 1)).toMatchObject({
			views: ['front_flat'],
			initialValues: values,
		});
		expect(options(mount, 1)).not.toHaveProperty('placements');
		expect(options(mount, 2)).toMatchObject({
			placements: [{ placementId: 'front' }, { placementId: 'sleeve' }],
			initialValues: values,
		});
		expect(options(mount, 2)).not.toHaveProperty('views');
	});

	it('mounts the side again on the customer’s values for another form layout, and drops it for the template’s', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();
		const values = { headline: { text: 'Hello' } };
		sdkDesigner.getState.mockReturnValue({ templateId: 'mini-tee@2', values });

		await choose(form, '18');
		await choose(form, '12');

		expect(mount).toHaveBeenCalledTimes(3);
		expect(options(mount, 1)).toMatchObject({
			layout: 'steps',
			initialValues: values,
		});
		expect(options(mount, 2)).not.toHaveProperty('layout');
	});

	it('mounts the side again on the customer’s values for another canvas, and drops it for the template’s', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();
		const values = { headline: { text: 'Hello' } };
		sdkDesigner.getState.mockReturnValue({ templateId: 'mini-tee@2', values });

		await choose(form, '20');
		await choose(form, '12');

		expect(mount).toHaveBeenCalledTimes(3);
		expect(options(mount, 1)).toMatchObject({
			drawAtTemplateRatio: true,
			initialValues: values,
		});
		expect(options(mount, 2)).not.toHaveProperty('drawAtTemplateRatio');
	});

	it('mounts the side again on the customer’s values for another value set, and drops it for the template’s own', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();
		const values = { headline: { text: 'Hello' } };
		sdkDesigner.getState.mockReturnValue({ templateId: 'mini-tee@2', values });

		await choose(form, '29');
		await choose(form, '30');
		await choose(form, '12');

		expect(mount).toHaveBeenCalledTimes(4);
		expect(options(mount, 1)).toMatchObject({
			valueSets: { 'mini-tee': FARMOR },
			initialValues: values,
		});
		expect(options(mount, 2)).toMatchObject({
			valueSets: { 'mini-tee': MORMOR },
			initialValues: values,
		});
		expect(options(mount, 2)).toHaveProperty('templateId', 'mini-tee@2');
		expect(options(mount, 3)).not.toHaveProperty('valueSets');
	});

	it('keeps the side mounted on its value set for a variation on the same set', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();

		await choose(form, '30');
		await choose(form, '33');

		expect(mount).toHaveBeenCalledTimes(2);
		expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(1);
	});

	it('switches the template in the mounted designer for another template', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();

		await choose(form, '14');

		expect(sdkDesigner.setTemplate).toHaveBeenCalledExactlyOnceWith(
			'mini-poster@1',
		);
		expect(sdkDesigner.destroy).not.toHaveBeenCalled();
		expect(sdkDesigner.setPrintSize).not.toHaveBeenCalled();
		expect(mount).toHaveBeenCalledOnce();
	});

	it('switches the print size once the template is switched', async () => {
		const { form, sdkDesigner } = start();
		await settle();

		await choose(form, '28');

		expect(sdkDesigner.setTemplate).toHaveBeenCalledExactlyOnceWith(
			'mini-poster@1',
		);
		expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(1);
		expect(sdkDesigner.setTemplate.mock.invocationCallOrder[0]).toBeLessThan(
			sdkDesigner.setPrintSize.mock.invocationCallOrder[0] ?? 0,
		);
	});

	it('switches the template back after a size switch that failed once the template had switched', async () => {
		const { form, sdkDesigner } = start();
		await settle();
		sdkDesigner.setPrintSize.mockImplementationOnce(() => {
			throw new Error('The size could not be drawn.');
		});
		await choose(form, '28');

		await choose(form, '11');

		expect(sdkDesigner.setTemplate.mock.calls).toEqual([
			['mini-poster@1'],
			['mini-tee@2'],
		]);
	});

	it('mounts the side again on the customer’s values when the template cannot be switched in place', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();
		const values = { headline: { text: 'Hello' } };
		sdkDesigner.getState.mockReturnValue({ templateId: 'mini-tee@2', values });
		sdkDesigner.setTemplate.mockRejectedValueOnce(
			requestError(503, 'unavailable'),
		);

		await choose(form, '14');

		expect(sdkDesigner.destroy).toHaveBeenCalledOnce();
		expect(mount).toHaveBeenCalledTimes(2);
		expect(options(mount, 1)).toMatchObject({
			templateId: 'mini-poster@1',
			blankId: 'blank_tee',
			placementId: 'front',
			initialValues: values,
		});
	});

	it('carries a value without a field key to the switched template by layer id', async () => {
		const { form, sdkDesigner } = start();
		await settle();
		sdkDesigner.getState.mockReturnValue({
			templateId: 'mini-tee@2',
			values: { slogan: { content: 'Typed' } },
		});
		sdkDesigner.setTemplate.mockImplementation(() => {
			sdkDesigner.getState.mockReturnValue({
				templateId: 'mini-poster@1',
				values: {},
			});
			sdkDesigner.getFormSchema.mockReturnValue({
				steps: [{ fields: [{ layerId: 'slogan', prop: 'content' }] }],
			});
			return Promise.resolve();
		});

		await choose(form, '14');

		expect(sdkDesigner.setValue).toHaveBeenCalledExactlyOnceWith(
			'slogan',
			'content',
			'Typed',
		);
	});

	it('keeps what the designer showed in its place until the side mounted again draws it', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		let mounted = (_designer: unknown): void => undefined;
		let shown = (): void => undefined;
		hoodie.shown.mockReturnValue(
			new Promise<void>((resolve) => {
				shown = resolve;
			}),
		);
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockReturnValue(
			new Promise((resolve) => {
				mounted = resolve;
			}),
		);
		const { form, container } = start({ sdk });
		await settle();
		const target = container.querySelector(
			'#pixonaut-form-front',
		) as HTMLElement;
		const drawnForm = target.innerHTML;
		const row = target.querySelector('.pixonaut-cart-row') as HTMLElement;

		await choose(form, '13');

		// Mounting again: the old form's copy stands in, the row at home hidden.
		expect(target.innerHTML).not.toBe('');
		expect((target.firstElementChild as HTMLElement).inert).toBe(true);
		expect(target.textContent).toBe(
			Object.assign(document.createElement('div'), { innerHTML: drawnForm })
				.textContent,
		);
		expect(row.style.display).toBe('none');
		mounted(hoodie);
		await settle();
		// The new designer's form holds the row: it shows there at once,
		// while the preview waits.
		expect(target.contains(row)).toBe(true);
		expect(row.style.display).toBe('');
		shown();
		await settle();
		expect(row.style.display).toBe('');
		expect(target.contains(row)).toBe(true);
	});

	it('gives the form’s parts back visible when a variation bought without a design is chosen before the side mounted again is shown', async () => {
		const sdk = fakeSdk();
		const hoodie = fakeDesigner();
		hoodie.shown.mockReturnValue(new Promise<void>(() => undefined));
		sdk.mount.mockResolvedValueOnce(sdk.designer).mockResolvedValue(hoodie);
		const { form, container } = start({ sdk });
		await settle();
		const row = container.querySelector('.pixonaut-cart-row') as HTMLElement;
		await choose(form, '13');

		await choose(form, '15');

		expect(hoodie.destroy).toHaveBeenCalledOnce();
		expect(row.style.display).toBe('');
		expect(form.querySelector('[inert]')).toBeNull();
	});

	it('approves again when a switch changed the template under the proof', async () => {
		const { form, button, sdkDesigner } = start();
		await settle();
		const sent = submitted(form);
		sdkDesigner.approve
			.mockRejectedValueOnce(new TemplateChangedError())
			.mockResolvedValue(PROOF);
		await choose(form, '14');

		button.click();
		await settle();

		expect(sdkDesigner.approve).toHaveBeenCalledTimes(2);
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('keeps the frame in the template’s shape on a variation on no blank, and gives the printed one back on a blank', async () => {
		const { form } = start();
		const preview = document.querySelector<HTMLElement>(
			'#pixonaut-preview-front',
		);
		const frame = document.createElement('div');
		frame.className = 'pixonaut-preview__frame';
		frame.setAttribute(
			'style',
			'--pixonaut-preview-ratio: 1000 / 1000; --pixonaut-preview-aspect: 1',
		);
		preview?.before(frame);
		if (preview) {
			frame.append(preview);
		}
		await settle();

		await choose(form, '19');

		expect(frame.dataset.pixonautShape).toBe('template');
		expect(frame.style.getPropertyValue('--pixonaut-preview-ratio')).toBe(
			`${SMALL.widthMm} / ${SMALL.heightMm}`,
		);

		await choose(form, '12');

		expect(frame.dataset.pixonautShape).toBeUndefined();
		expect(frame.style.getPropertyValue('--pixonaut-preview-ratio')).toBe(
			'1000 / 1000',
		);
		expect(frame.style.getPropertyValue('--pixonaut-preview-aspect')).toBe('1');
	});

	it('keeps the frame in the shape the page printed on a variation on no blank whose canvas keeps the template’s ratio', async () => {
		const { form } = start();
		const preview = document.querySelector<HTMLElement>(
			'#pixonaut-preview-front',
		);
		const frame = document.createElement('div');
		frame.className = 'pixonaut-preview__frame';
		frame.dataset.pixonautShape = 'template';
		frame.setAttribute(
			'style',
			'--pixonaut-preview-ratio: 5 / 7; --pixonaut-preview-aspect: 0.714',
		);
		preview?.before(frame);
		if (preview) {
			frame.append(preview);
		}
		await settle();

		await choose(form, '23');

		expect(frame.dataset.pixonautShape).toBe('template');
		expect(frame.style.getPropertyValue('--pixonaut-preview-ratio')).toBe(
			'5 / 7',
		);
	});

	for (const [after, printedShape, variation] of [
		['another template', 'template', '24'],
		['a blank', undefined, '23'],
	] as const) {
		it(`gives the frame the template’s shape at the size’s ratio on a variation on no blank after ${after}, whose canvas keeps the template’s ratio`, async () => {
			const { form } = start();
			const preview = document.querySelector<HTMLElement>(
				'#pixonaut-preview-front',
			);
			const frame = document.createElement('div');
			frame.className = 'pixonaut-preview__frame';
			if (printedShape !== undefined) {
				frame.dataset.pixonautShape = printedShape;
			}
			frame.setAttribute(
				'style',
				'--pixonaut-preview-ratio: 5 / 7; --pixonaut-preview-aspect: 0.714',
			);
			preview?.before(frame);
			if (preview) {
				frame.append(preview);
			}
			await settle();

			await choose(form, variation);

			expect(frame.dataset.pixonautShape).toBe('template');
			expect(frame.style.getPropertyValue('--pixonaut-preview-ratio')).toBe(
				`${SMALL.widthMm} / ${SMALL.heightMm}`,
			);
		});
	}

	describe('a print size switched in place on no blank', () => {
		/** The front's preview in a frame printed in `style`, `shape` too. */
		function framed(style: string, shape?: string): HTMLElement {
			const preview = document.querySelector<HTMLElement>(
				'#pixonaut-preview-front',
			);
			const frame = document.createElement('div');
			frame.className = 'pixonaut-preview__frame';
			if (shape !== undefined) {
				frame.dataset.pixonautShape = shape;
			}
			frame.setAttribute('style', style);
			preview?.before(frame);
			if (preview) {
				frame.append(preview);
			}
			return frame;
		}

		/** The SDK's preview image in the front's preview, drawn at the
		 *  size `drawn` holds; `draw()` lands a picture of that size. */
		function previewImage(drawn: { width: number; height: number }) {
			const image = document.createElement('img');
			Object.defineProperty(image, 'naturalWidth', {
				get: () => drawn.width,
			});
			Object.defineProperty(image, 'naturalHeight', {
				get: () => drawn.height,
			});
			document.querySelector('#pixonaut-preview-front')?.append(image);
			return { draw: () => image.dispatchEvent(new Event('load')) };
		}

		const ratio = (frame: HTMLElement) =>
			frame.style.getPropertyValue('--pixonaut-preview-ratio');

		it('keeps the frame’s shape until the new size is drawn, then takes it', async () => {
			const { form, sdkDesigner } = start();
			const frame = framed(
				'--pixonaut-preview-ratio: 1000 / 1000; --pixonaut-preview-aspect: 1',
			);
			await settle();
			await choose(form, '19');
			expect(ratio(frame)).toBe(`${SMALL.widthMm} / ${SMALL.heightMm}`);
			const drawn = { width: SMALL.widthMm, height: SMALL.heightMm };
			const image = previewImage(drawn);

			await choose(form, '25');

			expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(0);
			expect(ratio(frame)).toBe(`${SMALL.widthMm} / ${SMALL.heightMm}`);
			// Another drawing of the old size, still landing.
			image.draw();
			expect(ratio(frame)).toBe(`${SMALL.widthMm} / ${SMALL.heightMm}`);

			const [size] = MOUNTS.front.printSizes;
			drawn.width = size?.widthMm ?? 0;
			drawn.height = size?.heightMm ?? 0;
			image.draw();

			expect(ratio(frame)).toBe(`${size?.widthMm} / ${size?.heightMm}`);
			expect(frame.dataset.pixonautShape).toBe('template');
		});

		it('takes the new shape in the frame painted with the new size, before the image tells it loaded', async () => {
			const { form } = start();
			const frame = framed(
				'--pixonaut-preview-ratio: 1000 / 1000; --pixonaut-preview-aspect: 1',
			);
			await settle();
			await choose(form, '19');
			const drawn = { width: SMALL.widthMm, height: SMALL.heightMm };
			previewImage(drawn);

			await choose(form, '25');
			const [size] = MOUNTS.front.printSizes;
			drawn.width = size?.widthMm ?? 0;
			drawn.height = size?.heightMm ?? 0;
			await new Promise((resolve) => requestAnimationFrame(resolve));

			expect(ratio(frame)).toBe(`${size?.widthMm} / ${size?.heightMm}`);
		});

		it('keeps waiting for the latest size when the one switched from since lands first', async () => {
			const wide = { widthMm: 400, heightMm: 270, label: 'Wide' };
			const { form } = start({
				mounts: {
					front: {
						...MOUNTED.front,
						printSizes: [...MOUNTED.front.printSizes, wide],
					},
				},
			});
			const frame = framed(
				'--pixonaut-preview-ratio: 1000 / 1000; --pixonaut-preview-aspect: 1',
			);
			await settle();
			await choose(form, '19');
			const drawn = { width: SMALL.widthMm, height: SMALL.heightMm };
			const image = previewImage(drawn);

			await choose(form, '25');
			await choose(form, '27');
			const [size] = MOUNTS.front.printSizes;
			drawn.width = size?.widthMm ?? 0;
			drawn.height = size?.heightMm ?? 0;
			image.draw();

			expect(ratio(frame)).toBe(`${SMALL.widthMm} / ${SMALL.heightMm}`);

			drawn.width = wide.widthMm;
			drawn.height = wide.heightMm;
			image.draw();

			expect(ratio(frame)).toBe(`${wide.widthMm} / ${wide.heightMm}`);
		});

		it('stops waiting for a size once its designer is gone', async () => {
			const { form } = start();
			const frame = framed(
				'--pixonaut-preview-ratio: 1000 / 1000; --pixonaut-preview-aspect: 1',
			);
			await settle();
			await choose(form, '19');
			const drawn = { width: SMALL.widthMm, height: SMALL.heightMm };
			const image = previewImage(drawn);

			await choose(form, '25');
			// Bought without a design: the designer is let go.
			await choose(form, '15');
			const [size] = MOUNTS.front.printSizes;
			drawn.width = size?.widthMm ?? 0;
			drawn.height = size?.heightMm ?? 0;
			image.draw();
			await new Promise((resolve) => requestAnimationFrame(resolve));

			expect(ratio(frame)).toBe(`${SMALL.widthMm} / ${SMALL.heightMm}`);
		});

		it('keeps the old frame when the switch fails', async () => {
			const { form, sdkDesigner } = start();
			const frame = framed(
				'--pixonaut-preview-ratio: 1000 / 1000; --pixonaut-preview-aspect: 1',
			);
			await settle();
			await choose(form, '19');
			const drawn = { width: SMALL.widthMm, height: SMALL.heightMm };
			const image = previewImage(drawn);
			sdkDesigner.setPrintSize.mockImplementationOnce(() => {
				throw new Error('The size could not be drawn.');
			});

			await choose(form, '25');
			drawn.width = 1;
			image.draw();

			expect(ratio(frame)).toBe(`${SMALL.widthMm} / ${SMALL.heightMm}`);
		});

		it('keeps a still frame on a canvas drawn at the template’s ratio', async () => {
			const { form, sdkDesigner } = start();
			const frame = framed(
				'--pixonaut-preview-ratio: 5 / 7; --pixonaut-preview-aspect: 0.714',
				'template',
			);
			await settle();
			await choose(form, '23');

			await choose(form, '26');

			expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(0);
			expect(ratio(frame)).toBe('5 / 7');
		});
	});

	it('approves again after a switch dropped the proof made before it', async () => {
		modalDialogs();
		const { form, container, button, sdkDesigner } = start({ checkbox: true });
		await settle();
		const sent = submitted(form);
		await choose(form, '12');
		container
			.querySelector<HTMLButtonElement>('.pixonaut-approval__proof')
			?.click();
		await settle();
		expect(sdkDesigner.approve).toHaveBeenCalledOnce();

		await choose(form, '11');
		const box = container.querySelector<HTMLInputElement>(
			'.pixonaut-approval__box',
		);
		expect(box?.checked).toBe(false);
		box?.click();
		button.click();
		await settle();

		expect(sdkDesigner.approve).toHaveBeenCalledTimes(2);
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('approves again after a switch to a variation in another variant only', async () => {
		modalDialogs();
		const { form, container, button, sdkDesigner } = start({
			checkbox: true,
			variations: COLOURS,
		});
		await settle();
		const sent = submitted(form);
		await choose(form, '21');
		sdkDesigner.approve.mockResolvedValueOnce(WHITE_PROOF);
		container
			.querySelector<HTMLButtonElement>('.pixonaut-approval__proof')
			?.click();
		await settle();
		const box = container.querySelector<HTMLInputElement>(
			'.pixonaut-approval__box',
		);
		box?.click();

		await choose(form, '22');

		expect(sdkDesigner.setVariant).toHaveBeenLastCalledWith('v_tee_black');
		expect(box?.checked).toBe(false);
		box?.click();
		button.click();
		await settle();

		expect(sdkDesigner.approve).toHaveBeenCalledTimes(2);
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('approves again when a switch changed the variant under the proof', async () => {
		const { form, button, sdkDesigner } = start({ variations: COLOURS });
		await settle();
		await choose(form, '21');
		const sent = submitted(form);
		let approve: (result: typeof PROOF) => void = () => undefined;
		sdkDesigner.approve.mockReturnValueOnce(
			new Promise((resolve) => {
				approve = resolve;
			}),
		);

		button.click();
		await settle();
		await choose(form, '22');
		approve(WHITE_PROOF);
		await settle();

		expect(sdkDesigner.approve).toHaveBeenCalledTimes(2);
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('hides the approval box of a variation approved by adding to the cart', async () => {
		const { form, container, button } = start({ checkbox: true });
		await settle();
		const box = container.querySelector<HTMLInputElement>(
			'.pixonaut-approval__box',
		);
		button.click();
		await settle();
		const controls = box?.closest<HTMLElement>('.pixonaut-approval');
		expect(controls?.classList.contains('is-invalid')).toBe(true);

		await choose(form, '11');

		expect(box?.disabled).toBe(true);
		expect(controls?.hidden).toBe(true);
		expect(controls?.classList.contains('is-invalid')).toBe(false);
	});

	it('approves again when a switch changed the placement under the proof', async () => {
		const { form, button, sdkDesigner } = start();
		await settle();
		const sent = submitted(form);
		sdkDesigner.approve
			.mockRejectedValueOnce(new PlacementChangedError())
			.mockResolvedValue(PROOF);
		await choose(form, '11');

		button.click();
		await settle();

		expect(sdkDesigner.approve).toHaveBeenCalledTimes(2);
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('makes a variation whose placement Pixonaut refuses unavailable, and only that one', async () => {
		const { form, container, button, mount, report, sdkDesigner } = start();
		await settle();
		mount.mockRejectedValueOnce(requestError(422, 'unknown_placement'));

		await choose(form, '13');

		expect(report).toHaveBeenCalledExactlyOnceWith(
			13,
			'front',
			'unknown_placement',
		);
		expect(container.classList.contains('is-unavailable')).toBe(true);
		const sent = submitted(form);
		button.click();
		await settle();
		expect(sdkDesigner.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([]);

		await choose(form, '11');
		expect(container.classList.contains('is-unavailable')).toBe(false);
		expect(mount).toHaveBeenCalledTimes(3);
		expect(options(mount, 2)).toMatchObject({
			blankId: 'blank_tee',
			selectedPrintSizeIndex: 1,
		});
	});

	it('makes a variation whose variant Pixonaut cannot show unavailable, unreported', async () => {
		const { form, container, button, report, sdkDesigner } = start();
		await settle();
		sdkDesigner.setVariant.mockRejectedValueOnce(
			new VariantUnavailableError('v_tee_dark', 'unknown_variant'),
		);
		const sent = submitted(form);

		await choose(form, '12');

		expect(container.classList.contains('is-unavailable')).toBe(true);
		// The designer is hidden: the variation fields are back in the form,
		// so another variation can be chosen.
		expect(container.contains(form.querySelector('table.variations'))).toBe(
			false,
		);
		button.click();
		await settle();
		expect(sdkDesigner.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
		expect(report).not.toHaveBeenCalled();

		await choose(form, '11');
		expect(container.classList.contains('is-unavailable')).toBe(false);
		expect(container.contains(form.querySelector('table.variations'))).toBe(
			true,
		);
		button.click();
		await settle();
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('hides the designer of a variation bought without a design and adds it as it is', async () => {
		const { form, container, button, sdkDesigner } = start();
		await settle();
		const sent = submitted(form);

		await choose(form, '15');

		expect(container.hidden).toBe(true);
		expect(
			document.querySelector<HTMLElement>('.pixonaut-preview')?.hidden,
		).toBe(true);
		// The variation fields and Add to cart are back in the form, shown.
		expect(container.contains(button)).toBe(false);
		expect(container.contains(form.querySelector('table.variations'))).toBe(
			false,
		);
		button.click();
		await settle();
		expect(sdkDesigner.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([{ front: '', back: 'null' }]);

		await choose(form, '11');
		expect(container.hidden).toBe(false);
		expect(container.contains(button)).toBe(true);
	});

	it('gives the form’s parts back when a variation bought without a design is chosen while the designer mounts', async () => {
		const sdk = fakeSdk();
		let finish: () => void = () => undefined;
		sdk.mount.mockImplementationOnce(
			() =>
				new Promise((resolve) => {
					finish = () => resolve(sdk.designer);
				}),
		);
		const { form, container, button } = start({ sdk });
		await settle();

		await choose(form, '15');
		finish();
		await settle();

		expect(container.hidden).toBe(true);
		expect(container.contains(button)).toBe(false);
		expect(container.contains(form.querySelector('table.variations'))).toBe(
			false,
		);
	});

	it('mounts again on an upload that finished after the designer was hidden', async () => {
		const { form, mount, sdkDesigner } = start();
		await settle();
		let upload: () => void = () => undefined;
		sdkDesigner.settled.mockImplementationOnce(
			() =>
				new Promise<void>((resolve) => {
					upload = () => {
						sdkDesigner.getState.mockReturnValue({
							templateId: 'mini-tee@2',
							values: { motif: { assetId: 'ast_1' } },
						});
						resolve();
					};
				}),
		);

		await choose(form, '15');
		await choose(form, '11');
		upload();
		await settle();

		expect(mount.mock.calls.at(-1)?.[0].initialValues).toEqual({
			motif: { assetId: 'ast_1' },
		});
	});

	it('draws the variation fields in the first step and leaves out the designer’s own variant picker', async () => {
		const { form, mount } = start({
			mounts: {
				front: { ...MOUNTED.front, variants: ['v_tee_white', 'v_tee_black'] },
			},
			variations: PICKED,
		});
		await settle();

		const options = mount.mock.calls[0]?.[0];
		expect(options.showVariantPicker).toBe(false);
		expect(options.optionsStep).toEqual({
			content: form.querySelector('table.variations'),
		});
	});

	it('approves and shows only the sides the chosen variation has', async () => {
		const { form, container, button, sdkDesigner } = start({
			mounts: TWO_SIDES,
			variations: {
				'11': personalised('S', { front: FRONT }),
				'12': personalised('L', {
					front: FRONT,
					back: { ...FRONT, placementId: 'back' },
				}),
			},
		});
		await settle();
		const sent = submitted(form);

		await choose(form, '11');
		const tab = (side: string) =>
			container.querySelector<HTMLElement>(
				`.pixonaut-tabs__tab[data-pixonaut-side="${side}"]`,
			);
		expect(tab('back')?.hidden).toBe(true);
		expect(container.querySelector<HTMLElement>('.pixonaut-tabs')?.hidden).toBe(
			true,
		);
		button.click();
		await settle();

		expect(sdkDesigner.approve).toHaveBeenCalledOnce();
		expect(sent).toEqual([{ front: PROOF.proofToken, back: '' }]);

		await choose(form, '12');
		expect(tab('back')?.hidden).toBe(false);
	});

	it('shows the variation’s variant on each side it has, and not on a side it lacks', async () => {
		const sdk = fakeSdk();
		const front = fakeDesigner();
		const back = fakeDesigner();
		sdk.mount.mockImplementation((mounted: { placementId?: string }) =>
			Promise.resolve(mounted.placementId === 'back' ? back : front),
		);
		const { form } = start({
			sdk,
			mounts: TWO_SIDES,
			variations: {
				'11': { ...personalised('S', { front: FRONT }), variant: 'v_tee_s' },
				'12': {
					...personalised('L', {
						front: FRONT,
						back: { ...FRONT, placementId: 'back' },
					}),
					variant: 'v_tee_l',
				},
			},
		});
		await settle();

		await choose(form, '11');
		expect(front.setVariant).toHaveBeenCalledExactlyOnceWith('v_tee_s');
		// A side the variation lacks may be on another blank, without the variant.
		expect(back.setVariant).not.toHaveBeenCalled();

		await choose(form, '12');
		expect(front.setVariant).toHaveBeenLastCalledWith('v_tee_l');
		expect(back.setVariant).toHaveBeenLastCalledWith('v_tee_l');
	});

	it('shows the chosen variation’s variant on a side mounted after the choice', async () => {
		const sdk = fakeSdk();
		const loadSdk = vi
			.fn<() => Promise<PixonautSdk>>()
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockResolvedValue(sdk.sdk);
		const { form, mount } = start({ sdk, loadSdk });
		await settle();
		expect(mount).not.toHaveBeenCalled();

		await choose(form, '12');

		expect(mount).toHaveBeenCalledOnce();
		expect(options(mount)).toMatchObject({ initialVariant: 'v_tee_dark' });
		expect(sdk.designer.setVariant).not.toHaveBeenCalled();
	});

	it('mounts with the chosen variation’s options when it could not mount before', async () => {
		const sdk = fakeSdk();
		const loadSdk = vi
			.fn<() => Promise<PixonautSdk>>()
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockResolvedValue(sdk.sdk);
		const { form, mount } = start({ sdk, loadSdk });
		await settle();
		expect(mount).not.toHaveBeenCalled();

		await choose(form, '11');

		expect(mount).toHaveBeenCalledOnce();
		expect(options(mount)).toMatchObject({ selectedPrintSizeIndex: 1 });
		expect(sdk.designer.setPrintSize).not.toHaveBeenCalled();
	});

	it('shows the variant again on the add to cart after showing it failed', async () => {
		const { form, button, sdkDesigner } = start();
		await settle();
		const sent = submitted(form);
		sdkDesigner.setVariant.mockRejectedValueOnce(
			requestError(503, 'service_unavailable'),
		);
		await choose(form, '12');

		button.click();
		await settle();

		expect(sdkDesigner.setVariant).toHaveBeenCalledTimes(2);
		expect(sdkDesigner.setVariant).toHaveBeenLastCalledWith('v_tee_dark');
		expect(sdkDesigner.setVariant.mock.invocationCallOrder[1]).toBeLessThan(
			sdkDesigner.approve.mock.invocationCallOrder[0] ?? 0,
		);
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('sends nothing while the designer cannot show the chosen variation’s variant', async () => {
		const { form, container, button, sdkDesigner } = start();
		await settle();
		const sent = submitted(form);
		sdkDesigner.setVariant.mockRejectedValue(
			requestError(503, 'service_unavailable'),
		);
		await choose(form, '12');

		button.click();
		await settle();

		expect(sdkDesigner.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
		expect(
			container.querySelector<HTMLElement>('.pixonaut-form__error')
				?.textContent,
		).toBe('label:design_failed');
	});

	it('mounts on the chosen variation’s starting size when it could not mount before', async () => {
		const sdk = fakeSdk();
		const loadSdk = vi
			.fn<() => Promise<PixonautSdk>>()
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockResolvedValue(sdk.sdk);
		const { form, mount } = start({
			sdk,
			loadSdk,
			variations: {
				'41': personalised('S', {
					front: { ...FRONT, printSizes: [0, 1], selectedPrintSizeIndex: 1 },
				}),
			},
		});
		await settle();

		await choose(form, '41');

		expect(options(mount)).toMatchObject({ selectedPrintSizeIndex: 1 });
	});

	it('keeps the size the customer is on when the variation offers it, whatever it starts on', async () => {
		const { form, sdkDesigner } = start({
			variations: {
				'41': personalised('S', {
					front: { ...FRONT, printSizes: [0, 1], selectedPrintSizeIndex: 1 },
				}),
				'42': personalised('L', {
					front: { ...FRONT, printSizes: [1], selectedPrintSizeIndex: 1 },
				}),
			},
		});
		await settle();

		await choose(form, '41');
		expect(sdkDesigner.setPrintSize).not.toHaveBeenCalled();

		await choose(form, '42');
		expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(1);
	});

	it('mounts on the chosen variation’s starting view when only that differs', async () => {
		const sdk = fakeSdk();
		const loadSdk = vi
			.fn<() => Promise<PixonautSdk>>()
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockResolvedValue(sdk.sdk);
		const { form, mount } = start({
			sdk,
			loadSdk,
			variations: {
				'41': personalised('S', { front: FRONT }),
				'42': personalised('L', {
					front: { ...FRONT, initialView: 'front_model' },
				}),
			},
		});
		await settle();

		await choose(form, '42');
		await choose(form, '41');
		expect(mount).not.toHaveBeenCalled();
		await choose(form, '42');

		expect(options(mount)).toMatchObject({ initialView: 'front_model' });
	});

	it('mounts on the first view when the chosen variation names no starting view', async () => {
		const sdk = fakeSdk();
		const loadSdk = vi
			.fn<() => Promise<PixonautSdk>>()
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockResolvedValue(sdk.sdk);
		const { form, mount } = start({
			sdk,
			loadSdk,
			mounts: { front: { ...MOUNTED.front, initialView: 'front_flat' } },
			variations: { '41': personalised('S', { front: FRONT }) },
		});
		await settle();

		await choose(form, '41');

		expect(options(mount)).not.toHaveProperty('initialView');
	});

	it('mounts the side again on the variation’s starting view', async () => {
		const { form, mount } = start({
			variations: {
				'41': personalised('S', {
					front: { ...FRONT, views: ['front_flat'], initialView: 'front_flat' },
				}),
				'42': personalised('L', {
					front: { ...FRONT, views: ['front_model'] },
				}),
			},
		});
		await settle();

		await choose(form, '41');
		await choose(form, '42');

		expect(options(mount, 1)).toMatchObject({ initialView: 'front_flat' });
		expect(options(mount, 2)).not.toHaveProperty('initialView');
	});

	it('switches from the print size the customer picked in the designer', async () => {
		const { form, sdkDesigner } = start();
		await settle();
		sdkDesigner.getState.mockReturnValue({
			templateId: 'mini-tee@2',
			values: {},
			printSize: { widthMm: SMALL.widthMm, heightMm: SMALL.heightMm },
		});

		await choose(form, '12');

		expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(0);
	});

	it('mounts only the sides of the chosen variation, past a side whose placement Pixonaut refuses', async () => {
		const { form, button, mount, report, sdkDesigner } = start({
			mounts: TWO_SIDES,
			variations: {
				'11': personalised('S', { front: FRONT }),
				'12': personalised('L', {
					front: FRONT,
					back: { ...FRONT, placementId: 'back' },
				}),
			},
		});
		mount.mockImplementation((mounted: { placementId?: string }) =>
			mounted.placementId === 'back'
				? Promise.reject(requestError(422, 'unknown_placement'))
				: Promise.resolve(sdkDesigner),
		);
		await settle();
		const sent = submitted(form);

		await choose(form, '11');
		button.click();
		await settle();

		expect(report).not.toHaveBeenCalled();
		expect(sent).toEqual([{ front: PROOF.proofToken, back: '' }]);

		await choose(form, '12');
		expect(report).toHaveBeenCalledExactlyOnceWith(
			12,
			'back',
			'unknown_placement',
		);
	});

	it('starts a mounted side from its values after Pixonaut refused another side’s placement', async () => {
		const values = { 'layer-1:content': 'Table 12' };
		const { form, mount, sdkDesigner } = start({
			mounts: TWO_SIDES,
			variations: {
				'11': personalised('S', { front: FRONT }),
				'12': personalised('L', {
					front: FRONT,
					back: { ...FRONT, placementId: 'back' },
				}),
			},
		});
		sdkDesigner.getState.mockReturnValue({
			templateId: 'mini-tee@2',
			values,
		});
		mount.mockImplementation((mounted: { placementId?: string }) =>
			mounted.placementId === 'back'
				? Promise.reject(requestError(422, 'unknown_placement'))
				: Promise.resolve(sdkDesigner),
		);
		await settle();

		await choose(form, '11');

		expect(options(mount, mount.mock.calls.length - 1)).toMatchObject({
			placementId: 'front',
			initialValues: values,
		});
	});

	it('blames a failed mount on the variation it was for, not the one chosen since', async () => {
		const { form, container, button, mount, report, sdkDesigner } = start();
		await settle();
		const sent = submitted(form);
		let refuse: (error: Error) => void = () => undefined;
		mount.mockReturnValueOnce(
			new Promise((_resolve, reject) => {
				refuse = reject;
			}),
		);
		await choose(form, '13');

		await choose(form, '11');
		refuse(requestError(422, 'unknown_placement'));
		await settle();

		expect(report).not.toHaveBeenCalled();
		expect(container.classList.contains('is-unavailable')).toBe(false);
		expect(mount).toHaveBeenCalledTimes(3);
		expect(options(mount, 2)).toMatchObject({
			templateId: 'mini-tee@2',
			selectedPrintSizeIndex: 1,
		});
		button.click();
		await settle();
		expect(sdkDesigner.approve).toHaveBeenCalledOnce();
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('approves the sides of a variation chosen while the proof was made', async () => {
		const { form, button, mount, sdkDesigner } = start({
			mounts: TWO_SIDES,
			variations: {
				'11': personalised('S', { front: FRONT }),
				'12': personalised('L', {
					front: FRONT,
					back: { ...FRONT, placementId: 'back' },
				}),
			},
		});
		await settle();
		await choose(form, '11');
		const sent = submitted(form);
		let approve: (result: typeof PROOF) => void = () => undefined;
		sdkDesigner.approve.mockReturnValueOnce(
			new Promise((resolve) => {
				approve = resolve;
			}),
		);

		button.click();
		await settle();
		await choose(form, '12');
		approve(PROOF);
		await settle();

		expect(options(mount, 1)).toMatchObject({ placementId: 'back' });
		expect(sent).toEqual([{ front: PROOF.proofToken, back: PROOF.proofToken }]);
	});

	it('adds a variation bought without a design when no side can mount', async () => {
		const { form, container, button, mount } = start({ mounts: {} });
		await settle();
		const sent = submitted(form);

		await choose(form, '15');
		button.click();
		await settle();

		expect(mount).not.toHaveBeenCalled();
		expect(container.hidden).toBe(true);
		expect(sent).toEqual([{ front: '', back: 'null' }]);
	});

	it('follows a variation chosen just before the add to cart, without waiting for it to settle', async () => {
		const { form, button, sdkDesigner } = start();
		await settle();
		const sent = submitted(form);
		const field = form.querySelector<HTMLInputElement>('[name="variation_id"]');
		if (field !== null) {
			field.value = '11';
		}

		button.click();
		await settle();

		expect(sdkDesigner.setPrintSize).toHaveBeenCalledExactlyOnceWith(1);
		expect(sdkDesigner.setPrintSize.mock.invocationCallOrder[0]).toBeLessThan(
			sdkDesigner.approve.mock.invocationCallOrder[0] ?? 0,
		);
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('selects the variation sold as the variant the customer picked in the designer', async () => {
		const { form, mount, sdkDesigner } = start({ variations: PICKED });
		findVariations(form, PICKED);
		await settle();
		await choose(form, '31');
		const sent = submitted(form);

		sdkDesigner.pick('v_tee_black');
		await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
		await settle();

		expect(
			form.querySelector<HTMLSelectElement>('[name="attribute_size"]')?.value,
		).toBe('L');
		expect(new FormData(form).get('variation_id')).toBe('32');
		// The designer shows the variant already.
		expect(sdkDesigner.setVariant).toHaveBeenCalledExactlyOnceWith(
			'v_tee_white',
		);
		expect(mount).toHaveBeenCalledOnce();

		form
			.querySelector<HTMLButtonElement>('button[name="add-to-cart"]')
			?.click();
		await settle();
		expect(sent).toEqual([{ front: PROOF.proofToken, back: 'null' }]);
	});

	it('selects the variation sold as a variant the customer picked while the designer mounted', async () => {
		const { form } = start({
			sdk: fakeSdk(fakeDesigner({ pickedWhileMounting: 'v_tee_black' })),
			variations: PICKED,
		});
		findVariations(form, PICKED);
		await settle();
		await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
		await settle();

		expect(
			form.querySelector<HTMLSelectElement>('[name="attribute_size"]')?.value,
		).toBe('L');
		expect(new FormData(form).get('variation_id')).toBe('32');
	});

	it('leaves the form alone when the designer starts on its first variant before one is chosen', async () => {
		const { form } = start({
			sdk: fakeSdk(fakeDesigner({ startsOn: 'v_tee_black' })),
			variations: PICKED,
		});
		findVariations(form, PICKED);
		await settle();
		await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
		await settle();

		expect(
			form.querySelector<HTMLSelectElement>('[name="attribute_size"]')?.value,
		).toBe('');
		expect(new FormData(form).get('variation_id')).toBe('0');
	});

	it('leaves the chosen variation in the form when the designer cannot show its variant and starts on another', async () => {
		const sdk = fakeSdk(
			fakeDesigner({ refusesInitialVariant: true, startsOn: 'v_tee_black' }),
		);
		// The SDK first fails to load, so the side mounts once 31 is chosen.
		const loadSdk = vi
			.fn<() => Promise<PixonautSdk>>()
			.mockRejectedValueOnce(new SdkLoadError('blocked'))
			.mockResolvedValue(sdk.sdk);
		const { form, mount } = start({ sdk, loadSdk, variations: PICKED });
		findVariations(form, PICKED);
		await settle();

		await choose(form, '31');
		await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
		await settle();

		expect(options(mount)).toMatchObject({ initialVariant: 'v_tee_white' });
		expect(new FormData(form).get('variation_id')).toBe('31');
		expect(sdk.designer.setVariant).toHaveBeenCalledWith('v_tee_white');
	});

	it('shows the product unavailable while the designer shows a variant no variation is sold as', async () => {
		const { form, container, button, sdkDesigner } = start({
			variations: PICKED,
		});
		findVariations(form, PICKED);
		await settle();
		await choose(form, '31');
		const sent = submitted(form);

		sdkDesigner.pick('v_tee_heather');
		button.click();
		await settle();

		expect(container.classList.contains('is-unavailable')).toBe(true);
		expect(sdkDesigner.approve).not.toHaveBeenCalled();
		expect(sent).toEqual([]);
		expect(new FormData(form).get('variation_id')).toBe('31');

		// Back on the variation's own variant.
		sdkDesigner.pick('v_tee_white');
		expect(container.classList.contains('is-unavailable')).toBe(false);
	});

	it('follows the customer’s last pick when they pick again before the form settled', async () => {
		const { form, sdkDesigner } = start({ variations: PICKED });
		findVariations(form, PICKED);
		await settle();
		await choose(form, '31');

		sdkDesigner.pick('v_tee_black');
		sdkDesigner.pick('v_tee_white');
		await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
		await settle();

		expect(new FormData(form).get('variation_id')).toBe('31');
		expect(sdkDesigner.getVariant()).toBe('v_tee_white');
	});

	it('leaves a newer choice in the form when an older switch shows its variant late', async () => {
		const { form, sdkDesigner } = start({ variations: PICKED });
		findVariations(form, PICKED);
		await settle();
		await choose(form, '31');
		let finish: () => void = () => undefined;
		sdkDesigner.setVariant.mockImplementationOnce(
			(ref: string) =>
				new Promise<void>((resolve) => {
					finish = () => {
						sdkDesigner.pick(ref);
						resolve();
					};
				}),
		);
		await choose(form, '32');

		await choose(form, '31');
		finish();
		await new Promise((resolve) => setTimeout(resolve, VARIATION_SETTLE_MS));
		await settle();

		expect(new FormData(form).get('variation_id')).toBe('31');
		expect(sdkDesigner.getVariant()).toBe('v_tee_white');
	});
});
