/** Each side's tab, carrying its side in `data-pixonaut-side`. */
const TAB_SELECTOR = '.pixonaut-tabs__tab';

/** Everything that belongs to one side and shows only while its tab is
 *  selected: its form, its preview and, in the checkbox approval mode, its
 *  proof button and link. */
const SIDE_PART_SELECTOR =
	'.pixonaut-form__side, .pixonaut-preview__frame, .pixonaut-preview__side, .pixonaut-views, .pixonaut-approval__proof';

/** The keys that move between tabs, and where each one goes. */
const KEY_MOVES: Record<string, (index: number, count: number) => number> = {
	ArrowRight: (index, count) => (index + 1) % count,
	ArrowLeft: (index, count) => (index - 1 + count) % count,
	Home: () => 0,
	End: (_index, count) => count - 1,
};

/**
 * The side tabs of a product with more than one side: one tab per side, which
 * shows that side's form and preview and hides the others'. Follows the WAI-ARIA
 * tabs pattern with automatic activation: the arrow keys, Home and End move
 * between tabs and select the one they land on; only the selected tab is in
 * the tab order.
 */
export class SideTabs {
	private constructor(
		private readonly container: HTMLElement,
		private readonly preview: () => HTMLElement | null,
		private readonly tabs: HTMLElement[],
		private readonly onShow: (side: string) => void,
	) {}

	/** The container's side tabs; null for a product with one side, which
	 *  the server prints without them. `onShow` hears of each side whose
	 *  tab another side's gives way to. */
	static find(
		container: HTMLElement,
		preview: () => HTMLElement | null,
		onShow: (side: string) => void = () => undefined,
	): SideTabs | null {
		const tabs = Array.from(
			container.querySelectorAll<HTMLElement>(TAB_SELECTOR),
		);
		return tabs.length === 0
			? null
			: new SideTabs(container, preview, tabs, onShow);
	}

	start(): void {
		for (const tab of this.tabs) {
			tab.addEventListener('click', () => this.select(sideOf(tab)));
			tab.addEventListener('keydown', (event) => this.onKey(event, tab));
		}
		this.sync();
	}

	/** The side whose tab is selected. */
	selected(): string {
		const tab =
			this.tabs.find((t) => t.getAttribute('aria-selected') === 'true') ??
			this.tabs[0];
		return tab === undefined ? '' : sideOf(tab);
	}

	/** Selects the side's tab and shows its parts; an unknown side is ignored. */
	select(side: string): void {
		if (!this.tabs.some((tab) => sideOf(tab) === side)) {
			return;
		}
		const before = this.selected();
		for (const tab of this.tabs) {
			const selected = sideOf(tab) === side;
			tab.setAttribute('aria-selected', String(selected));
			tab.tabIndex = selected ? 0 : -1;
		}
		this.sync();
		if (side !== before) {
			this.onShow(side);
		}
	}

	/**
	 * Shows only the tabs of `sides`, the sides the chosen variation has,
	 * moving the selection to the first of them when its side is not one;
	 * with fewer than two, the tabs go. An empty list shows every tab.
	 */
	limit(sides: readonly string[]): void {
		const shown = this.tabs.filter(
			(tab) => sides.length === 0 || sides.includes(sideOf(tab)),
		);
		for (const tab of this.tabs) {
			tab.hidden = !shown.includes(tab);
		}
		const list = this.tabs[0]?.parentElement;
		if (list !== null && list !== undefined) {
			list.hidden = shown.length < 2;
		}
		const first = shown[0];
		if (
			first !== undefined &&
			!shown.some((tab) => sideOf(tab) === this.selected())
		) {
			this.select(sideOf(first));
		}
	}

	/** Shows the selected side's parts and hides the others', including parts
	 *  made after the tabs started (a preview made above the form). */
	sync(): void {
		const side = this.selected();
		const preview = this.preview();
		const roots =
			preview === null ? [this.container] : [this.container, preview];
		for (const root of roots) {
			for (const part of root.querySelectorAll<HTMLElement>(
				SIDE_PART_SELECTOR,
			)) {
				const owner = part.dataset.pixonautSide;
				if (owner !== undefined) {
					part.hidden = owner !== side;
				}
			}
		}
	}

	private onKey(event: KeyboardEvent, tab: HTMLElement): void {
		const move = KEY_MOVES[event.key];
		if (move === undefined) {
			return;
		}
		event.preventDefault();
		const shown = this.tabs.filter((t) => !t.hidden);
		const next = shown[move(shown.indexOf(tab), shown.length)];
		if (next !== undefined) {
			this.select(sideOf(next));
			next.focus();
		}
	}
}

function sideOf(tab: HTMLElement): string {
	return tab.dataset.pixonautSide ?? '';
}
