import { beforeEach, describe, expect, it } from 'vitest';
import {
	createFake,
	FAKE_FAILED_STATUS,
	VIOLATION_STATUS,
} from '../../fake-pixonaut/src/app';
import { FIXTURE } from '../../fake-pixonaut/src/fixture-ids';
import { readTemplate } from '../../fake-pixonaut/src/handlers/templates';
import { bearer, MAIN, SITE_URL, send, templatePath } from './support';

/** A constraints answer missing the required `ratio`. */
const BROKEN_CONSTRAINTS = { templateId: 'x@1', ratioTolerance: 0 };

/** The body Pixonaut's `text/plain` 500 is declared to carry. */
const INTERNAL_ERROR_TEXT = 'Internal Server Error';

const fake = createFake({
	handlers: {
		// Scripted by the template id the test asks for.
		'GET /v1/templates/{id}': (context) => {
			switch (context.params.id) {
				case 'text-wrong@1':
					return { status: 500, body: 'oops', contentType: 'text/plain' };
				case 'text-right@1':
					return {
						status: 500,
						body: INTERNAL_ERROR_TEXT,
						contentType: 'text/plain',
					};
				case 'missing@1':
					// The reference declares this 404 without a body.
					return { status: 404, body: { invented: true } };
				default:
					return readTemplate(context);
			}
		},
		// Answers with what the reference does not allow, to prove the fake
		// catches its own mistakes too.
		'GET /v1/templates/{id}/constraints': (context) =>
			context.params.id === 'broken@1'
				? { status: 200, body: BROKEN_CONSTRAINTS }
				: { status: 418, body: { error: 'teapot' } },
	},
});

/** A fake whose template read throws, as a broken handler would. */
const failing = createFake({
	handlers: {
		'GET /v1/templates/{id}': () => {
			throw new Error('handler broke');
		},
	},
});

beforeEach(() => {
	fake.reset('default', SITE_URL);
	failing.reset('default', SITE_URL);
});

describe('request validation', () => {
	it('answers a request the reference allows', async () => {
		const response = await send(fake, templatePath(FIXTURE.template.live), {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(200);
		expect(fake.violations).toEqual([]);
	});

	it('fails a query parameter outside its schema, not with a 400', async () => {
		const response = await send(
			fake,
			`${templatePath(FIXTURE.template.live, '/constraints')}?blankId=&placementId=front`,
			{ headers: bearer(MAIN.integrationKey) },
		);

		expect(response.status).toBe(VIOLATION_STATUS);
		const body = await response.json();
		expect(body.error).toBe('contract_violation');
		expect(body.violations[0]).toMatchObject({
			direction: 'request',
			at: 'query.blankId',
		});
	});

	it('fails a query parameter the operation does not declare', async () => {
		const response = await send(
			fake,
			`${templatePath(FIXTURE.template.live)}?expand=all`,
			{ headers: bearer(MAIN.integrationKey) },
		);

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([
			{ direction: 'request', at: 'query.expand' },
		]);
	});

	it('fails a method and path no operation has', async () => {
		const response = await send(fake, templatePath(FIXTURE.template.live), {
			method: 'DELETE',
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([{ at: 'path' }]);
	});

	it('fails a path with a malformed percent-escape', async () => {
		const response = await send(fake, '/v1/templates/%FF', {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([
			{ direction: 'request', at: 'path' },
		]);
	});

	it('logs every violation for the test to read', async () => {
		await send(fake, '/v1/nowhere', { headers: bearer(MAIN.integrationKey) });

		const logged = await send(fake, '/__fake/violations');
		expect(await logged.json()).toMatchObject([
			{ operation: 'GET /v1/nowhere', at: 'path' },
		]);
	});
});

describe('response validation', () => {
	it('fails a response body outside its schema', async () => {
		const response = await send(
			fake,
			templatePath('broken@1', '/constraints'),
			{
				headers: bearer(MAIN.integrationKey),
			},
		);

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([
			{ direction: 'response', at: 'body' },
		]);
		expect(fake.violations[0]?.message).toContain('ratio');
	});

	it('fails a text body outside its schema', async () => {
		const response = await send(fake, templatePath('text-wrong@1'), {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([
			{ direction: 'response', at: 'body' },
		]);
	});

	it('answers a text body its schema allows', async () => {
		const response = await send(fake, templatePath('text-right@1'), {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(500);
		expect(await response.text()).toBe(INTERNAL_ERROR_TEXT);
		expect(fake.violations).toEqual([]);
	});

	it('fails a body on a status declared without one', async () => {
		const response = await send(fake, templatePath('missing@1'), {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([
			{ direction: 'response', at: 'body' },
		]);
	});

	it('fails a status the operation does not declare', async () => {
		const response = await send(
			fake,
			templatePath(FIXTURE.template.live, '/constraints'),
			{ headers: bearer(MAIN.integrationKey) },
		);

		expect(response.status).toBe(VIOLATION_STATUS);
		expect(fake.violations).toMatchObject([
			{ direction: 'response', at: 'status' },
		]);
	});
});

describe('a fake that fails', () => {
	it('logs the failure as a violation and answers 500', async () => {
		const response = await send(failing, templatePath(FIXTURE.template.live), {
			headers: bearer(MAIN.integrationKey),
		});

		expect(response.status).toBe(FAKE_FAILED_STATUS);
		expect(failing.violations).toMatchObject([
			{
				operation: 'GET /v1/templates/{id}',
				direction: 'response',
				at: 'handler',
			},
		]);
		expect(failing.violations[0]?.message).toContain('handler broke');
		expect(failing.requests).toMatchObject([{ status: FAKE_FAILED_STATUS }]);
	});
});
