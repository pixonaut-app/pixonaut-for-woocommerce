import type { Labeler } from '../shared/labels';
import type {
	Approved,
	DesignerState,
	PixonautDesigner,
	ProductPlacement,
	SplitMountOptions,
} from '../types/sdk';
import { CheckboxApproval } from './approval';
import { removePrintedSwatches } from './attribute-swatches';
import {
	type CartEdit,
	designAlone,
	EDIT_CLOSED,
	type EditSettings,
	NOT_EDITABLE,
	PROOF_EXPIRED,
	REVISION_CHANGED,
	readEdit,
} from './cart-edit';
import { isLasting, type UnavailableReason, unavailableReason } from './errors';
import {
	announceApproved,
	announceBeforeSubmit,
	filterMountOptions,
} from './events';
import {
	FIELD_VALUES_NAME,
	type FieldValue,
	fieldValues,
} from './field-values';
import {
	type ArrangedGroups,
	type HostGroups,
	hostComplete,
	hostGroups,
	lentElements,
} from './form-groups';
import {
	applyShape,
	type FrameShape,
	shapeOf,
	shapeOnceDrawn,
} from './frame-shape';
import { withStandIns } from './lent-parts';
import {
	PREVIEW_OPENED_NAME,
	previewOpenedValue,
	reportPreviewOpened,
} from './preview-opened';
import { PriceDisplay } from './price';
import { dropUntouchedInputs, followPrintedInputs } from './printed-inputs';
import { ProofDialog, type ProofFetch } from './proof-dialog';
import { joinLabels, refusedFieldLabels } from './refused-fields';
import type { PixonautSdk } from './sdk-loader';
import { mountSide, readServerReads } from './server-reads';
import { type ProductMounts, readMounts, type SideOptions } from './settings';
import {
	gatherShopParts,
	hasCartSlot,
	printShopParts,
	returnShopParts,
	type ShopParts,
	type ShopStepOptions,
	shopStepOptions,
} from './shop-parts';
import { SidePreloads } from './side-preload';
import { SubmitHold } from './submit-hold';
import { SideTabs } from './tabs';
import type { ThemeMountOptions } from './theme-look';
import { showVariant, startingVariant, variantRef } from './variant';
import {
	followChoices,
	NO_VARIATION,
	readVariations,
	selectAttributes,
	type Variation,
	type VariationSide,
	type Variations,
	variationsSoldAs,
	watchVariation,
} from './variations';
import { whenNeeded } from './when-needed';

export type DesignerDeps = {
	loadSdk: () => Promise<PixonautSdk>;
	label: Labeler;
	/** Tells the server a side cannot be personalised. */
	report: (productId: number, side: string, reason: UnavailableReason) => void;
	/** The cart line edit on a page opened to edit one; never called on any
	 *  other page. */
	cartEdit: (settings: EditSettings) => CartEdit;
	/** Leaves the page for `url`, once an edited line is saved. */
	navigate: (url: string) => void;
	/** Logs a mount and its failure, with the developer tools' SDK debug on. */
	debug?: (message: string, details: unknown) => void;
	/** What the mount options take from the theme's look, read from the
	 *  page, with the store's accent set to follow the theme. */
	themeOptions?: (
		container: HTMLElement,
		form: HTMLFormElement | null,
	) => ThemeMountOptions;
	/** Draws the variation attributes in a part of the add-to-cart form the
	 *  designer draws in its form as swatches, with the store's attribute
	 *  options set to them. */
	attributeSwatches?: (part: HTMLElement) => () => void;
	/** Fetches the image of a proof the customer opens; the browser's `fetch`
	 *  when absent. */
	fetchProof?: ProofFetch;
};

/** Label keys of the proof dialog's text. */
const PROOF_ALT_LABEL = 'proof_alt';
const PROOF_LOADING_LABEL = 'proof_loading';
const PROOF_CLOSE_LABEL = 'proof_close';

const PREVIEW_ID_PREFIX = 'pixonaut-preview-';

const FORM_ID_PREFIX = 'pixonaut-form-';

const VIEWS_ID_PREFIX = 'pixonaut-views-';

const MARK_ID_PREFIX = 'pixonaut-mark-';

/** The class of a side's mark target, which the stylesheet keeps the
 *  mark's room in. */
const MARK_TARGET_CLASS = 'pixonaut-preview__mark';

/** On a side's preview frame once its design is drawn, or its mount
 *  failed: the spinner over it goes. */
const DRAWN_CLASS = 'is-drawn';

/** What the page printed in a side's preview target; the SDK replaces it
 *  once the preview shows its photo and design. */
const PREVIEW_PLACEHOLDER_SELECTOR = '.pixonaut-preview__placeholder';

/** A side's preview with the spinner over it and its views under it. */
const FRAME_SELECTOR = '.pixonaut-preview__frame';

/** The frame's mark for a side on no blank, which keeps its template's
 *  shape (`SideLayout::$template_shaped`). */
const TEMPLATE_SHAPE = 'template';

const UNAVAILABLE_CLASS = 'is-unavailable';

/** Set beside UNAVAILABLE_CLASS once the product cannot be personalised for
 *  the rest of the page; without it the customer's next action retries. */
const CLOSED_CLASS = 'is-closed';

/** How long before its expiry a proof is no longer reused: the time an add
 *  to cart needs to reach the server and commit it. */
const PROOF_EXPIRY_MARGIN_MS = 60_000;

/** How many times a side is approved while its design keeps changing under
 *  the approval before the add to cart gives up. */
const APPROVE_ATTEMPTS = 3;

const UNAVAILABLE_LABEL = 'personalisation_unavailable';

const DESIGN_FAILED_LABEL = 'design_failed';

/** Shown when the design was refused for fields the customer must change,
 *  with `%s` for their labels. */
const FIELDS_TO_CHANGE_LABEL = 'design_fields_to_change';

/** Shown under the approval box when the form is sent without it. */
const APPROVAL_REQUIRED_LABEL = 'approval_required';

/** What a page opened to edit a cart line shows when the design refuses a
 *  change, by the code the update route, or the SDK's approval, names. */
const EDIT_REFUSAL_LABELS: Readonly<Record<string, string>> = {
	[REVISION_CHANGED]: 'design_changed_elsewhere',
	[NOT_EDITABLE]: 'design_not_editable',
};

/** Refusals after which the design takes no more changes on this page. */
const CLOSING_CODES: ReadonlySet<string> = new Set([NOT_EDITABLE, EDIT_CLOSED]);

/** How many times an edited side is saved while its proof keeps expiring
 *  before the update gives up. */
const SAVE_ATTEMPTS = 2;

/** The status `approve()` rejects with once a correction link lapsed. */
const LINK_EXPIRED_STATUS = 401;

/** The approval mode, as the server names it, whose box a variation shows. */
const CHECKBOX_MODE = 'checkbox';

/** How far, in millimetres, the print size a designer reports may be from a
 *  menu entry and still be that entry: the size travels as a float through
 *  the SDK's document model. */
const PRINT_SIZE_TOLERANCE_MM = 0.5;

/** The mount options a variation switches. */
type Placed = {
	templateId?: string;
	blankId?: string;
	placementId?: string;
	placements?: readonly ProductPlacement[];
	views?: readonly string[];
	initialView?: string;
	variants?: readonly string[];
	selectedPrintSizeIndex?: number;
	drawAtTemplateRatio?: boolean;
};

/**
 * One personalised product's inline designer: a split mount per side, shown
 * one at a time under side tabs when there are several, and the add-to-cart
 * form it hooks into. Submitting the form approves every side,
 * writes the proof tokens into the designs field and sends the form on; the
 * designer stays locked while that is under way. In the checkbox approval
 * mode, opening a side's proof approves it early; that proof is the one sent
 * unless the design changed since.
 *
 * The page shows one inline designer, so its targets are looked up inside
 * this form and inside the preview of this product only.
 */
export class ProductDesigner {
	readonly productId: number;

	private readonly mounts: ProductMounts;

	/** The mount options the server's reads were made for, by side. */
	private readonly readFor: ProductMounts;

	/** The add-to-cart button, held busy while its click is handled. */
	private readonly hold: SubmitHold | null;

	/** The field the proof tokens travel in, one `<field>[<side>]` per side,
	 *  as the server's add to cart reads them. */
	private readonly field: string;

