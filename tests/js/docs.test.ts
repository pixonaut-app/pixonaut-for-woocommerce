// The generated developer references: renderers, marker replacement and the
// staleness check. The data comes from bin/docs/reference-data.php in wp-env.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
	PAGES,
	replaceSection,
	undocumentedHooks,
	updatePages,
} from '../../bin/docs/references.mjs';
import {
	renderDeprecatedHooks,
	renderEmails,
	renderHooks,
	renderLabelKeys,
	renderRestRoutes,
	renderSettings,
	renderTemplates,
} from '../../bin/docs/render.mjs';
import { tempDir, writeFiles } from './support/scripts';

type ReferenceData = Parameters<typeof renderHooks>[0];
type Hook = ReferenceData['hooks'][number];

function hook(fields: Partial<Hook> = {}): Hook {
	return {
		name: 'pixonaut_wc_area_name',
		type: 'filter',
		deprecated: false,
		file: 'src/Area.php',
		line: 10,
		args: 1,
		documented: true,
		summary: 'Filters a thing.',
		description: '',
		since: '0.1.0',
		params: [{ type: 'string', name: '$thing', description: 'The thing.' }],
		example:
			"add_filter( 'pixonaut_wc_area_name', function ( $thing ) {\n    return $thing;\n} );",
		deprecation: '',
		...fields,
	};
}

function data(fields: Partial<ReferenceData> = {}): ReferenceData {
	return {
		hooks: [],
		renamed: [],
		labels: {},
		settings: [],
		emails: [],
		rest_routes: [],
		templates: [],
		...fields,
	};
}

describe('renderHooks', () => {
	it('documents a hook from its docblock fields', () => {
		const markdown = renderHooks(
			data({
				hooks: [
					hook({
						description: 'More detail.',
						params: [
							{
								type: 'Acme\\Line|null',
								name: '$line',
								description: 'The line.',
							},
						],
					}),
				],
			}),
		);

		expect(markdown).toBe(
			[
				'## Filters',
				'',
				'### `pixonaut_wc_area_name`',
				'',
				'Filters a thing.',
				'',
				'More detail.',
				'',
				'| Parameter | Type | Description |',
				'|---|---|---|',
				'| `$line` | `Acme\\Line\\|null` | The line. |',
				'',
				'Since 0.1.0. Fired in `src/Area.php`.',
				'',
				'```php',
				"add_filter( 'pixonaut_wc_area_name', function ( $thing ) {",
				'    return $thing;',
				'} );',
				'```',
				'',
				'## Actions',
				'',
				'None yet.',
			].join('\n'),
		);
	});

	it('splits filters from actions and leaves deprecated hooks out', () => {
		const markdown = renderHooks(
			data({
				hooks: [
					hook({ name: 'pixonaut_wc_a_filter' }),
					hook({ name: 'pixonaut_wc_a_action', type: 'action' }),
					hook({ name: 'pixonaut_wc_a_old', deprecated: true }),
				],
			}),
		);

		expect(markdown.indexOf('pixonaut_wc_a_filter')).toBeLessThan(
			markdown.indexOf('## Actions'),
		);
		expect(markdown.indexOf('pixonaut_wc_a_action')).toBeGreaterThan(
			markdown.indexOf('## Actions'),
		);
		expect(markdown).not.toContain('pixonaut_wc_a_old');
	});
});

describe('renderDeprecatedHooks', () => {
	it('lists renamed and retired hooks', () => {
		expect(
			renderDeprecatedHooks(
				data({
					renamed: [
						{
							old: 'pixonaut_wc_a_old',
							new: 'pixonaut_wc_a_new',
							version: '1.2.0',
							type: 'filter',
						},
					],
					hooks: [
						hook({
							name: 'pixonaut_wc_a_gone',
							type: 'action',
							deprecated: true,
							deprecation: '1.3.0 No replacement.',
						}),
					],
				}),
			),
		).toBe(
			[
				'| Hook | Type | Deprecated in | Instead |',
				'|---|---|---|---|',
				'| `pixonaut_wc_a_old` | filter | 1.2.0 | Renamed to `pixonaut_wc_a_new` |',
				'| `pixonaut_wc_a_gone` | action |  | 1.3.0 No replacement. |',
			].join('\n'),
		);
	});

	it('says when none is deprecated', () => {
		expect(renderDeprecatedHooks(data())).toBe('No hook is deprecated.');
	});
});

