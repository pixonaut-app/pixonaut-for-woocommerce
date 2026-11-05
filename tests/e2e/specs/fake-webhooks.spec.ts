import { createHmac } from 'node:crypto';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { expect, restUrl, TESTS_SITE_ON_NETWORK, test } from '../fixtures';

const SINK_ROUTE = '/pixonaut-e2e/v1/webhook-sink';

const MAIN = FIXTURE.store.main;

type Sunk = { body: string; timestamp: string; signature: string };

test('an emitted event reaches the tests site by its network name', async ({
	request,
	fake,
}) => {
	await request.delete(restUrl(SINK_ROUTE));
	const endpoint = await fake.control.post('/__fake/webhooks/endpoint', {
		data: { url: `${TESTS_SITE_ON_NETWORK}${restUrl(SINK_ROUTE)}` },
	});
	expect(endpoint.ok()).toBe(true);

	const emitted = await fake.control.post('/__fake/webhooks/emit', {
		data: {
			events: [
				{
					type: 'design.approved',
					payload: {
						designId: '6f1c8a2b-3d4e-4f5a-8b6c-7d8e9f0a1b2c',
						approvedVia: 'explicit',
					},
				},
			],
		},
	});

	expect(emitted.ok()).toBe(true);
	expect((await emitted.json()).attempts).toMatchObject([{ status: 204 }]);
	const sunk: Sunk[] = await (await request.get(restUrl(SINK_ROUTE))).json();
	expect(sunk).toHaveLength(1);
	const [delivery] = sunk as [Sunk];
	expect(JSON.parse(delivery.body)).toMatchObject({ type: 'design.approved' });
	const mac = createHmac('sha256', MAIN.webhook.secret)
		.update(`${delivery.timestamp}.${delivery.body}`, 'utf8')
		.digest('hex');
	expect(delivery.signature).toBe(`v1=${mac}`);
});
