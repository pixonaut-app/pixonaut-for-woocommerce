import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
	arrivedPending,
	awaitedByOverlay,
	buildConsumed,
	CONSUMED_FILE,
	formatConsumed,
	missingFromSpec,
	parseConsumed,
	readConsumed,
	refusedKeyKinds,
} from '../../../bin/contracts/consumed.mjs';
import { readJson, SPEC_FILE } from '../../../bin/contracts/files.mjs';
import { readOverlay, withOverlay } from '../../../bin/contracts/overlay.mjs';

const spec = readJson(SPEC_FILE);

describe('consumed.json', () => {
	const consumed = readConsumed();

	it('names only operations and webhooks the pinned spec declares', () => {
		expect(missingFromSpec(consumed, spec)).toEqual([]);
	});

	it('has no pending entry the code already uses, but those the overlay stands in for', () => {
		const used = new Set([
			...consumed.operations,
			...consumed.webhooks.map((type) => `WEBHOOK ${type}`),
		]);
		const awaited = awaitedByOverlay(spec, readOverlay());

		expect(
			consumed.pending.filter(
				(entry) => used.has(entry.op) && !awaited.has(entry.op),
			),
		).toEqual([]);
	});

	it('lists every operation and webhook the overlay stands in for as pending', () => {
		const pending = consumed.pending.map((entry) => entry.op);

		expect(
			[...awaitedByOverlay(spec, readOverlay())].filter(
				(name) => !pending.includes(name),
			),
		).toEqual([]);
	});

	it('is written as pnpm contracts:consumed writes it', () => {
		expect(formatConsumed(consumed)).toBe(readFileSync(CONSUMED_FILE, 'utf8'));
	});

	it('names key kinds the pinned spec, with the overlay, accepts for each operation', () => {
		expect(refusedKeyKinds(consumed, withOverlay(spec, readOverlay()))).toEqual(
			[],
		);
	});

	it('lists operations, webhooks and keys sorted, one per line', () => {
		const text = readFileSync(CONSUMED_FILE, 'utf8');
		const raw = JSON.parse(text);
		const lines = text.split('\n');
		const sorted = (list: string[]) => [...list].sort();
		const hasLine = (entry: string) =>
			lines.some((line) => line.trim().replace(/,$/, '') === entry);

		expect(raw.operations).toEqual(sorted(raw.operations));
		expect(raw.webhooks).toEqual(sorted(raw.webhooks));
		expect(Object.keys(raw.keys)).toEqual(sorted(Object.keys(raw.keys)));
		for (const name of [...raw.operations, ...raw.webhooks]) {
			expect(hasLine(JSON.stringify(name)), name).toBe(true);
		}
		for (const [operation, kinds] of Object.entries(raw.keys)) {
			const value = Array.isArray(kinds)
				? `[${kinds.map((kind) => JSON.stringify(kind)).join(', ')}]`
				: JSON.stringify(kinds);
			expect(hasLine(`${JSON.stringify(operation)}: ${value}`), operation).toBe(
				true,
			);
		}
	});

	it('receives webhook.ping, which is not an event type', () => {
		expect(consumed.webhooks).toContain('webhook.ping');
	});

	it('names every key kind the SDK calls an operation with', () => {
		expect(consumed.keys['GET /v1/templates/{id}/constraints']).toEqual([
			'integrationKey',
			'publishableKey',
			'shareLink',
		]);
		expect(consumed.keys['POST /v1/proofs']).toEqual(['publishableKey']);
	});
});

describe('pending', () => {
	const consumed = (pending: { op: string; requirement: string }[]) =>
		parseConsumed(
			{
				operations: ['GET /v1/not-yet', 'GET /v1/orders/{id}'],
				webhooks: ['thing.happened'],
				pending,
			},
			'fixture',
		);

	it('excuses a consumed name the spec does not declare yet', () => {
		expect(
			missingFromSpec(
				consumed([
					{ op: 'GET /v1/not-yet', requirement: 'R1' },
					{ op: 'WEBHOOK thing.happened', requirement: 'R2' },
				]),
				spec,
			),
		).toEqual([]);
	});

	it('reports a consumed name neither declared nor pending', () => {
		expect(missingFromSpec(consumed([]), spec)).toEqual([
			'GET /v1/not-yet',
			'WEBHOOK thing.happened',
		]);
	});

	it('reports the pending entries a new spec declares and the pinned one did not', () => {
		const pinned = structuredClone(spec);
		delete pinned.paths['/v1/orders/{id}'];
		delete pinned.webhooks['order.submitted'];

		expect(
			arrivedPending(
				consumed([
					{ op: 'GET /v1/orders/{id}', requirement: 'R3' },
					{ op: 'WEBHOOK order.submitted', requirement: 'R4' },
					{ op: 'GET /v1/not-yet', requirement: 'R1' },
					{ op: 'GET /v1/blanks', requirement: 'R5' },
				]),
				pinned,
				spec,
			),
		).toEqual(['GET /v1/orders/{id}', 'WEBHOOK order.submitted']);
	});
});