	private readonly form: HTMLFormElement | null;

	private readonly designers = new Map<string, PixonautDesigner>();

	/** Each frame's shape as the page printed it, kept from the first switch
	 *  that changed it, to go back to. */
	private readonly printedShapes = new WeakMap<HTMLElement, FrameShape>();

	/** Per side, what stops waiting for a size switched in place to be drawn
	 *  before its frame takes the new shape. */
	private readonly reshaping = new Map<string, () => void>();

	/** Each side's latest approval, until its design changes. */
	private readonly approved = new Map<string, Approved>();

	/** How many times each side's design changed, so an approval made while
	 *  it changed is not kept. */
	private readonly changes = new Map<string, number>();

	private readonly approval: CheckboxApproval | null;

	private readonly tabs: SideTabs | null;

	private readonly preloads = new SidePreloads();

	/** On a page opened to edit a cart line's design: that line, which
	 *  submitting the form updates instead of adding to the cart. */
	private readonly edit: CartEdit | null;

	/** On a variable product: what each variation switches the designer to. */
	private readonly variations: Variations | null;

	/** The price the form shows, following the chosen variation; null on a
	 *  product without variations. */
	private readonly price: PriceDisplay | null;

	/** The variation the form holds; `NO_VARIATION` until one is chosen. */
	private variation = NO_VARIATION;

	/** Reads the form's variation at once, rather than once it settles. */
	private readVariation: () => void = () => undefined;

	/** Every switch, one after the other; an add to cart waits for them. */
	private switching: Promise<void> = Promise.resolve();

	/** The variation the designer was last switched to in full; null until
	 *  one was, and after a switch that failed part way. */
	private applied: string | null = null;

	/** Variations found unavailable on this page: Pixonaut refused a
	 *  placement one of them switched to, or the variant it is sold as;
	 *  `NO_VARIATION` names a simple product's own variant. */
	private readonly brokenVariations = new Set<string>();

	/** Set while the designer shows a variant the customer picked in it
	 *  that no variation the form can hold is sold as; cleared once the
	 *  form or the designer chooses again. */
	private unmatched = false;

	/** The variant last picked in the designer whose variation was put in
	 *  the form, until the designer follows the form's next choice. */
	private picked: string | undefined;

	/** The variant the script is switching the designer to, whose
	 *  `variant` events are its own rather than the customer's. */
	private showing: string | undefined;

	/** Set once the designer was needed, so a variation it could not mount
	 *  for is mounted for the next one. */
	private needed = false;

	private sdk: PixonautSdk | null = null;

	private mounting: Promise<boolean> | null = null;

	/** Set once `mounting` resolved with every side it mounted, so a side
	 *  another variation needs later is mounted then. */
	private mounted = false;

	/** The values of a side's designer removed to mount it again, which its
	 *  next mount starts from, until one succeeds. */
	private readonly keptValues = new Map<string, DesignerState['values']>();

	/** Set for good once a lasting reason says the product cannot be
	 *  personalised on this page. */
	private unavailable = false;

	/** Reasons already reported, so retries do not report them again. */
	private readonly reported = new Set<UnavailableReason>();

	private busy = false;

	/** The dialog a side's proof opens in, once the customer opened one. */
	private proofDialog: ProofDialog | null = null;

	/** True while the form is sent on, so the submit handler lets it through. */
	private passing = false;

	/** What the mount options take from the theme, once read; null before. */
	private themeLook: ThemeMountOptions | null = null;

	/** The form's parts a designer draws in its steps, once gathered; null
	 *  when it draws none of them. */
	private shopParts: ShopParts | null | undefined;

	/** The host items each side's designer was lent (`formGroups`), by
	 *  side: the add-to-cart form's attribute fields, named by field. */
	private readonly hosts = new Map<string, HostGroups>();

	/** By side, what takes away the swatches drawn for its designer. */
	private readonly swatches = new Map<string, Array<() => void>>();

	/** Whether the form's choices are followed to mark host items complete. */
	private followingHosts = false;

	/** Settles once the designers last hidden are destroyed, and the form's
	 *  parts back in the form, which a side mounts again only after. */
	private releasing: Promise<void> = Promise.resolve();

	constructor(
		private readonly container: HTMLElement,
		private readonly deps: DesignerDeps,
	) {
		this.productId = Number(container.dataset.pixonautProduct);
		this.mounts = readMounts(container);
		this.readFor = { ...this.mounts };
		this.field = container.dataset.pixonautField ?? '';
		this.form = container.closest('form');
		this.hold =
			this.form === null
				? null
				: new SubmitHold(this.form, (element) => this.drawnByDesigner(element));
		this.unavailable = Object.keys(this.mounts).length === 0;
		this.approval = CheckboxApproval.find(container, this.form);
		this.tabs = SideTabs.find(
			container,
			() => this.preview(),
			(side) => this.preloads.shown(side, this.designers.get(side)),
		);
		const edit = readEdit(container);
		this.edit = edit === null ? null : deps.cartEdit(edit);
		this.variations = this.edit === null ? readVariations(container) : null;
		this.price =
			this.variations === null || this.form === null
				? null
				: new PriceDisplay(container, this.form);
	}

	/** Hooks into the form and mounts once the designer is needed. */
	start(): void {
		// Capture, so the designs are in place before any other handler of
		// the form (a theme's AJAX add to cart) reads it.
		this.form?.addEventListener('submit', this.onSubmit, { capture: true });
		this.tabs?.start();
		if (this.form !== null) {
			this.edit?.lockLine(this.form);
		}
		this.approval?.start(
			(side, opener) => {
				void this.openProof(side, opener);
			},
			this.deps.label(APPROVAL_REQUIRED_LABEL, { productId: this.productId }),
		);
		// Followed even with no side to mount, so a variation bought without a
		// design still hides the form and goes to the cart.
		if (this.variations !== null && this.form !== null) {
			this.readVariation = watchVariation(this.form, (id) =>
				this.chooseVariation(id),
			);
			// A page printed at a variation's price that the form did not open
			// on shows the product's own once the form settles without one.
			if (this.price?.printedOpening === true) {
				followChoices(this.form, () => {
					if (this.variation === NO_VARIATION) {
						this.showPrice();
					}
				});
			}
		}
		if (this.unavailable) {
			return;
		}
		this.printShopParts();
		followPrintedInputs(this.container.ownerDocument);
		if (this.form !== null) {
			removePrintedSwatches(this.form);
		}
		const previews = Object.keys(this.mounts)
			.map((side) => this.existingPreview(side))
			.filter((element): element is HTMLElement => element !== null);
		whenNeeded([this.container, ...previews], () => {
			this.needed = true;
			void this.mount();
		});
	}

	/**
	 * Mounts the sides the chosen variation has, every side until one is
	 * chosen; false when the product cannot be personalised. A mount that
	 * failed for a passing reason is tried again on the next call, and a
	 * side a later variation adds is mounted on the call after it.
	 */
	mount(): Promise<boolean> {
		if (this.unavailable) {
			return Promise.resolve(false);
		}
		if (this.mounting === null || (this.mounted && !this.ready())) {
			this.mounted = false;
			const mounting = this.mountSides();
			this.mounting = mounting;
			void mounting.then((mounted) => {
				this.mounted = mounted && this.mounting === mounting;
			});
		}
		return this.mounting;
	}

	private async mountSides(): Promise<boolean> {
		if (this.unavailable) {
			return false;
		}
		// A failure is the variation's the mount started for, not a later one's.
		const id = this.variation;
		let side = '';
		try {
			this.sdk ??= await this.deps.loadSdk();
			for (side of this.activeSides()) {
				// A side mounted again on its own may be the only one missing.
				if (!this.designers.has(side)) {
					await this.mountSide(this.sdk, side);
				}
			}
			await this.showVariant().catch((error: unknown) => {
				console.error('Pixonaut: the variant could not be shown.', error);
			});
			// The customer may have chosen another variation while it mounted:
			// one bought without a design takes the form's parts back.
			this.showVariation();
			return true;
		} catch (error) {
			this.mountFailed(id, side, error);
			return false;
		}
	}

