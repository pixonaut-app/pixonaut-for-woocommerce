/**
 * The pinned oasdiff binary, and a runner for it on two documents in memory.
 *
 * oasdiff is a Go binary with no npm package, so it is fetched once from its
 * GitHub release and cached outside the checkout. The pin is the version and
 * the sha256 of each platform's tarball, copied from that release's
 * `checksums.txt` (the same pin Pixonaut's own consumer-contract check uses).
 * A tarball whose checksum differs is refused. To move the pin, change all of
 * them together.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const OASDIFF_VERSION = '1.33.0';

/** sha256 of each release tarball, by `<os>/<arch>` as Node names them. */
const TARBALLS = {
	'linux/x64': {
		platform: 'linux_amd64',
		sha256: '43a4e328e2d13ba1552d760aa68d2485c75c5621f309f6ff64ae895188345247',
	},
	'linux/arm64': {
		platform: 'linux_arm64',
		sha256: '4ae3c362d6074d919aada2dea82d0ee84366591600384455d0bdc658ddf8f7ae',
	},
	'darwin/x64': {
		platform: 'darwin_all',
		sha256: '2a479337c15afdcbf0b1e89c0b4d0cf0176472dbc11483358fed1f831d1e46c5',
	},
	'darwin/arm64': {
		platform: 'darwin_all',
		sha256: '2a479337c15afdcbf0b1e89c0b4d0cf0176472dbc11483358fed1f831d1e46c5',
	},
};

/** Room for oasdiff's output on a whole reference: the default buffer of a
 *  megabyte is smaller than `openapi.json`. */
const MAX_OUTPUT_BYTES = 256 * 1024 * 1024;

/** The exit status oasdiff gives when `--fail-on` finds a finding at or
 *  above that level. */
export const OASDIFF_FAILED = 1;

/** The severity-levels file: rules whose findings are downgraded. */
export const LEVELS_FILE = path.join(
	import.meta.dirname,
	'..',
	'..',
	'contracts',
	'pixonaut',
	'oasdiff-levels.txt',
);

function cacheDir() {
	const base = process.env.XDG_CACHE_HOME || path.join(os.homedir(), '.cache');
	return path.join(
		base,
		'pixonaut-for-woocommerce',
		'oasdiff',
		OASDIFF_VERSION,
	);
}

/** @type {string | undefined} */
let binary;

/** The pinned binary's path, downloaded on first use. */
export function oasdiffBinary() {
	if (binary !== undefined) {
		return binary;
	}
	const target = path.join(cacheDir(), 'oasdiff');
	if (!fs.existsSync(target)) {
		download(target);
	}
	binary = target;
	return target;
}

/** @param {string} target */
function download(target) {
	const tarball =
		TARBALLS[
			/** @type {keyof typeof TARBALLS} */ (
				`${process.platform}/${process.arch}`
			)
		];
	if (tarball === undefined) {
		throw new Error(
			`oasdiff: no pinned build for ${process.platform}/${process.arch}`,
		);
	}
	const name = `oasdiff_${OASDIFF_VERSION}_${tarball.platform}.tar.gz`;
	const url = `https://github.com/oasdiff/oasdiff/releases/download/v${OASDIFF_VERSION}/${name}`;
	const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'oasdiff-'));
	try {
		const archive = path.join(scratch, name);
		execFileSync(
			'curl',
			[
				'--fail',
				'--silent',
				'--show-error',
				'--location',
				'--output',
				archive,
				url,
			],
			{ stdio: ['ignore', 'ignore', 'inherit'] },
		);
		const actual = createHash('sha256')
			.update(fs.readFileSync(archive))
			.digest('hex');
		if (actual !== tarball.sha256) {
			throw new Error(
				`oasdiff: ${name} has sha256 ${actual}, the pin says ${tarball.sha256}`,
			);
		}
		execFileSync('tar', ['-xzf', archive, '-C', scratch, 'oasdiff']);
		fs.mkdirSync(path.dirname(target), { recursive: true });
		// Moved into place last, so an interrupted download never leaves a
		// binary the cache check would trust.
		const partial = `${target}.partial.${process.pid}`;
		fs.copyFileSync(path.join(scratch, 'oasdiff'), partial);
		fs.chmodSync(partial, 0o755);
		fs.renameSync(partial, target);
	} finally {
		fs.rmSync(scratch, { recursive: true, force: true });
	}
}

/**
 * `oasdiff <command> <before> <after> [...args]` on two documents held in
 * memory, with the severity-levels file applied.
 *
 * @param {'breaking' | 'changelog'} command
 * @param {object} before
 * @param {object} after
 * @param {string[]} [args]
 * @returns {{ status: number, output: string }}
 */
export function runOasdiff(command, before, after, args = []) {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'oasdiff-'));
	try {
		// Named for what they hold: oasdiff prints the file each finding is in.
		const from = path.join(dir, 'pinned.json');
		const to = path.join(dir, 'candidate.json');
		fs.writeFileSync(from, JSON.stringify(before));
		fs.writeFileSync(to, JSON.stringify(after));
		try {
			const output = execFileSync(
				oasdiffBinary(),
				[command, from, to, '--severity-levels', LEVELS_FILE, ...args],
				{
					encoding: 'utf8',
					maxBuffer: MAX_OUTPUT_BYTES,
					stdio: ['ignore', 'pipe', 'pipe'],
				},
			);
			return { status: 0, output };
		} catch (error) {
			const failed =
				/** @type {{ status?: number, stdout?: string, stderr?: string }} */ (
					error
				);
			if (failed.status !== OASDIFF_FAILED) {
				throw error;
			}
			return { status: failed.status, output: failed.stdout ?? '' };
		}
	} finally {
		fs.rmSync(dir, { recursive: true, force: true });
	}
}