describe('parseConsumed', () => {
	it.each([
		[
			'an operation without a method',
			{ operations: ['/v1/orders'], webhooks: [] },
		],
		[
			'keys for an operation not listed',
			{
				operations: ['GET /v1/orders'],
				webhooks: [],
				keys: { 'POST /v1/orders': 'integrationKey' },
			},
		],
		[
			'an empty list of key kinds',
			{
				operations: ['GET /v1/orders'],
				webhooks: [],
				keys: { 'GET /v1/orders': [] },
			},
		],
		[
			'a pending entry without a requirement',
			{ operations: [], webhooks: [], pending: [{ op: 'GET /v1/x' }] },
		],
	])('refuses %s', (_, json) => {
		expect(() => parseConsumed(json, 'fixture')).toThrow();
	});
});

describe('refusedKeyKinds', () => {
	const consumed = parseConsumed(
		{
			operations: ['GET /v1/orders/{id}'],
			webhooks: [],
			keys: { 'GET /v1/orders/{id}': ['integrationKey', 'masterKey'] },
		},
		'fixture',
	);

	it('reports a kind the operation stopped accepting and an undeclared one', () => {
		const changed = structuredClone(spec);
		changed.paths['/v1/orders/{id}'].get.security = [{ secretKey: [] }];

		expect(refusedKeyKinds(consumed, changed)).toEqual([
			'GET /v1/orders/{id} does not accept integrationKey',
			'GET /v1/orders/{id} names masterKey, which the spec declares no security scheme for',
		]);
	});

	it('reports a kind that now needs a second scheme with it, inherited from the document', () => {
		const changed = structuredClone(spec);
		delete changed.paths['/v1/orders/{id}'].get.security;
		changed.security = [{ integrationKey: [], secretKey: [] }];

		expect(refusedKeyKinds(consumed, changed)).toContain(
			'GET /v1/orders/{id} does not accept integrationKey',
		);
	});
});

describe('buildConsumed', () => {
	const built = buildConsumed(
		{
			operations: {
				'POST /v1/designs': ['integrationKey'],
				'GET /f/{file}': [],
				'GET /v1/templates/{id}/constraints': [
					'publishableKey',
					'integrationKey',
					'publishableKey',
				],
			},
			webhooks: ['order.failed', 'webhook.ping', 'design.edited'],
		},
		{
			pending: [
				{ op: 'POST /v1/share-links', requirement: 'R2' },
				{ op: 'POST /v1/designs', requirement: 'R1' },
				{ op: 'WEBHOOK order.failed', requirement: 'R3' },
				{ op: 'GET /v1/blanks/{id}/mockups', requirement: 'R4' },
			],
		},
	);

	it('lists what the code uses, sorted, with the key kinds of each operation once', () => {
		expect(built).toMatchObject({
			operations: [
				'GET /f/{file}',
				'GET /v1/templates/{id}/constraints',
				'POST /v1/designs',
			],
			webhooks: ['design.edited', 'order.failed', 'webhook.ping'],
			keys: {
				'GET /v1/templates/{id}/constraints': [
					'integrationKey',
					'publishableKey',
				],
				'POST /v1/designs': ['integrationKey'],
			},
		});
		expect(Object.keys(built.keys)).not.toContain('GET /f/{file}');
	});

	it('keeps the pending entries the code does not use yet, sorted', () => {
		expect(built.pending).toEqual([
			{ op: 'GET /v1/blanks/{id}/mockups', requirement: 'R4' },
			{ op: 'POST /v1/share-links', requirement: 'R2' },
		]);
	});

	it('keeps a used pending entry the overlay stands in for', () => {
		const awaiting = buildConsumed(
			{ operations: { 'POST /v1/designs': ['integrationKey'] }, webhooks: [] },
			{ pending: [{ op: 'POST /v1/designs', requirement: 'R1' }] },
			new Set(['POST /v1/designs']),
		);

		expect(awaiting.pending).toEqual([
			{ op: 'POST /v1/designs', requirement: 'R1' },
		]);
	});

	it('writes a file parseConsumed reads back', () => {
		expect(parseConsumed(JSON.parse(formatConsumed(built)), 'fixture')).toEqual(
			built,
		);
	});
});
