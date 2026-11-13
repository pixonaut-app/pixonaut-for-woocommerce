// The exchanges the plugin and the SDK have with Pixonaut, in the order a
// store has them: connect, narrow the webhook endpoint, read the catalogue,
// import a printshop's catalogue product as a blank,
// let a customer personalise, commit, approve and order, sell a fixed
// product from a standing design, hold orders on the free plan and release
// them (where the target can), mint a proof page, correct a design from
// the cart through a correction link, read the event stream, disconnect. Ids come from the answers, never from fixtures,
// so the same scenarios run against the fake and a live Pixonaut.
import { createHash, randomBytes, randomUUID } from 'node:crypto';
import type { Session } from './session';

export type Scenario = {
	name: string;
	run(session: Session, found: Found): Promise<void>;
};

/** What earlier scenarios found for later ones. */
export type Found = {
	/** A published template that fits `blankId`'s `placementId`. */
	templateId?: string;
	blankId?: string;
	placementId?: string;
	/** A variant of `blankId` still sold, which its orders name. */
	variantRef?: string;
	/** A proof the storefront minted on that blank, for the design flow. */
	proofToken?: string;
};

/** The event types the store's endpoint receives and its pull asks for
 *  (`Endpoint::EVENT_TYPES`). */
function eventTypes(session: Session): string[] {
	return session.contract.consumed.webhooks.filter(
		(type) => type !== 'webhook.ping',
	);
}

/** Where the studio sends the member back with the code, as the plugin
 *  asks it to. */
function redirectUriOf(siteUrl: string): string {
	return new URL('/wp-admin/admin.php?page=pixonaut', siteUrl).href;
}

/** RFC 7636 allows a verifier of 43 to 128 characters; 32 random bytes
 *  write as 43. */
const PKCE_VERIFIER_BYTES = 32;

/** `improve-resolution`: made now, or made before. */
const IMPROVED = [201, 200];
/** `improve-resolution` on an upload it was not offered for. */
const NOT_OFFERED = 404;
/** A cut-out: asked now, or asked before. */
const CUT_OUT = [202, 200];
/** A cut-out of an upload made for no picture, or of a picture whose
 *  constraints offer none. */
const CUT_OUT_REFUSED = [404, 422];
/** A cut-out read back: how it stands, or none was made. */
const CUT_OUT_READ = [200, 404];
/** A picture's copy in threads: made before or none to make, made now, or
 *  refused where the workspace has no embroidery. */
const THREAD_COPY = [200, 201, 403];
/** A catalogue import is written at once and filled in in the background. */
const ACCEPTED = 202;
/** A product imported before is answered with its blank, not imported again. */
const IMPORTED_BEFORE = 200;
/** A catalogue product with no print position is refused, before anything is written. */
const UNPRINTABLE = 422;
/** A machine's threads, as a placement's stitch block lists them. */
const THREADS = {
	maxThreads: 6,
	threads: [
		{ id: '1801', name: '1801 White', hex: '#FFFFFF', cmyk: [0, 0, 0, 0] },
	],
};
/** Answers of the order screen's moves: done, placed again, refused for the
 *  order's status, or its printshop out of reach. */
const OK = 200;
const CREATED = 201;
const NO_CONTENT = 204;
const CONFLICT = 409;
const UNAVAILABLE = 503;
/** A placement's preview tables: read, or `no_preview_tables` where its
 *  print goes through a bundled profile. */
const PREVIEW_TABLES = [200, 404];

/** A print size to judge, square and small enough for any placement. */
const ASKED_SIZE = { widthMm: 100, heightMm: 100 };

/** `ASKED_SIZE`'s width at the template's ratio, which a designer link's
 *  print sizes must keep. */
function sizeAtRatio(ratio: { w: number; h: number }): {
	widthMm: number;
	heightMm: number;
} {
	return {
		widthMm: ASKED_SIZE.widthMm,
		heightMm: (ASKED_SIZE.widthMm * ratio.h) / ratio.w,
	};
}

