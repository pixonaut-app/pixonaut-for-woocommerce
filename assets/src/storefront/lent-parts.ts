import type { SplitMountOptions } from '../types/sdk';

/** The tag of the element standing in for a part the designer is lent. */
const STAND_IN_TAG = 'pixonaut-lent-part';

/** What a part's controls may submit. */
const CONTROLS_SELECTOR = 'input, select, textarea, button';

/** The prefix of the id a form gets when a control has to name it. */
const FORM_ID_PREFIX = 'pixonaut-form-';

let formIds = 0;

/**
 * Stands in for a part of the add-to-cart form that is in a slot of the printed
 * form, inside the element the designer draws into. The designer warns of an
 * element lent from there, as it would not put it back, which the plugin does
 * itself (`returnShopParts`). This element is lent in its place, with nothing
 * around it, and takes the part only as the designer draws it. The part keeps
 * its form and its box sizing there, as the designer keeps a lent element's.
 */
class LentPart extends HTMLElement {
	lent: HTMLElement | null = null;

	owner: HTMLFormElement | null = null;

	/**
	 * The box sizing the part has in its slot, read once as it first connects:
	 * in this element it no longer matches what styled it there. Null while the
	 * part's own inline style sets one.
	 */
	private boxSizing: string | null = null;

	private undo: (() => void)[] = [];

	connectedCallback(): void {
		const { lent: part, owner } = this;
		if (part === null) {
			return;
		}
		this.keepBoxSizing(part);
		if (part.parentElement !== this) {
			this.append(part);
		}
		if (owner !== null) {
			this.keepForm(part, owner);
		}
	}

	disconnectedCallback(): void {
		for (const step of this.undo.splice(0).reverse()) {
			step();
		}
	}

	private keepBoxSizing(part: HTMLElement): void {
		if (this.boxSizing === null && part.style.boxSizing === '') {
			this.boxSizing = getComputedStyle(part).boxSizing;
		}
		const { boxSizing } = this;
		if (boxSizing === null) {
			return;
		}
		part.style.boxSizing = boxSizing;
		this.undo.push(() => part.style.removeProperty('box-sizing'));
	}

	private keepForm(part: HTMLElement, owner: HTMLFormElement): void {
		if (owner.id === '') {
			formIds += 1;
			owner.id = `${FORM_ID_PREFIX}${formIds}`;
			this.undo.push(() => owner.removeAttribute('id'));
		}
		for (const control of part.querySelectorAll(CONTROLS_SELECTOR)) {
			if (control.getAttribute('form') === null) {
				control.setAttribute('form', owner.id);
				this.undo.push(() => control.removeAttribute('form'));
			}
		}
	}
}

/** Each part's stand-in, so a designer drawn again takes the same one. */
const standIns = new WeakMap<HTMLElement, LentPart>();

function standInFor(
	part: HTMLElement,
	owner: HTMLFormElement | null,
): LentPart {
	if (customElements.get(STAND_IN_TAG) === undefined) {
		customElements.define(STAND_IN_TAG, LentPart);
	}
	let standIn = standIns.get(part);
	if (standIn === undefined) {
		standIn = new LentPart();
		standIn.style.display = 'contents';
		standIn.lent = part;
		standIns.set(part, standIn);
	}
	standIn.owner = owner;
	return standIn;
}

/**
 * The mount options with a stand-in lent for each part inside an element
 * the designer draws into, or still in its stand-in from a designer before.
 */
export function withStandIns<T extends SplitMountOptions>(
	options: T,
	owner: HTMLFormElement | null,
): T {
	const roots = [
		options.previewTarget,
		options.formTarget,
		options.viewsTarget,
	].filter((target) => target instanceof Element);
	const lend = (part: HTMLElement): HTMLElement => {
		const held = standIns.get(part)?.contains(part) ?? false;
		return held || roots.some((root) => root.contains(part))
			? standInFor(part, owner)
			: part;
	};
	const { optionsStep, lastStepContent, formGroups } = options;
	return {
		...options,
		...(optionsStep?.content === undefined
			? {}
			: {
					optionsStep: {
						...optionsStep,
						content: lend(optionsStep.content),
					},
				}),
		...(lastStepContent === undefined
			? {}
			: { lastStepContent: lend(lastStepContent) }),
		...(formGroups === undefined
			? {}
			: {
					formGroups: {
						...formGroups,
						groups: formGroups.groups.map((group) => ({
							...group,
							items: group.items.map((item) =>
								'element' in item
									? { ...item, element: lend(item.element) }
									: item,
							),
						})),
					},
				}),
	};
}
