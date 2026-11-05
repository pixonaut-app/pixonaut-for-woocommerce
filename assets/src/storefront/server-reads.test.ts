// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import type { SplitMountOptions } from '../types/sdk';
import {
	drawsSplitMounts,
	mountSide,
	readServerReads,
	type SideReads,
} from './server-reads';
import type { SideOptions } from './settings';
import { fakeDesigner, fakeSdk, MOUNTS } from './test-support';

/** An SDK release after the last whose `createDesigner` takes one target. */
const SPLIT_SDK = '0.6.0';

const READS = {
	template: { status: 'live', formSchema: { steps: [] } },
	placement: { templateId: 'mini-tee@2' },
	blank: { mockups: { placements: [] }, variants: { variants: [] } },
} as unknown as SideReads;

const UI = { __pixonautDesignerUi: true };

function targets() {
	return {
		previewTarget: document.createElement('div'),
		formTarget: document.createElement('div'),
		viewsTarget: document.createElement('div'),
	};
}

/** An SDK of `version` with `@pixonaut/sdk/core`'s exports. */
function coreSdk(version: string) {
	const { sdk, mount, designer } = fakeSdk();
	const createDesigner = vi.fn().mockResolvedValue(designer);
	Object.assign(sdk, { version, createDesigner, designerUi: UI });
	return { sdk, mount, createDesigner };
}

/** When the page in these tests was made, in Unix seconds. */
const READ_AT = 1_790_000_000;

const MS_PER_S = 1000;

const MINUTE_S = 60;

function form(json: string, readAt: number | null = READ_AT): HTMLElement {
	const container = document.createElement('div');
	const stamp = readAt === null ? '' : ` data-pixonaut-read-at="${readAt}"`;
	container.innerHTML = `<script type="application/json" class="pixonaut-reads"${stamp}>${json}</script>`;
	return container;
}

describe('readServerReads', () => {
	it('reads the reads the server printed in the form, by side', () => {
		const container = form(JSON.stringify({ front: READS }));

		expect(readServerReads(container, (READ_AT + MINUTE_S) * MS_PER_S)).toEqual(
			{ front: READS },
		);
	});

	it('leaves reads to the browser on a page a cache kept for longer than its links last', () => {
		const container = form(JSON.stringify({ front: READS }));

		expect(
			readServerReads(container, (READ_AT + 31 * MINUTE_S) * MS_PER_S),
		).toEqual({});
		expect(
			readServerReads(form(JSON.stringify({ front: READS }), null)),
		).toEqual({});
	});

	it('reads none from a form without them, or with mangled JSON', () => {
		expect(readServerReads(document.createElement('div'))).toEqual({});
		expect(readServerReads(form('{"front":'), READ_AT * MS_PER_S)).toEqual({});
	});
});

describe('mountSide', () => {
	const options = { ...MOUNTS.front, ...targets() } as SplitMountOptions;
	const readFor = MOUNTS.front as SideOptions;

	it('draws the split mount over the targets from the reads on an SDK that takes them', async () => {
		const { sdk, mount, createDesigner } = coreSdk(SPLIT_SDK);

		await mountSide(sdk, options, READS, readFor);

		expect(mount).not.toHaveBeenCalled();
		expect(createDesigner).toHaveBeenCalledWith({
			...options,
			template: READS.template,
			placement: READS.placement,
			blank: READS.blank,
			ui: UI,
		});
	});

	it('leaves a side without a blank without a placement and a blank', async () => {
		const { sdk, createDesigner } = coreSdk(SPLIT_SDK);

		await mountSide(sdk, options, { template: READS.template }, readFor);

		const passed = createDesigner.mock.calls[0]?.[0];
		expect(passed).not.toHaveProperty('placement');
		expect(passed).not.toHaveProperty('blank');
	});

	it('starts a remounted side from the values it keeps', async () => {
		const { sdk, createDesigner } = coreSdk(SPLIT_SDK);
		const values = { slogan: { text: 'Ada' } };

		await mountSide(
			sdk,
			{ ...options, initialValues: values } as SplitMountOptions,
			READS,
			readFor,
		);

		const passed = createDesigner.mock.calls[0]?.[0];
		expect(passed).toHaveProperty('values', values);
		expect(passed).not.toHaveProperty('initialValues');
	});

	it('mounts as before on an SDK whose createDesigner takes one target', async () => {
		const { sdk, mount, createDesigner } = coreSdk('0.5.0');

		await mountSide(sdk, options, READS, readFor);

		expect(createDesigner).not.toHaveBeenCalled();
		expect(mount).toHaveBeenCalledWith(options);
	});

	it('reads in the browser without reads, or when the options were changed after them', async () => {
		const { sdk, mount, createDesigner } = coreSdk(SPLIT_SDK);

		await mountSide(sdk, options, undefined, readFor);
		await mountSide(
			sdk,
			{ ...options, templateId: 'other@1' } as SplitMountOptions,
			READS,
			readFor,
		);
		await mountSide(sdk, options, READS, undefined);

		expect(createDesigner).not.toHaveBeenCalled();
		expect(mount).toHaveBeenCalledTimes(3);
	});

	it('reads in the browser on an SDK without the core exports', async () => {
		const { sdk, mount } = fakeSdk(fakeDesigner());
		Object.assign(sdk, { version: SPLIT_SDK });

		await mountSide(sdk, options, READS, readFor);

		expect(mount).toHaveBeenCalledWith(options);
	});
});

describe('drawsSplitMounts', () => {
	it.each([
		['0.5.0', false],
		['0.5.9', false],
		['0.4.2', false],
		['0.6.0', true],
		['1.0.0', true],
		['dev', false],
	])('SDK %s: %s', (version, draws) => {
		expect(drawsSplitMounts(version)).toBe(draws);
	});
});
