import { describe, expect, it } from 'vitest';
import { fakeTarget } from '../../../contracts/scenarios/fake';
import { describeReport, runScenarios } from '../../../contracts/scenarios/run';
import { PROOFS, type Proof } from '../../fake-pixonaut/src/proofs';
import { allOwned } from '../../fake-pixonaut/src/state';

/** A blank's mockups or variants, as the SDK reads them. */
const BLANK_READ = /^\/v1\/blanks\/[^/]+\/(mockups|variants)$/;

/** What a publishable key is sent behind. */
const PUBLISHABLE_BEARER = 'Bearer pk_';

/** A full scenario run takes about 1 s alone and several on a loaded machine. */
const SCENARIO_RUN_TIMEOUT_MS = 60_000;

describe('the scenario suite against the fake', {
	timeout: SCENARIO_RUN_TIMEOUT_MS,
}, () => {
	it('runs every scenario within the pinned reference and sends every consumed operation', async () => {
		const target = fakeTarget();

		const report = await runScenarios(target);

		expect(report.passed, describeReport(report).join('\n')).toBe(true);
		expect(report.uncalled).toEqual([]);
		expect(target.fake.violations).toEqual([]);
	});

	it("reads the blank with the publishable key, as the SDK does, and proofs on a variant's view", async () => {
		const target = fakeTarget();
		const send = target.fetch;
		const storefrontReads: { path: string; status: number }[] = [];
		target.fetch = async (path, init) => {
			const response = await send(path, init);
			const authorization = new Headers(init.headers).get('authorization');
			if (
				BLANK_READ.test(path) &&
				authorization?.startsWith(PUBLISHABLE_BEARER) === true
			) {
				storefrontReads.push({ path, status: response.status });
			}
			return response;
		};

		await runScenarios(target);

		expect(storefrontReads.map((read) => read.path.split('/').at(-1))).toEqual([
			'mockups',
			'variants',
		]);
		expect(storefrontReads.every((read) => read.status === 200)).toBe(true);
		const previewed = [
			...allOwned<Proof>(target.fake.state, PROOFS).values(),
		].filter(({ value }) => value.variant !== null && value.viewId !== null);
		expect(previewed).not.toEqual([]);
	});
});
