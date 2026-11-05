// The test-only control API under `/__fake/`: reset to a fixture set, move
// the clock, arm faults, seed designs, and read the request and violation
// logs.
//
// It is for the test runner, not for pages: the fake is published on
// loopback only, the API sends no CORS headers, and a `POST` must say it is
// JSON, so a page on another origin cannot drive it even with a "simple"
// cross-origin request (which skips the preflight). That is why it takes no
// token.
import type { Hono } from 'hono';
import type { Fake } from './app';
import type { Fault, FaultField } from './faults';
import { FIXTURE } from './fixture-ids';
import { DEFAULT_FIXTURE_SET, FIXTURE_SETS } from './fixtures';
import {
	DESIGN_STATUSES,
	DESIGNS,
	type Design,
	type DesignSeed,
	type DesignStatus,
	seedDesign,
	seededView,
} from './handlers/designs-store';
import type { OperationKey } from './spec';
import {
	allOwned,
	type Brand,
	brandsOf,
	type Consent,
	HOSTED_LOCALES,
	isHostedLocale,
	type Member,
	type OrderFailure,
	type OrderOutcomeUnknown,
	type Store,
	type Tenant,
	type WorkspaceSettings,
} from './state';

export const PREFIX = '/__fake';

const JSON_MEDIA_TYPE = 'application/json';

const BAD_REQUEST_STATUS = 400;
const NOT_FOUND_STATUS = 404;
const UNSUPPORTED_MEDIA_TYPE_STATUS = 415;

/** The statuses a `status` fault may answer: what a `Response` can carry. */
const MIN_FAULT_STATUS = 200;
const MAX_FAULT_STATUS = 599;

export type Body = Record<string, unknown>;

/** A control request read, or the answer refusing it. */
export type Read<T> =
	| { ok: true; value: T }
	| { ok: false; response: Response };

export function refused(
	message: string,
	status = BAD_REQUEST_STATUS,
): { ok: false; response: Response } {
	return { ok: false, response: Response.json({ error: message }, { status }) };
}

/** The request's JSON object; empty when it has no body. Nothing but
 *  `application/json` is read. */
export async function jsonBody(request: Request): Promise<Read<Body>> {
	const type = request.headers
		.get('content-type')
		?.split(';')[0]
		?.trim()
		.toLowerCase();
	if (type !== JSON_MEDIA_TYPE) {
		return refused(
			`the control API takes ${JSON_MEDIA_TYPE}`,
			UNSUPPORTED_MEDIA_TYPE_STATUS,
		);
	}
	const text = await request.text();
	if (text === '') {
		return { ok: true, value: {} };
	}
	let parsed: unknown;
	try {
		parsed = JSON.parse(text);
	} catch (error) {
		return refused(`the body is not JSON: ${String(error)}`);
	}
	if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
		return refused('the body is not a JSON object');
	}
	return { ok: true, value: parsed as Body };
}

export function optionalString(
	body: Body,
	name: string,
): Read<string | undefined> {
	const value = body[name];
	if (value === undefined || typeof value === 'string') {
		return { ok: true, value };
	}
	return refused(`\`${name}\` is not a string`);
}

/** The store a command names, or the main fixture store. */
export function storeOf(body: Body, fake: Fake): Read<string> {
	const store = optionalString(body, 'store');
	if (!store.ok) {
		return store;
	}
	const id = store.value ?? FIXTURE.store.main.id;
	if (!fake.state.stores.has(id)) {
		return refused(`there is no store ${id}`);
	}
	return { ok: true, value: id };
}

export function finite(value: unknown): value is number {
	return typeof value === 'number' && Number.isFinite(value);
}

type WorkspaceChange = Partial<Omit<WorkspaceSettings, 'branding'>> & {
	branding?: Partial<WorkspaceSettings['branding']>;
};

/** The brands a workspace keeps besides its default: `{id, name}` with
 *  `accentColor` and `logoUrl`, each a string or null when given. */
function readBrands(value: unknown): Read<Brand[]> {
	if (!Array.isArray(value)) {
		return refused('`brands` is a list');
	}
	const brands: Brand[] = [];
	for (const item of value as Body[]) {
		const { id, name, accentColor = null, logoUrl = null } = item ?? {};
		if (
			typeof id !== 'string' ||
			typeof name !== 'string' ||
			(accentColor !== null && typeof accentColor !== 'string') ||
			(logoUrl !== null && typeof logoUrl !== 'string')
		) {
			return refused('each of `brands` is {id, name, accentColor?, logoUrl?}');
		}
		brands.push({ id, name, accentColor, logoUrl });
	}
	return { ok: true, value: brands };
}

