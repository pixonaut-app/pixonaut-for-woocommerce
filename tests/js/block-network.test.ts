import { expect, it } from 'vitest';

it('refuses requests to real hosts', async () => {
	await expect(fetch('https://api.pixonaut.app/v1/connect')).rejects.toThrow(
		'Tests may not reach https://api.pixonaut.app.',
	);
});
