// Serves the fake on FAKE_PIXONAUT_PORT, on every interface, so WordPress's
// container reaches it by name and the browser by the published port.
import { serve } from '@hono/node-server';
import { createFake } from './app';

const port = Number(process.env.FAKE_PIXONAUT_PORT);
if (!Number.isInteger(port) || port <= 0) {
	console.error('Set FAKE_PIXONAUT_PORT to the port the fake listens on.');
	process.exit(1);
}

// Keyless image links reach the browser, which knows the fake by its
// published port, not by the name WordPress asks it at.
const fake = createFake({
	publicOrigin: process.env.FAKE_PIXONAUT_PUBLIC_URL || undefined,
});
const server = serve(
	{ fetch: fake.app.fetch, port, hostname: '0.0.0.0' },
	() => {
		console.log(`fake-pixonaut listening on ${port}`);
	},
);

for (const signal of ['SIGINT', 'SIGTERM'] as const) {
	process.on(signal, () => {
		server.close(() => process.exit(0));
	});
}
