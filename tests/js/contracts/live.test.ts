import { describe, expect, it } from 'vitest';
import { liveConfig } from '../../../contracts/scenarios/live';

const COMPLETE = {
	PIXONAUT_LIVE_API_URL: 'http://127.0.0.1:9999',
	PIXONAUT_LIVE_TENANT: 'a-tenant',
	PIXONAUT_LIVE_EMAIL: 'member@example.test',
	PIXONAUT_LIVE_PASSWORD: 'a-password',
};

describe('liveConfig', () => {
	it('takes the Pixonaut and its member from the environment', () => {
		expect(liveConfig(COMPLETE)).toEqual({
			apiUrl: 'http://127.0.0.1:9999',
			tenant: 'a-tenant',
			member: { email: 'member@example.test', password: 'a-password' },
		});
	});

	it('names what is missing, so a run can say why it skips', () => {
		expect(
			liveConfig({ PIXONAUT_LIVE_API_URL: COMPLETE.PIXONAUT_LIVE_API_URL }),
		).toEqual({
			missing: [
				'PIXONAUT_LIVE_TENANT',
				'PIXONAUT_LIVE_EMAIL',
				'PIXONAUT_LIVE_PASSWORD',
			],
		});
	});

	it('refuses a Pixonaut that is not on this machine', () => {
		expect(() =>
			liveConfig({
				...COMPLETE,
				PIXONAUT_LIVE_API_URL: 'https://api.example.test',
			}),
		).toThrow('only a Pixonaut on this machine');
	});
});
