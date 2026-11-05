#!/usr/bin/env node
// Checks the links in the docs as the docs site serves them: a page
// `docs/<section>/<page>.md` is at `/<section>/<page>/` and a section's
// `index.md` at `/<section>/`, so a link resolves against the page's URL,
// not its file. Fails on a link to a page or heading that does not exist,
// and on a page that cannot be reached from its section's index by
// following links. Relative links in README.md must name an existing file.
// Exits 1 and names each problem by file and line.
//
// Usage: docs/links.mjs [repo-root]   (default: this checkout)
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { root as checkoutRoot } from '../env.mjs';

const DOCS_DIR = 'docs';
const INDEX = 'index.md';
/** A base for resolving links that are relative to a page's URL. */
const SITE = 'https://docs.invalid';
/** A link with a scheme (`https:`, `mailto:`) leaves the docs. */
const EXTERNAL = /^[a-z][a-z0-9+.-]*:/i;
const FENCE = /^\s*(```|~~~)/;
const HEADING = /^#{1,6}\s+(.+?)\s*#*\s*$/;
/** An inline link `[text](target)` or image `![alt](target "title")`. */
const LINK = /(!?)\[(?:[^\]\\]|\\.)*\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"]*")?\s*\)/g;
/**
 * A reference definition `[label]: target`, which every `[text][label]`
 * links through: checking the definition checks them all.
 */
const DEFINITION = /^ {0,3}\[(?!\^)(?:[^\]\\]|\\.)+\]:\s*<?([^\s>]+)>?/;
const INLINE_CODE = /(`+)[\s\S]*?\1/g;
/** A heading's text, as its anchor, separated from the number that tells duplicates apart. */
const SUFFIX_SEPARATOR = '-';

/**
 * @typedef {{ line: number, target: string, image: boolean }} Link
 * @typedef {{ file: string, route: string, links: Link[], anchors: Set<string> }} Page
 */

/**
 * The URL path a docs page is served at.
 *
 * @param {string} file Path relative to the docs directory, `/`-separated.
 */
export function route(file) {
	const path = file.replace(/\.md$/, '');
	return path === 'index' || path.endsWith('/index')
		? `/${path.replace(/\/?index$/, '')}/`.replace(/^\/\/$/, '/')
		: `/${path}/`;
}

/**
 * A heading's anchor, as the docs site (github-slugger) makes it: lower case,
 * punctuation dropped, spaces to hyphens.
 *
 * @param {string} heading The heading's Markdown text.
 */
export function slug(heading) {
	return heading
		.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
		.replace(/<[^>]+>/g, '')
		.toLowerCase()
		.replace(/[^\p{L}\p{M}\p{N}\p{Pc} -]/gu, '')
		.replace(/ /g, '-');
}

/**
 * The links and heading anchors of a Markdown text, outside code.
 *
 * @param {string} text
 * @returns {{ links: Link[], anchors: Set<string> }}
 */
export function parse(text) {
	/** @type {Link[]} */
	const links = [];
	const anchors = new Set();
	/** @type {Map<string, number>} */
	const seen = new Map();
	let fenced = false;
	let frontmatter = text.startsWith('---');
	text.split(/\r?\n/).forEach((raw, index) => {
		if (frontmatter) {
			frontmatter = index === 0 || raw.trim() !== '---';
			return;
		}
		if (FENCE.test(raw)) {
			fenced = !fenced;
			return;
		}
		if (fenced) {
			return;
		}
		const heading = raw.match(HEADING);
		if (heading !== null) {
			anchors.add(uniqueSlug(slug(heading[1]), seen));
		}
		const definition = raw.match(DEFINITION);
		if (definition !== null) {
			links.push({ line: index + 1, target: definition[1], image: false });
			return;
		}
		for (const match of raw.replace(INLINE_CODE, '').matchAll(LINK)) {
			links.push({
				line: index + 1,
				target: match[2],
				image: match[1] === '!',
			});
		}
	});
	return { links, anchors };
}

/**
 * A heading's anchor among the page's others, as github-slugger numbers
 * them: a repeat gets the next `-<n>` no heading has taken yet, so `A`, `A-1`,
 * `A` give `a`, `a-1`, `a-2`.
 *
 * @param {string} base
 * @param {Map<string, number>} seen Anchor => how often it was asked for.
 */
function uniqueSlug(base, seen) {
	let anchor = base;
	while (seen.has(anchor)) {
		const count = (seen.get(base) ?? 0) + 1;
		seen.set(base, count);
		anchor = `${base}${SUFFIX_SEPARATOR}${count}`;
	}
	seen.set(anchor, 0);
	return anchor;
}

