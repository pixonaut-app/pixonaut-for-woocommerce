/** A step's heading on a form the SDK lays out as a page: a child of its
 *  form, the steps layout's own heading being one level down. */
const PAGE_HEADING_SELECTOR =
	'[data-testid="pixonaut-form"]:not([data-layout="steps"]) > h3';

/** The SDK writes a page's step heading as `<n>. <label>`. */
const NUMBERED_HEADING = /^(\d+)\. ([\s\S]*)$/;

/** The class of a heading drawn with its number apart. */
export const STEP_CLASS = 'pixonaut-step';

/** The class of a heading the SDK drew, its number taken out. A later look
 *  must pass it over: its label may itself start like a number. */
export const PLAIN_STEP_CLASS = 'pixonaut-step-plain';

/** The steps layout's "Step 1 of 4" line. */
const STEP_COUNTER_SELECTOR = '[data-testid="form-step-counter"]';

/** How the numbers of a form's groups are drawn. */
export type StepNumbers = {
	/** Whether its headings take the theme's accent, the number in a badge. */
	badges: boolean;
	/** Whether it shows its numbers. */
	numbered: boolean;
};

/**
 * The heading and its badge, inline: the SDK shields what it draws from the
 * page's stylesheets (`all: revert` under a selector weighing three ids),
 * which an inline style alone outweighs. A theme restyles them by the
 * custom properties, which the shield leaves alone.
 *
 * The heading takes the theme's heading font at its `large` size, and the
 * number its `medium` size, each falling back to what the SDK drew.
 */
function headingStyle(sdk: CSSStyleDeclaration): Record<string, string> {
	return {
		display: 'flex',
		'align-items': 'center',
		gap: 'var(--wp--preset--spacing--30, 0.5em)',
		color: 'var(--pixonaut-step-color, var(--wp--preset--color--accent))',
		'font-family': `var(--wp--preset--font-family--heading, ${sdk.fontFamily || 'inherit'})`,
		'font-size': `var(--wp--preset--font-size--large, ${sdk.fontSize || 'inherit'})`,
	};
}

const NUMBER_STYLE = {
	display: 'inline-grid',
	'place-content': 'center',
	flex: 'none',
	// Twice the number's size.
	width: '2em',
	height: '2em',
	'border-radius': '50%',
	background:
		'var(--pixonaut-step-number-background, var(--wp--preset--color--accent))',
	color:
		'var(--pixonaut-step-number-color, var(--wp--preset--color--accent-contrast, #fff))',
	'font-size': 'var(--wp--preset--font-size--medium, 0.75em)',
	'line-height': '1',
};

/**
 * Draws the number of each step of the designer's form, laid out as a
 * page, as a badge of its own beside the step's label
 * (`.pixonaut-step__number`, `.pixonaut-step__label`), in the theme's
 * accent, and takes the numbers out of a form that shows none: the page's
 * and the steps layout's counter. The SDK has no option for either, so a
 * look at what it drew runs before the browser paints it. Watches
 * `container` for as long as the page lives, since the SDK draws its form
 * again when it changes.
 */
export function drawStepNumbers(
	container: HTMLElement,
	numbers: StepNumbers,
): void {
	const draw = () => {
		for (const heading of container.querySelectorAll<HTMLElement>(
			PAGE_HEADING_SELECTOR,
		)) {
			drawHeading(heading, numbers);
		}
		if (!numbers.numbered) {
			for (const counter of container.querySelectorAll<HTMLElement>(
				STEP_COUNTER_SELECTOR,
			)) {
				counter.style.setProperty('display', 'none');
			}
		}
	};
	draw();
	new MutationObserver(draw).observe(container, {
		childList: true,
		subtree: true,
	});
}

function drawHeading(heading: HTMLElement, numbers: StepNumbers): void {
	if (
		heading.classList.contains(STEP_CLASS) ||
		heading.classList.contains(PLAIN_STEP_CLASS)
	) {
		return;
	}
	const match = NUMBERED_HEADING.exec(heading.textContent ?? '');
	if (match === null) {
		return;
	}
	const [, number, label] = match;
	if (!numbers.badges) {
		heading.classList.add(PLAIN_STEP_CLASS);
		heading.textContent = label;
		return;
	}
	const doc = heading.ownerDocument;
	const numberElement = doc.createElement('span');
	numberElement.className = `${STEP_CLASS}__number`;
	numberElement.textContent = number;
	setStyle(numberElement, NUMBER_STYLE);
	const labelElement = doc.createElement('span');
	labelElement.className = `${STEP_CLASS}__label`;
	labelElement.textContent = label;
	heading.classList.add(STEP_CLASS);
	setStyle(heading, headingStyle(heading.style));
	if (numbers.numbered) {
		heading.replaceChildren(numberElement, labelElement);
	} else {
		heading.replaceChildren(labelElement);
	}
}

function setStyle(element: HTMLElement, style: Record<string, string>): void {
	for (const [property, value] of Object.entries(style)) {
		element.style.setProperty(property, value);
	}
}
