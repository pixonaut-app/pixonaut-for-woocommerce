#!/usr/bin/env node
// Evidence that a tree passed the full `ci:local` gate.
//
//   ci-local-attest.mjs record <tree>
//                                end of a full gate that started on a clean
//                                <tree>: remember it passed, and post the
//                                status if HEAD is on GitHub
//   ci-local-attest.mjs post     after a later push: post the status for HEAD,
//                                if its tree has a recorded pass
//
// The evidence is a commit status, context `ci:local`, on the gated commit,
// whose description names the TREE hash. Pull requests run no CI, so this
// status is the record on a PR that its branch was gated. The tree rather than
// the commit, because a squash merge makes a new commit on main: what survives
// is the content, so a branch rebased on origin/main before its gate and
// merged straight after lands exactly the tree the status names.
//
// The failure to guard against is a false attestation, a status claiming a
// pass for a tree that never had one. So `record` records nothing when the
// working tree is dirty or HEAD's tree is not the one the gate started on,
// and `post` refuses a dirty working tree or a tree with no recorded pass.
//
// Works on the repository in the current directory. CI_LOCAL_GH replaces `gh`
// (the tests use a stub); GITHUB_REPOSITORY names the repository without
// asking `gh`.
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';

const GH = process.env.CI_LOCAL_GH ?? 'gh';
const CONTEXT = 'ci:local';
const DESCRIPTION_PREFIX = 'full gate passed on tree';
/** Hash length shown in messages. */
const SHORT_HASH = 9;
/**
 * How `gh api` reports a commit GitHub does not have: a 404, or a 422
 * ("No commit found for SHA") for a full SHA that was never pushed.
 */
const NOT_FOUND = /HTTP 404|HTTP 422/;

function exec(command, args) {
	const result = spawnSync(command, args, { encoding: 'utf8' });
	if (result.error) {
		throw result.error;
	}
	return result;
}

function git(...args) {
	const result = exec('git', args);
	if (result.status !== 0) {
		throw new Error(`git ${args.join(' ')} failed: ${result.stderr}`);
	}
	return result.stdout.trim();
}

function repo() {
	if (process.env.GITHUB_REPOSITORY) {
		return process.env.GITHUB_REPOSITORY;
	}
	const result = exec(GH, [
		'repo',
		'view',
		'--json',
		'nameWithOwner',
		'-q',
		'.nameWithOwner',
	]);
	if (result.status !== 0) {
		throw new Error(`gh repo view failed: ${result.stderr}`);
	}
	return result.stdout.trim();
}

/**
 * Recorded passes live in the common git dir, so a gate run in any worktree
 * of this clone counts for the others: the tree is the same object everywhere.
 */
function marker(tree) {
	return join(
		resolve(git('rev-parse', '--git-common-dir')),
		'ci-local',
		`pass-${tree}`,
	);
}

function dirty() {
	return git('status', '--porcelain') !== '';
}

function postForHead() {
	const sha = git('rev-parse', 'HEAD');
	const tree = git('rev-parse', 'HEAD^{tree}');
	const slug = repo();
	const lookup = exec(GH, ['api', `repos/${slug}/commits/${sha}`, '--silent']);
	if (lookup.status !== 0) {
		if (!NOT_FOUND.test(lookup.stderr)) {
			throw new Error(`looking up HEAD on GitHub failed: ${lookup.stderr}`);
		}
		console.log(
			`HEAD ${sha.slice(0, SHORT_HASH)} is not on GitHub yet: push it, then run pnpm ci:local:attest.`,
		);
		return;
	}
	const posted = exec(GH, [
		'api',
		'-X',
		'POST',
		`repos/${slug}/statuses/${sha}`,
		'-f',
		'state=success',
		'-f',
		`context=${CONTEXT}`,
		'-f',
		`description=${DESCRIPTION_PREFIX} ${tree}`,
	]);
	if (posted.status !== 0) {
		throw new Error(`posting the ${CONTEXT} status failed: ${posted.stderr}`);
	}
	console.log(
		`Posted ${CONTEXT} success on ${sha.slice(0, SHORT_HASH)} (tree ${tree.slice(0, SHORT_HASH)}).`,
	);
}

switch (process.argv[2]) {
	case 'record': {
		// A gate run on a dirty tree tested files HEAD does not hold, and one
		// whose HEAD moved while it ran tested an earlier tree for some steps,
		// so neither pass says anything about HEAD's tree.
		const tree = git('rev-parse', 'HEAD^{tree}');
		if (dirty()) {
			console.log(
				'Not attested: the working tree has uncommitted changes, so this pass was not a pass of HEAD. Commit and run the gate again to attest.',
			);
			break;
		}
		if (tree !== process.argv[3]) {
			console.log(
				`Not attested: HEAD's tree ${tree.slice(0, SHORT_HASH)} is not the tree the gate started on. Run the gate again to attest.`,
			);
			break;
		}
		const file = marker(tree);
		mkdirSync(dirname(file), { recursive: true });
		writeFileSync(file, '');
		postForHead();
		break;
	}
	case 'post': {
		if (dirty()) {
			console.error(
				'The working tree has uncommitted changes: commit or discard them before attesting HEAD.',
			);
			process.exit(1);
		}
		const tree = git('rev-parse', 'HEAD^{tree}');
		if (!existsSync(marker(tree))) {
			console.error(
				`No full ci:local pass recorded for HEAD's tree ${tree.slice(0, SHORT_HASH)}: run pnpm ci:local first.`,
			);
			process.exit(1);
		}
		postForHead();
		break;
	}
	default:
		console.error('usage: ci-local-attest.mjs record <tree> | post');
		process.exit(2);
}
