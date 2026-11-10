// The control API's catalogue changes: what a member does to the workspace's
// templates and blanks in the studio. Each changes the fake's catalogue as
// Pixonaut would and emits the event Pixonaut sends for it: publishing a
// template's next version, pausing or resuming it, deleting it, changing its
// id, and updating,
// deleting or restoring a blank. Pixonaut sends catalogue events to every
// store of the workspace; the fake sends them to the store a command names
// (the main one by default), so a test reads one stream.
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
	type Blank,
	type State,
	type TemplateStatus,
	type TemplateVersion,
	templateId,
	type Variant,
	type VariantRetirement,
} from '../state';
import type { EmitEvent } from './deliveries';

const TEMPLATES_CONTROL = `${PREFIX}/templates`;
const BLANKS_CONTROL = `${PREFIX}/blanks`;

/** Every version of `baseId`, oldest first. */
function versionsOf(state: State, baseId: string): TemplateVersion[] {
	return [...state.templates.values()]
		.filter((version) => version.baseId === baseId)
		.sort((a, b) => a.version - b.version);
}

/** The store a command emits to, and the id it names under `field`. */
function readCommand(
	body: Body,
	fake: Fake,
	field: string,
): Read<{ store: string; id: string }> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	if (fake.state.stores.get(store.value)?.webhook.active !== true) {
		// Its event could not be emitted, so nothing is changed.
		return refused(
			`store ${store.value} disconnected; its endpoint is revoked`,
		);
	}
	const id = optionalString(body, field);
	if (!id.ok) {
		return id;
	}
	if (id.value === undefined) {
		return refused(`\`${field}\` is required`);
	}
	return { ok: true, value: { store: store.value, id: id.value } };
}

/** Applies a change to what the body names and emits the event it returns;
 *  refused, it changes nothing. */
