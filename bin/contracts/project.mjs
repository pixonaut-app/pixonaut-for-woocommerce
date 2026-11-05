/**
 * A spec cut down to what the plugin consumes, for oasdiff.
 *
 * oasdiff scopes by path regex only, and judges a webhook from the sender's
 * side, as a request body: a removed payload property is only a warning, and
 * an optional-ised one or a new enum value is not reported. The plugin
 * receives webhooks, so each consumed webhook `W` is rewritten as
 * `GET /__webhooks/W` answering its payload, which oasdiff judges by its
 * response rules. Pixonaut's consumer-contract check projects the same way.
 */
import { parseOperation } from './consumed.mjs';

/** The path a consumed webhook's payload is diffed under. */
export const WEBHOOK_PATH_PREFIX = '/__webhooks/';

/**
 * @param {string} type
 * @param {any} hook the webhook's `post`
 */
function webhookAsResponse(type, hook) {
	return {
		get: {
			operationId: `webhook ${type}`,
			responses: {
				200: {
					description: `The ${type} payload`,
					content: hook.requestBody?.content ?? {},
				},
			},
		},
	};
}

/**
 * `spec` with only the consumed operations (with their path's shared
 * parameters and the security requirements they inherit), each consumed webhook as a `GET` answering its payload, and
 * every component, which a reference may reach. A consumed name the spec
 * lacks is left out, so oasdiff reports it as removed.
 *
 * @param {import('./consumed.mjs').Spec} spec
 * @param {Pick<import('./consumed.mjs').Consumed, 'operations' | 'webhooks'>} consumed
 */
export function project(spec, consumed) {
	/** @type {Record<string, Record<string, unknown>>} */
	const paths = {};
	for (const written of consumed.operations) {
		const { method, path } = parseOperation(written);
		const item = spec.paths?.[path];
		if (item?.[method] === undefined) {
			continue;
		}
		paths[path] ??=
			item.parameters === undefined ? {} : { parameters: item.parameters };
		// The document's own `security` is not carried over (the webhook
		// paths take none), so each operation states the requirements it
		// inherits.
		const security = item[method].security ?? spec.security;
		paths[path][method] =
			security === undefined ? item[method] : { ...item[method], security };
	}
	for (const type of consumed.webhooks) {
		const hook = spec.webhooks?.[type]?.post;
		if (hook !== undefined) {
			paths[`${WEBHOOK_PATH_PREFIX}${type}`] = webhookAsResponse(type, hook);
		}
	}
	return {
		openapi: spec.openapi,
		info: spec.info,
		paths,
		components: spec.components ?? {},
	};
}
