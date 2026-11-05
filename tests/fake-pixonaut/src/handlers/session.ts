// The designer's browser session (`x-pixonaut-session`), which tells one
// visitor's uploads and proofs from another's on the same key.

const SESSION_HEADER = 'x-pixonaut-session';

/** 6 to 64 letters, digits, `_` or `-`; Pixonaut ignores any other value. */
const SESSION_ID = /^[A-Za-z0-9_-]{6,64}$/;

/** The request's session id, or null when it sends none Pixonaut reads. */
export function sessionOf(headers: Headers): string | null {
	const id = headers.get(SESSION_HEADER);
	return id !== null && SESSION_ID.test(id) ? id : null;
}