/** A template's base id: its versioned id (`baseId@version`) up to the `@`. */
function baseIdOf(templateId: string): string {
	return templateId.split('@')[0] ?? templateId;
}

/** RFC 7636 S256: base64url of the verifier's SHA-256. */
function pkce(): { verifier: string; challenge: string } {
	const verifier = randomBytes(PKCE_VERIFIER_BYTES).toString('base64url');
	const challenge = createHash('sha256').update(verifier).digest('base64url');
	return { verifier, challenge };
}

/** Bytes of a 1 × 1 PNG, as a customer's upload. */
const PIXEL_PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64',
);

/** The token of a keyless `/i/{token}` image link. */
const IMAGE_LINK = /\/i\/([^/?#]+)$/;

/** A photo in a blank's mockups answer, as far as the scenarios read it. */
type MockupPhoto = {
	thumbnailUrl?: string;
	mount?: { url?: string } | null;
};

/** Every photo of every view of a blank's mockups answer. */
function mockupPhotos(answer: unknown): MockupPhoto[] {
	const placements =
		(
			answer as {
				placements?: { views?: { photos?: MockupPhoto[] }[] }[];
			}
		)?.placements ?? [];
	return placements
		.flatMap((placement) => placement.views ?? [])
		.flatMap((view) => view.photos ?? []);
}

/** The tokens of the keyless image links among `urls`. */
function imageTokens(urls: (string | undefined)[]): string[] {
	return urls
		.map((url) =>
			url === undefined ? null : new URL(url).pathname.match(IMAGE_LINK),
		)
		.flatMap((match) => (match === null ? [] : [match[1] as string]));
}

/** The image tokens a blank's mockups link to: thumbnails and mount photos. */
function mockupImageTokens(answer: unknown): string[] {
	return imageTokens(
		mockupPhotos(answer).flatMap((photo) => [
			photo.thumbnailUrl,
			photo.mount?.url,
		]),
	);
}

/** The image tokens of the photos a designer mounts, as the SDK loads them. */
function mountImageTokens(answer: unknown): string[] {
	return imageTokens(mockupPhotos(answer).map((photo) => photo.mount?.url));
}

/** A variant as `GET /v1/blanks/{id}/variants` answers it. */
type BlankVariant = {
	ref: string;
	retired: unknown;
	views: { placementId: string; viewId: string }[];
};

/** Where a customer previews a proof, as the SDK names it on
 *  `POST /v1/proofs`: a sold variant, `preferred` when it is one, and a view
 *  of `placementId` it has a photo in. Empty when the blank sells none; no
 *  view when the variant has no photo in the placement. */
function previewOf(
	variants: BlankVariant[],
	placementId: string,
	preferred: string | undefined,
): { variant?: string; viewId?: string } {
	const sold = variants.filter((v) => v.retired === null);
	const inPlacement = (v: BlankVariant) =>
		v.views.find((view) => view.placementId === placementId);
	const variant =
		sold.find((v) => v.ref === preferred && inPlacement(v) !== undefined) ??
		sold.find((v) => inPlacement(v) !== undefined) ??
		sold[0];
	if (variant === undefined) {
		return {};
	}
	const view = inPlacement(variant);
	return view === undefined
		? { variant: variant.ref }
		: { variant: variant.ref, viewId: view.viewId };
}

/** A font file name the SDK fetches, from a template's `fonts`. */
const FONT_HASH = /^[0-9a-f]{64}$/;

function fontFiles(template: unknown): string[] {
	const fonts = (template as { fonts?: Record<string, { hash?: string }[]> })
		?.fonts;
	return Object.values(fonts ?? {})
		.flat()
		.map((face) => face.hash ?? '')
		.filter((hash) => FONT_HASH.test(hash))
		.map((hash) => `${hash}.ttf`);
}

/** Only resolves a relative URL; never fetched. */
const ANY_BASE = 'http://base.invalid';

/** The path and query of a URL Pixonaut handed out, absolute or not: the
 *  target sends it to its own Pixonaut. */
function pathOf(url: string): string {
	const resolved = new URL(url, ANY_BASE);
	return `${resolved.pathname}${resolved.search}`;
}

/** A checkbox consent given now, in milliseconds since the epoch. */
const consentNow = () => ({ kind: 'checkbox', at: Date.now() });

function need<T>(value: T | null | undefined, what: string): T {
	if (value === undefined || value === null) {
		throw new Error(`${what} was not found by an earlier scenario`);
	}
	return value;
}

/** How long a proof page minted by a scenario works, as the plugin's default. */
const PROOF_PAGE_DAYS = 14;

/** Commits a design from a proof minted now, for a scenario of its own. */
async function commitFreshDesign(
	session: Session,
	found: Found,
): Promise<string> {
	const templateId = need(found.templateId, 'a template');
	const proof = (
		await session.call('POST /v1/proofs', {
			json: {
				templateId,
				values: {},
				blankId: need(found.blankId, 'a blank'),
				placementId: need(found.placementId, 'a placement'),
			},
			sdkSession: randomUUID(),
		})
	).body;
	const runId = randomUUID();
	const { designId } = (
		await session.call('POST /v1/designs', {
			json: {
				proofToken: proof.proofToken,
				externalRef: `wc:scenario:cart:${runId}`,
			},
			idempotencyKey: `commit:${runId}`,
		})
	).body;
	return designId as string;
}

export const SCENARIOS: Scenario[] = [
	{
		name: 'connect a store',
		async run(session) {
			const { verifier, challenge } = pkce();
			const redirectUri = redirectUriOf(session.target.siteUrl);
			const code = await session.target.connectCode(challenge, redirectUri);
			const { body } = await session.call('POST /v1/connect/token', {
				json: { code, code_verifier: verifier, redirect_uri: redirectUri },
				key: null,
			});
			session.keys = {
				integrationKey: body.integrationKey.key,
				publishableKey: body.publishableKey.key,
				webhookEndpointId: body.webhook.id,
			};
			await session.call('GET /v1/connect');
			// Once connected, the store adds its counterpart with or without
			// `www.`, a host of its own domain.
			const site = new URL(session.target.siteUrl);
			site.hostname = site.hostname.startsWith('www.')
				? site.hostname.slice('www.'.length)
				: `www.${site.hostname}`;
			await session.call('PATCH /v1/connect', {
				json: { additionalOrigins: [site.origin] },
			});
		},
	},
	{
		name: 'narrow and check the webhook endpoint',
		async run(session) {
			const id = need(session.keys?.webhookEndpointId, 'the endpoint');
			await session.call('GET /v1/webhooks');
			await session.call('PATCH /v1/webhooks/{id}', {
				params: { id },
				json: { eventTypes: eventTypes(session) },
			});
			// Nothing listens at the site: the ping reports the failed delivery.
			await session.call('POST /v1/webhooks/{id}/ping', { params: { id } });
			await session.call('POST /v1/webhooks/{id}/deliveries/redeliver-failed', {
				params: { id },
			});
		},
	},
	{
		name: 'read the catalogue',
		async run(session, found) {
			await session.call('GET /v1/printshop-connections');
			await session.call('GET /v1/templates', { query: { status: 'live' } });
			const blanks = (
				await session.call('GET /v1/blanks', {
					query: { hasPlacements: 'true' },
				})
			).body.blanks as {
				id: string;
			}[];
			for (const { id } of blanks) {
				const blank = (
					await session.call('GET /v1/blanks/{id}', { params: { id } })
				).body;
				const mockups = (
					await session.call('GET /v1/blanks/{id}/mockups', { params: { id } })
				).body;
				const variants = (
					await session.call('GET /v1/blanks/{id}/variants', { params: { id } })
				).body.variants as { ref: string; retired: unknown }[];
				const [token] = mockupImageTokens(mockups);
				if (token !== undefined) {
					await session.call('GET /i/{token}', {
						params: { token },
						accept: '*/*',
					});
				}
				for (const placement of blank.placements ?? []) {
					const fitting = (
						await session.call(
							'GET /v1/blanks/{id}/placements/{placementId}/fitting-templates',
							{
								params: { id, placementId: placement.id },
								query: { status: 'live' },
							},
						)
					).body;
					const template = (
						fitting.items as {
							templateId: string;
							verdict: { legal: boolean };
						}[]
					).find((item) => item.verdict.legal);
					if (template !== undefined && found.templateId === undefined) {
						found.templateId = template.templateId;
						found.blankId = id;
						found.placementId = placement.id;
						found.variantRef = variants.find((v) => v.retired === null)?.ref;
					}
				}
			}
			const templateId = need(found.templateId, 'a template fitting a blank');
			const onBlank = {
				blankId: need(found.blankId, 'a blank'),
				placementId: need(found.placementId, 'a placement'),
			};
			await session.call('GET /v1/templates/{id}/constraints', {
				params: { id: templateId },
				query: onBlank,
			});
			await session.call('POST /v1/templates/{id}/print-size', {
				params: { id: templateId },
				json: { ...onBlank, ...ASKED_SIZE },
			});
			// A product found sellable reads whether its template is live; the
			// product screen lists its showcase sets for the product's pictures.
			const { showcases } = (
				await session.call('GET /v1/templates/{id}', {
					params: { id: templateId },
				})
			).body as { showcases?: { id: string }[] };
			// Its picture: the template on the placement's first photographed
			// view, or the template alone for a product with no blank.
			const placements = (
				await session.call('GET /v1/blanks/{id}/mockups', {
					params: { id: onBlank.blankId },
				})
			).body.placements as {
				id: string;
				views: { id: string; photos: unknown[] }[];
			}[];
			const view = placements
				.find((p) => p.id === onBlank.placementId)
				?.views.find((v) => v.photos.length > 0);
			await session.call('GET /t/{id}/mockup.png', {
				params: { id: templateId },
				query: { ...onBlank, viewId: need(view?.id, 'a photographed view') },
				accept: 'image/png',
			});
			await session.call('GET /t/{id}/preview.png', {
				params: { id: templateId },
				accept: 'image/png',
			});
			// A picture chosen to show a showcase set is drawn with it.
			const [showcase] = showcases ?? [];
			if (showcase !== undefined) {
				await session.call('GET /t/{id}/preview.png', {
					params: { id: templateId },
					query: { showcase: showcase.id },
					accept: 'image/png',
				});
			}
		},
	},
	{
		name: "link a product by a printshop's catalogue product",
		async run(session) {
			const connections = (await session.call('GET /v1/printshop-connections'))
				.body.connections as { id: string; catalogue?: boolean }[];
			const connectionId = need(
				connections.find((connection) => connection.catalogue === true)?.id,
				'a printshop with a catalogue',
			);
			const products = (
				await session.call('GET /v1/printshop-catalogue-products', {
					query: { connectionId, limit: '20' },
				})
			).body.products as { productId: string }[];
			// A name of its own, so a blank another product left under the printshop's name does not refuse it.
			await session.call(
				'POST /v1/printshop-catalogue-products/{productId}/blank',
				{
					params: {
						productId: need(products[0]?.productId, 'a catalogue product'),
					},
					query: { connectionId },
					json: { name: `Store import ${randomUUID()}` },
					expect: [ACCEPTED, IMPORTED_BEFORE, UNPRINTABLE],
				},
			);
		},
	},
	{
		name: 'personalise on the product page',
		async run(session, found) {
			const templateId = need(found.templateId, 'a template');
			const onBlank = {
				blankId: need(found.blankId, 'a blank'),
				placementId: need(found.placementId, 'a placement'),
			};
			const sdkSession = randomUUID();
			const template = (
				await session.call('GET /v1/templates/{id}', {
					params: { id: templateId },
				})
			).body;
			for (const file of fontFiles(template.template ?? template)) {
				await session.call('GET /f/{file}', {
					params: { file },
					accept: '*/*',
				});
			}
			const constraints = (
				await session.call('GET /v1/templates/{id}/constraints', {
					params: { id: templateId },
					query: onBlank,
					key: 'publishableKey',
				})
			).body;
			// The SDK reads them where the constraints name `cmykPreviewTables`;
			// read here either way, as the answer is a refusal without them.
			await session.call(
				'GET /v1/blanks/{id}/placements/{placementId}/preview-tables',
				{
					params: { id: onBlank.blankId, placementId: onBlank.placementId },
					key: 'publishableKey',
					expect: PREVIEW_TABLES,
				},
			);
			const size = sizeAtRatio(constraints.ratio);
			await session.call('POST /v1/templates/{id}/print-size', {
				params: { id: templateId },
				json: { ...onBlank, ...size },
				key: 'publishableKey',
			});
			// The SDK reads the blank's mockups and variants itself, mounts their
			// photos through the keyless links, and names the variant and view
			// the customer previewed on the proof.
			const blankParams = { id: onBlank.blankId };
			const mockups = (
				await session.call('GET /v1/blanks/{id}/mockups', {
					params: blankParams,
					key: 'publishableKey',
				})
			).body;
			const variants = (
				await session.call('GET /v1/blanks/{id}/variants', {
					params: blankParams,
					key: 'publishableKey',
				})
			).body.variants as BlankVariant[];
			const [mount] = mountImageTokens(mockups);
			if (mount !== undefined) {
				await session.call('GET /i/{token}', {
					params: { token: mount },
					accept: '*/*',
				});
			}
			const preview = previewOf(
				variants,
				onBlank.placementId,
				found.variantRef,
			);
			// The personalise button's link to the hosted designer, kept for the
			// product until withdrawn: it names the product in its return query
			// and is named after it. A link the product's changes replace gives
			// up its name, is withdrawn, and a re-check lists the working ones.
			const linkBody = {
				...onBlank,
				printSizes: [{ ...size, label: 'Asked' }],
				selectedPrintSizeIndex: 0,
				returnUrl: new URL(
					'/?pixonaut_designer=return&pixonaut_product=1&pixonaut_variation=0',
					session.origin,
				).href,
				locale: 'en',
				layout: 'steps',
				afterApprove: 'add_to_cart',
			};
			const linkName = `Scenario tee (#${randomUUID()})`;
			const link = (
				await session.call('POST /v1/templates/{id}/designer-links', {
					params: { id: baseIdOf(templateId) },
					json: { ...linkBody, name: linkName },
				})
			).body;
			await session.call('PATCH /v1/share-links/{id}', {
				params: { id: link.id },
				json: { name: null },
			});
			await session.call('GET /v1/share-links', {
				query: {
					kind: 'designer',
					subjectId: baseIdOf(templateId),
					createdBy: 'me',
					page: '1',
					pageSize: '100',
				},
			});
			await session.call('DELETE /v1/share-links/{id}', {
				params: { id: link.id },
			});

			const declared = (
				await session.call('POST /v1/assets', {
					json: {
						filename: 'picture.png',
						mime: 'image/png',
						sizeBytes: PIXEL_PNG.length,
						templateId,
					},
					sdkSession,
				})
			).body;
			await session.linked(pathOf(declared.uploadUrl), {
				method: 'PUT',
				headers: { 'content-type': 'image/png', origin: session.origin },
				body: PIXEL_PNG,
			});
			await session.call('GET /v1/assets/{id}/content', {
				params: { id: declared.assetId },
				accept: '*/*',
			});
			// Pixonaut offers an improvement only for a picture short of the
			// template's resolution on a plan that pays for it; where it offers
			// none, its refusal is held to the reference instead.
			await session.call('POST /v1/assets/{id}/improve-resolution', {
				params: { id: declared.assetId },
				sdkSession,
				expect: [...IMPROVED, NOT_OFFERED],
			});
			// A cut-out is offered only on an upload for a picture whose
			// constraints ask for one; this upload names no picture, so it is
			// refused, and there is none to read back.
			await session.call('POST /v1/assets/{id}/cut-out', {
				params: { id: declared.assetId },
				sdkSession,
				expect: [...CUT_OUT, ...CUT_OUT_REFUSED],
			});
			await session.call('GET /v1/assets/{id}/cut-out', {
				params: { id: declared.assetId },
				sdkSession,
				expect: CUT_OUT_READ,
			});
			// A picture on an embroidered side is asked for its copy in the
			// machine's threads; a workspace without embroidery refuses it.
			await session.call('POST /v1/assets/{id}/threads', {
				params: { id: declared.assetId },
				json: THREADS,
				sdkSession,
				expect: THREAD_COPY,
			});
			// Pixel answers the designer's customer only on a template that
			// offers it, and every other refusal as unavailable.
			await session.call('POST /v1/pixel/designer', {
				json: { templateId, prompt: 'Write my name in gold', values: {} },
				sdkSession,
				expect: [OK, UNAVAILABLE],
			});

			const proof = (
				await session.call('POST /v1/proofs', {
					json: { templateId, values: {}, ...onBlank, ...preview },
					sdkSession,
				})
			).body;
			found.proofToken = proof.proofToken;
			// A second session's proof, left behind as a customer would.
			const abandoned = randomUUID();
			await session.call('POST /v1/proofs', {
				json: { templateId, values: {}, ...onBlank },
				sdkSession: abandoned,
			});
			await session.call('DELETE /v1/sessions/{id}', {
				params: { id: abandoned },
			});
		},
	},
	{
		name: 'commit, approve and order a design',
		async run(session, found) {
			const proofToken = need(found.proofToken, 'a proof');
			const runId = randomUUID();
			const design = (
				await session.call('POST /v1/designs', {
					json: { proofToken, externalRef: `wc:scenario:cart:${runId}` },
					idempotencyKey: `commit:${runId}`,
				})
			).body;
			const id = design.designId as string;
			const read = (
				await session.call('GET /v1/designs/{id}', { params: { id } })
			).body;
			await session.call('GET /v1/designs/{id}/proof', {
				params: { id },
				accept: 'image/jpeg',
			});
			await session.call('GET /v1/designs/{id}/mockup.png', {
				params: { id },
				query: { v: read.proofId as string, size: '320' },
				accept: 'image/png',
			});
			const externalRef = `wc:scenario:order:${runId}:item:1`;
			await session.call('PUT /v1/designs/{id}/external-ref', {
				params: { id },
				json: { externalRef },
			});
			await session.call('POST /v1/designs/{id}/approve', {
				params: { id },
				json: { consent: consentNow(), expectedRevision: read.revision },
			});
			// Pixonaut renders the print and holds the order until it is done.
			const order = (
				await session.call('POST /v1/orders', {
					json: {
						designs: [{ designId: id }],
						variant: need(found.variantRef, 'a variant sold'),
						shipToAddressId: null,
						externalRef: `wc:scenario:order:${runId}`,
						recipient: { name: 'Ada Lovelace' },
						shippingAddress: {
							line1: 'Storgatan 1',
							city: 'Lund',
							postalCode: '222 22',
							country: 'SE',
						},
					},
					idempotencyKey: `order:${runId}`,
				})
			).body;
			await session.call('GET /v1/orders', {
				query: { externalRef: `wc:scenario:order:${runId}` },
			});
			await session.call('GET /v1/orders/{id}', { params: { id: order.id } });
			// A design has one live order: another order of it is refused,
			// naming that order.
			await session.call('POST /v1/orders', {
				json: {
					designs: [{ designId: id }],
					variant: need(found.variantRef, 'a variant sold'),
					externalRef: `wc:scenario:order:${runId}:again`,
				},
				idempotencyKey: `order:${runId}:again`,
				expect: [CONFLICT],
			});
			// The customer moved: a printshop that takes no address changes,
			// or one too far along, refuses it.
			await session.call('PATCH /v1/orders/{id}/delivery', {
				params: { id: order.id },
				json: {
					recipient: { name: 'Ada Lovelace' },
					shippingAddress: {
						line1: 'Kungsgatan 9',
						city: 'Göteborg',
						postalCode: '411 19',
						country: 'SE',
					},
				},
				idempotencyKey: `delivery:${runId}:1`,
				expect: [OK, CONFLICT, UNAVAILABLE],
			});
			// The order screen's moves. A live order whose outcome is known
			// allows none of them, so a refusal the reference declares is an
			// answer too; cancel last, so the order goes no further.
			await session.call('PATCH /v1/orders/{id}', {
				params: { id: order.id },
				json: { status: 'failed', reason: 'The printshop has no such order.' },
				expect: [OK, CONFLICT],
			});
			await session.call('POST /v1/orders/{id}/retry', {
				params: { id: order.id },
				json: {},
				expect: [CREATED, CONFLICT],
			});
			await session.call('POST /v1/orders/{id}/cancel', {
				params: { id: order.id },
				expect: [OK, CONFLICT, UNAVAILABLE],
			});
			// A privacy request: an order still on its way is refused.
			await session.call('POST /v1/orders/{id}/erase-personal-data', {
				params: { id: order.id },
				expect: [OK, CONFLICT],
			});
			await session.call('POST /v1/designs/{id}/erase-personal-data', {
				params: { id },
			});
		},
	},
	{
		name: 'sell a fixed product from a standing design',
		async run(session, found) {
			const runId = randomUUID();
			const side = {
				templateId: need(found.templateId, 'a template'),
				blankId: need(found.blankId, 'a blank'),
				placementId: need(found.placementId, 'a placement'),
				approve: true,
			};
			// Saving the product finds or creates it; an earlier run may have made it.
			const standing = (
				await session.call('POST /v1/designs/standing', {
					json: side,
					expect: [CREATED, OK],
				})
			).body;
			const again = (
				await session.call('POST /v1/designs/standing', {
					json: side,
					expect: [OK],
				})
			).body;
			if (again.designId !== standing.designId) {
				throw new Error('The same standing design was not found again.');
			}
			// Each paid order of it places an order of its own, never refused as ordered.
			for (const n of [1, 2]) {
				const order = (
					await session.call('POST /v1/orders', {
						json: {
							designs: [{ designId: standing.designId }],
							variant: need(found.variantRef, 'a variant sold'),
							externalRef: `wc:scenario:order:${runId}:${n}`,
							recipient: { name: 'Ada Lovelace' },
							shippingAddress: {
								line1: 'Storgatan 1',
								city: 'Lund',
								postalCode: '222 22',
								country: 'SE',
							},
						},
						idempotencyKey: `order:${runId}:${n}`,
					})
				).body;
				await session.call('POST /v1/orders/{id}/cancel', {
					params: { id: order.id },
					expect: [OK, CONFLICT, UNAVAILABLE],
				});
			}
		},
	},
	{
		name: 'hold orders on the free plan, then release them',
		async run(session, found) {
			const holds = session.target.holds;
			// A live Pixonaut holds orders only for a free workspace past its
			// monthly limit, which a scenario cannot make.
			if (holds === undefined) {
				return;
			}
			const runId = randomUUID();
			const standing = (
				await session.call('POST /v1/designs/standing', {
					json: {
						templateId: need(found.templateId, 'a template'),
						blankId: need(found.blankId, 'a blank'),
						placementId: need(found.placementId, 'a placement'),
						approve: true,
					},
					expect: [CREATED, OK],
				})
			).body;
			for (const releasedBy of ['upgrade', 'month_reset'] as const) {
				await holds.holdNextOrder();
				const order = (
					await session.call('POST /v1/orders', {
						json: {
							designs: [{ designId: standing.designId }],
							variant: need(found.variantRef, 'a variant sold'),
							externalRef: `wc:scenario:order:${runId}:${releasedBy}`,
							recipient: { name: 'Ada Lovelace' },
							shippingAddress: {
								line1: 'Storgatan 1',
								city: 'Lund',
								postalCode: '222 22',
								country: 'SE',
							},
						},
						idempotencyKey: `order:${runId}:${releasedBy}`,
					})
				).body;
				if (order.status !== 'held') {
					throw new Error(`The order was placed ${order.status}, not held.`);
				}
				await session.call('GET /v1/orders/{id}', { params: { id: order.id } });
				await holds.release(order.id, releasedBy);
				await session.call('GET /v1/orders/{id}', { params: { id: order.id } });
				await session.call('POST /v1/orders/{id}/cancel', {
					params: { id: order.id },
					expect: [OK, CONFLICT, UNAVAILABLE],
				});
			}
		},
	},
	{
		name: 'mint a proof page for a customer to approve',
		async run(session, found) {
			const designId = await commitFreshDesign(session, found);
			await session.call('POST /v1/share-links', {
				json: {
					kind: 'proof-page',
					subjectId: designId,
					mayDecide: true,
					expiresInDays: PROOF_PAGE_DAYS,
					returnUrl: new URL('/', session.target.siteUrl).href,
					state: randomUUID(),
					locale: 'en',
				},
			});
		},
	},
	{
		name: 'correct a design from the cart',
		async run(session, found) {
			const id = await commitFreshDesign(session, found);
			const link = (
				await session.call('POST /v1/designs/{id}/correction-links', {
					params: { id },
					json: {},
				})
			).body.token as string;
			const opened = (
				await session.call('GET /v1/designs/{id}/designer', {
					params: { id },
					link,
				})
			).body;
			// Opened on its product, the SDK reads the blank and placement the
			// design was made on through the link.
			const { blankId, placementId, template } = opened;
			if (typeof blankId === 'string' && typeof placementId === 'string') {
				const blankParams = { id: blankId };
				await session.call('GET /v1/blanks/{id}/mockups', {
					params: blankParams,
					link,
				});
				await session.call('GET /v1/blanks/{id}/variants', {
					params: blankParams,
					link,
				});
				await session.call('GET /v1/templates/{id}/constraints', {
					params: { id: `${template.id}@${template.version}` },
					query: { blankId, placementId },
					link,
				});
				await session.call(
					'GET /v1/blanks/{id}/placements/{placementId}/preview-tables',
					{
						params: { id: blankId, placementId },
						link,
						expect: PREVIEW_TABLES,
					},
				);
			}
			const correction = (
				await session.call('POST /v1/designs/{id}/correction-proofs', {
					params: { id },
					json: { values: {}, expectedRevision: opened.revision },
					link,
				})
			).body;
			await session.call('PATCH /v1/designs/{id}', {
				params: { id },
				json: { proofToken: correction.proofToken },
			});
			await session.call('DELETE /v1/designs/{id}', { params: { id } });
		},
	},
	{
		name: 'reject a design whose order line was cancelled',
		async run(session, found) {
			const designId = await commitFreshDesign(session, found);
			await session.call('POST /v1/designs/{id}/reject', {
				params: { id: designId },
			});
		},
	},
	{
		name: 'delete a design no order holds',
		async run(session, found) {
			const designId = await commitFreshDesign(session, found);
			await session.call('DELETE /v1/designs/{id}', {
				params: { id: designId },
			});
			await session.call('POST /v1/designs/{id}/erase-personal-data', {
				params: { id: designId },
				expect: [NO_CONTENT],
			});
		},
	},
	{
		name: 'order a design again from a past order',
		async run(session, found) {
			const sourceId = await commitFreshDesign(session, found);
			const copyId = (
				await session.call('POST /v1/designs/{id}/duplicate', {
					params: { id: sourceId },
				})
			).body.designId as string;
			// A copy the reorder leaves out of the cart is deleted again.
			await session.call('DELETE /v1/designs/{id}', { params: { id: copyId } });
			await session.call('DELETE /v1/designs/{id}', {
				params: { id: sourceId },
			});
		},
	},
	{
		name: 'read the event stream',
		async run(session) {
			await session.call('GET /v1/events', {
				query: { types: eventTypes(session).join(',') },
			});
		},
	},
	{
		name: 'hand over the store access credential, and take it back',
		async run(session) {
			await session.call('PUT /v1/connect/store-access', {
				json: { credential: randomUUID() },
			});
			await session.call('DELETE /v1/connect/store-access');
		},
	},
	{
		name: 'disconnect',
		async run(session) {
			await session.call('DELETE /v1/connect');
		},
	},
];
