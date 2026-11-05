// Every JavaScript hook and DOM event the plugin's scripts fire is a row of
// the storefront reference's hook and event tables, which are written by hand.
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const ROOT = join(__dirname, '../..');
const SOURCES = join(ROOT, 'assets/src');
const REFERENCE = join(ROOT, 'docs/developer/storefront.md');

/** A `@wordpress/hooks` name (`pixonaut.area.name`) or a DOM event name
 *  (`pixonaut:name`), as a string literal. */
const HOOK_LITERAL = /['"`](pixonaut[.:][A-Za-z.:-]+)['"`]/g;

/** A table row that starts with a name in backticks. */
const TABLE_ROW = /^\| `([^`]+)` \|/gm;

function scripts(dir: string): string[] {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name);
		if (entry.isDirectory()) {
			return scripts(path);
		}
		return /\.tsx?$/.test(entry.name) && !entry.name.includes('.test.')
			? [path]
			: [];
	});
}

function firedNames(): string[] {
	const names = new Set<string>();
	for (const file of scripts(SOURCES)) {
		for (const match of readFileSync(file, 'utf8').matchAll(HOOK_LITERAL)) {
			names.add(match[1]);
		}
	}
	return [...names].sort();
}

function documentedNames(): Set<string> {
	const reference = readFileSync(REFERENCE, 'utf8');
	return new Set([...reference.matchAll(TABLE_ROW)].map((match) => match[1]));
}

describe('JavaScript hooks reference', () => {
	it('finds the hooks and events the storefront fires', () => {
		expect(firedNames()).toEqual(
			expect.arrayContaining([
				'pixonaut.designer.mountOptions',
				'pixonaut.designer.approved',
				'pixonaut.cart.beforeSubmit',
				'pixonaut.storefront.label',
				'pixonaut:designer-approved',
				'pixonaut:cart-before-submit',
			]),
		);
	});

	it('lists every hook and event the scripts fire', () => {
		const documented = documentedNames();

		expect(firedNames().filter((name) => !documented.has(name))).toEqual([]);
	});
});
