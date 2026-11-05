// The docs link check: links resolve against the page's URL on the docs
// site, headings exist, and every page is reachable from its section's index.
import { describe, expect, it } from 'vitest';
import { check, parse, route, slug } from '../../bin/docs/links.mjs';
import { runScript, tempDir, writeFiles } from './support/scripts';

function site(files: Record<string, string>): string {
	const dir = tempDir('docs');
	writeFiles(dir, files);
	return dir;
}

const FRONTMATTER = '---\ntitle: T\n---\n';

describe('route', () => {
	it('serves a page under its name and an index at its directory', () => {
		expect(route('merchant/connect.md')).toBe('/merchant/connect/');
		expect(route('merchant/index.md')).toBe('/merchant/');
		expect(route('index.md')).toBe('/');
	});
});

describe('slug', () => {
	it('makes the anchor the docs site gives a heading', () => {
		expect(slug('Printing and shipping')).toBe('printing-and-shipping');
		expect(slug('`pixonaut_wc_settings`')).toBe('pixonaut_wc_settings');
		expect(slug('Changes asked for on a proof?')).toBe(
			'changes-asked-for-on-a-proof',
		);
		expect(slug('See [Webhooks](./webhooks/)')).toBe('see-webhooks');
	});
});

describe('parse', () => {
	it('skips frontmatter and code, and numbers duplicate headings', () => {
		const { links, anchors } = parse(
			[
				'---',
				'title: x',
				'---',
				'# A',
				'[one](./one/) and `[not](./code/)`',
				'```',
				'[not](./fenced/)',
				'# Not a heading',
				'```',
				'## A',
			].join('\n'),
		);

		expect(links).toEqual([{ line: 5, target: './one/', image: false }]);
		expect([...anchors]).toEqual(['a', 'a-1']);
	});

	it('numbers a repeat past a heading that already has the suffix', () => {
		const { anchors } = parse(
			['# A', '# A-1', '# A', '# A', '# A-1'].join('\n'),
		);

		expect([...anchors]).toEqual(['a', 'a-1', 'a-2', 'a-3', 'a-1-1']);
	});

	it('takes a reference definition as the link and marks images', () => {
		const { links } = parse(
			[
				'[one][ref] ![pic](./p.png)',
				'',
				'[ref]: ./one/',
				'[^note]: Text.',
			].join('\n'),
		);

		expect(links).toEqual([
			{ line: 1, target: './p.png', image: true },
			{ line: 3, target: './one/', image: false },
		]);
	});
});

describe('check', () => {
	it('passes linked pages and headings', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[A](./a/) [B](./b/#two)`,
			'docs/merchant/a.md': `${FRONTMATTER}[B](../b/) [Dev](../../developer/)`,
			'docs/merchant/b.md': `${FRONTMATTER}## Two`,
			'docs/developer/index.md': `${FRONTMATTER}[Merchant](../merchant/a/)`,
			'README.md': '[docs](docs/merchant/index.md)',
		});

		expect(check(dir)).toEqual([]);
	});

	it('resolves a link against the page URL, not its file', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[A](./a/)`,
			'docs/merchant/a.md': `${FRONTMATTER}[B](./b/)\n[file](./b.md)`,
			'docs/merchant/b.md': FRONTMATTER,
		});

		expect(check(dir)).toEqual([
			'docs/merchant/a.md:4: no page at ./b/',
			'docs/merchant/a.md:5: no page at ./b.md',
			'docs/merchant/b.md: not reachable from docs/merchant/index.md',
		]);
	});

	it('names a missing heading', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[A](./a/#gone)\n## Here\n[x](#here)`,
			'docs/merchant/a.md': FRONTMATTER,
		});

		expect(check(dir)).toEqual([
			'docs/merchant/index.md:4: no heading #gone in docs/merchant/a.md',
		]);
	});

	it('finds a page reached only through another page, and one not at all', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[A](./a/)`,
			'docs/merchant/a.md': `${FRONTMATTER}[B](../b/)`,
			'docs/merchant/b.md': FRONTMATTER,
			'docs/merchant/orphan.md': `${FRONTMATTER}[A](../a/)`,
		});

		expect(check(dir)).toEqual([
			'docs/merchant/orphan.md: not reachable from docs/merchant/index.md',
		]);
	});

	it('follows reference definitions and checks their targets', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[A][a] [B][b]\n\n[a]: ./a/\n[b]: ./gone/`,
			'docs/merchant/a.md': FRONTMATTER,
		});

		expect(check(dir)).toEqual([
			'docs/merchant/index.md:7: no page at ./gone/',
		]);
	});

	it('looks for an image beside the page file and does not follow it', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}![here](./here.png) ![gone](./gone.png)`,
			'docs/merchant/here.png': '',
		});

		expect(check(dir)).toEqual([
			'docs/merchant/index.md:4: no file at ./gone.png',
		]);
	});

	it('names a malformed escape and goes on to the next link', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[bad](#%zz) [gone](./gone/)`,
		});

		expect(check(dir)).toEqual([
			'docs/merchant/index.md:4: malformed link #%zz',
			'docs/merchant/index.md:4: no page at ./gone/',
		]);
	});

	it('ignores external links and names a missing README target', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[x](https://example.test/a/)`,
			'README.md': '[ok](https://example.test)\n[gone](docs/nothing.md)',
		});

		expect(check(dir)).toEqual(['README.md:2: no file at docs/nothing.md']);
	});
});

describe('docs/links.mjs', () => {
	it('passes the checkout itself', () => {
		const result = runScript('docs/links.mjs', [], { cwd: process.cwd() });

		expect(result.status, result.stderr).toBe(0);
	});

	it('exits 1 and names each problem', () => {
		const dir = site({
			'docs/merchant/index.md': `${FRONTMATTER}[x](./x/)`,
		});
		const result = runScript('docs/links.mjs', [dir], { cwd: dir });

		expect(result.status).toBe(1);
		expect(result.stderr).toContain(
			'docs/merchant/index.md:4: no page at ./x/',
		);
	});
});
