// The release's check that no template is newer than the version released.
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
	newer,
	templatesAhead,
	templateVersion,
} from '../../bin/release/check-template-versions.mjs';
import { runScript, tempDir, writeFiles } from './support/scripts';

const SCRIPT = 'release/check-template-versions.mjs';

const CHECKOUT = join(__dirname, '..', '..');

/** @param version The template's `@version`. */
function template(version: string): string {
	return `<?php\n/**\n * A template.\n *\n * @version ${version}\n */\n`;
}

function plugin(version: string, templates: Record<string, string>): string {
	const dir = tempDir('template-versions');
	writeFiles(dir, {
		'pixonaut-for-woocommerce.php': `<?php\n/**\n * Plugin Name: Example\n * Version:     ${version}\n */\n`,
		...Object.fromEntries(
			Object.entries(templates).map(([name, text]) => [
				`templates/${name}`,
				text,
			]),
		),
	});
	return dir;
}

describe('newer', () => {
	it.each([
		['0.2.0', '0.1.0', true],
		['0.1.1', '0.1.0', true],
		['0.10.0', '0.9.0', true],
		['1.0.0', '0.99.0', true],
		['0.1.0', '0.1.0', false],
		['0.1.0', '0.2.0', false],
		['', '0.1.0', false],
	])('%s newer than %s: %s', (a, b, expected) => {
		expect(newer(a, b)).toBe(expected);
	});
});

describe('templateVersion', () => {
	it('reads the docblock line', () => {
		expect(templateVersion(template('0.3.1'))).toBe('0.3.1');
	});

	it('is empty without one', () => {
		expect(templateVersion('<?php\n/** Keep the @version tag. */\n')).toBe('');
	});
});

describe('templatesAhead', () => {
	it('names every template newer than the release, nested ones included', () => {
		const dir = plugin('0.1.0', {
			'admin/a.php': template('0.1.0'),
			'admin/b.php': template('0.2.0'),
			'emails/plain/c.php': template('0.1.1'),
			'top.php': template('0.0.9'),
		});

		expect(templatesAhead(dir, '0.1.0')).toEqual([
			{ name: 'admin/b.php', version: '0.2.0' },
			{ name: 'emails/plain/c.php', version: '0.1.1' },
		]);
	});
});

describe('check-template-versions.mjs', () => {
	it('fails, naming the template, when one is newer than the plugin version', () => {
		const dir = plugin('0.1.0', { 'admin/b.php': template('0.2.0') });

		const result = runScript(SCRIPT, [dir], { cwd: dir });

		expect(result.status).toBe(1);
		expect(result.stderr).toContain(
			'templates/admin/b.php: @version 0.2.0 is newer than the release, 0.1.0.',
		);
	});

	it('passes when every template is at or below the plugin version', () => {
		const dir = plugin('0.2.0', {
			'admin/a.php': template('0.1.0'),
			'admin/b.php': template('0.2.0'),
		});

		expect(runScript(SCRIPT, [dir], { cwd: dir }).status).toBe(0);
	});

	it('passes the checkout itself', () => {
		expect(runScript(SCRIPT, [CHECKOUT], { cwd: CHECKOUT }).status).toBe(0);
	});
});
