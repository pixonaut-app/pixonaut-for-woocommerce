#!/usr/bin/env node
// Assembles the fake's image context in tests/fake-pixonaut/dist/: the server
// bundled into one file, its fixtures, the pinned contract with its overlay,
// and the Dockerfile.
import { cpSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { build } from 'esbuild';

const fakeDir = join(import.meta.dirname, '..');
const root = join(fakeDir, '..', '..');
const dist = join(fakeDir, 'dist');

/** The contract files the fake reads at run time. */
const CONTRACT_FILES = ['openapi.json', 'consumed.json'];

rmSync(dist, { recursive: true, force: true });
mkdirSync(join(dist, 'contracts'), { recursive: true });

await build({
	entryPoints: [join(fakeDir, 'src', 'main.ts')],
	outfile: join(dist, 'server.mjs'),
	bundle: true,
	platform: 'node',
	format: 'esm',
	target: 'node26',
	logLevel: 'warning',
	// Bundled CommonJS dependencies (Ajv) call `require`.
	banner: {
		js: "import { createRequire } from 'node:module'; const require = createRequire(import.meta.url);",
	},
});
cpSync(join(fakeDir, 'fixtures'), join(dist, 'fixtures'), { recursive: true });
for (const file of CONTRACT_FILES) {
	cpSync(
		join(root, 'contracts', 'pixonaut', file),
		join(dist, 'contracts', file),
	);
}
cpSync(
	join(fakeDir, 'spec-overlay.json'),
	join(dist, 'contracts', 'spec-overlay.json'),
);
cpSync(join(fakeDir, 'Dockerfile'), join(dist, 'Dockerfile'));
console.log(`fake-pixonaut built into ${dist}`);
