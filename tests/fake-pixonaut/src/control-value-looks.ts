// `POST /__fake/blanks/:id/value-looks`: gives one value of a blank's option,
// named by axis and value as written, a look, so a test can see the store
// draw it: `{axis, value, look}`, `look` being `"png"` or `"svg"` for a
// picture the fake serves under `/v/`, `{colours}` for a fill, or null for
// none. The next reset brings back the fixture's colour fills.
//
// `GET /v/:file`: the value pictures, open to every page as Pixonaut's are.
import type { Hono } from 'hono';
import { cors } from 'hono/cors';
import type { Fake } from './app';
import { jsonBody, PREFIX, refused } from './control';
import type { ValueAppearance } from './state';

const NOT_FOUND_STATUS = 404;

/** As Pixonaut serves a value's picture: any cache may keep it. */
const PUBLIC_CACHE = 'public, max-age=2592000';

/** A 2×2 brown PNG, the wood of a frame. */
const WOOD_PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGPojtIGIgYIBQAfTgRBICr0RgAAAABJRU5ErkJggg==',
	'base64',
);

/** An oak grain as a vector: a plain picture, no script. */
const OAK_SVG =
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#c8a165"/><path d="M0 3h10M0 7h10" stroke="#8b6b3d"/></svg>';

const PICTURES = {
	png: { body: WOOD_PNG, contentType: 'image/png' },
	svg: { body: OAK_SVG, contentType: 'image/svg+xml' },
} as const;

type PictureKind = keyof typeof PICTURES;

function isPictureKind(kind: unknown): kind is PictureKind {
	return typeof kind === 'string' && kind in PICTURES;
}

export function registerValueLooksControl(app: Hono, fake: Fake): void {
	app.use('/v/*', cors({ origin: '*' }));
	app.get('/v/:file', (c) => {
		const kind = c.req.param('file').split('.').pop();
		if (!isPictureKind(kind)) {
			return c.text('404 Not Found', NOT_FOUND_STATUS);
		}
		const picture = PICTURES[kind];
		return new Response(picture.body, {
			headers: {
				'content-type': picture.contentType,
				'cache-control': PUBLIC_CACHE,
			},
		});
	});
	app.post(`${PREFIX}/blanks/:id/value-looks`, async (c) => {
		const blank = fake.state.blanks.get(c.req.param('id'));
		if (blank === undefined) {
			return c.json({ error: 'there is no such blank' }, NOT_FOUND_STATUS);
		}
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		const { axis, value, look } = body.value;
		if (
			typeof axis !== 'string' ||
			typeof value !== 'string' ||
			!blank.axes.includes(axis)
		) {
			return refused('`axis` is not one of the blank’s, or `value` is not text')
				.response;
		}
		const appearance = appearanceOf(look, value, new URL(c.req.url).origin);
		if (appearance === undefined) {
			return refused('`look` is not "png", "svg", {colours} or null').response;
		}
		blank.valueLooks ??= {};
		blank.valueLooks[axis] = {
			...blank.valueLooks[axis],
			[value]: appearance,
		};
		return c.json(appearance);
	});
}

/** The look asked for; undefined for one the control does not know. */
function appearanceOf(
	look: unknown,
	value: string,
	origin: string,
): ValueAppearance | undefined {
	if (look === null) {
		return null;
	}
	if (isPictureKind(look)) {
		const name = encodeURIComponent(value.toLowerCase());
		return {
			kind: 'image',
			assetId: `asset_value_${look}`,
			url: `${origin}/v/${name}.${look}`,
			mime: PICTURES[look].contentType,
		};
	}
	const colours = (look as { colours?: unknown } | undefined)?.colours;
	if (
		Array.isArray(colours) &&
		colours.every((colour) => typeof colour === 'string')
	) {
		return { kind: 'fill', colours: colours as string[] };
	}
	return undefined;
}