	private async mountSide(sdk: PixonautSdk, side: string): Promise<void> {
		await this.releasing;
		const kept = this.keptValues.get(side);
		// Shown from the start, rather than switched to once mounted.
		const ref = variantRef(this.container, this.variations, this.variation);
		const own = this.mounts[side];
		const { formGroups, ...lent } = this.shopStepOptions(side, own);
		const options = filterMountOptions<SplitMountOptions>(
			{
				// The SDK's colour and size picker only where there is a choice
				// the form does not offer: on a variable product the variation
				// fields offer every variant the product sells.
				showVariantPicker:
					this.variations === null && (own?.variants?.length ?? 0) > 1,
				...lent,
				// The server's options, a PHP filter's among them, win.
				...this.themeOptions(),
				...own,
				...(formGroups === undefined ? {} : { formGroups }),
				...(ref === undefined || !this.onBlank(side)
					? {}
					: { initialVariant: ref }),
				...(kept === undefined ? {} : { initialValues: kept }),
				previewTarget: this.previewTarget(side),
				formTarget: this.formTarget(side),
				...this.viewsTarget(side),
				...this.markTarget(side),
			} as SplitMountOptions,
			{ productId: this.productId, side },
		);
		// Read at each mount: a side mounted late, or again, may find the
		// server's reads too old to use by then.
		const reads = readServerReads(this.container)[side];
		// The form printed another template's defaults, or ones the browser
		// now reads afresh: the SDK would take them as the customer's.
		if (
			reads === undefined ||
			this.readFor[side]?.templateId !== options.templateId
		) {
			dropUntouchedInputs(this.formTarget(side));
		}
		// The SDK replaces the target's content: a part beside the printed
		// form that the options no longer lend (a mount filter took it out)
		// goes back to the add-to-cart form first.
		this.returnShopParts(
			new Set([
				options.optionsStep?.content,
				options.lastStepContent,
				...(options.formGroups?.groups ?? []).flatMap((group) =>
					group.items.flatMap((item) =>
						'element' in item ? [item.element] : [],
					),
				),
			]),
		);
		const hidden = this.tabs !== null && this.tabs.selected() !== side;
		const preloaded = this.preloads.options(
			side,
			withStandIns(options, this.form),
			hidden,
		);
		this.deps.debug?.('mounting', {
			productId: this.productId,
			side,
			options: preloaded,
		});
		let designer: PixonautDesigner;
		try {
			designer = await mountSide(
				sdk,
				this.forgetOnChange(side, preloaded),
				reads,
				this.readFor[side],
			);
		} catch (error) {
			const alone = error instanceof TypeError ? designAlone(own) : undefined;
			if (alone === undefined) {
				this.markDrawn(side);
				throw error;
			}
			console.error('Pixonaut: the design opens without its product.', error);
			this.mounts[side] = alone;
			await this.mountSide(sdk, side);
			return;
		}
		this.markDrawnOnReveal(side);
		this.deps.debug?.('mounted', { productId: this.productId, side });
		designer.on('variant', ({ ref }) => this.variantPicked(side, ref));
		this.designers.set(side, designer);
		this.keptValues.delete(side);
		this.followHosts();
		this.completeHosts(side, designer);
		// Its tab may have been shown while it mounted.
		if (this.tabs?.selected() === side) {
			this.preloads.shown(side, designer);
		}
		// A variant the customer picked while the side mounted was reported
		// before anything listened. Without an `initialVariant`, or with one
		// it cannot show, the SDK starts on the first variant it offers with
		// a photo, which nobody picked: the variation keeps its own, which
		// showVariant() then finds unavailable.
		const shown = designer.getVariant();
		if (
			shown !== undefined &&
			shown !== ref &&
			shown !== startingVariant(designer)
		) {
			this.variantPicked(side, shown);
		}
	}

	/**
	 * The options that draw the form's option fields in the designer's first
	 * step and its cart row on the last, so the customer goes through one
	 * flow. Only with one side: the parts can be drawn in one designer, and
	 * with several each side's tab would need them. A side the store manager
	 * arranged (`formGroups`) has each option field in the group it was
	 * placed in instead, as a host item, so no step of their own.
	 */
	private shopStepOptions(
		side: string,
		own: SideOptions | undefined,
	): ShopStepOptions & Pick<SplitMountOptions, 'formGroups'> {
		const lending = this.form !== null && Object.keys(this.mounts).length === 1;
		if (lending && this.form !== null) {
			this.shopParts ??= gatherShopParts(this.container, this.form);
		}
		const parts =
			lending && (this.shopParts ?? null) !== null
				? shopStepOptions(this.shopParts as ShopParts)
				: {};
		this.removeSwatches(side);
		const arranged = arrangedOf(own);
		if (arranged === undefined) {
			this.hosts.delete(side);
			this.drawSwatches(side, parts.optionsStep?.content);
			return parts;
		}
		const groups = hostGroups(arranged, lending ? this.form : null);
		this.hosts.set(side, groups);
		if (groups.hosts.length === 0) {
			this.drawSwatches(side, parts.optionsStep?.content);
			return { ...parts, formGroups: groups.formGroups };
		}
		for (const lent of lentElements(groups)) {
			this.drawSwatches(side, lent);
		}
		const { optionsStep: _options, ...rest } = parts;
		return { ...rest, formGroups: groups.formGroups };
	}

	/** Puts the form's parts in the slots of the printed form, where the
	 *  designer draws them, until it loads: the script printed after the
	 *  form has done so unless it could not run. Drawn as swatches there. */
	private printShopParts(): void {
		const [side, ...others] = Object.keys(this.mounts);
		if (
			side === undefined ||
			others.length > 0 ||
			this.form === null ||
			!hasCartSlot(this.formTarget(side))
		) {
			return;
		}
		this.shopParts ??= gatherShopParts(this.container, this.form);
		const parts = this.shopParts;
		if (parts === null) {
			return;
		}
		for (const part of printShopParts(
			parts,
			this.formTarget(side),
			this.form,
		)) {
			this.drawSwatches(side, part);
		}
	}

	/** Destroys a designer, and puts the form's parts it gave back where the
	 *  form had them. */
	private destroyDesigner(designer: PixonautDesigner): void {
		designer.destroy();
		this.returnShopParts();
	}

	private returnShopParts(lent?: ReadonlySet<unknown>): void {
		if ((this.shopParts ?? null) !== null) {
			returnShopParts(this.shopParts as ShopParts, this.container, lent);
		}
	}

	/** Draws the variation fields in `part`, which the side's designer draws
	 *  in its form, as swatches, with the store's attribute options set to
	 *  them. */
	private drawSwatches(side: string, part: unknown): void {
		const draw = this.deps.attributeSwatches;
		if (draw === undefined || !(part instanceof HTMLElement)) {
			return;
		}
		const removers = this.swatches.get(side) ?? [];
		removers.push(draw(part));
		this.swatches.set(side, removers);
	}

	/** Shows the variation fields the side's designer drew as swatches as
	 *  the theme rendered them again. */
	private removeSwatches(side: string): void {
		for (const remove of this.swatches.get(side) ?? []) {
			remove();
		}
		this.swatches.delete(side);
	}

	/** Follows the form's choices, once, to mark each designer's host items
	 *  complete as their fields get a choice or lose it. */
	private followHosts(): void {
		const form = this.form;
		if (this.followingHosts || form === null || this.hosts.size === 0) {
			return;
		}
		this.followingHosts = true;
		followChoices(form, () => {
			for (const [side, designer] of this.designers) {
				this.completeHosts(side, designer);
			}
		});
	}

	/** Tells `designer` which of its host items hold a choice: Next waits for
	 *  the others. */
	private completeHosts(side: string, designer: PixonautDesigner): void {
		const form = this.form;
		if (form === null) {
			return;
		}
		for (const host of this.hosts.get(side)?.hosts ?? []) {
			designer.setHostItemComplete(host, hostComplete(form, host));
		}
	}

	/** Whether `element` is one the designer drew, rather than a part of the
	 *  form it draws in its steps. */
	private drawnByDesigner(element: Element): boolean {
		return (
			this.container.contains(element) &&
			!(this.shopParts?.cartRow.contains(element) ?? false) &&
			!(this.shopParts?.options?.contains(element) ?? false) &&
			![...this.hosts.values()].some((groups) =>
				lentElements(groups).some((lent) => lent.contains(element)),
			)
		);
	}

