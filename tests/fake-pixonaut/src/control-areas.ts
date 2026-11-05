// `POST /__fake/blanks/:id/areas`: makes a blank's print areas change with
// options, as a merchant sets up in the studio, so a test can sell a fixed
// product on a blank whose areas vary by size. The fixtures' blanks have one
// print area per placement.
import type { Hono } from 'hono';
import type { Fake } from './app';
import {
	type Body,
	finite,
	jsonBody,
	PREFIX,
	type Read,
	refused,
} from './control';
import type { PrintArea } from './state';

const NOT_FOUND_STATUS = 404;

type AreasCommand = {
	areasVaryBy: string[];
	/** Each placement's areas, by placement id; a placement left out keeps
	 *  one area. */
	areas: Record<string, PrintArea[]>;
};

function readArea(value: unknown, varies: string[]): PrintArea | null {
	if (value === null || typeof value !== 'object') {
		return null;
	}
	const area = value as Record<string, unknown>;
	const row = area.row as Record<string, unknown> | undefined;
	const sizes = [
		area.widthMm,
		area.heightMm,
		area.usualWidthMm,
		area.usualHeightMm,
	];
	if (
		row === undefined ||
		typeof row !== 'object' ||
		Object.keys(row).length !== varies.length ||
		!varies.every((option) => typeof row[option] === 'string') ||
		!sizes.every(finite)
	) {
		return null;
	}
	return {
		row: row as Record<string, string>,
		widthMm: area.widthMm as number,
		heightMm: area.heightMm as number,
		usualWidthMm: area.usualWidthMm as number,
		usualHeightMm: area.usualHeightMm as number,
	};
}

function readAreas(body: Body): Read<AreasCommand> {
	const varies = body.areasVaryBy;
	if (
		!Array.isArray(varies) ||
		varies.length === 0 ||
		!varies.every((option) => typeof option === 'string' && option !== '')
	) {
		return refused('`areasVaryBy` is not a list of option names');
	}
	const given = body.areas ?? {};
	if (given === null || typeof given !== 'object' || Array.isArray(given)) {
		return refused('`areas` is not an object of placements');
	}
	const areas: Record<string, PrintArea[]> = {};
	for (const [placement, list] of Object.entries(given)) {
		const read = Array.isArray(list)
			? list.map((area) => readArea(area, varies))
			: [null];
		if (read.some((area) => area === null)) {
			return refused(`an area of \`${placement}\` is not a row with sizes`);
		}
		areas[placement] = read as PrintArea[];
	}
	return { ok: true, value: { areasVaryBy: varies, areas } };
}

export function registerAreasControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/blanks/:id/areas`, async (c) => {
		const blank = fake.state.blanks.get(c.req.param('id'));
		if (blank === undefined) {
			return c.json({ error: 'there is no such blank' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		const command = body.ok ? readAreas(body.value) : body;
		if (!command.ok) {
			return command.response;
		}
		blank.areasVaryBy = command.value.areasVaryBy;
		for (const placement of blank.placements) {
			placement.areas = command.value.areas[placement.id];
		}
		return c.json({
			areasVaryBy: blank.areasVaryBy,
			areas: command.value.areas,
		});
	});
}
