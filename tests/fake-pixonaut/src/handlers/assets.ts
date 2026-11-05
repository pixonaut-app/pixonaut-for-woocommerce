// A customer's picture: `POST /v1/assets` declares it, `PUT /u/{token}` (the
// `uploadUrl` it answers) takes its bytes, `GET /v1/assets/{id}/content`
// serves them, `POST /v1/assets/{id}/improve-resolution` upscales it, and
// `POST /v1/assets/{id}/cut-out` cuts it out of its background, which
// `GET /v1/assets/{id}/cut-out` reads back.
import { createHash, randomBytes } from 'node:crypto';
import { allOwned, findOwned, putOwned, type State } from '../state';
import { IMMUTABLE } from './fonts';
import { sessionOf } from './session';
import { visibleTemplate } from './templates';
import { callerOf, type Handler, type HandlerContext, refusal } from './types';

export const ASSETS = 'assets';

/** Pixonaut's asset ids: this prefix, then random bytes written as hex. */
const ASSET_ID_PREFIX = 'up_';
const ASSET_ID_BYTES = 12;
/** Random bytes in an upload token, written as hex. */
const UPLOAD_TOKEN_BYTES = 16;

const MS_PER_MINUTE = 60 * 1000;
/** How long an `uploadUrl` waits for its bytes. */
export const UPLOAD_LIFETIME_MS = 10 * MS_PER_MINUTE;

const PNG = 'image/png';
const JPEG = 'image/jpeg';
/** The largest upload Pixonaut takes: a PNG may be larger than the rest. */
const MAX_PNG_BYTES = 268_435_456;
const MAX_OTHER_BYTES = 83_886_080;

/** How much an improvement enlarges an upload along each edge. The fake
 *  keeps the bytes and reports the size Pixonaut's upscaler would make. */
export const IMPROVEMENT_SCALE = 2;

/** An uploaded file, as the fake read it. */
type Upload = {
	bytes: Uint8Array;
	widthPx: number;
	heightPx: number;
	/** Names these bytes: a `v` that matches is cached for good. */
	contentKey: string;
};

/** A picture a store's storefront uploaded, or an improvement of one. */
export type AssetRecord = {
	id: string;
	mime: string;
	sizeBytes: number;
	templateId: string | null;
	layerId: string | null;
	/** The designer session that uploaded it, when the request named one. */
	sessionId: string | null;
	/** The `uploadUrl`'s token; null for an improvement, which nobody
	 *  uploads. */
	uploadToken: string | null;
	uploadExpiresAt: number;
	/** The file; null while the upload waits for its bytes. */
	upload: Upload | null;
	/** The upload this one improves, for an improvement. */
	improves: string | null;
	/** The improvement made of this upload, once made. */
	improvedBy: string | null;
	/** The cut-out made of this upload, once asked. */
	cutOutBy: string | null;
	/** Held by a committed design: a session wipe keeps it. */
	retained: boolean;
};

/** `storeId`'s asset `id`, uploaded or not; undefined when another store
 *  made it or there is none. */
export function findAsset(
	state: State,
	storeId: string,
	id: string,
): AssetRecord | undefined {
	return findOwned<AssetRecord>(state, ASSETS, id, storeId);
}

function assetId(): string {
	return `${ASSET_ID_PREFIX}${randomBytes(ASSET_ID_BYTES).toString('hex')}`;
}

function contentKey(id: string, bytes: Uint8Array): string {
	return createHash('sha256').update(id).update(bytes).digest('hex');
}

/** PNG: the signature, then the IHDR chunk's width and height. */
const PNG_SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];
const PNG_WIDTH_OFFSET = 16;
const PNG_HEIGHT_OFFSET = 20;
const PNG_HEADER_BYTES = 24;

/** JPEG: a marker is `0xFF` and a code, then (but for start-of-image) the
 *  segment's 2-byte length; a start-of-frame segment carries the height and
 *  width after that length and a 1-byte precision. */
const JPEG_MARKER = 0xff;
const JPEG_MARKER_BYTES = 2;
const JPEG_START_OF_IMAGE = 0xd8;
const JPEG_FIRST_FRAME = 0xc0;
const JPEG_LAST_FRAME = 0xcf;
/** Codes in the start-of-frame range that are not frames: DHT, JPG, DAC. */
const JPEG_NOT_FRAMES = [0xc4, 0xc8, 0xcc];
const JPEG_HEIGHT_OFFSET = 5;
const JPEG_WIDTH_OFFSET = 7;
const UINT16_BYTES = 2;

type Size = { widthPx: number; heightPx: number };

function pngSize(view: DataView): Size | null {
	const signed = PNG_SIGNATURE.every((byte, i) => view.getUint8(i) === byte);
	if (!signed || view.byteLength < PNG_HEADER_BYTES) {
		return null;
	}
	return {
		widthPx: view.getUint32(PNG_WIDTH_OFFSET),
		heightPx: view.getUint32(PNG_HEIGHT_OFFSET),
	};
}

