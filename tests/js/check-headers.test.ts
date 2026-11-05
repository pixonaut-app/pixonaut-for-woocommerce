import { describe, expect, it } from 'vitest';
import { runScript, tempDir, writeFiles } from './support/scripts';

const PLUGIN_HEADER = `<?php
/**
 * Plugin Name:          Example
 * Version:              1.2.0
 * Requires at least:    6.9
 * Tested up to:         7.1
 * Requires PHP:         8.1
 * WC requires at least: 10.6
 */
`;

const README = `=== Example ===
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
`;

function plugin(files: Record<string, string> = {}): string {
	const dir = tempDir('headers');
	writeFiles(dir, {
		'pixonaut-for-woocommerce.php': PLUGIN_HEADER,
		'composer.json': JSON.stringify({
			require: { php: '>=8.1' },
			config: { platform: { php: '8.1.0' } },
		}),
		'src/Requirements.php':
			"<?php\nfinal class Requirements {\n\tconst MIN_PHP = '8.1';\n\tconst MIN_WOOCOMMERCE = '10.6';\n}\n",
		...files,
	});
	return dir;
}

function check(dir: string) {
	return runScript('check-headers.mjs', [dir], { cwd: dir });
}

describe('header minimums', () => {
	it('passes the checkout itself', () => {
		const result = runScript('check-headers.mjs', [], { cwd: process.cwd() });
		expect(result.status, result.stderr).toBe(0);
	});

	it('passes when every place agrees, without a readme.txt', () => {
		expect(check(plugin()).status).toBe(0);
	});

	it('passes with a readme.txt that agrees', () => {
		expect(check(plugin({ 'readme.txt': README })).status).toBe(0);
	});

	it('fails when composer.json requires another PHP', () => {
		const dir = plugin({
			'composer.json': JSON.stringify({
				require: { php: '>=8.2' },
				config: { platform: { php: '8.1.0' } },
			}),
		});

		const result = check(dir);

		expect(result.status).toBe(1);
		expect(result.stderr).toContain(
			'composer.json: require.php is 8.2, the plugin header says 8.1',
		);
	});

	it('fails when the Composer platform PHP differs', () => {
		const dir = plugin({
			'composer.json': JSON.stringify({
				require: { php: '>=8.1' },
				config: { platform: { php: '8.3.0' } },
			}),
		});

		expect(check(dir).stderr).toContain('config.platform.php is 8.3');
	});

	it('fails when Requirements::MIN_PHP differs', () => {
		const dir = plugin({
			'src/Requirements.php': "<?php\nconst MIN_PHP = '7.4';\n",
		});

		expect(check(dir).stderr).toContain('MIN_PHP is 7.4');
	});

	it('fails when Requirements::MIN_WOOCOMMERCE differs', () => {
		const dir = plugin({
			'src/Requirements.php':
				"<?php\nconst MIN_PHP = '8.1';\nconst MIN_WOOCOMMERCE = '10.5';\n",
		});

		const result = check(dir);

		expect(result.status).toBe(1);
		expect(result.stderr).toContain('MIN_WOOCOMMERCE is 10.5');
	});

	it.each([
		['Requires at least', 'Requires at least: 6.8'],
		['Tested up to', 'Tested up to: 7.0'],
		['Requires PHP', 'Requires PHP: 8.2'],
		['Stable tag', 'Stable tag: 1.1.0'],
	])('fails when readme.txt disagrees on %s', (name, line) => {
		const readme = README.replace(new RegExp(`^${name}: .*$`, 'm'), line);

		const result = check(plugin({ 'readme.txt': readme }));

		expect(result.status).toBe(1);
		expect(result.stderr).toContain(`readme.txt: ${name} is`);
	});

	it('fails when readme.txt leaves a header out', () => {
		const readme = README.replace(/^Requires PHP: .*\n/m, '');

		expect(check(plugin({ 'readme.txt': readme })).stderr).toContain(
			'readme.txt: Requires PHP is missing',
		);
	});
});