/** The store's tenant (the main store's by default) and the settings to
 *  change: `accentColor` and `logoUrl` (strings or null) of its default
 *  brand, `brands` besides it, `proofWatermark`, `deliverOrdersTo`
 *  (`{id, name}` or null) and `defaultLanguage`; and `brandId`, the brand a
 *  member chose for the store (null for the default). */
function readWorkspace(
	body: Body,
	fake: Fake,
): Read<{
	tenant: Tenant;
	store: Store;
	change: WorkspaceChange;
	brandId: string | null | undefined;
}> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	const chosen = fake.state.stores.get(store.value);
	const tenant = fake.state.tenants.get(chosen?.tenantId ?? '');
	if (chosen === undefined || tenant === undefined) {
		return refused(`store ${store.value} has no tenant`);
	}
	const change: WorkspaceChange = {};
	if (body.brands !== undefined) {
		const brands = readBrands(body.brands);
		if (!brands.ok) {
			return brands;
		}
		change.brands = brands.value;
	}
	const brandId = body.brandId;
	if (
		brandId !== undefined &&
		brandId !== null &&
		(typeof brandId !== 'string' ||
			!brandsOf({
				...tenant,
				workspace: {
					...tenant.workspace,
					brands: change.brands ?? tenant.workspace.brands,
				},
			}).some((brand) => brand.id === brandId))
	) {
		return refused("`brandId` is one of the workspace's brands, or null");
	}
	for (const field of ['accentColor', 'logoUrl'] as const) {
		const value = body[field];
		if (value !== undefined) {
			if (value !== null && typeof value !== 'string') {
				return refused(`\`${field}\` is a string or null`);
			}
			change.branding = { ...change.branding, [field]: value };
		}
	}
	if (body.defaultLanguage !== undefined) {
		if (!isHostedLocale(body.defaultLanguage)) {
			return refused(
				`\`defaultLanguage\` is one of ${HOSTED_LOCALES.join(', ')}`,
			);
		}
		change.defaultLanguage = body.defaultLanguage;
	}
	if (body.proofWatermark !== undefined) {
		if (typeof body.proofWatermark !== 'boolean') {
			return refused('`proofWatermark` is a boolean');
		}
		change.proofWatermark = body.proofWatermark;
	}
	const deliver = body.deliverOrdersTo;
	if (deliver !== undefined) {
		const address = deliver as { id?: unknown; name?: unknown } | null;
		if (
			address !== null &&
			(typeof address?.id !== 'string' || typeof address.name !== 'string')
		) {
			return refused('`deliverOrdersTo` is {id, name} or null');
		}
		change.deliverOrdersTo =
			address === null
				? null
				: { id: String(address.id), name: String(address.name) };
	}
	return {
		ok: true,
		value: {
			tenant,
			store: chosen,
			change,
			brandId: brandId as string | null | undefined,
		},
	};
}

type Reset = { fixture?: string; siteUrl?: string };

function readReset(body: Body): Read<Reset> {
	const fixture = optionalString(body, 'fixture');
	if (!fixture.ok) {
		return fixture;
	}
	if (
		fixture.value !== undefined &&
		!Object.hasOwn(FIXTURE_SETS, fixture.value)
	) {
		return refused(`No fixture set named ${fixture.value}.`);
	}
	const siteUrl = optionalString(body, 'siteUrl');
	if (!siteUrl.ok) {
		return siteUrl;
	}
	if (siteUrl.value !== undefined && !URL.canParse(siteUrl.value)) {
		return refused('`siteUrl` is not a URL');
	}
	return {
		ok: true,
		value: { fixture: fixture.value, siteUrl: siteUrl.value },
	};
}

type ClockMove = { set?: number; advanceMs?: number };

function readClock(body: Body): Read<ClockMove> {
	const move: ClockMove = {};
	if (body.set !== undefined) {
		const at = typeof body.set === 'string' ? Date.parse(body.set) : body.set;
		if (!finite(at)) {
			return refused('`set` is not a time');
		}
		move.set = at;
	}
	if (body.advanceMs !== undefined) {
		if (!finite(body.advanceMs)) {
			return refused('`advanceMs` is not a number');
		}
		move.advanceMs = body.advanceMs;
	}
	return { ok: true, value: move };
}

