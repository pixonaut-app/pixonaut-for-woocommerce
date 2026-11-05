import { beforeEach, describe, expect, it } from 'vitest';
import { createFake } from '../../fake-pixonaut/src/app';
import { bearer, MAIN } from './support';

const CONFLICT = 409;
const NO_CONTENT = 204;
const PATH = 'http://fake.test/v1/connect/store-access';
const JSON_TYPE = { 'content-type': 'application/json' };

const fake = createFake();

/** Sets the studio's current request on the fake. */
async function studioRequests(requestId: string): Promise<void> {
	await fake.app.request('http://fake.test/__fake/store-access/request', {
		method: 'POST',
		headers: JSON_TYPE,
		body: JSON.stringify({ requestId }),
	});
}

/** Hands over a credential as the store does. */
async function put(body: {
	credential: string;
	requestId?: string;
}): Promise<Response> {
	return fake.app.request(PATH, {
		method: 'PUT',
		headers: { ...JSON_TYPE, ...bearer(MAIN.integrationKey) },
		body: JSON.stringify(body),
	});
}

/** What the fake holds for the main store. */
function held(): string | null | undefined {
	return fake.state.stores.get(MAIN.id)?.storeAccess;
}

beforeEach(() => {
	fake.reset();
});

describe('store access', () => {
	it('takes an answer to the current request, and a second one', async () => {
		await studioRequests('req_1');

		const first = await put({ credential: 'one', requestId: 'req_1' });
		const second = await put({ credential: 'two', requestId: 'req_1' });

		expect(first.status).toBe(NO_CONTENT);
		expect(second.status).toBe(NO_CONTENT);
		expect(held()).toBe('two');
	});

	it('refuses a stale request and changes nothing', async () => {
		await put({ credential: 'kept' });

		const answer = await put({ credential: 'late', requestId: 'req_0' });

		expect(answer.status).toBe(CONFLICT);
		expect(await answer.json()).toEqual({
			error: 'store_access_request_stale',
		});
		expect(held()).toBe('kept');
	});

	it('ends the request when the store deletes the credential', async () => {
		await studioRequests('req_1');
		await fake.app.request(PATH, {
			method: 'DELETE',
			headers: bearer(MAIN.integrationKey),
		});

		const answer = await put({ credential: 'late', requestId: 'req_1' });

		expect(answer.status).toBe(CONFLICT);
		expect(held()).toBeNull();
	});

	it('lets an opt-in in WordPress win and end the request', async () => {
		await studioRequests('req_1');

		expect((await put({ credential: 'mine' })).status).toBe(NO_CONTENT);

		const late = await put({ credential: 'late', requestId: 'req_1' });
		expect(late.status).toBe(CONFLICT);
		expect(held()).toBe('mine');
	});
});
