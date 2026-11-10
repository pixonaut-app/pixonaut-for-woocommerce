// `POST /__fake/catalogue/imports`: `{held: true}` keeps each catalogue
// import `importing` until `POST /__fake/blanks/finish-import` finishes it,
// so a test can see a product on a blank still being imported; `{held:
// false}` makes imports whole at once again, as the next reset does.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';

export function registerCatalogueImportControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/catalogue/imports`, async (c) => {
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { held } = body.value;
		if (typeof held !== 'boolean') {
			return refused('`held` is not a boolean').response;
		}
		fake.state.catalogueImportsHeld = held;
		return c.json({ held });
	});
}