describe('the registry references', () => {
	it('lists label keys with their default text', () => {
		expect(
			renderLabelKeys(
				data({ labels: { a_key: { text: 'A | B', shown: 'Somewhere' } } }),
			),
		).toBe(
			[
				'| Key | Default text | Shown |',
				'|---|---|---|',
				'| `a_key` | A \\| B | Somewhere |',
			].join('\n'),
		);
	});

	it('documents each setting with its default and choices', () => {
		expect(
			renderSettings(
				data({
					settings: [
						{
							key: 'a_setting',
							default: 'one',
							type: 'select',
							title: 'A setting',
							description: 'What it does.',
							options: { one: 'One', two: 'Two' },
						},
					],
				}),
			),
		).toBe(
			[
				'### `a_setting`',
				'',
				'**A setting** (select). What it does.',
				'',
				'Default: `one`.',
				'',
				'| Value | Shown as |',
				'|---|---|',
				'| `one` | One |',
				'| `two` | Two |',
			].join('\n'),
		);
	});

	it('says an empty default is empty', () => {
		expect(
			renderSettings(
				data({
					settings: [
						{
							key: 'an_address',
							default: '',
							type: 'url',
							title: 'An address',
							description: 'Where.',
							options: {},
						},
					],
				}),
			),
		).toContain('Default: empty.');
	});

	it('lists emails and REST routes, or says there are none', () => {
		expect(renderEmails(data())).toBe('The plugin adds no emails yet.');
		expect(renderRestRoutes(data())).toBe(
			'The plugin registers no REST routes yet.',
		);
		expect(
			renderEmails(
				data({
					emails: [
						{
							id: 'pixonaut_proof',
							title: 'Proof',
							description: 'Asks for approval.',
							customer: true,
							template: 'emails/proof.php',
						},
					],
				}),
			),
		).toContain(
			'| `pixonaut_proof` | Proof | Customer | `emails/proof.php` | Asks for approval. |',
		);
		expect(
			renderRestRoutes(
				data({
					rest_routes: [{ route: '/pixonaut/v1/webhook', methods: ['POST'] }],
				}),
			),
		).toContain('| `/pixonaut/v1/webhook` | POST |');
	});
});

describe('renderTemplates', () => {
	it('lists each template with its version and override path', () => {
		expect(
			renderTemplates(
				data({
					templates: [
						{
							name: 'admin/notice.php',
							version: '0.2.0',
							override: 'woocommerce/pixonaut/admin/notice.php',
							summary: 'An admin notice | with a pipe.',
						},
					],
				}),
			),
		).toBe(
			[
				'| Template | Version | Override in the theme | Description |',
				'|---|---|---|---|',
				'| `admin/notice.php` | 0.2.0 | `woocommerce/pixonaut/admin/notice.php` | An admin notice \\| with a pipe. |',
			].join('\n'),
		);
	});

	it('says when there are none', () => {
		expect(renderTemplates(data())).toBe('The plugin ships no templates yet.');
	});
});

describe('replaceSection', () => {
	const page = [
		'Hand-written.',
		'',
		'<!-- BEGIN GENERATED a: run pnpm docs:generate -->',
		'old',
		'<!-- END GENERATED a -->',
		'',
		'Also hand-written.',
		'',
	].join('\n');

	it('replaces only what is between the markers', () => {
		expect(replaceSection(page, 'a', 'new')).toBe(
			[
				'Hand-written.',
				'',
				'<!-- BEGIN GENERATED a: run pnpm docs:generate -->',
				'',
				'new',
				'',
				'<!-- END GENERATED a -->',
				'',
				'Also hand-written.',
				'',
			].join('\n'),
		);
	});

	it('is stable when run again', () => {
		const once = replaceSection(page, 'a', 'new');
		expect(replaceSection(once, 'a', 'new')).toBe(once);
	});

	it('refuses a page without the markers', () => {
		expect(() => replaceSection(page, 'b', 'new')).toThrow(
			'No GENERATED b markers.',
		);
	});
});

describe('updatePages', () => {
	/** A checkout whose pages hold the repository's markers and nothing between. */
	function checkout(): string {
		const root = tempDir('docs');
		const files: Record<string, string> = {};
		for (const [file, sections] of Object.entries(PAGES)) {
			files[file] = Object.keys(sections)
				.map(
					(name) =>
						`<!-- BEGIN GENERATED ${name} -->\n<!-- END GENERATED ${name} -->\n`,
				)
				.join('\n');
		}
		writeFiles(root, files);
		return root;
	}

	it('writes the pages, then finds them up to date', () => {
		const root = checkout();
		const reference = data({ hooks: [hook()] });

		expect(updatePages(root, reference)).toEqual(Object.keys(PAGES));
		expect(
			readFileSync(join(root, 'docs/developer/hooks.md'), 'utf8'),
		).toContain('### `pixonaut_wc_area_name`');
		expect(updatePages(root, reference, { check: true })).toEqual([]);
	});

	it('reports a changed docblock as stale in check mode, writing nothing', () => {
		const root = checkout();
		updatePages(root, data({ hooks: [hook()] }));
		const page = join(root, 'docs/developer/hooks.md');
		const before = readFileSync(page, 'utf8');

		const stale = updatePages(
			root,
			data({ hooks: [hook({ summary: 'Filters a thing, changed.' })] }),
			{ check: true },
		);

		expect(stale).toEqual(['docs/developer/hooks.md']);
		expect(readFileSync(page, 'utf8')).toBe(before);
	});

	it('names the page that lacks a marker', () => {
		const root = checkout();
		writeFiles(root, { 'docs/developer/emails.md': 'No markers.' });

		expect(() => updatePages(root, data())).toThrow(
			'docs/developer/emails.md: No GENERATED emails markers.',
		);
	});
});

describe('undocumentedHooks', () => {
	it('names hooks without a docblock, summary or @since', () => {
		expect(
			undocumentedHooks(
				data({
					hooks: [
						hook(),
						hook({ name: 'pixonaut_wc_a_bare', documented: false }),
						hook({ name: 'pixonaut_wc_a_undated', since: '' }),
					],
				}),
			),
		).toEqual([
			'pixonaut_wc_a_bare (src/Area.php:10)',
			'pixonaut_wc_a_undated (src/Area.php:10)',
		]);
	});
});
