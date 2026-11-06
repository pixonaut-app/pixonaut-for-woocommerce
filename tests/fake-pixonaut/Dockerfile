# The fake Pixonaut as a container on wp-env's network. Built from the
# context `pnpm fake:build` assembles in tests/fake-pixonaut/dist/: the bundled
# server, its fixtures and the pinned contract with its overlay.
FROM node:26.11.1-alpine

WORKDIR /fake
COPY . .

ENV PIXONAUT_FAKE_CONTRACTS_DIR=/fake/contracts \
	PIXONAUT_FAKE_OVERLAY_FILE=/fake/contracts/spec-overlay.json \
	PIXONAUT_FAKE_FIXTURES_DIR=/fake/fixtures \
	NODE_ENV=production

USER node
CMD ["node", "server.mjs"]
