import type {
	BlankViewsRead,
	createDesigner,
	DesignerOptions,
	DesignerTargets,
	designerUi,
	PixonautDesigner,
	PlacementAnswer,
	SplitMountOptions,
	TemplateAnswer,
} from '../types/sdk';
import type { PixonautSdk } from './sdk-loader';
import type { SideOptions } from './settings';

/** What the server read from Pixonaut for one side's designer. */
export type SideReads = {
	template?: TemplateAnswer;
	placement?: PlacementAnswer;
	blank?: BlankViewsRead;
};

export type ProductReads = Record<string, SideReads>;

/** The SDK's global holds `@pixonaut/sdk/core`'s exports beside the
 *  default export's. */
type CoreSdk = PixonautSdk & {
	createDesigner?: typeof createDesigner;
	designerUi?: typeof designerUi;
};

const READS_SELECTOR = 'script.pixonaut-reads';

/** How old, in seconds, the server's reads may be and still be used: the
 *  photo links in them open for an hour or two, and the server keeps an
 *  answer for five minutes. A full-page cache's older copy of the page
 *  leaves the reads to the browser. */
const READS_MAX_AGE_S = 30 * 60;

const MS_PER_S = 1000;

/** The SDK release up to which `createDesigner` draws into one target
 *  alone, as `[major, minor]`: 0.6.0 takes a split mount's preview, form
 *  and views targets in `createDesigner({ previewTarget, formTarget,
 *  viewsTarget, ... })`. An older SDK ignores the form and views targets
 *  rather than refusing them. */
const LAST_SINGLE_TARGET_RELEASE = [0, 5] as const;

/** The mount options the reads were made for; a side whose options a
 *  filter or a variation changed reads again in the browser. */
const READ_KEYS = ['templateId', 'blankId', 'placementId'] as const;

/** The reads the server printed in the form, by side key; empty for none,
 *  for reads too old to use, or for JSON the page mangled. */
export function readServerReads(
	container: HTMLElement,
	now: number = Date.now(),
): ProductReads {
	const script = container.querySelector<HTMLElement>(READS_SELECTOR);
	const readAt = Number(script?.dataset.pixonautReadAt);
	if (
		script === null ||
		!Number.isFinite(readAt) ||
		now / MS_PER_S - readAt > READS_MAX_AGE_S
	) {
		return {};
	}
	try {
		const reads: unknown = JSON.parse(script.textContent ?? '');
		return typeof reads === 'object' && reads !== null
			? (reads as ProductReads)
			: {};
	} catch {
		return {};
	}
}

/**
 * Mounts a side, starting from the server's reads when there are any for
 * the options it mounts with: `createDesigner` over the targets the page
 * printed, which makes no request for them and keeps what the page printed
 * in them until the preview is drawn. Without reads, or on an SDK
 * whose `createDesigner` takes one target, the split mount reads them in
 * the browser.
 */
export function mountSide(
	sdk: CoreSdk,
	options: SplitMountOptions,
	reads: SideReads | undefined,
	readFor: SideOptions | undefined,
): Promise<PixonautDesigner> {
	if (
		reads?.template === undefined ||
		readFor === undefined ||
		!READ_KEYS.every((key) => options[key] === readFor[key]) ||
		sdk.createDesigner === undefined ||
		sdk.designerUi === undefined ||
		!drawsSplitMounts(sdk.version)
	) {
		return sdk.mount(options);
	}
	// `createDesigner` takes the values a design starts from as `values`.
	const { initialValues, ...split } = options;
	// The spread keeps the one credential the options hold, which the
	// credential union cannot follow through it.
	const designer = {
		...split,
		...(initialValues === undefined ? {} : { values: initialValues }),
		template: reads.template,
		...(reads.placement === undefined ? {} : { placement: reads.placement }),
		...(reads.blank === undefined ? {} : { blank: reads.blank }),
		ui: sdk.designerUi,
	} as DesignerOptions & DesignerTargets;
	return sdk.createDesigner(designer);
}

/** Whether an SDK of `version` draws a split mount from `createDesigner`. */
export function drawsSplitMounts(version: string): boolean {
	const [major, minor] = version.split('.').map(Number);
	const [lastMajor, lastMinor] = LAST_SINGLE_TARGET_RELEASE;
	if (
		major === undefined ||
		minor === undefined ||
		Number.isNaN(major + minor)
	) {
		return false;
	}
	return major > lastMajor || (major === lastMajor && minor > lastMinor);
}
