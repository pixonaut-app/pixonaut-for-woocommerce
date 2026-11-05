import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { readJson, SPEC_FILE } from '../../../bin/contracts/files.mjs';
import {
	applicableEntries,
	OVERLAY_FILE,
	overlaidOperations,
	overlaidWebhooks,
	parseOverlay,
	readOverlay,
	withOverlay,
} from '../../../bin/contracts/overlay.mjs';

const entry = (pointer: string, value: unknown) => ({
	pointer,
	awaits: 'pixonaut-app/pixonaut#1',
	value,
});

const pinned = {
	paths: {
		'/v1/things': { get: { responses: {} } },
	},
	components: { schemas: { Thing: { properties: { id: {} } } } },
	webhooks: { 'thing.created': { post: {} } },
};

describe('spec overlay', () => {
	it('adds an entry where the pin has the parent and lacks the key', () => {
		const overlay = {
			entries: [
				entry('/paths/~1v1~1things~1{id}', { get: { responses: {} } }),
				entry('/components/schemas/Thing/properties/name', { type: 'string' }),
			],
		};

		const merged = withOverlay(pinned, overlay);

		expect(merged.paths).toHaveProperty(['/v1/things/{id}', 'get']);
		expect(merged.components.schemas.Thing.properties).toHaveProperty('name', {
			type: 'string',
		});
		expect(pinned.paths).not.toHaveProperty(['/v1/things/{id}']);
	});

	it('leaves what the pin already declares, and a parent the pin lacks', () => {
		const overlay = {
			entries: [
				entry('/paths/~1v1~1things', { post: {} }),
				entry('/components/schemas/Gone/properties/id', {}),
			],
		};

		expect(applicableEntries(pinned, overlay)).toEqual([]);
		expect(withOverlay(pinned, overlay)).toEqual(pinned);
	});

	it('applies its entries in order, so one adds to what an earlier one added', () => {
		const overlay = {
			entries: [
				entry('/paths/~1v1~1things~1{id}', { get: { security: [{ a: [] }] } }),
				entry('/paths/~1v1~1things~1{id}/get/security/1', { b: [] }),
			],
		};

		expect(applicableEntries(pinned, overlay)).toEqual(overlay.entries);
		expect(withOverlay(pinned, overlay).paths).toHaveProperty(
			['/v1/things/{id}', 'get', 'security'],
			[{ a: [] }, { b: [] }],
		);
	});

	it('names the operations it adds', () => {
		const overlay = {
			entries: [entry('/paths/~1v1~1things~1{id}', { delete: {}, get: {} })],
		};

		expect(overlaidOperations(pinned, overlay)).toEqual([
			'DELETE /v1/things/{id}',
			'GET /v1/things/{id}',
		]);
	});

	it('names the webhooks it adds', () => {
		const overlay = {
			entries: [
				entry('/webhooks/thing.deleted', { post: {} }),
				entry('/webhooks/thing.created', { post: {} }),
			],
		};

		expect(overlaidWebhooks(pinned, overlay)).toEqual(['thing.deleted']);
	});

	it('refuses an entry without what it awaits', () => {
		expect(() =>
			parseOverlay({ entries: [{ pointer: '/paths', value: {} }] }, 'fixture'),
		).toThrow(/awaits/);
	});

	it('keeps its entries sorted by pointer, each applying to the pinned spec', () => {
		const overlay = readOverlay();
		const pointers = overlay.entries.map((one) => one.pointer);

		expect(pointers).toEqual([...pointers].sort());
		// An entry the pin brought, or whose parent moved, is removed rather than kept.
		expect(applicableEntries(readJson(SPEC_FILE), overlay)).toEqual(
			overlay.entries,
		);
		expect(readFileSync(OVERLAY_FILE, 'utf8').endsWith('\n')).toBe(true);
	});
});
