// Every request the fake takes and every response it gives, checked against
// the pinned reference with Ajv's JSON Schema 2020-12 (the dialect OpenAPI 3.1
// uses). A mismatch is a violation: the caller or the fake broke the contract.
import Ajv2020, {
	type ErrorObject,
	type ValidateFunction,
} from 'ajv/dist/2020';
import addFormats from 'ajv-formats';
import type { Contract, JsonSchema, Operation, Parameter } from './spec';
import { pointerToken } from './spec';

/** The reference registered under this id, so `$ref`s resolve inside it. */
const SPEC_ID = 'pixonaut-openapi.json';

const JSON_MEDIA_TYPE = 'application/json';

export type Violation = {
	operation: string;
	direction: 'request' | 'response';
	/** Where in the exchange: `body`, `query.page`, `status`, ... */
	at: string;
	message: string;
	errors?: ErrorObject[];
};

export type RequestParts = {
	params: Record<string, string>;
	query: URLSearchParams;
	headers: Headers;
	contentType: string | null;
	/** Parsed JSON, or the raw text when it does not parse. */
	body: unknown;
	hasBody: boolean;
};

export type ResponseParts = {
	status: number;
	contentType: string | null;
	body: unknown;
};

function createAjv(spec: Contract['spec'], coerceTypes: boolean): Ajv2020 {
	const ajv = new Ajv2020({
		strict: true,
		strictTuples: true,
		allErrors: true,
		coerceTypes,
		// OpenAPI's `discriminator` is a hint over a `oneOf` that already
		// decides; it is not checked on its own.
		discriminator: false,
	});
	addFormats(ajv);
	// Formats the reference names for binary bodies; nothing in JSON to check.
	ajv.addFormat('pdf', true);
	// Open enums: the reference lists the codes known today and promises more
	// (`x-extensible-enum`), so an unknown value is not a violation.
	ajv.addKeyword('x-extensible-enum');
	ajv.addKeyword('x-input-only');
	ajv.addKeyword('discriminator');
	// The whole reference as one schema resource, so `#/components/...` refs
	// resolve; each check below compiles only the part it points into. Its
	// top-level OpenAPI members are declared as keywords that check nothing,
	// so strict mode reads the resource root without refusing them.
	for (const member of Object.keys(spec)) {
		ajv.addKeyword(member);
	}
	ajv.addSchema({ $id: SPEC_ID, ...spec }, undefined, undefined, false);
	return ajv;
}

function jsonMediaType(contentType: string | null): boolean {
	const type = contentType?.split(';')[0]?.trim().toLowerCase();
	return type === JSON_MEDIA_TYPE || (type?.endsWith('+json') ?? false);
}

/** A text body (`text/plain` and the like) is checked as one string against
 *  its schema; a binary one (a font, an image) is not checked at all. */
function textMediaType(contentType: string): boolean {
	return contentType.toLowerCase().startsWith('text/');
}

/** A body whose schema the fake checks: JSON, or text read as a string. */
function checkedMediaType(contentType: string): boolean {
	return jsonMediaType(contentType) || textMediaType(contentType);
}

function parameterRef(operation: Operation, index: number): string {
	return `${operation.pointer}/parameters/${index}/schema`;
}

function requestBodyRef(operation: Operation, mediaType: string): string {
	return `${operation.pointer}/requestBody/content/${pointerToken(mediaType)}/schema`;
}

function responseRef(
	operation: Operation,
	status: string,
	mediaType: string,
): string {
	return `${operation.pointer}/responses/${status}/content/${pointerToken(mediaType)}/schema`;
}

/** The body schema of the webhook `type`, as the reference's `webhooks`
 *  declares Pixonaut posting it. */
function webhookBodyRef(type: string): string {
	return `#/webhooks/${pointerToken(type)}/post/requestBody/content/${pointerToken(JSON_MEDIA_TYPE)}/schema`;
}

function arrayParameter(schema: JsonSchema | undefined): boolean {
	return schema?.type === 'array';
}

export type Validator = {
	request(operation: Operation, parts: RequestParts): Violation[];
	response(operation: Operation, parts: ResponseParts): Violation[];
	/** A webhook body the fake is about to deliver. */
	webhook(type: string, body: unknown): Violation[];
	/** Compile every check of these operations and webhooks now, so a schema
	 *  the fake cannot read fails at start-up rather than mid-test. */
	warm(operations: Operation[], webhooks?: readonly string[]): void;
};