	/** What the mount options take from the theme, read once, when the
	 *  store follows it. */
	private themeOptions(): ThemeMountOptions {
		if (this.deps.themeOptions === undefined) {
			return {};
		}
		this.themeLook ??= this.deps.themeOptions(this.container, this.form);
		return this.themeLook;
	}

	/**
	 * After the side's designer showed variant `ref`: a colour or size the
	 * customer picked in it selects the variation sold as that variant in
	 * the form, which the designer then follows. The side's approval was of
	 * the variant before. A variant no variation the form can hold is sold
	 * as leaves the product unavailable until the customer chooses again.
	 */
	private variantPicked(side: string, ref: string): void {
		const variations = this.variations;
		const form = this.form;
		if (variations === null || form === null || ref === this.showing) {
			return;
		}
		// A pick the form already holds, or the customer going back to it.
		// The form may hold a pick the designer has not followed yet.
		if (
			ref ===
			(this.picked ?? variantRef(this.container, variations, this.variation))
		) {
			this.setUnmatched(false);
			return;
		}
		this.dropApproval(side);
		const chosen = variationsSoldAs(
			variations,
			ref,
			form,
			(variation) => !this.brokenVariations.has(variation),
		).some((id) => selectAttributes(form, variations[id]?.attributes ?? {}));
		if (chosen) {
			this.picked = ref;
		} else {
			console.error(`Pixonaut: no variation the form holds is sold as ${ref}.`);
		}
		this.setUnmatched(!chosen);
	}

	private setUnmatched(unmatched: boolean): void {
		if (this.unmatched !== unmatched) {
			this.unmatched = unmatched;
			this.showVariation();
		}
	}

	/** The options with an `onChange` that also drops the side's approval,
	 *  so a changed design is approved, and in the checkbox mode ticked,
	 *  again. */
	private forgetOnChange(
		side: string,
		options: SplitMountOptions,
	): SplitMountOptions {
		const { onChange } = options;
		return {
			...options,
			onChange: (state) => {
				this.dropApproval(side);
				onChange?.(state);
			},
		};
	}

	/** Forgets the side's approval, so its design is approved, and in the
	 *  checkbox mode ticked, again; an approval under way is made again. */
	private dropApproval(side: string): void {
		this.approved.delete(side);
		this.changes.set(side, this.changeCount(side) + 1);
		this.approval?.designChanged();
	}

	/** The live designers of the sides the chosen variation has that show a
	 *  blank, the only ones a variant can be shown in. */
	private variantDesigners(): PixonautDesigner[] {
		return this.activeSides()
			.filter((side) => this.onBlank(side))
			.map((side) => this.designers.get(side))
			.filter((designer) => designer !== undefined);
	}

	/** Whether the side mounts on a blank. A page editing a design opens a
	 *  side on the design alone once its product no longer sells it as it
	 *  was made, and such a side has no variants. */
	private onBlank(side: string): boolean {
		const own = this.mounts[side];
		return own !== undefined && 'blankId' in own && own.blankId !== undefined;
	}

	/** Whether every side has a live designer to approve. */
	private ready(): boolean {
		return (
			!this.unavailable &&
			this.activeSides().every((side) => this.designers.has(side))
		);
	}

	/** The sides the chosen variation has; every side until one is chosen. */
	private activeSides(): string[] {
		const variation = this.chosen();
		return variation?.state === 'personalised'
			? Object.keys(variation.sides).filter((side) => side in this.mounts)
			: Object.keys(this.mounts);
	}

	/** The chosen variation; undefined until one is, and on a product
	 *  without variations. */
	private chosen(): Variation | undefined {
		return this.variations?.[this.variation];
	}

	/** Whether the chosen variation cannot be personalised on this page. */
	private variationUnavailable(): boolean {
		return (
			this.unmatched ||
			this.chosen()?.state === 'unavailable' ||
			this.brokenVariations.has(this.variation)
		);
	}

	/** Follows the form to another variation, after any switch under way. */
	private chooseVariation(id: string): void {
		this.variation = id;
		this.unmatched = false;
		this.picked = undefined;
		this.showPrice();
		void this.queueSwitch(id);
	}

	/** Switches to the variation after any switch under way; false when the
	 *  switch failed. */
	private queueSwitch(id: string): Promise<boolean> {
		let switched = true;
		this.switching = this.switching
			.then(() => this.switchTo(id))
			.catch((error: unknown) => {
				switched = false;
				console.error('Pixonaut: the variation could not be switched.', error);
			});
		return this.switching.then(() => switched);
	}

	/**
	 * After every switch under way: switches the designer to the chosen
	 * variation once more when its last switch failed part way (a network
	 * blip), so an add to cart never approves another variation's design.
	 * False when the designer still is not on it.
	 */
	private async applyVariation(): Promise<boolean> {
		await this.switching;
		if (
			this.chosen()?.state !== 'personalised' ||
			this.variationUnavailable() ||
			this.applied === this.variation
		) {
			return true;
		}
		const id = this.variation;
		return (
			(await this.queueSwitch(id)) &&
			(this.applied === this.variation || this.variationUnavailable())
		);
	}

	/**
	 * Shows the designer as the variation needs it, then switches each of
	 * its sides. A side not mounted yet only changes the options it mounts
	 * with. A later choice of variation stops this one.
	 */
	private async switchTo(id: string): Promise<void> {
		if (id !== this.variation) {
			return;
		}
		this.applied = null;
		this.showVariation();
		const variation = this.chosen();
		if (variation?.state !== 'personalised' || this.brokenVariations.has(id)) {
			return;
		}
		this.approval?.setEnabled(variation.approvalMode === CHECKBOX_MODE);
		this.tabs?.limit(this.activeSides());
		// A side mounting now would mount with the options it read before.
		await this.mounting;
		for (const [side, target] of Object.entries(variation.sides)) {
			if (id !== this.variation) {
				return;
			}
			if (side in this.mounts) {
				await this.switchSide(id, side, target);
			}
		}
		// A side Pixonaut refused leaves the variation unavailable, unmounted.
		if (
			id !== this.variation ||
			this.brokenVariations.has(id) ||
			!(await this.showVariant(true))
		) {
			return;
		}
		this.applied = id;
		// A designer that could not mount for the last variation may for this
		// one, and a side it adds mounts now.
		if (this.needed && !this.ready()) {
			void this.mount();
		}
	}

	/**
	 * Switches one side to the variation's: another template, blank,
	 * placement, form layout, canvas, or set of placements or views mounts the side
	 * again on the customer's values; another print size alone is switched in
	 * place. Any
	 * change drops the side's approval. A size switch that fails leaves the
	 * side's options as they were, so the next one compares against what is
	 * shown.
	 */
	private async switchSide(
		id: string,
		side: string,
		target: VariationSide,
	): Promise<void> {
		const previous = this.mounts[side] as SideOptions & Placed;
		const designer = this.designers.get(side);
		const size =
			this.liveSize(previous, designer) ?? previous.selectedPrintSizeIndex ?? 0;
		// A size the customer is on is kept; a designer not mounted yet starts
		// on the variation's own.
		const starting = target.selectedPrintSizeIndex ?? target.printSizes[0];
		const nextSize =
			designer !== undefined && target.printSizes.includes(size)
				? size
				: (starting ?? size);
		const remounted =
			previous.templateId !== target.templateId ||
			previous.blankId !== target.blankId ||
			previous.placementId !== target.placementId ||
			!sameList(previous.placements, target.placements) ||
			!sameList(previous.views, target.views) ||
			!sameList(previous.variants, target.variants) ||
			previous.layout !== target.layout ||
			previous.drawAtTemplateRatio !== target.drawAtTemplateRatio ||
			JSON.stringify(arrangedOf(previous)) !==
				JSON.stringify(target.formGroups);
		if (!remounted && nextSize === size) {
			// A side not mounted yet still opens on this variation's starting view.
			if (designer === undefined) {
				const { initialView: _shown, ...kept } = previous;
				this.mounts[side] = {
					...kept,
					...(target.initialView === undefined
						? {}
						: { initialView: target.initialView }),
				} as SideOptions;
			}
			return;
		}
		const {
			blankId: _blank,
			placementId: _placement,
			placements: _placements,
			views: _views,
			initialView: _initialView,
			variants: _variants,
			layout: _layout,
			formGroups: _formGroups,
			drawAtTemplateRatio: _drawAtTemplateRatio,
			...rest
		} = previous;
		this.mounts[side] = {
			...rest,
			templateId: target.templateId,
			...(target.blankId === undefined || target.placementId === undefined
				? {}
				: {
						blankId: target.blankId,
						placementId: target.placementId,
						...(target.placements === undefined
							? {}
							: { placements: target.placements }),
						...(target.views === undefined ? {} : { views: target.views }),
						...(target.initialView === undefined
							? {}
							: { initialView: target.initialView }),
						...(target.variants === undefined
							? {}
							: { variants: target.variants }),
					}),
			...(target.layout === undefined ? {} : { layout: target.layout }),
			...(target.formGroups === undefined
				? {}
				: { formGroups: target.formGroups }),
			// Left out, the template's own setting decides.
			...(target.drawAtTemplateRatio === undefined
				? {}
				: { drawAtTemplateRatio: target.drawAtTemplateRatio }),
			selectedPrintSizeIndex: nextSize,
		} as SideOptions;
		const inPlace = !remounted && designer !== undefined && this.sdk !== null;
		// A size switched in place keeps the frame's shape until the SDK has
		// drawn it; nothing drawn yet, or drawn again, has no picture to keep.
		this.shapeFrame(side, inPlace);
		this.dropApproval(side);
		if (designer === undefined || this.sdk === null) {
			return;
		}
		if (remounted) {
			await this.remount(id, side, designer, this.sdk);
			return;
		}
		try {
			designer.setPrintSize(nextSize);
		} catch (error) {
			this.mounts[side] = previous;
			this.shapeFrame(side);
			throw error;
		}
	}

