// Shared by the wrappers in bin/: one wp-env home per checkout, so several
// checkouts can run side by side. Ports come from WP_ENV_PORT and
// WP_ENV_TESTS_PORT, which wp-env reads itself.
import { spawnSync } from 'node:child_process';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = dirname(dirname(fileURLToPath(import.meta.url)));

/** The plugin's slug: its directory, main file and text domain. */
export const SLUG = 'pixonaut-for-woocommerce';

/** wp-env mounts the checkout under its directory name. */
export const pluginDir = `wp-content/plugins/${basename(root)}`;

/** Generous upper bound for output captured from a wp-env command. */
const MAX_OUTPUT_BYTES = 16 * 1024 * 1024;

export function wpEnvHome() {
	return process.env.WP_ENV_HOME ?? './.wp-env-home';
}

function env(extraEnv = {}) {
	return {
		...process.env,
		WP_ENV_HOME: wpEnvHome(),
		...extraEnv,
	};
}

/**
 * Runs `command` with this checkout's environment; returns its exit status.
 *
 * @param {string} command
 * @param {string[]} args
 * @param {Record<string, string>} [extraEnv]
 */
export function spawn(command, args, extraEnv = {}) {
	const result = spawnSync(command, args, {
		cwd: root,
		stdio: 'inherit',
		env: env(extraEnv),
	});
	if (result.error) {
		throw result.error;
	}
	return result.status ?? 1;
}

/**
 * @param {string} command
 * @param {string[]} args
 * @param {Record<string, string>} [extraEnv]
 */
export function run(command, args, extraEnv = {}) {
	process.exit(spawn(command, args, extraEnv));
}

/** wp-env, or the stub the script tests put in `ENV_WP_ENV`. */
export const wpEnvBin =
	process.env.ENV_WP_ENV ?? join(root, 'node_modules', '.bin', 'wp-env');

/** Docker, or the stub the script tests put in `ENV_DOCKER`. */
export const dockerBin = process.env.ENV_DOCKER ?? 'docker';

/**
 * Runs wp-env and returns its result with stdout captured; stderr (wp-env's
 * own progress lines) still goes to the terminal.
 *
 * @param {string[]} args
 */
export function wpEnvOutput(args) {
	const result = spawnSync(wpEnvBin, args, {
		cwd: root,
		encoding: 'utf8',
		maxBuffer: MAX_OUTPUT_BYTES,
		stdio: ['ignore', 'pipe', 'inherit'],
		env: env(),
	});
	if (result.error) {
		throw result.error;
	}
	return result;
}

/**
 * Whether Docker runs rootless, where the container's root is the host user.
 * Rootful Docker (a CI runner) writes as the real root instead.
 */
function dockerIsRootless() {
	const result = spawnSync(
		'docker',
		['info', '--format', '{{json .SecurityOptions}}'],
		{ encoding: 'utf8' },
	);
	return result.status === 0 && result.stdout.includes('name=rootless');
}

/** The image the Composer container runs; it is Alpine Linux, with PHP. */
export const COMPOSER_IMAGE = 'composer:2';

/**
 * The `uid:gid` a container must write as for the host user to own its files:
 * on rootful Docker the host user's, and undefined on rootless Docker, which
 * maps the container's root to the host user already.
 */
export function hostUser() {
	return dockerIsRootless() || process.getuid === undefined
		? undefined
		: `${process.getuid()}:${process.getgid?.()}`;
}

/**
 * `docker run` arguments for Composer in a throwaway container working in
 * `dir`, so the host needs no PHP or Composer. Append Composer's own
 * arguments. On rootful Docker the container runs as the host user, so the
 * files it writes stay the host's to change and remove; rootless Docker maps
 * its root to the host user already, and a `--user` there would not.
 *
 * @param {string} dir
 * @param {Record<string, string>} [readOnly] Host directories to mount read-only, by container path.
 */
export function composerInDocker(dir, readOnly = {}) {
	const user = hostUser();
	return [
		'run',
		'--rm',
		...(user === undefined ? [] : ['--user', user]),
		'-v',
		`${dir}:/app`,
		...Object.entries(readOnly).flatMap(([target, source]) => [
			'-v',
			`${source}:${target}:ro`,
		]),
		'-w',
		'/app',
		'-e',
		'COMPOSER_HOME=/tmp/composer',
		COMPOSER_IMAGE,
	];
}
