#!/usr/bin/env node
// Composer in a throwaway container, so the host needs no PHP or Composer.
import { composerInDocker, root, run } from './env.mjs';

run('docker', [...composerInDocker(root), ...process.argv.slice(2)]);
