// The control API's design changes: what happens to a store's design in the
// studio, out of the store's sight. Each changes the fake's design as
// Pixonaut would and emits the event Pixonaut sends for it: an edit (a
// correction) moves it to its next revision and clears any approval, a
// delete removes it, an erasure clears its personal data.
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
	type Design,
	deleteOwnedDesign,
	designEditable,
	findDesign,
} from '../handlers/designs-store';
import type { EmitEvent } from './deliveries';

const DESIGNS_CONTROL = `${PREFIX}/designs`;

type DesignChange = { store: string; design: Design };

/** The store (the main one by default) and its design `design`, while the
 *  store is connected. */
function readChange(body: Body, fake: Fake): Read<DesignChange> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	const id = optionalString(body, 'design');
	if (!id.ok) {
		return id;
	}
	if (id.value === undefined) {
		return refused('`design` is required');
	}
	if (fake.state.stores.get(store.value)?.webhook.active !== true) {
		// Its event could not be emitted, so nothing is changed.
		return refused(
			`store ${store.value} disconnected; its endpoint is revoked`,
		);
	}
	const design = findDesign(fake.state, id.value, store.value);
	if (design === undefined) {
		return refused(`store ${store.value} has no design ${id.value}`);
	}
	return { ok: true, value: { store: store.value, design } };
}

/** Applies a change to the design the body names and emits the event it
 *  returns; refused, it changes nothing. */
function registerChange(
	app: Hono,
	fake: Fake,
	path: string,
	change: (design: Design, store: string) => Read<EmitEvent>,
): void {
	app.post(`${DESIGNS_CONTROL}/${path}`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const read = body.ok ? readChange(body.value, fake) : body;
		if (!read.ok) {
			return read.response;
		}
		const event = change(read.value.design, read.value.store);
		if (!event.ok) {
			return event.response;
		}
		const emitted = await fake.webhooks.emit(read.value.store, [event.value]);
		if (!emitted.ok) {
			return c.json({ error: 'refused', problems: emitted.problems }, 400);
		}
		return c.json({ attempts: emitted.attempts });
	});
}

export function registerDesignEvents(app: Hono, fake: Fake): void {
	registerChange(app, fake, 'edit', (design) => {
		if (!designEditable(design)) {
			return refused(`design ${design.id} can no longer be edited`);
		}
		design.revision += 1;
		// Back to awaiting approval: an approval, made or scheduled, is cleared.
		design.status = 'committed';
		design.approvedRevision = null;
		design.consent = null;
		return {
			ok: true,
			value: {
				type: 'design.edited',
				payload: { designId: design.id, revision: design.revision },
			},
		};
	});

	registerChange(app, fake, 'delete', (design, store) => {
		deleteOwnedDesign(fake.state, design.id, store);
		return {
			ok: true,
			value: { type: 'design.deleted', payload: { designId: design.id } },
		};
	});

	registerChange(app, fake, 'erase', (design) => {
		design.personalDataErasedAt ??= fake.clock.now();
		return {
			ok: true,
			value: {
				type: 'design.personal_data_erased',
				payload: { designId: design.id },
			},
		};
	});
}
