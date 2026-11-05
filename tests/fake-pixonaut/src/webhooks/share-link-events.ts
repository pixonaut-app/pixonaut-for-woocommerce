// The control API's share-link changes: what a member does to a store's
// designer links in the studio. Withdrawing one stops it opening and emits
// `share_link.revoked`; regenerating one gives it a new address under the
// same id and emits `share_link.regenerated`. Neither payload carries the
// link's token or address. A store withdrawing its own link
// (`DELETE /v1/share-links/{id}`) emits `share_link.revoked` too.
import { randomUUID } from 'node:crypto';
import type { Hono } from 'hono';
import type { Fake } from '../app';
import {
	type Body,
	jsonBody,
	optionalString,
	PREFIX,
	type Read,
	refused,
	storeOf,
} from '../control';
import {
	DESIGNER_KIND,
	DESIGNER_LINKS,
	type DesignerLink,
} from '../handlers/designer-links';
import { allOwned, putOwned, type State } from '../state';
import type { EmitEvent } from './deliveries';

const SHARE_LINKS_CONTROL = `${PREFIX}/share-links`;

export const SHARE_LINK_REVOKED = 'share_link.revoked';

export const SHARE_LINK_REGENERATED = 'share_link.regenerated';

/** Who changed a link, as the events name them. */
type ChangedBy = 'member' | 'key' | 'store';

/** The event naming `link` and who changed it. */
export function shareLinkEvent(
	type: typeof SHARE_LINK_REVOKED | typeof SHARE_LINK_REGENERATED,
	link: DesignerLink,
	by: ChangedBy,
): EmitEvent {
	return {
		type,
		payload: {
			linkId: link.id,
			kind: DESIGNER_KIND,
			subjectId: link.subjectId,
			name: link.name,
			by,
		},
	};
}

/** `storeId`'s working designer link `id`, or undefined. */
function findLink(
	state: State,
	storeId: string,
	id: string,
): DesignerLink | undefined {
	return [...allOwned<DesignerLink>(state, DESIGNER_LINKS).values()].find(
		(record) => record.storeId === storeId && record.value.id === id,
	)?.value;
}

/** The store (the main one by default) and its link `link`, while the store
 *  is connected. */
function readChange(
	body: Body,
	fake: Fake,
): Read<{ store: string; link: DesignerLink }> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	const id = optionalString(body, 'link');
	if (!id.ok) {
		return id;
	}
	if (id.value === undefined) {
		return refused('`link` is required');
	}
	if (fake.state.stores.get(store.value)?.webhook.active !== true) {
		// Its event could not be emitted, so nothing is changed.
		return refused(
			`store ${store.value} disconnected; its endpoint is revoked`,
		);
	}
	const link = findLink(fake.state, store.value, id.value);
	if (link === undefined) {
		return refused(`store ${store.value} has no working link ${id.value}`);
	}
	return { ok: true, value: { store: store.value, link } };
}

/** Applies a change to the link the body names and emits its event. */
function registerChange(
	app: Hono,
	fake: Fake,
	path: string,
	change: (link: DesignerLink) => EmitEvent,
): void {
	app.post(`${SHARE_LINKS_CONTROL}/${path}`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const read = body.ok ? readChange(body.value, fake) : body;
		if (!read.ok) {
			return read.response;
		}
		const event = change(read.value.link);
		const emitted = await fake.webhooks.emit(read.value.store, [event]);
		if (!emitted.ok) {
			return c.json({ error: 'refused', problems: emitted.problems }, 400);
		}
		return c.json({ attempts: emitted.attempts });
	});
}

export function registerShareLinkEvents(app: Hono, fake: Fake): void {
	const links = () => allOwned<DesignerLink>(fake.state, DESIGNER_LINKS);

	registerChange(app, fake, 'revoke', (link) => {
		links().delete(link.token);
		return shareLinkEvent(SHARE_LINK_REVOKED, link, 'member');
	});

	registerChange(app, fake, 'regenerate', (link) => {
		links().delete(link.token);
		link.token = `dl_${randomUUID().replaceAll('-', '')}`;
		putOwned(fake.state, DESIGNER_LINKS, link.token, link.storeId, link);
		return shareLinkEvent(SHARE_LINK_REGENERATED, link, 'member');
	});
}