function jpegSize(view: DataView): Size | null {
	if (
		view.getUint8(0) !== JPEG_MARKER ||
		view.getUint8(1) !== JPEG_START_OF_IMAGE
	) {
		return null;
	}
	let at = JPEG_MARKER_BYTES;
	while (at + JPEG_MARKER_BYTES + UINT16_BYTES <= view.byteLength) {
		if (view.getUint8(at) !== JPEG_MARKER) {
			return null;
		}
		const code = view.getUint8(at + 1);
		const frame =
			code >= JPEG_FIRST_FRAME &&
			code <= JPEG_LAST_FRAME &&
			!JPEG_NOT_FRAMES.includes(code);
		if (frame && at + JPEG_WIDTH_OFFSET + UINT16_BYTES <= view.byteLength) {
			return {
				widthPx: view.getUint16(at + JPEG_WIDTH_OFFSET),
				heightPx: view.getUint16(at + JPEG_HEIGHT_OFFSET),
			};
		}
		// The length counts itself, not the marker.
		at += JPEG_MARKER_BYTES + view.getUint16(at + JPEG_MARKER_BYTES);
	}
	return null;
}

/** The picture's pixel size, or null when the fake cannot read it: it reads
 *  PNG and JPEG only, and refuses anything else as Pixonaut refuses a file
 *  that is no image. */
function pixelSize(mime: string, bytes: Uint8Array): Size | null {
	if (bytes.length < PNG_SIGNATURE.length) {
		return null;
	}
	const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.length);
	if (mime === PNG) {
		return pngSize(view);
	}
	return mime === JPEG ? jpegSize(view) : null;
}

type AssetRequest = {
	mime: string;
	sizeBytes: number;
	templateId?: string | null;
	layerId?: string | null;
};

export const createAsset: Handler = (context) => {
	const { state, clock } = context;
	const caller = callerOf(context);
	const request = context.body as AssetRequest;
	const templateId = request.templateId ?? null;
	if (
		templateId !== null &&
		visibleTemplate(context, templateId) === undefined
	) {
		return refusal(404, 'not_found');
	}
	const maxBytes = request.mime === PNG ? MAX_PNG_BYTES : MAX_OTHER_BYTES;
	if (request.sizeBytes > maxBytes) {
		return { status: 422, body: { error: 'size_out_of_bounds', maxBytes } };
	}
	const id = assetId();
	const uploadToken = randomBytes(UPLOAD_TOKEN_BYTES).toString('hex');
	const record: AssetRecord = {
		id,
		mime: request.mime,
		sizeBytes: request.sizeBytes,
		templateId,
		layerId: request.layerId ?? null,
		sessionId: sessionOf(context.headers),
		uploadToken,
		uploadExpiresAt: clock.now() + UPLOAD_LIFETIME_MS,
		upload: null,
		improves: null,
		improvedBy: null,
		cutOutBy: null,
		retained: false,
	};
	putOwned(state, ASSETS, id, caller.storeId, record);
	return {
		status: 201,
		// A path: the SDK puts the bytes to it under its own `apiBase`.
		body: {
			assetId: id,
			uploadUrl: `/u/${uploadToken}`,
			absoluteUploadUrl: `${context.origin}/u/${uploadToken}`,
		},
	};
};

/** `PUT /u/{token}`: keyless, the token is the credential. */
export const uploadAssetBytes: Handler = (context) => {
	const token = context.params.token ?? '';
	const asset = [...allOwned<AssetRecord>(context.state, ASSETS).values()]
		.map((record) => record.value)
		.find((value) => value.uploadToken === token);
	if (asset === undefined || asset.uploadExpiresAt <= context.clock.now()) {
		return refusal(410, 'upload_expired');
	}
	if (asset.upload !== null) {
		// Good once.
		return refusal(404, 'unknown_upload');
	}
	const bytes =
		context.body instanceof Uint8Array ? context.body : new Uint8Array();
	if (bytes.length !== asset.sizeBytes) {
		return refusal(422, 'size_mismatch');
	}
	const size = pixelSize(asset.mime, bytes);
	if (size === null) {
		return refusal(422, 'not_a_valid_image');
	}
	asset.upload = { bytes, ...size, contentKey: contentKey(asset.id, bytes) };
	return {
		status: 200,
		body: {
			assetId: asset.id,
			widthPx: size.widthPx,
			heightPx: size.heightPx,
			contentKey: asset.upload.contentKey,
			// The fake judges no picture's resolution, so offers no improvement.
			effectiveDpi: null,
			warning: null,
			warningKey: null,
			warningParams: null,
			improveResolution: null,
		},
	};
};

export const readAssetContent: Handler = (context) => {
	const asset = findAsset(
		context.state,
		callerOf(context).storeId,
		context.params.id ?? '',
	);
	const upload = asset?.upload ?? null;
	if (asset === undefined || upload === null) {
		return { status: 404 };
	}
	const headers: Record<string, string> = {
		'x-pixonaut-original-width': String(upload.widthPx),
		'x-pixonaut-original-height': String(upload.heightPx),
	};
	if (context.query.get('v') === upload.contentKey) {
		headers['cache-control'] = IMMUTABLE;
	}
	return {
		status: 200,
		body: upload.bytes,
		contentType: asset.mime,
		headers,
	};
};

