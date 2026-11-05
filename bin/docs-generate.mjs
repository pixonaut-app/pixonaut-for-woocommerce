#!/usr/bin/env node
// Regenerates the generated sections of docs/developer/ (bin/docs/references.mjs)
// from the hook docblocks in src/ and from what the plugin registers in
// wp-env.
//
// `--check` writes nothing and exits 1 when a page is stale.
import { undocumentedHooks, updatePages } from './docs/references.mjs';
import { pluginDir, root, wpEnvOutput } from './env.mjs';

const SCRIPT = 'bin/docs/reference-data.php';
const CONTAINER_SITE_DATA = '/tmp/pixonaut-for-woocommerce-reference.json';

// Hooks come from plain PHP: WordPress may have loaded another plugin's copy
// of the PHP parser. The site data goes through a file because WordPress can
// print notices to stdout.
const result = wpEnvOutput([
	'run',
	'tests-cli',
	`--env-cwd=${pluginDir}`,
	'sh',
	'-c',
	[
		`wp eval-file ${SCRIPT} site ${CONTAINER_SITE_DATA} >&2`,
		`printf '{"source":'`,
		`php ${SCRIPT} hooks`,
		`printf ',"site":'`,
		`cat ${CONTAINER_SITE_DATA}`,
		`printf '}'`,
	].join(' && '),
]);
if (result.status !== 0) {
	console.error('Collecting the reference data in wp-env failed.');
	process.exit(result.status || 1);
}

const { source, site } = JSON.parse(result.stdout);
const data = { ...source, ...site };

const undocumented = undocumentedHooks(data);
if (undocumented.length > 0) {
	console.error(
		`These hooks need a docblock with a summary and @since:\n  ${undocumented.join('\n  ')}`,
	);
	process.exit(1);
}

const check = process.argv.includes('--check');
const stale = updatePages(root, data, { check });
if (check && stale.length > 0) {
	console.error(
		`Out of date: ${stale.join(', ')}. Run pnpm docs:generate and commit the result.`,
	);
	process.exit(1);
}
console.log(
	check
		? 'Generated references are up to date.'
		: stale.length > 0
			? `Updated ${stale.join(', ')}.`
			: 'Generated references were already up to date.',
);