function stringRecord(value: unknown): value is Record<string, string> {
	return (
		value !== null &&
		typeof value === 'object' &&
		!Array.isArray(value) &&
		Object.values(value).every((v) => typeof v === 'string')
	);
}

function readFault(value: unknown): Read<Fault> {
	if (value === null || typeof value !== 'object') {
		return refused('`fault` is not an object');
	}
	const fault = value as Body;
	if (fault.kind === 'timeout') {
		if (fault.ms !== undefined && !(finite(fault.ms) && fault.ms >= 0)) {
			return refused('`fault.ms` is not a duration');
		}
		return { ok: true, value: { kind: 'timeout', ms: fault.ms } };
	}
	if (fault.kind !== 'status') {
		return refused('`fault.kind` is neither `status` nor `timeout`');
	}
	if (
		!Number.isInteger(fault.status) ||
		(fault.status as number) < MIN_FAULT_STATUS ||
		(fault.status as number) > MAX_FAULT_STATUS
	) {
		return refused(
			`\`fault.status\` is not a status from ${MIN_FAULT_STATUS} to ${MAX_FAULT_STATUS}`,
		);
	}
	if (fault.error !== undefined && typeof fault.error !== 'string') {
		return refused('`fault.error` is not a string');
	}
	if (fault.headers !== undefined && !stringRecord(fault.headers)) {
		return refused('`fault.headers` is not an object of strings');
	}
	if (fault.fields !== undefined && !fieldRecord(fault.fields)) {
		return refused(
			'`fault.fields` is not an object of strings, numbers and booleans',
		);
	}
	if (fault.fields !== undefined && fault.error === undefined) {
		return refused('`fault.fields` needs `fault.error`');
	}
	return {
		ok: true,
		value: {
			kind: 'status',
			status: fault.status as number,
			error: fault.error,
			fields: fault.fields,
			headers: fault.headers,
		},
	};
}

function fieldRecord(value: unknown): value is Record<string, FaultField> {
	return (
		value !== null &&
		typeof value === 'object' &&
		!Array.isArray(value) &&
		Object.values(value).every((v) =>
			['string', 'number', 'boolean'].includes(typeof v),
		)
	);
}

function readMember(value: unknown, current: Member): Read<Member> {
	if (value === undefined) {
		return { ok: true, value: current };
	}
	const { memberId, memberName, memberEmail } = (value ?? {}) as Body;
	if (
		typeof memberId !== 'string' ||
		typeof memberName !== 'string' ||
		typeof memberEmail !== 'string'
	) {
		return refused(
			'`member` needs `memberId`, `memberName` and `memberEmail` strings',
		);
	}
	return { ok: true, value: { memberId, memberName, memberEmail } };
}

function readConsent(body: Body, current: Consent): Read<Consent> {
	const { decision, environment } = body;
	const member = readMember(body.member, current.member);
	if (!member.ok) {
		return member;
	}
	if (decision !== undefined && decision !== 'approve' && decision !== 'deny') {
		return refused('`decision` is neither `approve` nor `deny`');
	}
	if (
		environment !== undefined &&
		environment !== 'live' &&
		environment !== 'sandbox'
	) {
		return refused('`environment` is neither `live` nor `sandbox`');
	}
	return {
		ok: true,
		value: {
			decision: decision ?? current.decision,
			environment: environment ?? current.environment,
			member: member.value,
		},
	};
}

type ArmFault = { operation: OperationKey; fault: Fault; times?: number };

function readArmFault(body: Body, fake: Fake): Read<ArmFault> {
	if (body.operation === undefined || body.fault === undefined) {
		return refused('`operation` and `fault` are required');
	}
	if (
		typeof body.operation !== 'string' ||
		!fake.contract.operations.has(body.operation as OperationKey)
	) {
		return refused(`${String(body.operation)} is not an operation`);
	}
	const fault = readFault(body.fault);
	if (!fault.ok) {
		return fault;
	}
	const { times } = body;
	if (
		times !== undefined &&
		!(Number.isInteger(times) && (times as number) > 0)
	) {
		return refused('`times` is not a positive integer');
	}
	return {
		ok: true,
		value: {
			operation: body.operation as OperationKey,
			fault: fault.value,
			times: times as number | undefined,
		},
	};
}

