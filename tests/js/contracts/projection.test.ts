import { describe, expect, it } from 'vitest';
import { compareSpecs } from '../../../bin/contracts/compare.mjs';
import { readConsumed, type Spec } from '../../../bin/contracts/consumed.mjs';
import { readJson, SPEC_FILE } from '../../../bin/contracts/files.mjs';
import { runOasdiff } from '../../../bin/contracts/oasdiff.mjs';
import {
	project,
	WEBHOOK_PATH_PREFIX,
} from '../../../bin/contracts/project.mjs';

/** oasdiff's levels in its JSON output. */
const WARN = 2;
const ERR = 3;
/** Each diff runs oasdiff over every component; the first also fetches it. */
const OASDIFF_TIMEOUT_MS = 120_000;

const consumed = readConsumed();
const HOOK = 'order.status_changed';
const HOOK_PATH = `${WEBHOOK_PATH_PREFIX}${HOOK}`;

type Finding = { id: string; level: number; path: string };

/** A fresh copy of the pinned spec, to mutate. */
function pinned(): Spec {
	return readJson(SPEC_FILE);
}

/** The order.status_changed payload schema in `spec`. */
function payload(spec: Spec) {
	return spec.components?.schemas.OrderStatusChangedEvent.properties.payload;
}

/** `spec` with the payload's `status` as a closed enum, plus `extra`. */
function closeStatus(spec: Spec, extra: string[] = []) {
	const status = payload(spec).properties.status;
	status.enum = [...status['x-extensible-enum'], ...extra];
	delete status['x-extensible-enum'];
	return spec;
}

function breaking(before: Spec, after: Spec): Finding[] {
	const { output } = runOasdiff(
		'breaking',
		project(before, consumed),
		project(after, consumed),
		['--format', 'json'],
	);
	return JSON.parse(output.trim() || '[]');
}

/** The findings on the consumed webhook, as `rule level`. */
function onHook(findings: Finding[]) {
	return findings
		.filter((finding) => finding.path === HOOK_PATH)
		.map((finding) => `${finding.id} ${finding.level}`);
}

describe('project', () => {
	const spec = pinned();
	const projected = project(spec, consumed);

	it('keeps the consumed operations and nothing else', () => {
		expect(Object.keys(projected.paths['/v1/designs/{id}']).sort()).toEqual([
			'delete',
			'get',
			'patch',
		]);
		expect(Object.keys(projected.paths['/v1/assets'])).toEqual(['post']);
		expect(projected.paths).not.toHaveProperty('/v1/admin/sessions/{id}');
	});

	it('answers each consumed webhook payload from a GET', () => {
		expect(projected.paths[HOOK_PATH]).toEqual({
			get: expect.objectContaining({
				responses: {
					200: expect.objectContaining({
						content: spec.webhooks?.[HOOK].post.requestBody.content,
					}),
				},
			}),
		});
		expect(projected.paths).not.toHaveProperty(
			`${WEBHOOK_PATH_PREFIX}print.completed`,
		);
	});

	it('gives each operation the security it inherits from the document', () => {
		const inherited = pinned();
		const operation = inherited.paths?.['/v1/orders/{id}'].get;
		delete operation.security;
		inherited.security = [{ integrationKey: [] }];

		const result = project(inherited, consumed);

		expect(result.paths['/v1/orders/{id}'].get).toHaveProperty('security', [
			{ integrationKey: [] },
		]);
		expect(result.paths[HOOK_PATH].get).not.toHaveProperty('security');
	});
});

describe('oasdiff on the projection', { timeout: OASDIFF_TIMEOUT_MS }, () => {
	it('is an error when a required webhook field is removed', () => {
		const changed = pinned();
		const hook = payload(changed);
		delete hook.properties.orderId;
		hook.required = hook.required.filter((name: string) => name !== 'orderId');

		expect(onHook(breaking(pinned(), changed))).toEqual([
			`response-required-property-removed ${ERR}`,
		]);
	});

	it('is an error when a webhook field becomes optional', () => {
		const changed = pinned();
		const hook = payload(changed);
		hook.required = hook.required.filter((name: string) => name !== 'printId');

		expect(onHook(breaking(pinned(), changed))).toEqual([
			`response-property-became-optional ${ERR}`,
		]);
	});

	it('is a warning when a closed enum gains a value', () => {
		expect(
			onHook(breaking(closeStatus(pinned()), closeStatus(pinned(), ['lost']))),
		).toEqual([`response-property-enum-value-added ${WARN}`]);
	});

	it('reports nothing for a change the plugin does not consume', () => {
		const changed = pinned();
		const paths = changed.paths ?? {};
		delete paths['/v1/admin/sessions/{id}'];
		paths['/v1/assets/bulk'].post.responses = {};

		expect(breaking(pinned(), changed)).toEqual([]);
	});

	it('calls a dropped consumed operation breaking', () => {
		const changed = pinned();
		delete changed.paths?.['/v1/designs/{id}/approve'];

		const result = compareSpecs(pinned(), changed, consumed);

		expect(result.breaking).toBe(true);
		expect(result.missing).toEqual(['POST /v1/designs/{id}/approve']);
	});
});