/**
 * `text` percent-decoded, or undefined when it holds a malformed escape.
 *
 * @param {string} text
 */
function decoded(text) {
	try {
		return decodeURIComponent(text);
	} catch {
		return undefined;
	}
}

/** @param {string} dir */
function markdownFiles(dir) {
	return readdirSync(dir, { recursive: true, encoding: 'utf8' })
		.filter((file) => file.endsWith('.md'))
		.map((file) => file.split(sep).join('/'))
		.sort();
}

/**
 * Every problem with the docs' links, as `file:line: message`.
 *
 * @param {string} root The repository root.
 * @returns {string[]}
 */
export function check(root) {
	const docs = join(root, DOCS_DIR);
	/** @type {Map<string, Page>} */
	const pages = new Map();
	for (const file of markdownFiles(docs)) {
		const page = {
			file: `${DOCS_DIR}/${file}`,
			route: route(file),
			...parse(readFileSync(join(docs, file), 'utf8')),
		};
		pages.set(page.route, page);
	}

	const problems = [];
	/** @type {Map<string, string[]>} Route => the routes it links to. */
	const edges = new Map();
	for (const page of pages.values()) {
		const targets = [];
		for (const { line, target, image } of page.links) {
			if (EXTERNAL.test(target)) {
				continue;
			}
			const at = `${page.file}:${line}`;
			const anchor = decoded(target.replace(/^[^#]*#?/, ''));
			if (anchor === undefined) {
				problems.push(`${at}: malformed link ${target}`);
				continue;
			}
			if (image) {
				// The docs site bundles an image from beside the file, not the URL.
				problems.push(...missingFile(root, dirname(page.file), target, at));
				continue;
			}
			const url = new URL(target, `${SITE}${page.route}`);
			const path = url.pathname.endsWith('/')
				? url.pathname
				: `${url.pathname}/`;
			const linked = pages.get(path);
			if (linked === undefined) {
				problems.push(`${at}: no page at ${target}`);
				continue;
			}
			targets.push(linked.route);
			if (anchor !== '' && !linked.anchors.has(anchor)) {
				problems.push(`${at}: no heading #${anchor} in ${linked.file}`);
			}
		}
		edges.set(page.route, targets);
	}

	for (const page of pages.values()) {
		const section = page.route.split('/')[1];
		const index = pages.get(`/${section}/`);
		if (index === undefined) {
			if (page.route !== '/') {
				problems.push(`${page.file}: its section has no ${INDEX}`);
			}
			continue;
		}
		if (!reachable(index.route, edges).has(page.route)) {
			problems.push(`${page.file}: not reachable from ${index.file}`);
		}
	}

	problems.push(...readmeProblems(root));
	return problems;
}

/**
 * The routes reachable from `start` by following links.
 *
 * @param {string} start
 * @param {Map<string, string[]>} edges
 */
function reachable(start, edges) {
	const seen = new Set([start]);
	const queue = [start];
	while (queue.length > 0) {
		for (const next of edges.get(queue.shift() ?? '') ?? []) {
			if (!seen.has(next)) {
				seen.add(next);
				queue.push(next);
			}
		}
	}
	return seen;
}

/**
 * README.md's relative links, which GitHub resolves as files.
 *
 * @param {string} root
 */
function readmeProblems(root) {
	const readme = join(root, 'README.md');
	if (!existsSync(readme)) {
		return [];
	}
	return parse(readFileSync(readme, 'utf8'))
		.links.filter(({ target }) => !EXTERNAL.test(target))
		.flatMap(({ line, target }) =>
			missingFile(root, '', target, `README.md:${line}`),
		);
}

/**
 * The problem with a link to a file, resolved from `dir` under `root`: none
 * when the file exists or the link is to a heading on the same page.
 *
 * @param {string} root
 * @param {string} dir
 * @param {string} target
 * @param {string} at `file:line` of the link.
 * @returns {string[]}
 */
function missingFile(root, dir, target, at) {
	const path = decoded(target.replace(/[#?].*$/, ''));
	if (path === undefined) {
		return [`${at}: malformed link ${target}`];
	}
	return path === '' || existsSync(join(root, dir, path))
		? []
		: [`${at}: no file at ${target}`];
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	const root = resolve(process.argv[2] ?? checkoutRoot);
	const problems = check(root);
	if (problems.length > 0) {
		console.error(
			`Docs links:\n${problems.map((problem) => `  ${problem}`).join('\n')}`,
		);
		process.exit(1);
	}
	console.log('Docs links resolve; every page is reachable from its index.');
}
