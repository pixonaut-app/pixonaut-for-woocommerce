// Builds the release's readme.txt from readme.src.txt, the plugin header and
// CHANGELOG.md: the header minimums and Stable tag come from the plugin
// header, the Changelog and Upgrade Notice sections from the changelog
// release-please writes.
import { header as pluginHeader, README_HEADERS } from '../plugin-header.mjs';

/** wordpress.org shows at most this many characters of an upgrade notice. */
const UPGRADE_NOTICE_MAX_LENGTH = 300;

const NO_BREAKING_CHANGES = 'No breaking changes: safe to update.';

/** release-please's heading for breaking changes, after its warning sign. */
const BREAKING_HEADING = /BREAKING CHANGES$/;

/**
 * @typedef {{ title: string, items: string[] }} Section
 * @typedef {{ version: string, date: string | undefined, sections: Section[] }} Release
 */

/**
 * One release-please bullet as readme text: links keep their text, commit
 * hash references go, `**scope:**` loses its emphasis.
 *
 * @param {string} text
 */
function bullet(text) {
	return text
		.replace(/\s*\(\[[0-9a-f]{7,40}\]\([^)]*\)\)/g, '')
		.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
		.replace(/\*\*([^*]+)\*\*/g, '$1')
		.trim();
}

/**
 * The releases in a release-please CHANGELOG.md, newest first.
 *
 * @param {string} changelog
 * @returns {Release[]}
 */
export function parseChangelog(changelog) {
	/** @type {Release[]} */
	const releases = [];
	for (const line of changelog.split('\n')) {
		const release = line.match(
			/^## \[?(\d+\.\d+\.\d+[^\]\s]*)\]?(?:\([^)]*\))?(?:\s+\((\d{4}-\d{2}-\d{2})\))?/,
		);
		const section = line.match(/^### (.+)$/);
		const item = line.match(/^[*-] (.+)$/);
		const current = releases.at(-1);
		if (release !== null) {
			releases.push({ version: release[1], date: release[2], sections: [] });
		} else if (section !== null && current !== undefined) {
			current.sections.push({ title: section[1].trim(), items: [] });
		} else if (item !== null) {
			current?.sections.at(-1)?.items.push(bullet(item[1]));
		} else if (/^\s+\S/.test(line)) {
			// A bullet's continuation line.
			const items = current?.sections.at(-1)?.items;
			if (items !== undefined && items.length > 0) {
				items[items.length - 1] += ` ${bullet(line)}`;
			}
		}
	}
	return releases;
}

/** @param {Release[]} releases */
function changelogSection(releases) {
	const lines = ['== Changelog =='];
	for (const { version, date, sections } of releases) {
		lines.push(
			'',
			date === undefined ? `= ${version} =` : `= ${version} - ${date} =`,
		);
		for (const { title, items } of sections) {
			lines.push('', `**${title.replace(/^\W+/u, '')}**`, '');
			lines.push(...items.map((text) => `* ${text}`));
		}
	}
	return lines.join('\n');
}

/** @param {string} text */
function upgradeNotice(text) {
	return text.length > UPGRADE_NOTICE_MAX_LENGTH
		? `${text.slice(0, UPGRADE_NOTICE_MAX_LENGTH - 1).trimEnd()}…`
		: text;
}

/**
 * An upgrade notice for every release with breaking changes, and one for
 * the version being released whatever it holds.
 *
 * @param {Release[]} releases
 * @param {string} version
 */
function upgradeNoticeSection(releases, version) {
	/** @type {[string, string][]} */
	const notices = releases.flatMap(({ version: release, sections }) => {
		const breaking = sections.find(({ title }) => BREAKING_HEADING.test(title));
		if (breaking !== undefined) {
			return [
				/** @type {[string, string]} */ ([
					release,
					upgradeNotice(breaking.items.join(' ')),
				]),
			];
		}
		return release === version
			? [/** @type {[string, string]} */ ([release, NO_BREAKING_CHANGES])]
			: [];
	});
	if (!releases.some(({ version: release }) => release === version)) {
		notices.unshift([version, NO_BREAKING_CHANGES]);
	}
	return [
		'== Upgrade Notice ==',
		...notices.flatMap(([release, text]) => ['', `= ${release} =`, '', text]),
	].join('\n');
}

/**
 * readme.txt for the release.
 *
 * @param {{ source: string, plugin: string, changelog: string }} input
 *   readme.src.txt, the main plugin file and CHANGELOG.md.
 * @returns {string}
 */
export function buildReadme({ source, plugin, changelog }) {
	const [title, ...rest] = source.trimEnd().split('\n');
	if (!/^=== .+ ===$/.test(title)) {
		throw new Error('readme.src.txt must start with its === title ===.');
	}
	if (/^== (Changelog|Upgrade Notice) ==$/m.test(source)) {
		throw new Error(
			'readme.src.txt must not hold the Changelog or Upgrade Notice: they are generated.',
		);
	}
	const version = pluginHeader(plugin, 'Version');
	if (version === undefined) {
		throw new Error('The plugin header has no Version.');
	}
	const header = README_HEADERS.map((name) => {
		const value = pluginHeader(plugin, name);
		if (value === undefined) {
			throw new Error(`The plugin header has no ${name}.`);
		}
		return `${name}: ${value}`;
	});
	const releases = parseChangelog(changelog);
	return [
		[title, ...header, `Stable tag: ${version}`, ...rest].join('\n'),
		changelogSection(releases),
		upgradeNoticeSection(releases, version),
	]
		.join('\n\n')
		.concat('\n');
}