	/**
	 * The index into the side's mounted menu of the print size its designer
	 * shows, which the customer may have picked in it; undefined without a
	 * designer, a size in its state or a matching entry.
	 */
	private liveSize(
		options: SideOptions,
		designer: PixonautDesigner | undefined,
	): number | undefined {
		const live = designer?.getState().printSize;
		const menu = 'printSizes' in options ? (options.printSizes ?? []) : [];
		if (live === undefined) {
			return undefined;
		}
		const index = menu.findIndex(
			(size) =>
				Math.abs(size.widthMm - live.widthMm) <= PRINT_SIZE_TOLERANCE_MM &&
				Math.abs(size.heightMm - live.heightMm) <= PRINT_SIZE_TOLERANCE_MM,
		);
		return index === -1 ? undefined : index;
	}

	/** Mounts the side again, for the variation `id`, on the template it now
	 *  names and the values its customer gave. */
	private async remount(
		id: string,
		side: string,
		designer: PixonautDesigner,
		sdk: PixonautSdk,
	): Promise<void> {
		// An upload under way lands in the values the side mounts again with.
		await designer.settled();
		this.keptValues.set(side, designer.getState().values);
		this.destroyDesigner(designer);
		this.designers.delete(side);
		try {
			await this.mountSide(sdk, side);
			this.showVariation();
		} catch (error) {
			this.mountFailed(id, side, error);
		}
	}

	/**
	 * Shows the variant the order is placed with in every mounted side the
	 * chosen variation has; a side it does not have may be on another blank,
	 * which has no such variant. True
	 * once each shows it, or when no variant applies; false once Pixonaut
	 * cannot show it, which makes the variation (on a simple product, the
	 * product) unavailable as a refused placement does, without a report:
	 * the report route names no such reason. Rejects on a passing failure,
	 * which the next switch or add to cart tries again. On a variable
	 * product it waits for the designer to be switched to the chosen
	 * variation (`switched`, or once applied): before then a side may still
	 * be on another variation's blank, which has no such variant. A side
	 * that showed another variant drops its approval: its proof is of that
	 * variant, and an approval under way is made again.
	 */
	private async showVariant(switched = false): Promise<boolean> {
		const id = this.variation;
		const ref = variantRef(this.container, this.variations, id);
		if (
			ref === undefined ||
			(this.variations !== null && !switched && this.applied !== id) ||
			this.sdk === null
		) {
			return true;
		}
		this.dropOtherVariantApprovals(ref);
		this.showing = ref;
		let shown: boolean;
		try {
			shown = await showVariant(this.variantDesigners(), ref, this.sdk);
		} finally {
			if (this.showing === ref) {
				this.showing = undefined;
			}
		}
		if (shown) {
			return true;
		}
		this.brokenVariations.add(id);
		if (id === this.variation) {
			this.showVariation();
		}
		return false;
	}

	/** Drops the approval of each mounted side the chosen variation has that
	 *  shows a variant other than `ref`; a side that shows none yet was
	 *  never approved, as approving shows the variant first. */
	private dropOtherVariantApprovals(ref: string): void {
		for (const side of this.activeSides()) {
			const shown = this.designers.get(side)?.getVariant();
			if (shown !== undefined && shown !== ref) {
				this.dropApproval(side);
			}
		}
	}

	/** After Pixonaut refused a placement of the variation: the variation
	 *  cannot be personalised on this page, and the store hears of it. */
	private breakVariation(id: string, side: string): void {
		if (this.brokenVariations.has(id)) {
			return;
		}
		this.brokenVariations.add(id);
		this.deps.report(Number(id), side, 'unknown_placement');
		console.error(`Pixonaut: variation ${id} cannot be personalised.`);
		if (id === this.variation) {
			this.showVariation();
		}
	}

	/** Shows the chosen variation's price, or the product's own once none
	 *  is chosen. */
	private showPrice(): void {
		const variation = this.chosen();
		const side = Object.keys(this.mounts)[0] ?? '';
		const size =
			variation?.state === 'personalised'
				? variation.sides[side]?.selectedPrintSizeIndex
				: undefined;
		this.price?.show(
			variation === undefined ? null : Number(this.variation),
			variation?.priceHtml,
			{ productId: this.productId, side, printSizeIndex: size ?? null },
		);
	}

	/** Hides the designer for a variation bought without a design, and says
	 *  when the chosen one cannot be personalised. */
	private showVariation(): void {
		const plain = this.chosen()?.state === 'plain';
		this.container.hidden = plain;
		const preview = this.preview();
		if (preview !== null) {
			preview.hidden = plain;
		}
		if (plain) {
			this.approval?.setEnabled(false);
			this.giveShopPartsBack();
		}
		if (!this.unavailable) {
			this.showUnavailable(this.variationUnavailable());
		}
	}

	/**
	 * Destroys the designers drawing the form's parts in their steps, which
	 * puts the parts back in the form, while the designer is hidden: a
	 * variation bought without a design, or one that cannot be personalised,
	 * still needs its option fields and Add to cart. The next variation that
	 * can be personalised mounts them again on the customer's values, once
	 * these are destroyed.
	 */
	private giveShopPartsBack(): void {
		this.stopReshaping();
		for (const side of [...this.swatches.keys()]) {
			this.removeSwatches(side);
		}
		this.returnShopParts();
		if ((this.shopParts ?? null) === null || this.designers.size === 0) {
			return;
		}
		const hidden = [...this.designers];
		this.designers.clear();
		this.approved.clear();
		this.mounting = null;
		this.mounted = false;
		const releasing = this.releasing;
		this.releasing = Promise.all(
			hidden.map(async ([side, designer]) => {
				// An upload under way lands in the values the side mounts again with.
				await designer.settled().catch(() => undefined);
				this.keptValues.set(side, designer.getState().values);
				this.destroyDesigner(designer);
			}),
		).then(() => releasing);
	}

	private placementChanged(error: unknown): boolean {
		return this.sdk !== null && error instanceof this.sdk.PlacementChangedError;
	}

