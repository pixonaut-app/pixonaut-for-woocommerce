/**
 * How a value of an attribute looks as a swatch (`attribute-swatches.ts`):
 * its colour, two colours for a heather or two-tone, or its picture, as
 * Pixonaut draws the value. The server works the looks out for the
 * product's attributes (`SwatchLooks`) and prints them on the form in
 * `data-pixonaut-swatches`; it prints the swatches the page opens with in
 * the same markup (`PrintedSwatches`). A value without a look is drawn as
 * its name.
 */

export type SwatchLook =
	| { kind: 'fill'; colours: string[] }
	| { kind: 'image'; url: string };

/** One attribute's looks. */
export type AttributeLooks = {
	/** Whether a swatch with a look shows the value's name beside it; when
	 *  not, the name is its accessible name and tooltip, and the chosen
	 *  value's name shows beside the attribute's. */
	names: boolean;
	/** Each value's look, by the value its field posts. */
	looks: Record<string, SwatchLook>;
};

/** Each attribute's looks, by the name of the field it is posted as
 *  (`attribute_pa_frame`). */
export type SwatchLooks = Record<string, AttributeLooks>;

/** On a swatch drawn with its value's look. */
export const LOOK_CLASS = 'has-look';

/** On a swatch with a look and no name beside it. */
export const BARE_CLASS = 'is-bare';

const LOOK_PART_CLASS = 'pixonaut-swatches__look';
const NAME_PART_CLASS = 'pixonaut-swatches__name';

/** The chosen value's name beside an attribute's whose swatches show
 *  none. */
const CHOSEN_NAME_CLASS = 'pixonaut-swatches__chosen-name';

/** A colour as Pixonaut writes a fill's, `#rrggbb`; anything else is not
 *  put in a style. */
const HEX_COLOUR = /^#[0-9a-f]{6}$/i;

/** Most colours a fill is drawn in: two for a heather or two-tone. */
const MAX_FILL_COLOURS = 2;

/** The schemes a picture's link may have. */
const IMAGE_PROTOCOLS = new Set(['https:', 'http:']);

/** The looks the form printed; empty for none, or for JSON the page
 *  mangled. */
export function readSwatchLooks(container: HTMLElement): SwatchLooks {
	const raw = container.dataset.pixonautSwatches;
	if (raw === undefined || raw === '') {
		return {};
	}
	try {
		const parsed: unknown = JSON.parse(raw);
		return typeof parsed === 'object' && parsed !== null
			? (parsed as SwatchLooks)
			: {};
	} catch {
		return {};
	}
}

/** The pictures that failed to load on this page, by link: a value whose
 *  picture is one of them is drawn as its name. */
const failedPictures = new Set<string>();

/** A value's look in `looks`, by the value its field posts; undefined for a
 *  value with none, or with anything but a look. Only the attribute's own
 *  entries count: a value such as `constructor` is not the prototype's. */
export function lookOf(
	looks: AttributeLooks | undefined,
	value: string,
): SwatchLook | undefined {
	if (looks === undefined || !Object.hasOwn(looks.looks, value)) {
		return undefined;
	}
	const look: unknown = looks.looks[value];
	if (typeof look !== 'object' || look === null) {
		return undefined;
	}
	const { kind } = look as { kind?: unknown };
	if (kind === 'image' && typeof (look as { url?: unknown }).url === 'string') {
		return look as SwatchLook;
	}
	if (
		kind === 'fill' &&
		Array.isArray((look as { colours?: unknown }).colours)
	) {
		return look as SwatchLook;
	}
	return undefined;
}

/**
 * Fills `swatch` with a value's words, and its look when it has one: the
 * look before the name, or the look alone with the name as its accessible
 * name and tooltip. A picture that fails to load gives way to the name, on
 * this redraw and every later one.
 */
export function drawSwatchFace(
	swatch: HTMLElement,
	label: string,
	look: SwatchLook | undefined,
	names: boolean,
): void {
	const face = look === undefined ? null : lookPart(swatch, look);
	const image = face?.querySelector('img');
	if (image != null && look?.kind === 'image') {
		image.onerror = () => {
			failedPictures.add(image.src);
			drawSwatchFace(swatch, label, look, names);
		};
	}
	const bare = face !== null && !names;
	swatch.classList.toggle(LOOK_CLASS, face !== null);
	swatch.classList.toggle(BARE_CLASS, bare);
	if (bare) {
		swatch.setAttribute('aria-label', label);
		swatch.title = label;
	} else {
		swatch.removeAttribute('aria-label');
		swatch.removeAttribute('title');
	}
	if (face === null) {
		swatch.textContent = label;
		return;
	}
	const parts: Node[] = [face];
	if (!bare) {
		const name = swatch.ownerDocument.createElement('span');
		name.className = NAME_PART_CLASS;
		name.textContent = label;
		parts.push(name);
	}
	swatch.replaceChildren(...parts);
}

/** The look's element; null for a look it cannot draw safely. Reuses the
 *  swatch's picture when it shows the same one, so a redraw does not load
 *  it again. */
function lookPart(swatch: HTMLElement, look: SwatchLook): HTMLElement | null {
	const document = swatch.ownerDocument;
	const kept = swatch.querySelector<HTMLElement>(`.${LOOK_PART_CLASS}`);
	if (look.kind === 'image') {
		const url = safeUrl(look.url, document.baseURI);
		if (url === null || failedPictures.has(url)) {
			return null;
		}
		if (kept?.querySelector('img')?.getAttribute('src') === url) {
			return kept;
		}
		const part = document.createElement('span');
		part.className = LOOK_PART_CLASS;
		const image = document.createElement('img');
		image.alt = '';
		image.decoding = 'async';
		image.src = url;
		part.append(image);
		return part;
	}
	const fill = fillOf(look.colours);
	if (fill === null) {
		return null;
	}
	const part = document.createElement('span');
	part.className = LOOK_PART_CLASS;
	part.style.background = fill;
	return part;
}

/** The CSS a fill paints with: its colour, or two split corner to corner,
 *  as `PrintedSwatches` prints it. */
function fillOf(colours: readonly unknown[]): string | null {
	if (!colours.every((colour) => HEX_COLOUR.test(String(colour)))) {
		return null;
	}
	const [first, second] = colours as string[];
	if (first === undefined || colours.length > MAX_FILL_COLOURS) {
		return null;
	}
	return second === undefined
		? first
		: `linear-gradient(135deg, ${first} 50%, ${second} 50%)`;
}

function safeUrl(raw: string, base: string): string | null {
	try {
		const url = new URL(raw, base);
		return IMAGE_PROTOCOLS.has(url.protocol) ? url.href : null;
	} catch {
		return null;
	}
}

/**
 * Shows the chosen value's name after the attribute's own (`label`), for
 * an attribute whose swatches show no names; takes it away when nothing is
 * chosen or when `name` is null. It repeats what the chosen swatch already
 * says to assistive technology, so it is hidden from it.
 */
export function showChosenName(
	label: HTMLElement | null,
	name: string | null,
): void {
	if (label === null) {
		return;
	}
	const next = label.nextElementSibling;
	const shown = next?.classList.contains(CHOSEN_NAME_CLASS)
		? (next as HTMLElement)
		: null;
	if (name === null) {
		shown?.remove();
		return;
	}
	const element = shown ?? label.ownerDocument.createElement('span');
	element.className = CHOSEN_NAME_CLASS;
	element.setAttribute('aria-hidden', 'true');
	element.textContent = name;
	if (shown === null) {
		label.after(element);
	}
}
