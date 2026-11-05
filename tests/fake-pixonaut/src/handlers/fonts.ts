// `GET /f/{file}`: the font files a template's `fonts` names by sha256, which
// the SDK fetches before it draws a text layer. Public and keyless.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { FIXTURES_DIR } from '../fixtures';
import type { Handler } from './types';

const FONT_FILE = /^[0-9a-f]{64}\.ttf$/;

/** A file named by its content hash never changes. */
export const IMMUTABLE = 'public, max-age=31536000, immutable';

export const readFontFile: Handler = (context) => {
	const file = context.params.file ?? '';
	if (!FONT_FILE.test(file)) {
		return { status: 404 };
	}
	let bytes: Buffer;
	try {
		bytes = readFileSync(join(FIXTURES_DIR, 'fonts', file));
	} catch {
		return { status: 404 };
	}
	return {
		status: 200,
		body: new Uint8Array(bytes),
		contentType: 'font/ttf',
		headers: { 'cache-control': IMMUTABLE },
	};
};
