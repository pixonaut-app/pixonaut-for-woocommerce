#!/usr/bin/env node
// Checks, before a release is built, that no template's `@version` is newer
// than the plugin version being released. Between releases a changed template
// already names the release that will ship it (the unit suite caps it at the
// next possible release); the release itself must not ship one ahead of it,
// or a theme's copy would not be flagged when the template changed again.
// Exits 1 and names each template that is ahead.
//
// Usage: check-template-versions.mjs [plugin-root]   (default: this checkout)
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import { pathToFileURL } from 'node:url';
import { root as checkoutRoot, SLUG } from '../env.mjs';
import { header } from '../plugin-header.mjs';

const TEMPLATES_DIR = 'templates';

/**
 * How much of a template is read for its `@version`, as `Templates::version()`
 * and WooCommerce read it.
 */
const HEADER_BYTES = 8192;

/** The `@version` line, as `Templates::version()` matches it. */
const VERSION_LINE = /^[ \t/*#@]*@version(.*)$/im;

/** @param {string} version */
function parts(version) {
	return version
		.split('-')[0]
		.split('.')
		.map((part) => Number.parseInt(part, 10) || 0);
}

/**
 * Whether `a` is a newer `major.minor.patch` than `b`.
 *
 * @param {string} a
 * @param {string} b
 */
export function newer(a, b) {
	const left = parts(a);
	const right = parts(b);
	for (const [index, value] of left.entries()) {
		const other = right[index] ?? 0;
		if (value !== other) {
			return value > other;
		}
	}
	return false;
}

/**
 * A template's `@version`; empty when it has none.
 *
 * @param {string} text The template's source.
 */
export function templateVersion(text) {
	return (
		text
			.slice(0, HEADER_BYTES)
			.replace(/\r/g, '\n')
			.match(VERSION_LINE)?.[1]
			?.trim() ?? ''
	);
}

/** @param {string} dir */
function phpFiles(dir) {
	return readdirSync(dir, { recursive: true, withFileTypes: true })
		.filter((entry) => entry.isFile() && entry.name.endsWith('.php'))
		.map((entry) => join(entry.parentPath, entry.name));
}

/**
 * The templates under `pluginRoot` whose `@version` is newer than `version`.
 *
 * @param {string} pluginRoot
 * @param {string} version
 * @returns {{ name: string, version: string }[]}
 */
export function templatesAhead(pluginRoot, version) {
	const dir = join(pluginRoot, TEMPLATES_DIR);
	return phpFiles(dir)
		.map((file) => ({
			name: relative(dir, file).split(sep).join('/'),
			version: templateVersion(readFileSync(file, 'utf8')),
		}))
		.filter((template) => newer(template.version, version))
		.sort((a, b) => a.name.localeCompare(b.name));
}

function main() {
	const pluginRoot = resolve(process.argv[2] ?? checkoutRoot);
	const version = header(
		readFileSync(join(pluginRoot, `${SLUG}.php`), 'utf8'),
		'Version',
	);
	if (version === undefined) {
		console.error(`${SLUG}.php: no Version header`);
		process.exit(1);
	}
	const ahead = templatesAhead(pluginRoot, version);
	for (const template of ahead) {
		console.error(
			`${TEMPLATES_DIR}/${template.name}: @version ${template.version} is newer than the release, ${version}. Set it to ${version}.`,
		);
	}
	if (ahead.length > 0) {
		process.exit(1);
	}
	console.log(`No template is newer than ${version}.`);
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	main();
}
