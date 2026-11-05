// `GET /i/{token}`: the keyless images a key's catalogue reads link to — a
// mockup's thumbnail and its `mount.url` photo, and a template's preview. The
// token names what it opens; the fake signs nothing, and serves one white
// picture for all of them.
import { type Handler, refusal } from './types';

/** An 8×8 white JPEG: every image the fake serves. It renders nothing. */
export const WHITE_JPEG = Buffer.from(
	'/9j/4AAQSkZJRgABAgAAAQABAAD//gAQTGF2YzYwLjMxLjEwMgD/2wBDAAg+Pkk+SVVVVVVVVWRdZGhoaGRkZGRoaGhwcHCDg4NwcHBoaHBwfHyDg4+Tj4eHg4eTk5ubm7q6srLZ2eD/////xABLAAEBAAAAAAAAAAAAAAAAAAAABwEBAAAAAAAAAAAAAAAAAAAAABABAAAAAAAAAAAAAAAAAAAAABEBAAAAAAAAAAAAAAAAAAAAAP/AABEIAAgACAMBIgACEQADEQD/2gAMAwEAAhEDEQA/AL+AD//Z',
	'base64',
);

/** As Pixonaut sends a thumbnail or preview: only the browser that opened
 *  it may keep it, for no longer than the link lives. */
const SHORT_LINK_CACHE = 'private, max-age=3600';

/** As Pixonaut sends a `mount.url` photo: any cache may keep it for the
 *  30 days the link is valid at least. */
const MOUNT_CACHE = 'public, max-age=2592000';

type MockupPhoto = {
	kind: 'thumbnail' | 'mount';
	blankId: string;
	placementId: string;
	mockupId: string;
};

/** A template's preview, drawn with one of its showcase sets when it names one. */
type Preview = { kind: 'preview'; templateId: string; showcase?: string };

/** What an image link opens. */
export type ImageRef = MockupPhoto | Preview;

/** A link that does not verify, or whose image is gone: Pixonaut's
 *  plain-text 404. */
const GONE = {
	status: 404,
	body: '404 Not Found',
	contentType: 'text/plain; charset=UTF-8',
} as const;

/** The keyless link to an image. */
export function imageUrl(origin: string, ref: ImageRef): string {
	return `${origin}/i/${Buffer.from(JSON.stringify(ref)).toString('base64url')}`;
}

function refOf(token: string): ImageRef | null {
	try {
		const ref: unknown = JSON.parse(
			Buffer.from(token, 'base64url').toString('utf8'),
		);
		return typeof ref === 'object' && ref !== null && 'kind' in ref
			? (ref as ImageRef)
			: null;
	} catch {
		return null;
	}
}

/** A link to a mockup the blank no longer has, or to a template version
 *  the fake does not hold, does not open. */
export const readImage: Handler = (context) => {
	const ref = refOf(context.params.token ?? '');
	const { state } = context;
	let found = false;
	if (ref?.kind === 'preview') {
		const template = state.templates.get(ref.templateId);
		if (
			template !== undefined &&
			ref.showcase !== undefined &&
			!template.showcases.some((s) => s.id === ref.showcase)
		) {
			return refusal(404, 'showcase_not_found');
		}
		found = template !== undefined;
	} else if (ref !== null) {
		found =
			state.blanks
				.get(ref.blankId)
				?.placements.find((p) => p.id === ref.placementId)
				?.views.some((v) => v.photos.some((m) => m.id === ref.mockupId)) ??
			false;
	}
	if (ref === null || !found) {
		return GONE;
	}
	return {
		status: 200,
		body: new Uint8Array(WHITE_JPEG),
		contentType: 'image/jpeg',
		headers: {
			'cache-control': ref.kind === 'mount' ? MOUNT_CACHE : SHORT_LINK_CACHE,
		},
	};
};
