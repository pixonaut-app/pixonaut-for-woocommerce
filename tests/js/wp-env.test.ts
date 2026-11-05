// What `env:stop` and `env:destroy` clean up. Docker and wp-env are stubs
// that log every call, so nothing here touches a real container.
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { type Answer, BIN, runScript, stub, tempDir } from './support/scripts';

/** The checkout the scripts run for: the one this test file is in. */
const CHECKOUT = join(BIN, '..');

/** A wp-env project directory, as wp-env names it in its home. */
const PROJECT = 'wp-env-checkout-0123abcd';
const NETWORK = `${PROJECT}_default`;

/** A fake an earlier run left behind, on a project that is gone. */
const LABELLED_ID = 'f00dcafe';
const OLD_NETWORK = 'wp-env-checkout-99999999_default';

/** Docker's answers when this checkout has the labelled orphan fake. */
const WITH_ORPHAN: Record<string, Answer> = {
	ps: { stdout: `${LABELLED_ID}\n` },
	inspect: { stdout: `${OLD_NETWORK}\nbridge\n` },
};

/** Runs `bin/wp-env.mjs <command>` against a wp-env home holding `projects`. */
function wpEnv(
	command: string,
	{
		projects = [PROJECT],
		docker = {},
		wpEnvExtra = '',
	}: {
		projects?: string[];
		docker?: Record<string, Answer>;
		wpEnvExtra?: string;
	} = {},
) {
	const dir = tempDir('wp-env');
	const home = join(dir, 'home');
	for (const project of projects) {
		mkdirSync(join(home, project), { recursive: true });
	}
	const log = join(dir, 'calls.log');
	const result = runScript('wp-env.mjs', [command], {
		cwd: dir,
		env: {
			WP_ENV_HOME: home,
			ENV_DOCKER: stub(dir, 'docker', log, { answers: docker }),
			ENV_WP_ENV: stub(dir, 'wp-env', log, {
				extra: wpEnvExtra.replaceAll('$HOME_DIR', JSON.stringify(home)),
			}),
		},
	});
	const calls = existsSync(log)
		? readFileSync(log, 'utf8').trim().split('\n')
		: [];
	return { calls, home, result };
}

const FIND_LABELLED = `docker ps --all --quiet --filter label=pixonaut-wc.checkout=${CHECKOUT}`;

describe('env:stop', () => {
	it('removes the fake container, stops wp-env, then removes the network', () => {
		const { calls, result } = wpEnv('stop');

		expect(result.status, result.stderr).toBe(0);
		expect(calls).toEqual([
			FIND_LABELLED,
			`docker rm --force ${PROJECT}-fake-pixonaut`,
			'wp-env stop',
			`docker network rm ${NETWORK}`,
		]);
	});

	it('removes fakes this checkout started under another project, and their networks', () => {
		const { calls, result } = wpEnv('stop', { docker: WITH_ORPHAN });

		expect(result.status, result.stderr).toBe(0);
		expect(calls).toContain(
			`docker rm --force ${PROJECT}-fake-pixonaut ${LABELLED_ID}`,
		);
		expect(calls.slice(-2)).toEqual([
			`docker network rm ${NETWORK}`,
			`docker network rm ${OLD_NETWORK}`,
		]);
	});

	it('removes orphan fakes and their networks when there is no project', () => {
		const { calls, result } = wpEnv('stop', {
			projects: [],
			docker: WITH_ORPHAN,
		});

		expect(result.status, result.stderr).toBe(0);
		expect(calls.slice(-3)).toEqual([
			`docker rm --force ${LABELLED_ID}`,
			'wp-env stop',
			`docker network rm ${OLD_NETWORK}`,
		]);
	});

	it('accepts a network that is already gone', () => {
		const { result } = wpEnv('stop', {
			docker: {
				'network rm': {
					status: 1,
					stderr: `Error response from daemon: network ${NETWORK} not found\n`,
				},
			},
		});

		expect(result.status, result.stderr).toBe(0);
		expect(result.stderr).toBe('');
	});

	it('fails and says why when a network cannot be removed, after trying every one', () => {
		const inUse = `Error response from daemon: error while removing network: network ${NETWORK} has active endpoints`;
		const { calls, result } = wpEnv('stop', {
			docker: {
				...WITH_ORPHAN,
				[`network rm ${NETWORK}`]: { status: 1, stderr: `${inUse}\n` },
			},
		});

		expect(result.status).toBe(1);
		expect(result.stderr).toContain(
			`Could not remove the Docker network ${NETWORK}: ${inUse}`,
		);
		expect(calls).toContain(`docker network rm ${OLD_NETWORK}`);
	});
});

describe('env:destroy', () => {
	it('removes the network of the project wp-env deleted', () => {
		const { calls, home, result } = wpEnv('destroy', {
			wpEnvExtra:
				"require('node:fs').rmSync($HOME_DIR, { recursive: true, force: true });",
		});

		expect(result.status, result.stderr).toBe(0);
		expect(existsSync(home)).toBe(false);
		expect(calls).toEqual([
			FIND_LABELLED,
			`docker rm --force ${PROJECT}-fake-pixonaut`,
			'wp-env destroy',
			`docker network rm ${NETWORK}`,
		]);
	});
});