/** The reason a failed order gives when the test names none. */
export const DEFAULT_ORDER_FAILURE_REASON = 'The printshop refused the order.';

/** How many orders an armed outcome is for: `times`, or one. */
export function readTimes(body: Body): Read<number> {
	const { times } = body;
	if (
		times !== undefined &&
		!(Number.isInteger(times) && (times as number) > 0)
	) {
		return refused('`times` is not a positive integer');
	}
	return { ok: true, value: (times as number | undefined) ?? 1 };
}

function readOrderFailure(body: Body): Read<OrderFailure> {
	const reason = optionalString(body, 'reason');
	if (!reason.ok) {
		return reason;
	}
	const times = readTimes(body);
	if (!times.ok) {
		return times;
	}
	return {
		ok: true,
		value: {
			outcome: 'failed',
			reason: reason.value ?? DEFAULT_ORDER_FAILURE_REASON,
			remaining: times.value,
		},
	};
}

function readOutcomeUnknown(body: Body): Read<OrderOutcomeUnknown> {
	const times = readTimes(body);
	return times.ok
		? {
				ok: true,
				value: { outcome: 'outcome_unknown', remaining: times.value },
			}
		: times;
}

type DesignCommand = {
	store: string;
	tenantId: string;
	templateId: string;
	seed: DesignSeed;
};

type SeedStrings = {
	id?: string;
	templateId?: string;
	blankId?: string;
	placementId?: string;
};

/** A seed's strings, each optional; the first that is not one refuses it. */
function seedStrings(body: Body): Read<SeedStrings> {
	const read: SeedStrings = {};
	for (const name of ['id', 'templateId', 'blankId', 'placementId']) {
		const value = optionalString(body, name);
		if (!value.ok) {
			return value;
		}
		read[name as keyof SeedStrings] = value.value;
	}
	return { ok: true, value: read };
}

function readDesign(body: Body, fake: Fake): Read<DesignCommand> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	const tenantId = fake.state.stores.get(store.value)?.tenantId ?? '';
	const strings = seedStrings(body);
	if (!strings.ok) {
		return strings;
	}
	const { revision, status } = body;
	if (
		revision !== undefined &&
		!(Number.isInteger(revision) && (revision as number) > 0)
	) {
		return refused('`revision` is not a positive integer');
	}
	if (
		status !== undefined &&
		!(DESIGN_STATUSES as readonly unknown[]).includes(status)
	) {
		return refused(`\`status\` is not one of ${DESIGN_STATUSES.join(', ')}`);
	}
	const { templateId, blankId, placementId } = strings.value;
	const templates = [...fake.state.templates.entries()].filter(
		([, t]) => t.tenantId === tenantId,
	);
	const template =
		templateId ?? templates.find(([, t]) => t.status === 'live')?.[0];
	if (template === undefined || !templates.some(([id]) => id === template)) {
		return refused(`the store's tenant has no template ${template ?? ''}`);
	}
	const blank =
		blankId === undefined ? undefined : fake.state.blanks.get(blankId);
	if (blankId !== undefined && blank?.tenantId !== tenantId) {
		return refused(`the store's tenant has no blank ${blankId}`);
	}
	if (
		placementId !== undefined &&
		!blank?.placements.some((p) => p.id === placementId)
	) {
		return refused(`the blank has no placement ${placementId}`);
	}
	return {
		ok: true,
		value: {
			store: store.value,
			tenantId,
			templateId: template,
			seed: {
				id: strings.value.id,
				blankId,
				placementId,
				revision: revision as number | undefined,
				status: status as DesignStatus | undefined,
			},
		},
	};
}

