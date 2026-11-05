import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		include: ['assets/src/**/*.test.ts', 'tests/js/**/*.test.ts'],
		setupFiles: ['tests/js/setup/block-network.ts'],
	},
});