function registerChange<T>(
	app: Hono,
	fake: Fake,
	path: string,
	field: string,
	find: (state: State, id: string) => T | undefined,
	change: (found: T, body: Body) => Read<EmitEvent>,
): void {
	app.post(path, async (c) => {
		const body = await jsonBody(c.req.raw);
		const read = body.ok ? readCommand(body.value, fake, field) : body;
		if (!read.ok) {
			return read.response;
		}
		const found = find(fake.state, read.value.id);
		if (found === undefined) {
			return refused(`there is no ${field} ${read.value.id}`).response;
		}
		const event = change(found, body.ok ? body.value : {});
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

function findTemplate(
	state: State,
	baseId: string,
): TemplateVersion[] | undefined {
	const versions = versionsOf(state, baseId);
	return versions.length === 0 ? undefined : versions;
}

function findBlank(state: State, id: string): Blank | undefined {
	return state.blanks.get(id);
}

/** The reasons a variant is retired for. */
const RETIREMENT_REASONS = ['retired', 'no_longer_sold'];

/** The variant an update retires or sells again: `variant` names it, and
 *  `retired` the reason, or null to sell it again. Undefined when the
 *  update names no variant. */
function readRetirement(
	blank: Blank,
	body: Body,
): Read<{ variant: Variant; retired: VariantRetirement | null } | undefined> {
	const ref = optionalString(body, 'variant');
	if (!ref.ok) {
		return ref;
	}
	if (ref.value === undefined) {
		return { ok: true, value: undefined };
	}
	const variant = blank.variants.find((v) => v.ref === ref.value);
	if (variant === undefined) {
		return refused(`blank ${blank.id} has no variant ${ref.value}`);
	}
	const { retired } = body;
	if (retired === null) {
		return { ok: true, value: { variant, retired: null } };
	}
	if (typeof retired !== 'string' || !RETIREMENT_REASONS.includes(retired)) {
		return refused(
			`\`retired\` is null or one of ${RETIREMENT_REASONS.join(', ')}`,
		);
	}
	return { ok: true, value: { variant, retired: { reason: retired } } };
}

/** Shows (`live`) or hides every version of a template; refused when it
 *  already is, as Pixonaut emits only a real flip. */
function setLive(
	versions: TemplateVersion[],
	status: TemplateStatus,
): Read<EmitEvent> {
	const [first] = versions;
	if (first === undefined || first.status === status) {
		return refused(`template ${first?.baseId} is already ${status}`);
	}
	for (const version of versions) {
		version.status = status;
	}
	return {
		ok: true,
		value: {
			type: 'template.live_changed',
			payload: { baseId: first.baseId, live: status === 'live' },
		},
	};
}

export function registerCatalogueEvents(app: Hono, fake: Fake): void {
	registerChange(
		app,
		fake,
		`${TEMPLATES_CONTROL}/publish`,
		'template',
		findTemplate,
		(versions) => {
			const latest = versions.at(-1);
			if (latest === undefined) {
				return refused('the template has no version');
			}
			// The next version is the latest one again, as a member who
			// republishes without changes makes it.
			const next: TemplateVersion = structuredClone(latest);
			next.version = latest.version + 1;
			const id = templateId(next.baseId, next.version);
			fake.state.templates.set(id, next);
			return {
				ok: true,
				value: {
					type: 'template.published',
					payload: { templateId: id, version: next.version },
				},
			};
		},
	);

	registerChange(
		app,
		fake,
		`${TEMPLATES_CONTROL}/pause`,
		'template',
		findTemplate,
		(versions) => setLive(versions, 'paused'),
	);

	registerChange(
		app,
		fake,
		`${TEMPLATES_CONTROL}/resume`,
		'template',
		findTemplate,
		(versions) => setLive(versions, 'live'),
	);

	registerChange(
		app,
		fake,
		`${TEMPLATES_CONTROL}/delete`,
		'template',
		findTemplate,
		(versions) => {
			for (const version of versions) {
				fake.state.templates.delete(
					templateId(version.baseId, version.version),
				);
			}
			const baseId = versions[0]?.baseId ?? '';
			const wasLive = versions.some((version) => version.status === 'live');
			return {
				ok: true,
				value: { type: 'template.deleted', payload: { baseId, wasLive } },
			};
		},
	);

	registerChange(
		app,
		fake,
		`${TEMPLATES_CONTROL}/rename`,
		'template',
		findTemplate,
		(versions, body) => {
			const newId = optionalString(body, 'newId');
			if (!newId.ok) {
				return newId;
			}
			const from = versions[0]?.baseId ?? '';
			const to = newId.value;
			if (to === undefined || to === '' || to.includes('@')) {
				return refused('`newId` is required: a base id, without a version');
			}
			if (versionsOf(fake.state, to).length > 0) {
				return refused(`template ${to} exists`);
			}
			// As Pixonaut moves the template, its designs and its designer links in one go.
			fake.state.templates.rename(from, to);
			for (const link of fake.state.shareLinks.values()) {
				if (link.subject.kind === 'template' && link.subject.id === from) {
					link.subject.id = to;
				}
			}
			return {
				ok: true,
				value: {
					type: 'template.renamed',
					payload: { oldId: from, newId: to, renamedAt: fake.clock.now() },
				},
			};
		},
	);

	registerChange(
		app,
		fake,
		`${BLANKS_CONTROL}/update`,
		'blank',
		findBlank,
		(blank, body) => {
			const removed = optionalString(body, 'removePlacement');
			if (!removed.ok) {
				return removed;
			}
			const retirement = readRetirement(blank, body);
			if (!retirement.ok) {
				return retirement;
			}
			const fields: string[] = [];
			if (retirement.value !== undefined) {
				retirement.value.variant.retired = retirement.value.retired;
				fields.push('variants');
			}
			if (removed.value !== undefined) {
				const kept = blank.placements.filter((p) => p.id !== removed.value);
				if (kept.length === blank.placements.length) {
					return refused(`blank ${blank.id} has no placement ${removed.value}`);
				}
				blank.placements = kept;
				fields.push('placements');
			}
			blank.updatedAt = fake.clock.now();
			return {
				ok: true,
				value: {
					type: 'blank.updated',
					payload: { blankId: blank.id, fields },
				},
			};
		},
	);

	registerChange(
		app,
		fake,
		`${BLANKS_CONTROL}/delete`,
		'blank',
		findBlank,
		(blank) => {
			if (blank.deletedAt !== null) {
				return refused(`blank ${blank.id} is already deleted`);
			}
			blank.deletedAt = fake.clock.now();
			return {
				ok: true,
				value: {
					type: 'blank.deleted',
					payload: { blankId: blank.id, blankName: blank.name },
				},
			};
		},
	);

	registerChange(
		app,
		fake,
		`${BLANKS_CONTROL}/restore`,
		'blank',
		findBlank,
		(blank) => {
			if (blank.deletedAt === null) {
				return refused(`blank ${blank.id} is not deleted`);
			}
			blank.deletedAt = null;
			return {
				ok: true,
				value: {
					type: 'blank.restored',
					payload: { blankId: blank.id, blankName: blank.name },
				},
			};
		},
	);
}