export function createValidator(contract: Contract): Validator {
	const bodies = createAjv(contract.spec, false);
	// Path, query and header values arrive as strings.
	const parameters = createAjv(contract.spec, true);
	const cache = new Map<string, ValidateFunction>();

	function compiled(ajv: Ajv2020, ref: string): ValidateFunction {
		const id = `${ajv === bodies ? 'b' : 'p'}${ref}`;
		let validate = cache.get(id);
		if (validate === undefined) {
			validate = ajv.compile({ $ref: `${SPEC_ID}${ref}` });
			cache.set(id, validate);
		}
		return validate;
	}

	function check(
		ajv: Ajv2020,
		ref: string,
		value: unknown,
		violation: Omit<Violation, 'message' | 'errors'>,
	): Violation[] {
		const validate = compiled(ajv, ref);
		if (validate(value)) {
			return [];
		}
		return [
			{
				...violation,
				message: ajv.errorsText(validate.errors, { dataVar: violation.at }),
				errors: validate.errors ?? [],
			},
		];
	}

	function parameterValue(
		parameter: Parameter,
		parts: RequestParts,
	): string | string[] | undefined {
		switch (parameter.in) {
			case 'path':
				return parts.params[parameter.name];
			case 'query': {
				const values = parts.query.getAll(parameter.name);
				if (values.length === 0) {
					return undefined;
				}
				return arrayParameter(parameter.schema) ? values : values[0];
			}
			case 'header':
				return parts.headers.get(parameter.name) ?? undefined;
			default:
				return undefined;
		}
	}

	function requestViolations(
		operation: Operation,
		parts: RequestParts,
	): Violation[] {
		const found: Violation[] = [];
		const base = { operation: operation.key, direction: 'request' as const };
		const declared = operation.definition.parameters ?? [];
		declared.forEach((parameter, index) => {
			const at = `${parameter.in}.${parameter.name}`;
			const value = parameterValue(parameter, parts);
			if (value === undefined) {
				if (parameter.required) {
					found.push({ ...base, at, message: `${at} is required` });
				}
				return;
			}
			if (parameter.schema !== undefined) {
				found.push(
					...check(parameters, parameterRef(operation, index), value, {
						...base,
						at,
					}),
				);
			}
		});
		// A query parameter the reference does not declare is a client bug.
		const known = new Set(
			declared.filter((p) => p.in === 'query').map((p) => p.name),
		);
		for (const name of new Set(parts.query.keys())) {
			if (!known.has(name)) {
				found.push({
					...base,
					at: `query.${name}`,
					message: `query.${name} is not a parameter of this operation`,
				});
			}
		}

		const body = operation.definition.requestBody;
		if (body === undefined) {
			if (parts.hasBody) {
				found.push({
					...base,
					at: 'body',
					message: 'the operation takes no request body',
				});
			}
			return found;
		}
		if (!parts.hasBody) {
			if (body.required) {
				found.push({ ...base, at: 'body', message: 'body is required' });
			}
			return found;
		}
		const mediaType = Object.keys(body.content).find(
			(type) => type === parts.contentType?.split(';')[0]?.trim().toLowerCase(),
		);
		if (mediaType === undefined) {
			found.push({
				...base,
				at: 'header.content-type',
				message: `content-type ${parts.contentType ?? '(none)'} is not one of ${Object.keys(body.content).join(', ')}`,
			});
			return found;
		}
		if (jsonMediaType(mediaType) && body.content[mediaType]?.schema) {
			found.push(
				...check(bodies, requestBodyRef(operation, mediaType), parts.body, {
					...base,
					at: 'body',
				}),
			);
		}
		return found;
	}

	function responseViolations(
		operation: Operation,
		parts: ResponseParts,
	): Violation[] {
		const base = { operation: operation.key, direction: 'response' as const };
		const status = String(parts.status);
		const declared = operation.definition.responses;
		const key = status in declared ? status : 'default';
		const response = declared[key];
		if (response === undefined) {
			return [
				{
					...base,
					at: 'status',
					message: `status ${status} is not one of ${Object.keys(declared).join(', ')}`,
				},
			];
		}
		const content = response.content;
		if (content === undefined) {
			return parts.contentType === null
				? []
				: [
						{
							...base,
							at: 'body',
							message: `status ${status} is declared without a body`,
						},
					];
		}
		const mediaType = Object.keys(content).find(
			(type) => type === parts.contentType?.split(';')[0]?.trim().toLowerCase(),
		);
		if (mediaType === undefined) {
			return [
				{
					...base,
					at: 'header.content-type',
					message: `content-type ${parts.contentType ?? '(none)'} is not one of ${Object.keys(content).join(', ')}`,
				},
			];
		}
		if (
			!checkedMediaType(mediaType) ||
			content[mediaType]?.schema === undefined
		) {
			return [];
		}
		return check(bodies, responseRef(operation, key, mediaType), parts.body, {
			...base,
			at: 'body',
		});
	}

	function webhookViolations(type: string, body: unknown): Violation[] {
		const base = {
			operation: `webhook ${type}`,
			direction: 'request' as const,
		};
		if (!Object.hasOwn(contract.spec.webhooks, type)) {
			return [
				{
					...base,
					at: 'type',
					message: `the reference has no webhook ${type}`,
				},
			];
		}
		return check(bodies, webhookBodyRef(type), body, { ...base, at: 'body' });
	}

	return {
		request: requestViolations,
		response: responseViolations,
		webhook: webhookViolations,
		warm(operations, webhooks = []) {
			for (const type of webhooks) {
				compiled(bodies, webhookBodyRef(type));
			}
			for (const operation of operations) {
				const { definition } = operation;
				(definition.parameters ?? []).forEach((parameter, index) => {
					if (parameter.schema !== undefined) {
						compiled(parameters, parameterRef(operation, index));
					}
				});
				for (const [type, media] of Object.entries(
					definition.requestBody?.content ?? {},
				)) {
					if (jsonMediaType(type) && media.schema !== undefined) {
						compiled(bodies, requestBodyRef(operation, type));
					}
				}
				for (const [status, response] of Object.entries(definition.responses)) {
					for (const [type, media] of Object.entries(response.content ?? {})) {
						if (checkedMediaType(type) && media.schema !== undefined) {
							compiled(bodies, responseRef(operation, status, type));
						}
					}
				}
			}
		},
	};
}
