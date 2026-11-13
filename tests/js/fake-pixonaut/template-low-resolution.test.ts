import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { bearer, MAIN, SITE_URL, send, templatePath } from './support';

const fake = createFake();
const asStore = { headers: bearer(MAIN.integrationKey) };

/** The tee's 280:360 shape at its fitted size. */
const TEE_FITTED = { widthMm: 280, heightMm: 360 };

/** The tee's shape 1000 mm wide: where the photo prints too soft. */
const HUGE = { widthMm: 1000, heightMm: (1000 * 360) / 280 };

/** A picture 4800 px wide: about 122 dpi across 1000 mm, 435 across 280. */
const PHOTO = { layerId: 'photo', assetId: 'ast_photo', widthPx: 4800 };

/** Above the photo's 435 dpi at the tee's fitted size, so it prints too soft there. */
const SHARP_AT_FITTED_DPI = 600;

/** Digits of the dpi a test compares. */
const DPI_DIGITS = 2;

function setLowResolution(json: unknown) {
	return send(
		fake,
		`/__fake/templates/${FIXTURE.template.live}/low-resolution`,
		{ method: 'POST', json },
	);
}

async function judge(json: unknown) {
	const response = await send(
		fake,
		templatePath(FIXTURE.template.live, '/print-size'),
		{ ...asStore, method: 'POST', json },
	);
	expect(response.status).toBe(200);
	return response.json();
}

beforeEach(() => {
	fake.reset('default', SITE_URL);
});

describe('POST /__fake/templates/:id/low-resolution', () => {
	it('names an image a size prints below its recommended resolution, and no other', async () => {
		await setLowResolution({ images: [PHOTO], recommendedDpi: 300 });

		const { verdicts } = await judge({ sizes: [TEE_FITTED, HUGE] });

		expect(verdicts[0]).toEqual({ legal: true, lowResolution: [] });
		expect(verdicts[1].legal).toBe(true);
		expect(verdicts[1].lowResolution).toEqual([
			{
				layerId: 'photo',
				assetId: 'ast_photo',
				label: 'Picture',
				effectiveDpi: expect.closeTo(121.92, DPI_DIGITS),
				recommendedDpi: 300,
			},
		]);
	});

	it('refuses the size low_resolution under refuse', async () => {
		await setLowResolution({ images: [PHOTO], action: 'refuse' });

		const verdict = await judge(HUGE);

		expect(verdict.legal).toBe(false);
		expect(verdict.reason).toBe('low_resolution');
		expect(verdict.lowResolution).toHaveLength(1);
	});

	it('names the image on the template fitted to a placement too', async () => {
		await setLowResolution({
			images: [PHOTO],
			recommendedDpi: SHARP_AT_FITTED_DPI,
		});

		const response = await send(
			fake,
			`/v1/blanks/${FIXTURE.blank.tee}/placements/${FIXTURE.placement.front}/fitting-templates`,
			asStore,
		);

		expect(response.status).toBe(200);
		const { items } = await response.json();
		const live = items.find(
			(item: { templateId: string }) =>
				item.templateId === FIXTURE.template.live,
		);
		expect(live.lowResolution).toEqual([
			expect.objectContaining({ layerId: 'photo', assetId: 'ast_photo' }),
		]);
		expect(
			items
				.filter((item: { templateId: string }) => item !== live)
				.every(
					(item: { lowResolution: unknown[] }) =>
						item.lowResolution.length === 0,
				),
		).toBe(true);
	});

	it('answers an image’s label when it has one', async () => {
		await setLowResolution({ images: [{ ...PHOTO, label: 'Photo' }] });

		const [image] = (await judge(HUGE)).lowResolution;

		expect(image).toMatchObject({ layerId: 'photo', label: 'Photo' });
	});

	it('forgets it with no images, and at the next reset', async () => {
		await setLowResolution({ images: [PHOTO] });
		await setLowResolution({ images: [] });
		expect((await judge(HUGE)).lowResolution).toEqual([]);

		await setLowResolution({ images: [PHOTO] });
		fake.reset('default', SITE_URL);
		expect((await judge(HUGE)).lowResolution).toEqual([]);
	});

	it('refuses what is no setup, and a template it lacks', async () => {
		const broken = await setLowResolution({ images: [{ layerId: 'x' }] });
		expect(broken.ok).toBe(false);
		const badLabel = await setLowResolution({
			images: [{ ...PHOTO, label: 1 }],
		});
		expect(badLabel.ok).toBe(false);
		const unknownAction = await setLowResolution({
			images: [],
			action: 'ban',
		});
		expect(unknownAction.ok).toBe(false);
		const missing = await send(
			fake,
			'/__fake/templates/no-such-template@1/low-resolution',
			{ method: 'POST', json: { images: [] } },
		);
		expect(missing.status).toBe(404);
	});
});