	private readonly onSubmit = (event: SubmitEvent): void => {
		if (this.passing) {
			return;
		}
		// The variation chosen just before the click is the one added.
		this.readVariation();
		// A variation bought without a design goes to the cart without one.
		if (this.chosen()?.state === 'plain') {
			this.clearDesignFields();
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		const submitter = event.submitter;
		// A button the SDK drew inside the form (an upload, a step) is not
		// an add to cart.
		if (
			this.busy ||
			this.hold?.leaving === true ||
			(submitter !== null && this.drawnByDesigner(submitter))
		) {
			return;
		}
		void this.approveAndSubmit(submitter);
	};

	private async approveAndSubmit(submitter: HTMLElement | null): Promise<void> {
		const form = this.form;
		if (form === null || !form.reportValidity()) {
			return;
		}
		this.busy = true;
		this.hold?.hold(submitter);
		this.showError(null);
		let side = '';
		let locked = false;
		try {
			let designs: Record<string, string> | null = null;
			let values: Record<string, FieldValue[]> = {};
			let opened: Record<string, boolean | null> = {};
			// A variation chosen while the proofs were made has sides of its
			// own: its proofs are collected again.
			for (
				let attempt = 0;
				attempt < APPROVE_ATTEMPTS && designs === null;
				attempt++
			) {
				if (!(await this.applyVariation())) {
					throw new Error('The designer could not switch to the variation.');
				}
				const id = this.variation;
				if (
					this.chosen()?.state === 'plain' ||
					this.variationUnavailable() ||
					!(await this.mount()) ||
					!this.ready() ||
					!(await this.showVariant())
				) {
					if (locked) {
						this.setLocked(false);
					}
					return;
				}
				this.setLocked(true);
				locked = true;
				if (this.edit !== null) {
					await this.saveEdit(this.edit);
					return;
				}
				const proofs: Record<string, string> = {};
				const fields: Record<string, FieldValue[]> = {};
				const previews: Record<string, boolean | null> = {};
				for (const key of this.activeSides()) {
					side = key;
					const designer = this.designers.get(side);
					if (designer === undefined) {
						throw new Error(`Side ${side} is not mounted.`);
					}
					proofs[side] = (await this.approveSide(side, designer)).proofToken;
					fields[side] = fieldValues(designer);
					// Only the box has a preview to open before it.
					previews[side] = this.approval?.ticked()
						? designer.wasPreviewOpened()
						: null;
				}
				await this.switching;
				if (id === this.variation) {
					designs = proofs;
					values = fields;
					opened = previews;
				}
			}
			if (designs === null) {
				throw new Error('The variation kept changing while it was approved.');
			}
			// A proof is committed once; another add to cart makes new ones.
			this.approved.clear();
			// A side the variation does not have sends no design.
			this.clearDesignFields();
			for (const [key, token] of Object.entries(designs)) {
				this.designField(key).value = token;
				this.hiddenField(`${FIELD_VALUES_NAME}[${key}]`).value = JSON.stringify(
					values[key] ?? [],
				);
				this.hiddenField(`${PREVIEW_OPENED_NAME}[${key}]`).value =
					previewOpenedValue(opened[key] ?? null);
			}
			// With every proof at hand nothing above waited for a task, and a
			// form ignores a submit made while its first one is dispatched.
			await nextTask();
			const proceed = announceBeforeSubmit(this.container, {
				productId: this.productId,
				form,
				designs,
			});
			if (proceed && this.sendOn(form, submitter)) {
				this.hold?.left();
			} else {
				this.setLocked(false);
			}
		} catch (error) {
			this.setLocked(false);
			this.clearDesignFields();
			this.approvalFailed(side, error);
		} finally {
			this.busy = false;
			this.hold?.release();
		}
	}

	/** The side's approval: the one made when its proof was opened, while
	 *  the design is unchanged and the proof has time left, or a new one. A
	 *  result whose design changed while it was made is made again. */
	private async approveSide(
		side: string,
		designer: PixonautDesigner,
	): Promise<Approved> {
		const kept = this.approved.get(side);
		if (
			kept !== undefined &&
			kept.expiresAt - PROOF_EXPIRY_MARGIN_MS > Date.now()
		) {
			return kept;
		}
		this.approved.delete(side);
		for (let attempt = 0; attempt < APPROVE_ATTEMPTS; attempt++) {
			const changes = this.changeCount(side);
			let result: Approved;
			try {
				result = await designer.approve();
			} catch (error) {
				// A view on another placement was shown while the proof was made.
				if (this.placementChanged(error)) {
					continue;
				}
				throw error;
			}
			if (this.changeCount(side) !== changes) {
				continue;
			}
			this.approved.set(side, result);
			announceApproved(this.container, {
				productId: this.productId,
				side,
				result,
			});
			return result;
		}
		throw new Error(
			`The design of side ${side} kept changing while it was approved.`,
		);
	}

	/**
	 * Saves every side's correction on the edited line, then leaves for the
	 * page the server names: the cart, or the order. A proof that expired on the way is approved again; a
	 * design that takes no more changes closes editing; any other refusal
	 * shows its message and leaves the designer as it is.
	 */
	private async saveEdit(edit: CartEdit): Promise<void> {
		let redirectUrl = '';
		for (const side of Object.keys(this.mounts)) {
			const saved = await this.saveSide(edit, side);
			if (saved === null) {
				this.setLocked(false);
				return;
			}
			redirectUrl = saved;
		}
		this.hold?.left();
		this.deps.navigate(redirectUrl);
	}

	/** The page to leave for once the side is saved; null when it was not. */
	private async saveSide(edit: CartEdit, side: string): Promise<string | null> {
		for (let attempt = 0; attempt < SAVE_ATTEMPTS; attempt++) {
			const { proofToken } = await this.approveEdited(edit, side);
			const designer = this.designers.get(side);
			const answer = await edit.save(
				side,
				proofToken,
				this.approval?.ticked() ?? false,
				designer === undefined ? [] : fieldValues(designer),
			);
			if (answer.ok) {
				return answer.redirectUrl;
			}
			// A refused proof is never sent again.
			this.approved.delete(side);
			if (answer.code === PROOF_EXPIRED) {
				continue;
			}
			this.editRefused(answer.code, answer.message);
			return null;
		}
		this.showError(this.refusalLabel(''));
		return null;
	}

	/**
	 * After the design refused a change, as the update route or the SDK's
	 * approval said: a design that takes no more changes closes editing; any
	 * other refusal shows `message`, or the refusal's label when there is
	 * none (an approval, or a proxy's error page in place of the route's
	 * answer).
	 */
	private editRefused(code: string, message: string): void {
		const text = message !== '' ? message : this.refusalLabel(code);
		if (CLOSING_CODES.has(code)) {
			this.closeEdit(text);
		} else {
			this.showError(text);
		}
	}

	private refusalLabel(code: string): string {
		const key = Object.hasOwn(EDIT_REFUSAL_LABELS, code)
			? EDIT_REFUSAL_LABELS[code]
			: undefined;
		return this.deps.label(key ?? DESIGN_FAILED_LABEL, {
			productId: this.productId,
		});
	}

	/** Approves an edited side; a correction link that lapsed is minted
	 *  again, and the side mounted on it with the customer's changes, before
	 *  a second try. A mount that fails leaves the side to the next submit,
	 *  which mounts it again. */
	private async approveEdited(edit: CartEdit, side: string): Promise<Approved> {
		const designer = this.designers.get(side);
		if (designer === undefined || this.sdk === null) {
			throw new Error(`Side ${side} is not mounted.`);
		}
		try {
			return await this.approveSide(side, designer);
		} catch (error) {
			if ((error as { status?: unknown }).status !== LINK_EXPIRED_STATUS) {
				throw error;
			}
		}
		const options = await edit.relink(side);
		if (options === null) {
			throw new Error(`Side ${side} could not be opened again.`);
		}
		await designer.settled();
		this.keptValues.set(side, designer.getState().values);
		this.destroyDesigner(designer);
		this.designers.delete(side);
		this.mounts[side] = options;
		try {
			await this.mountSide(this.sdk, side);
		} catch (error) {
			this.mounting = null;
			throw error;
		}
		const remounted = this.designers.get(side);
		if (remounted === undefined) {
			throw new Error(`Side ${side} could not be mounted again.`);
		}
		remounted.setLocked(true);
		if (!(await this.showVariant())) {
			throw new Error(`Side ${side} cannot show its variant.`);
		}
		return this.approveSide(side, remounted);
	}

	/** After the design stopped taking changes: the designer goes and the
	 *  server's message says why. */
	private closeEdit(message: string): void {
		this.unavailable = true;
		this.destroyDesigners();
		this.container.classList.add(UNAVAILABLE_CLASS, CLOSED_CLASS);
		const element = this.unavailableMessage();
		if (element !== null) {
			element.textContent = message;
			element.hidden = false;
		}
	}

	private changeCount(side: string): number {
		return this.changes.get(side) ?? 0;
	}

	/**
	 * Approves the side and shows its proof in a dialog over the page,
	 * opened at once from `opener` and saying the proof is on its way until
	 * it is. The form is free again once the approval is made, while the
	 * image downloads; closing the dialog early leaves the approval made.
	 */
	private async openProof(side: string, opener: HTMLElement): Promise<void> {
		if (this.busy) {
			return;
		}
		this.busy = true;
		this.showError(null);
		const dialog = this.dialog();
		dialog.open(opener);
		let proofUrl: string | null = null;
		let previewed: PixonautDesigner | null = null;
		let changes = 0;
		let variant: string | undefined;
		try {
			if (!(await this.applyVariation())) {
				throw new Error('The designer could not switch to the variation.');
			}
			const designer =
				!this.variationUnavailable() &&
				(await this.mount()) &&
				(await this.showVariant())
					? this.designers.get(side)
					: null;
			if (designer === undefined || designer === null) {
				dialog.close();
				return;
			}
			this.setLocked(true);
			proofUrl = (await this.approveSide(side, designer)).proofUrl;
			previewed = designer;
			changes = this.changeCount(side);
			variant = designer.getVariant();
		} catch (error) {
			dialog.close();
			this.approvalFailed(side, error);
		} finally {
			this.setLocked(false);
			this.busy = false;
		}
		if (proofUrl === null || previewed === null) {
			return;
		}
		try {
			// A proof of a design or variant changed since says nothing of the
			// design now.
			if (
				(await dialog.show(proofUrl)) &&
				this.changeCount(side) === changes &&
				previewed.getVariant() === variant
			) {
				reportPreviewOpened(previewed);
			}
		} catch {
			// The approval stands; the next click shows the same proof again.
			dialog.close();
			this.showError(
				this.deps.label(DESIGN_FAILED_LABEL, { productId: this.productId }),
			);
		}
	}

	/** The proof dialog, made the first time a proof is opened. */
	private dialog(): ProofDialog {
		const context = { productId: this.productId };
		this.proofDialog ??= new ProofDialog(
			this.doc(),
			{
				name: this.deps.label(PROOF_ALT_LABEL, context),
				loading: this.deps.label(PROOF_LOADING_LABEL, context),
				close: this.deps.label(PROOF_CLOSE_LABEL, context),
				buttonClass: this.approval?.buttonClass() ?? '',
			},
			// Pixonaut's proof link is its own credential; no cookie goes with it.
			this.deps.fetchProof ??
				((url, signal) => fetch(url, { credentials: 'omit', signal })),
		);
		return this.proofDialog;
	}

	/** After an approval failed, on the side that failed: on a page opened
	 *  to edit a cart line, a design that refused the change says why; a
	 *  lasting reason ends personalisation on this page; a design refused
	 *  for its fields names them, beside the SDK's marks at each; any other
	 *  asks the customer to check and try again. */
	private approvalFailed(side: string, error: unknown): void {
		this.tabs?.select(side);
		const code = (error as { code?: unknown } | null)?.code;
		if (
			this.edit !== null &&
			typeof code === 'string' &&
			Object.hasOwn(EDIT_REFUSAL_LABELS, code)
		) {
			this.editRefused(code, '');
			return;
		}
		const reason = unavailableReason(error, this.sdk);
		if (reason !== null && isLasting(reason)) {
			this.markUnavailable(side, reason, error);
		} else {
			this.showError(this.refusedFieldsText(side, error));
		}
	}

	/** What a failed approval of `side` says: the fields the customer must
	 *  change when the refusal names any the form heads, else that the
	 *  design could not be prepared. */
	private refusedFieldsText(side: string, error: unknown): string {
		const context = { productId: this.productId };
		const labels = refusedFieldLabels(error, this.designers.get(side));
		if (labels.length === 0) {
			return this.deps.label(DESIGN_FAILED_LABEL, context);
		}
		return (
			this.deps
				.label(FIELDS_TO_CHANGE_LABEL, context)
				// A function, so a `$&` in a merchant's label is not read as a pattern.
				.replace('%s', () => joinLabels(labels, this.mounts[side]?.locale))
		);
	}

	/**
	 * Sends the form on, through every other submit handler. True when the
	 * browser goes on to send it; false when a handler stopped it (a theme's
	 * AJAX add to cart), so the page stays and the designer unlocks.
	 */
	private sendOn(
		form: HTMLFormElement,
		submitter: HTMLElement | null,
	): boolean {
		let sent = false;
		const watch = (event: Event) => {
			if (event.target === form) {
				sent = !event.defaultPrevented;
			}
		};
		const view = this.doc().defaultView;
		view?.addEventListener('submit', watch);
		this.passing = true;
		try {
			if (submitter !== null && form.contains(submitter)) {
				form.requestSubmit(submitter);
			} else {
				form.requestSubmit();
			}
		} finally {
			this.passing = false;
			view?.removeEventListener('submit', watch);
		}
		return sent;
	}

	/**
	 * After a mount started for the variation `id` failed: on a variable
	 * product, a placement Pixonaut refuses makes that variation unavailable,
	 * when it is still the chosen one and has the side, and leaves the others
	 * to mount; otherwise a lasting reason ends personalisation on this page,
	 * and any other failure (a network blip, a script the browser did not
	 * load) clears what mounted so the next add to cart tries again.
	 */
	private mountFailed(id: string, side: string, error: unknown): void {
		// The SDK may not have loaded, or the sides after this one were never
		// tried: no spinner is left over a side nothing draws.
		for (const each of Object.keys(this.mounts)) {
			this.markDrawn(each);
		}
		const reason = unavailableReason(error, this.sdk);
		this.deps.debug?.('mount failed', {
			productId: this.productId,
			side,
			reason,
			error,
		});
		if (reason === 'unknown_placement' && this.variations !== null) {
			this.keepValues();
			this.destroyDesigners();
			this.mounting = null;
			const variation = this.variations[id];
			if (
				id === this.variation &&
				variation?.state === 'personalised' &&
				side in variation.sides
			) {
				this.breakVariation(id, side);
			} else if (id === this.variation) {
				// No variation chosen yet: the one the customer picks may mount.
				this.showUnavailable(true);
				console.error('Pixonaut: personalisation could not load.', error);
			}
			return;
		}
		if (reason !== null && isLasting(reason)) {
			this.markUnavailable(side, reason, error);
			return;
		}
		this.keepValues();
		this.destroyDesigners();
		this.mounting = null;
		this.showUnavailable(true);
		if (reason !== null) {
			this.report(side, reason);
		}
		console.error('Pixonaut: personalisation could not load.', error);
	}

	private markUnavailable(
		side: string,
		reason: UnavailableReason,
		error: unknown,
	): void {
		this.unavailable = true;
		this.destroyDesigners();
		this.showUnavailable(true);
		this.container.classList.add(CLOSED_CLASS);
		this.report(side, reason);
		console.error('Pixonaut: personalisation is unavailable.', error);
	}

	/** Reports a reason once, for the side that failed or else the first
	 *  side (a script that did not load failed before any side). */
	private report(side: string, reason: UnavailableReason): void {
		const reported = side !== '' ? side : Object.keys(this.mounts)[0];
		if (reported === undefined || this.reported.has(reason)) {
			return;
		}
		this.reported.add(reason);
		this.deps.report(this.productId, reported, reason);
	}

	/** Keeps each live designer's values for its next mount: past its first
	 *  preview the SDK no longer takes them from the printed inputs. */
	private keepValues(): void {
		for (const [side, designer] of this.designers) {
			this.keptValues.set(side, designer.getState().values);
		}
	}

	private destroyDesigners(): void {
		this.stopReshaping();
		for (const designer of this.designers.values()) {
			this.destroyDesigner(designer);
		}
		this.designers.clear();
		this.approved.clear();
	}

	private showUnavailable(shown: boolean): void {
		this.container.classList.toggle(UNAVAILABLE_CLASS, shown);
		if (shown) {
			this.giveShopPartsBack();
		}
		const message = this.unavailableMessage();
		if (message === null) {
			return;
		}
		if (shown) {
			message.textContent = this.deps.label(UNAVAILABLE_LABEL, {
				productId: this.productId,
			});
		}
		message.hidden = !shown;
	}

	private setLocked(locked: boolean): void {
		for (const designer of this.designers.values()) {
			designer.setLocked(locked);
		}
	}

	private showError(text: string | null): void {
		const element = this.container.querySelector<HTMLElement>(
			'.pixonaut-form__error',
		);
		if (element === null) {
			return;
		}
		element.textContent = text ?? '';
		element.hidden = text === null;
	}

	private unavailableMessage(): HTMLElement | null {
		return this.container.querySelector<HTMLElement>(
			'.pixonaut-form__unavailable',
		);
	}

	private clearDesignFields(): void {
		for (const key of Object.keys(this.mounts)) {
			this.designField(key).value = '';
			this.hiddenField(`${FIELD_VALUES_NAME}[${key}]`).value = '';
			this.hiddenField(`${PREVIEW_OPENED_NAME}[${key}]`).value = '';
		}
	}

	/** The side's hidden token field, added when a theme's form left it out. */
	private designField(side: string): HTMLInputElement {
		return this.hiddenField(`${this.field}[${side}]`);
	}

	/** The hidden field named `name`, added when a theme's form left it out. */
	private hiddenField(name: string): HTMLInputElement {
		let field = Array.from(
			this.container.querySelectorAll<HTMLInputElement>('input'),
		).find((input) => input.name === name);
		if (field === undefined) {
			field = this.doc().createElement('input');
			field.type = 'hidden';
			field.name = name;
			this.container.append(field);
		}
		return field;
	}

	private formTarget(side: string): HTMLElement {
		const target = byId(this.container, FORM_ID_PREFIX + side);
		if (target === null) {
			throw new Error(`The form target of side ${side} is missing.`);
		}
		return target;
	}

	/** The side's views target, where the page printed one under its
	 *  preview; without it the SDK draws the views above the form. */
	private viewsTarget(side: string): { viewsTarget?: HTMLElement } {
		const preview = this.preview();
		const target =
			preview === null ? null : byId(preview, VIEWS_ID_PREFIX + side);
		return target === null ? {} : { viewsTarget: target };
	}

	/** Where the side's "Powered by Pixonaut" goes: under its preview,
	 *  outside the border and shadow a theme draws around the preview. A
	 *  theme's copy of the preview template from before it printed the
	 *  target gets one made under the preview's stage; without a preview
	 *  frame the SDK draws the mark under the preview, in its element. */
	private markTarget(side: string): { markTarget?: HTMLElement } {
		const preview = this.preview();
		const printed =
			preview === null ? null : byId(preview, MARK_ID_PREFIX + side);
		if (printed !== null) {
			return { markTarget: printed };
		}
		const below = this.existingPreview(side);
		const frame = below?.closest<HTMLElement>(FRAME_SELECTOR);
		if (below === null || frame === null || frame === undefined) {
			return {};
		}
		const target = this.doc().createElement('div');
		target.id = MARK_ID_PREFIX + side;
		target.className = MARK_TARGET_CLASS;
		target.dataset.pixonautSide = side;
		// Under the stage the spinner sits on, else under the preview itself.
		const stage = below.parentElement === frame ? below : below.parentElement;
		stage?.after(target);
		return { markTarget: target };
	}

	/** Takes the spinner off a mounted side's preview once the SDK has
	 *  replaced the preview's placeholder: until then it draws the preview
	 *  into a layer of no height, which the printed ratio stands in for. */
	private markDrawnOnReveal(side: string): void {
		const target = this.existingPreview(side);
		const placeholder =
			target === null
				? null
				: target.querySelector(PREVIEW_PLACEHOLDER_SELECTOR);
		if (target === null || placeholder === null) {
			this.markDrawn(side);
			return;
		}
		const observer = new MutationObserver(() => {
			if (!placeholder.isConnected) {
				observer.disconnect();
				this.markDrawn(side);
			}
		});
		observer.observe(target, { childList: true });
	}

	/**
	 * Keeps the side's frame in the shape of what it is on after a switch:
	 * on no blank, the template's, at the ratio of the print size it starts
	 * on, which Pixonaut checked against the template's, or the template's
	 * own ratio the page printed when the canvas keeps it at every size and
	 * the page printed this template on no blank; on a blank, the ratio the
	 * page printed, which the SDK grows to the photo. `onceDrawn` waits for
	 * the SDK to draw the side's new size first (`shapeOnceDrawn()`).
	 */
	private shapeFrame(side: string, onceDrawn = false): void {
		this.stopReshaping(side);
		const preview = this.existingPreview(side);
		const frame = preview?.closest<HTMLElement>(FRAME_SELECTOR);
		if (!preview || !frame) {
			return;
		}
		const printed = this.printedShapes.get(frame) ?? shapeOf(frame);
		this.printedShapes.set(frame, printed);
		const options = this.mounts[side] as SideOptions & Placed;
		const menu = 'printSizes' in options ? (options.printSizes ?? []) : [];
		const keepsPrinted =
			options.drawAtTemplateRatio === true &&
			printed.shape === TEMPLATE_SHAPE &&
			this.readFor[side]?.templateId === options.templateId;
		const size =
			options.placementId === undefined && !keepsPrinted
				? menu[options.selectedPrintSizeIndex ?? 0]
				: undefined;
		const shape =
			size === undefined
				? printed
				: {
						shape: TEMPLATE_SHAPE,
						ratio: `${size.widthMm} / ${size.heightMm}`,
						aspect: String(size.widthMm / size.heightMm),
					};
		if (onceDrawn) {
			this.reshaping.set(side, shapeOnceDrawn(preview, frame, shape));
		} else {
			applyShape(frame, shape);
		}
	}

	/** Stops waiting to reshape the side's frame, or every side's: its
	 *  designer is gone, or its frame is shaped again. */
	private stopReshaping(side?: string): void {
		for (const [each, stop] of this.reshaping) {
			if (side === undefined || each === side) {
				stop();
				this.reshaping.delete(each);
			}
		}
	}

	/** Takes the spinner off the side's preview. */
	private markDrawn(side: string): void {
		this.existingPreview(side)
			?.closest(FRAME_SELECTOR)
			?.classList.add(DRAWN_CLASS);
	}

	/** The side's preview target; one above its form when the theme printed
	 *  no gallery to replace. */
	private previewTarget(side: string): HTMLElement {
		const existing = this.existingPreview(side);
		if (existing !== null) {
			return existing;
		}
		const preview = this.doc().createElement('div');
		preview.id = PREVIEW_ID_PREFIX + side;
		preview.className = 'pixonaut-preview__side';
		preview.dataset.pixonautSide = side;
		this.formTarget(side).before(preview);
		// Joins its side's tab at once: the side's mount may take a while,
		// or fail, and leave no later moment to hide it.
		this.tabs?.sync();
		return preview;
	}

	/** The side's preview target in this product's preview, or the one
	 *  made above the form on an earlier mount. */
	private existingPreview(side: string): HTMLElement | null {
		const id = PREVIEW_ID_PREFIX + side;
		const preview = this.preview();
		return (
			(preview !== null ? byId(preview, id) : null) ?? byId(this.container, id)
		);
	}

	/** This product's preview, where the theme printed one. */
	private preview(): HTMLElement | null {
		return this.doc().querySelector<HTMLElement>(
			`.pixonaut-preview[data-pixonaut-product="${this.productId}"]`,
		);
	}

	private doc(): Document {
		return this.container.ownerDocument;
	}
}

/** Whether two optional lists hold the same entries in the same order; an
 *  absent list is an empty one. */
function sameList(
	first: readonly unknown[] | undefined,
	second: readonly unknown[] | undefined,
): boolean {
	return JSON.stringify(first ?? []) === JSON.stringify(second ?? []);
}

/** Resolves once the current task, and any event it is dispatching, ends. */
/** The side's arrangement as the server prints it, which the SDK's own
 *  option type does not describe: attribute items stand in for the form's
 *  fields until `hostGroups()` lends them. */
function arrangedOf(
	options: SideOptions | undefined,
): ArrangedGroups | undefined {
	return (options as { formGroups?: ArrangedGroups } | undefined)?.formGroups;
}

function nextTask(): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, 0));
}

/** The element with `id` inside `root`; ids are compared as text, so a side
 *  key needs no CSS escaping. */
function byId(root: HTMLElement, id: string): HTMLElement | null {
	for (const element of root.querySelectorAll<HTMLElement>('[id]')) {
		if (element.id === id) {
			return element;
		}
	}
	return null;
}
