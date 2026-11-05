import { describe, expect, it } from 'vitest';
import { Session, type Target } from '../../../contracts/scenarios/session';

/** The upload route's path, as `POST /v1/assets` hands it out. */
const UPLOAD_PATH = '/u/signed-token';
const OK = 200;

/** A target that answers every request with `answer`. */
function stubTarget(answer: () => Response): Target {
	return {
		name: 'fake',
		fetch: async () => answer(),
		siteUrl: 'http://shop.test',
		connectCode: async () => 'code',
	};
}

describe('Session.linked', () => {
	it('holds a successful upload answer to the pinned reference', async () => {
		const session = new Session(
			stubTarget(() => Response.json({ assetId: 1 }, { status: OK })),
		);

		const answer = await session.linked(UPLOAD_PATH, {
			method: 'PUT',
			body: 'picture',
		});

		expect(answer.status).toBe(OK);
		expect(session.violations).toEqual(
			expect.arrayContaining([
				expect.objectContaining({
					operation: 'PUT /u/{token}',
					direction: 'response',
					at: 'body',
				}),
			]),
		);
	});

	it('refuses a URL the reference declares no operation for', async () => {
		const session = new Session(stubTarget(() => new Response(null)));

		await expect(session.linked('/nowhere', { method: 'PUT' })).rejects.toThrow(
			'no operation for PUT /nowhere',
		);
	});
});