export function registerControl(app: Hono, fake: Fake): void {
	app.post(`${PREFIX}/reset`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const reset = body.ok ? readReset(body.value) : body;
		if (!reset.ok) {
			return reset.response;
		}
		fake.reset(reset.value.fixture, reset.value.siteUrl);
		return c.json({
			fixture: reset.value.fixture ?? DEFAULT_FIXTURE_SET,
			now: fake.clock.now(),
		});
	});

	// The credential a store handed over, as Pixonaut would call its shop tools with it.
	app.get(`${PREFIX}/store-access`, (c) =>
		c.json({
			credentials: [...fake.state.stores.values()]
				.filter((store) => store.storeAccess !== null)
				.map((store) => ({
					siteUrl: store.siteUrl,
					credential: store.storeAccess,
				})),
		}),
	);
	// Sets the studio's current request to turn store access on, for a test
	// that then delivers `store_access.requested`.
	app.post(`${PREFIX}/store-access/request`, async (c) => {
		const body = (await c.req.json()) as { requestId?: string | null };
		for (const store of fake.state.stores.values()) {
			store.storeAccessRequest = body.requestId ?? null;
		}
		return c.json({ requestId: body.requestId ?? null });
	});
	app.get(`${PREFIX}/clock`, (c) => c.json({ now: fake.clock.now() }));
	app.post(`${PREFIX}/clock`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const move = body.ok ? readClock(body.value) : body;
		if (!move.ok) {
			return move.response;
		}
		// Applied only once the whole command reads, so a refusal moves nothing.
		if (move.value.set !== undefined) {
			fake.clock.set(move.value.set);
		}
		if (move.value.advanceMs !== undefined) {
			fake.clock.advance(move.value.advanceMs);
		}
		return c.json({ now: fake.clock.now() });
	});

	app.get(`${PREFIX}/faults`, (c) => c.json(fake.faults.list()));
	app.post(`${PREFIX}/faults`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const arm = body.ok ? readArmFault(body.value, fake) : body;
		if (!arm.ok) {
			return arm.response;
		}
		fake.faults.set(arm.value.operation, arm.value.fault, arm.value.times);
		return c.json(fake.faults.list());
	});
	app.delete(`${PREFIX}/faults`, (c) => {
		fake.faults.clear();
		return c.json({});
	});

	// The workspace settings a member changes in the studio, which a store
	// reads on `GET /v1/connect`; the fields named replace the store's
	// tenant's.
	app.post(`${PREFIX}/workspace`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const read = body.ok ? readWorkspace(body.value, fake) : body;
		if (!read.ok) {
			return read.response;
		}
		const { tenant, store, change, brandId } = read.value;
		tenant.workspace = {
			...tenant.workspace,
			...change,
			branding: { ...tenant.workspace.branding, ...change.branding },
		};
		if (brandId !== undefined) {
			store.brandId = brandId;
		}
		return c.json(tenant.workspace);
	});

	app.post(`${PREFIX}/consent`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const consent = body.ok
			? readConsent(body.value, fake.state.consent)
			: body;
		if (!consent.ok) {
			return consent.response;
		}
		fake.state.consent = consent.value;
		return c.json(fake.state.consent);
	});

	// The next orders placed answer `201` with status `failed`.
	app.post(`${PREFIX}/orders/fail`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const failure = body.ok ? readOrderFailure(body.value) : body;
		if (!failure.ok) {
			return failure.response;
		}
		fake.state.armedOrderOutcome = failure.value;
		return c.json(failure.value);
	});

	// The next orders placed answer `201` with status `outcome_unknown`, as
	// when the printshop's answer to the submission was lost.
	app.post(`${PREFIX}/orders/outcome-unknown`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const unknown = body.ok ? readOutcomeUnknown(body.value) : body;
		if (!unknown.ok) {
			return unknown.response;
		}
		fake.state.armedOrderOutcome = unknown.value;
		return c.json(unknown.value);
	});

	app.post(`${PREFIX}/designs`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const command = body.ok ? readDesign(body.value, fake) : body;
		if (!command.ok) {
			return command.response;
		}
		const { store, tenantId, templateId, seed } = command.value;
		const design = seedDesign(
			fake.state,
			store,
			tenantId,
			templateId,
			fake.clock.now(),
			seed,
		);
		return c.json({ storeId: store, ...seededView(design) });
	});

	// Any store's design, consent included, for a test checking what a store approved.
	app.get(`${PREFIX}/designs/:id`, (c) => {
		const design = allOwned<Design>(fake.state, DESIGNS).get(
			c.req.param('id'),
		)?.value;
		return design === undefined
			? c.json({ error: 'not_found' }, NOT_FOUND_STATUS)
			: c.json(seededView(design));
	});

	app.get(`${PREFIX}/requests`, (c) => c.json(fake.requests));
	app.get(`${PREFIX}/violations`, (c) => c.json(fake.violations));
}
