// `POST /__fake/blanks/:id/pages`: prints a blank's placement as pages, as a
// notebook's inner pages are, so a test can sell a book.
import type { Hono } from 'hono';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';

const NOT_FOUND_STATUS = 404;

function wholeAtLeastOne(value: unknown): value is number {
	return Number.isInteger(value) && (value as number) >= 1;
}

export function registerPagesControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/blanks/:id/pages`, async (c) => {
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { placement: id, min, max, step } = body.value;
		const placement = fake.state.blanks
			.get(c.req.param('id'))
			?.placements.find((each) => each.id === id);
		if (placement === undefined) {
			return c.json({ error: 'there is no such placement' }, NOT_FOUND_STATUS);
		}
		if (
			!wholeAtLeastOne(min) ||
			!wholeAtLeastOne(max) ||
			!wholeAtLeastOne(step) ||
			max < min
		) {
			return refused('`min`, `max` and `step` are not a range of page counts')
				.response;
		}
		placement.pages = { min, max, step };
		return c.json(placement.pages);
	});
}
