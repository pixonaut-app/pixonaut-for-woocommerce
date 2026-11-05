// The pinned Pixonaut reference and the plugin's consumed list, indexed by
// operation, so every other module asks one place what an operation is.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { readOverlay, withOverlay } from '../../../bin/contracts/overlay.mjs';

/** `contracts/pixonaut/`, from the source tree or from the bundle's image. */
export const CONTRACTS_DIR =
	process.env.PIXONAUT_FAKE_CONTRACTS_DIR ??
	join(import.meta.dirname, '..', '..', '..', 'contracts', 'pixonaut');

/** What Pixonaut agreed to add and the pin lacks (`bin/contracts/overlay.mjs`). */
export const OVERLAY_FILE =
	process.env.PIXONAUT_FAKE_OVERLAY_FILE ??
	join(import.meta.dirname, '..', 'spec-overlay.json');

const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'patch'] as const;

export type HttpMethod = (typeof HTTP_METHODS)[number];

/** A security scheme name, as `components.securitySchemes` names it. */
export type KeyKind =
	| 'integrationKey'
	| 'publishableKey'
	| 'secretKey'
	| 'shareLink'
	| 'memberKey';

export type JsonSchema = Record<string, unknown>;

export type Parameter = {
	name: string;
	in: 'path' | 'query' | 'header' | 'cookie';
	required?: boolean;
	schema?: JsonSchema;
};

export type ResponseObject = {
	content?: Record<string, { schema?: JsonSchema }>;
	headers?: Record<string, unknown>;
};

export type OperationObject = {
	parameters?: Parameter[];
	requestBody?: {
		required?: boolean;
		content: Record<string, { schema?: JsonSchema }>;
	};
	responses: Record<string, ResponseObject>;
	security?: Record<string, string[]>[];
};

export type Spec = {
	paths: Record<string, Partial<Record<HttpMethod, OperationObject>>>;
	webhooks: Record<string, unknown>;
	components: Record<string, unknown>;
};

/** `METHOD /path` with the path as the reference spells it. */
export type OperationKey = `${Uppercase<HttpMethod>} /${string}`;

export type Operation = {
	key: OperationKey;
	method: HttpMethod;
	path: string;
	/** JSON pointer to the operation inside the reference. */
	pointer: string;
	definition: OperationObject;
	/** Key kinds the operation takes; empty when it takes none. */
	accepts: KeyKind[];
	/** Whether it is served without a credential too: its `security` lists
	 *  an empty requirement beside the keys it takes. */
	keyOptional: boolean;
	matcher: RegExp;
	paramNames: string[];
};

export type Consumed = {
	operations: OperationKey[];
	webhooks: string[];
	keys: Partial<Record<OperationKey, KeyKind | KeyKind[]>>;
	/** Planned, not called yet; the fake serves none of them. */
	pending: { op: string; requirement: string }[];
};

function readJson<T>(file: string): T {
	return JSON.parse(readFileSync(join(CONTRACTS_DIR, file), 'utf8')) as T;
}

/** RFC 6901: `~` and `/` inside one reference token. */
export function pointerToken(segment: string): string {
	return segment.replaceAll('~', '~0').replaceAll('/', '~1');
}

export function jsonPointer(...segments: string[]): string {
	return `#/${segments.map(pointerToken).join('/')}`;
}

function operationOf(
	path: string,
	method: HttpMethod,
	definition: OperationObject,
): Operation {
	const paramNames: string[] = [];
	const pattern = path
		.split(/(\{[^}]+\})/)
		.map((part) => {
			const name = /^\{([^}]+)\}$/.exec(part)?.[1];
			if (name === undefined) {
				return part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			}
			paramNames.push(name);
			return '([^/]+)';
		})
		.join('');
	return {
		key: `${method.toUpperCase() as Uppercase<HttpMethod>} ${path as `/${string}`}`,
		method,
		path,
		pointer: jsonPointer('paths', path, method),
		definition,
		accepts: (definition.security ?? []).flatMap(
			(requirement) => Object.keys(requirement) as KeyKind[],
		),
		keyOptional: (definition.security ?? []).some(
			(requirement) => Object.keys(requirement).length === 0,
		),
		matcher: new RegExp(`^${pattern}$`),
		paramNames,
	};
}

export type Contract = {
	spec: Spec;
	consumed: Consumed;
	operations: Map<OperationKey, Operation>;
	/** The operation a request names, with its decoded path parameters. */
	match(
		method: string,
		pathname: string,
	): { operation: Operation; params: Record<string, string> } | null;
};

export function loadContract(): Contract {
	const spec = withOverlay(
		readJson<Spec>('openapi.json'),
		readOverlay(OVERLAY_FILE),
	);
	const consumed = readJson<Consumed>('consumed.json');
	const operations = new Map<OperationKey, Operation>();
	for (const [path, item] of Object.entries(spec.paths)) {
		for (const method of HTTP_METHODS) {
			const definition = item[method];
			if (definition !== undefined) {
				const operation = operationOf(path, method, definition);
				operations.set(operation.key, operation);
			}
		}
	}
	// Literal paths before templated ones, so `/v1/assets/bulk` is never read
	// as `/v1/assets/{id}`.
	const ordered = [...operations.values()].sort(
		(a, b) => a.paramNames.length - b.paramNames.length,
	);
	return {
		spec,
		consumed,
		operations,
		match(method, pathname) {
			const lower = method.toLowerCase();
			for (const operation of ordered) {
				if (operation.method !== lower) {
					continue;
				}
				const found = operation.matcher.exec(pathname);
				if (found) {
					const params = Object.fromEntries(
						operation.paramNames.map((name, i) => [
							name,
							decodeURIComponent(found[i + 1] ?? ''),
						]),
					);
					return { operation, params };
				}
			}
			return null;
		},
	};
}
