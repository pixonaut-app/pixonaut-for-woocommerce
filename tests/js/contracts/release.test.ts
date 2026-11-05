// Pinning and checking against the CDN, served here by a local fake: the
// real CDN is never called.
import {
	copyFileSync,
	mkdtempSync,
	readFileSync,
	writeFileSync,
} from 'node:fs';
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { sha256, sri384 } from '../../../bin/contracts/files.mjs';
import { checkPin, runPin } from '../../../bin/contracts/pinning.mjs';
import { apiBaseUrl, readApiRelease } from '../../../bin/contracts/release.mjs';

const API_VERSION = '1.2.0';
const NEXT_API_VERSION = '1.3.0';
const SDK_VERSION = '0.9.0';
const NOT_FOUND = 404;

const SPEC = Buffer.from('{"openapi":"3.1.0","info":{"version":"1.2.0"}}\n');
const NEXT_SPEC = Buffer.from(
	'{"openapi":"3.1.0","info":{"version":"1.3.0"}}\n',
);
const VECTORS = Buffer.from('{"vectors":[]}\n');
const SDK_FILES: Record<string, Buffer> = {
	'pixonaut.iife.js': Buffer.from('window.Pixonaut = {};\n'),
	'pixonaut.d.ts': Buffer.from('export {};\n'),
	'schema.d.ts': Buffer.from('export type Schema = unknown;\n'),
	'LICENSE-MIT': Buffer.from('MIT\n'),
};

/** What the fake CDN serves, by path. */
let served: Map<string, Buffer>;
let server: Server;
let cdn: string;
let dir: string;

function integrity(version: string, files: Record<string, Buffer>): Buffer {
	return Buffer.from(
		JSON.stringify({
			version,
			released: '2026-11-05',
			files: Object.fromEntries(
				Object.entries(files).map(([name, bytes]) => [name, sri384(bytes)]),
			),
		}),
	);
}

function publishApi(version: string, spec: Buffer): void {
	const files = {
		'openapi.json': spec,
		'webhook-signature-vectors.json': VECTORS,
	};
	for (const [name, bytes] of Object.entries(files)) {
		served.set(`/api/${version}/${name}`, bytes);
	}
	served.set(`/api/${version}/integrity.json`, integrity(version, files));
}

function publishSdk(version: string): void {
	for (const [name, bytes] of Object.entries(SDK_FILES)) {
		served.set(`/sdk/${version}/${name}`, bytes);
	}
	served.set(`/sdk/${version}/integrity.json`, integrity(version, SDK_FILES));
}

const noComparison = () => ({ breaking: false });
const breaking = () => ({ breaking: true });
const describeNothing = () => ['what changed'];

function pin(version = API_VERSION) {
	const lines: string[] = [];
	return runPin({
		dir,
		version,
		sdkVersion: SDK_VERSION,
		cdn,
		compare: noComparison,
		describe: describeNothing,
		log: (line: string) => lines.push(line),
	}).then(() => lines);
}

function check(
	api?: string,
	compare: () => { breaking: boolean } = noComparison,
) {
	const lines: string[] = [];
	return checkPin({
		dir,
		api,
		cdn,
		compare,
		describe: describeNothing,
		log: (line: string) => lines.push(line),
	}).then((passed: boolean) => ({ passed, text: lines.join('\n') }));
}

const lockIn = (file: string) =>
	JSON.parse(readFileSync(join(dir, file), 'utf8'));

beforeAll(async () => {
	server = createServer((request, response) => {
		const bytes = served.get(request.url ?? '');
		if (bytes === undefined) {
			response.writeHead(NOT_FOUND).end();
			return;
		}
		response.end(bytes);
	});
	await new Promise<void>((done) => server.listen(0, '127.0.0.1', done));
	cdn = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
});

afterAll(() => {
	server.close();
});

beforeEach(() => {
	served = new Map();
	dir = mkdtempSync(join(tmpdir(), 'pixonaut-contract-'));
	copyFileSync(
		join(import.meta.dirname, '../../../contracts/pixonaut/spec.lock.json'),
		join(dir, 'spec.lock.json'),
	);
	writeFileSync(join(dir, 'openapi.json'), '{"previous":true}\n');
	writeFileSync(
		join(dir, 'sdk.lock.json'),
		JSON.stringify({ version: '0.8.0', integrity: 'sha384-old', types: {} }),
	);
	publishApi(API_VERSION, SPEC);
	publishSdk(SDK_VERSION);
});