function improvedBody(improved: AssetRecord, upload: Upload): unknown {
	return {
		assetId: improved.id,
		sourceAssetId: improved.improves,
		widthPx: upload.widthPx,
		heightPx: upload.heightPx,
		contentKey: upload.contentKey,
	};
}

/** The uploaded asset the path names, with its file, when the caller's
 *  store and designer session own it: another visitor's upload is not theirs
 *  to spend the tenant's credits on. */
function ownUpload(
	context: HandlerContext,
): { source: AssetRecord; uploaded: Upload } | undefined {
	const { storeId } = callerOf(context);
	const source = findAsset(context.state, storeId, context.params.id ?? '');
	const uploaded = source?.upload ?? null;
	if (
		source === undefined ||
		uploaded === null ||
		(source.sessionId !== null &&
			source.sessionId !== sessionOf(context.headers))
	) {
		return undefined;
	}
	return { source, uploaded };
}

/** A copy of `source` with its own id and `bytes`, made by Pixonaut rather
 *  than uploaded, kept for the store. */
function madeFrom(
	context: HandlerContext,
	source: AssetRecord,
	size: Size,
	changes: Pick<Partial<AssetRecord>, 'improves'>,
): AssetRecord & { upload: Upload } {
	const id = assetId();
	const bytes = source.upload?.bytes ?? new Uint8Array();
	const made = {
		...source,
		improves: null,
		improvedBy: null,
		cutOutBy: null,
		...changes,
		id,
		uploadToken: null,
		upload: { bytes, ...size, contentKey: contentKey(id, bytes) },
	};
	putOwned(context.state, ASSETS, id, callerOf(context).storeId, made);
	return made;
}

export const improveAssetResolution: Handler = (context) => {
	const { state } = context;
	const { storeId } = callerOf(context);
	const own = ownUpload(context);
	if (own === undefined) {
		return refusal(404, 'not_found');
	}
	const { source, uploaded } = own;
	if (source.improves !== null) {
		// An improvement is not upscaled again.
		return refusal(422, 'not_improvable');
	}
	if (source.improvedBy !== null) {
		// Asked again, as when the answer was lost: the same improvement.
		const improved = findAsset(state, storeId, source.improvedBy);
		if (improved === undefined || improved.upload === null) {
			throw new Error(`Improvement ${source.improvedBy} is gone.`);
		}
		return { status: 200, body: improvedBody(improved, improved.upload) };
	}
	const improved = madeFrom(
		context,
		source,
		{
			widthPx: uploaded.widthPx * IMPROVEMENT_SCALE,
			heightPx: uploaded.heightPx * IMPROVEMENT_SCALE,
		},
		{ improves: source.id },
	);
	source.improvedBy = improved.id;
	return { status: 201, body: improvedBody(improved, improved.upload) };
};

/** How a cut-out stands. The fake cuts at once and never fails, so a
 *  cut-out it was asked for is done on its first attempt and kept. */
function cutOutBody(cut: AssetRecord & { upload: Upload }): unknown {
	return {
		status: 'done',
		attempts: 1,
		retryable: false,
		cutOut: {
			assetId: cut.id,
			widthPx: cut.upload.widthPx,
			heightPx: cut.upload.heightPx,
			contentKey: cut.upload.contentKey,
		},
	};
}

/** The cut-out made of `source`, once asked. */
function cutOutOf(
	context: HandlerContext,
	source: AssetRecord,
): (AssetRecord & { upload: Upload }) | undefined {
	if (source.cutOutBy === null) {
		return undefined;
	}
	const cut = findAsset(
		context.state,
		callerOf(context).storeId,
		source.cutOutBy,
	);
	if (cut?.upload == null) {
		throw new Error(`Cut-out ${source.cutOutBy} is gone.`);
	}
	return { ...cut, upload: cut.upload };
}

/** `POST /v1/assets/{id}/cut-out`. The fake keeps the bytes as they were:
 *  it judges no picture's background, and does not check that the picture's
 *  constraints offer a cut-out, as it does not for an improvement. Like
 *  Pixonaut, it cuts out only an upload made for a picture (`layerId`). */
export const cutOutAsset: Handler = (context) => {
	const own = ownUpload(context);
	if (own === undefined || own.source.layerId === null) {
		return refusal(404, 'not_found');
	}
	const { source, uploaded } = own;
	const made = cutOutOf(context, source);
	if (made !== undefined) {
		// Asked again, as when the answer was lost: the same cut-out.
		return { status: 200, body: cutOutBody(made) };
	}
	const { widthPx, heightPx } = uploaded;
	const cut = madeFrom(context, source, { widthPx, heightPx }, {});
	source.cutOutBy = cut.id;
	return { status: 202, body: cutOutBody(cut) };
};

/** `GET /v1/assets/{id}/cut-out`: not found until one was asked. */
export const readAssetCutOut: Handler = (context) => {
	const own = ownUpload(context);
	const cut = own === undefined ? undefined : cutOutOf(context, own.source);
	if (cut === undefined) {
		return refusal(404, 'not_found');
	}
	return { status: 200, body: cutOutBody(cut) };
};
