// The fake's template fixtures must stay inside what the pinned SDK release
// accepts: the SDK parses a template strictly and refuses a field it does not
// know, even one a newer spec allows. Checked against the SDK's own types
// (`contracts/pixonaut/sdk/schema.d.ts`), so `tsc` fails on such a field.
import { describe, expectTypeOf, it } from 'vitest';
import type { paths } from '../../../contracts/pixonaut/sdk/schema';
import type miniTee from '../../fake-pixonaut/fixtures/templates/mini-tee@1.json';

/** What the pinned SDK reads from `GET /v1/templates/{id}`. */
type SdkTemplateResponse =
	paths['/v1/templates/{id}']['get']['responses'][200]['content']['application/json'];

type KeysOfUnion<S> = S extends unknown ? keyof S : never;
type ValueOfUnion<S, K> = S extends unknown
	? K extends keyof S
		? S[K]
		: never
	: never;

/** Paths in `T` naming a field `S` has under no member of its unions. Under
 *  a value `S` types as `unknown`, anything goes. */
type UnknownFields<T, S, Path extends string = ''> = unknown extends S
	? never
	: T extends readonly (infer E)[]
		? UnknownFields<
				E,
				NonNullable<S> extends readonly (infer SE)[] ? SE : never,
				`${Path}[]`
			>
		: T extends object
			? {
					[K in keyof T & string]: K extends KeysOfUnion<NonNullable<S>>
						? UnknownFields<
								T[K],
								ValueOfUnion<NonNullable<S>, K>,
								`${Path}.${K}`
							>
						: `${Path}.${K}`;
				}[keyof T & string]
			: never;

describe('template fixtures', () => {
	it('carry only fields the pinned SDK knows', () => {
		expectTypeOf<
			UnknownFields<typeof miniTee, SdkTemplateResponse>
		>().toEqualTypeOf<never>();
	});
});