describe('readApiRelease', () => {
	it('reads a release and checks each file against its integrity', async () => {
		const release = await readApiRelease(API_VERSION, cdn);

		expect(release.spec.equals(SPEC)).toBe(true);
		expect(release.vectors.equals(VECTORS)).toBe(true);
	});

	it('says plainly that a version is not published', async () => {
		await expect(readApiRelease('9.9.9', cdn)).rejects.toThrow(
			'API 9.9.9 is not published',
		);
	});

	it('refuses a file that does not match its integrity', async () => {
		served.set(`/api/${API_VERSION}/openapi.json`, Buffer.from('tampered'));

		await expect(readApiRelease(API_VERSION, cdn)).rejects.toThrow(
			'openapi.json does not match its integrity',
		);
	});
});

describe('runPin', () => {
	it('writes the version and checksums, and the public address, never the source', async () => {
		await pin();

		expect(lockIn('spec.lock.json')).toEqual({
			version: API_VERSION,
			baseUrl: apiBaseUrl(API_VERSION),
			sha256: sha256(SPEC),
			webhookSignatureVectors: { sha256: sha256(VECTORS) },
		});
		expect(readFileSync(join(dir, 'openapi.json')).equals(SPEC)).toBe(true);
		expect(lockIn('sdk.lock.json').version).toBe(SDK_VERSION);
		expect(readFileSync(join(dir, 'sdk', 'pixonaut.d.ts')).toString()).toBe(
			'export {};\n',
		);
	});

	it('changes no byte when the version already pinned is pinned again', async () => {
		await pin();
		const lock = readFileSync(join(dir, 'spec.lock.json'));

		const lines = await pin();

		expect(readFileSync(join(dir, 'spec.lock.json')).equals(lock)).toBe(true);
		expect(lines.join('\n')).toContain(`Spec: unchanged at ${API_VERSION}.`);
	});

	it('reports the Spec constant to move when the version moves', async () => {
		const lines = await pin();

		expect(lines.join('\n')).toContain(
			`Set Api\\Spec::VERSION to ${API_VERSION}`,
		);
	});

	it('leaves the previous pin in place when a release cannot be read', async () => {
		const before = readFileSync(join(dir, 'spec.lock.json'));

		await expect(pin('9.9.9')).rejects.toThrow('API 9.9.9 is not published');

		expect(readFileSync(join(dir, 'spec.lock.json')).equals(before)).toBe(true);
	});
});

describe('checkPin', () => {
	it('passes when the published release is what is pinned', async () => {
		await pin();

		const { passed, text } = await check();

		expect(passed).toBe(true);
		expect(text).toContain(
			`API ${API_VERSION}: the pinned files are the release.`,
		);
		expect(text).toContain(`SDK ${SDK_VERSION}: matches its release record.`);
	});

	it('fails when the pinned version is not published', async () => {
		await pin();
		served.delete(`/api/${API_VERSION}/integrity.json`);

		const { passed, text } = await check();

		expect(passed).toBe(false);
		expect(text).toContain(`API ${API_VERSION} is not published`);
	});

	it('fails when the pinned files differ from the release', async () => {
		await pin();
		publishApi(API_VERSION, NEXT_SPEC);

		const { passed, text } = await check();

		expect(passed).toBe(false);
		expect(text).toContain(`run pnpm contracts:pin ${API_VERSION}`);
	});

	it('fails when the SDK release record differs from the lock', async () => {
		await pin();
		served.set(
			`/sdk/${SDK_VERSION}/integrity.json`,
			integrity(SDK_VERSION, {
				...SDK_FILES,
				'pixonaut.iife.js': Buffer.from('other'),
			}),
		);

		const { passed } = await check();

		expect(passed).toBe(false);
	});

	it('judges a newer release against what the plugin consumes', async () => {
		await pin();
		publishApi(NEXT_API_VERSION, NEXT_SPEC);

		const fine = await check(NEXT_API_VERSION);
		const broken = await check(NEXT_API_VERSION, breaking);

		expect(fine.passed).toBe(true);
		expect(fine.text).toContain('nothing the plugin consumes breaks');
		expect(broken.passed).toBe(false);
		expect(broken.text).toContain('breaks what the plugin consumes');
	});
});
