// Which consumed operations and webhooks the fake serves: every one in
// `consumed.json`, which the coverage test holds the two lists to. An
// operation gets its handler in the change that starts calling it.
import type { OperationKey } from '../spec';
import { PING_TYPE } from '../webhooks/deliveries';
import {
	createAsset,
	cutOutAsset,
	improveAssetResolution,
	readAssetContent,
	readAssetCutOut,
	threadCopyAsset,
	uploadAssetBytes,
} from './assets';
import {
	judgePrintSize,
	listBlankMockups,
	listBlanks,
	listBlankVariants,
	listFittingTemplates,
	listPrintshopConnections,
	listTemplates,
	readBlank,
	readPreviewTables,
} from './catalogue';
import {
	deleteStoreAccess,
	disconnect,
	exchangeConnectCode,
	readConnection,
	setStoreAccess,
	updateConnection,
} from './connect';
import {
	correctDesign,
	mintCorrectionLink,
	proofCorrection,
	readDesigner,
} from './corrections';
import {
	createDesignerLink,
	listShareLinks,
	updateShareLink,
	withdrawShareLink,
} from './designer-links';
import { askDesignerPixel } from './designer-pixel';
import {
	approveDesign,
	commitDesign,
	deleteDesign,
	duplicateDesign,
	readDesign,
	readDesignMockup,
	readDesignProof,
	rejectDesign,
	setDesignExternalRef,
} from './designs';
import { eraseDesign, eraseOrder } from './erasure';
import { listEvents } from './events';
import { readFontFile } from './fonts';
import { readImage } from './images';
import { changeOrderDelivery } from './order-delivery';
import {
	cancelOrder,
	createOrder,
	listOrders,
	readOrder,
	retryOrder,
	updateOrder,
} from './orders';
import { createProof, readProofImage } from './proofs';
import { deleteSession } from './sessions';
import { createShareLink } from './share-links';
import { findOrCreateStandingDesign } from './standing-designs';
import { readTemplateMockup, readTemplatePreview } from './template-images';
import { readTemplate, readTemplateConstraints } from './templates';
import type { Handler } from './types';
import {
	listWebhooks,
	pingWebhook,
	redeliverFailed,
	updateWebhook,
} from './webhooks';

export const HANDLERS: Partial<Record<OperationKey, Handler>> = {
	'DELETE /v1/connect': disconnect,
	'GET /v1/connect': readConnection,
	'PATCH /v1/connect': updateConnection,
	'POST /v1/connect/token': exchangeConnectCode,
	'PUT /v1/connect/store-access': setStoreAccess,
	'DELETE /v1/connect/store-access': deleteStoreAccess,
	'GET /f/{file}': readFontFile,
	'GET /i/{token}': readImage,
	'GET /t/{id}/mockup.png': readTemplateMockup,
	'GET /t/{id}/preview.png': readTemplatePreview,
	'GET /v1/blanks': listBlanks,
	'GET /v1/blanks/{id}': readBlank,
	'GET /v1/blanks/{id}/mockups': listBlankMockups,
	'GET /v1/blanks/{id}/variants': listBlankVariants,
	'GET /v1/blanks/{id}/placements/{placementId}/fitting-templates':
		listFittingTemplates,
	'GET /v1/blanks/{id}/placements/{placementId}/preview-tables':
		readPreviewTables,
	'GET /v1/templates': listTemplates,
	'GET /v1/orders': listOrders,
	'GET /v1/orders/{id}': readOrder,
	'PATCH /v1/orders/{id}': updateOrder,
	'POST /v1/orders': createOrder,
	'POST /v1/orders/{id}/cancel': cancelOrder,
	'POST /v1/orders/{id}/retry': retryOrder,
	'POST /v1/orders/{id}/erase-personal-data': eraseOrder,
	'PATCH /v1/orders/{id}/delivery': changeOrderDelivery,
	'GET /v1/templates/{id}': readTemplate,
	'GET /v1/templates/{id}/constraints': readTemplateConstraints,
	'POST /v1/templates/{id}/print-size': judgePrintSize,
	'POST /v1/templates/{id}/designer-links': createDesignerLink,
	'GET /v1/printshop-connections': listPrintshopConnections,
	'POST /v1/designs': commitDesign,
	'POST /v1/designs/standing': findOrCreateStandingDesign,
	'GET /v1/designs/{id}': readDesign,
	'GET /v1/designs/{id}/proof': readDesignProof,
	'GET /v1/designs/{id}/mockup.png': readDesignMockup,
	'DELETE /v1/designs/{id}': deleteDesign,
	'PUT /v1/designs/{id}/external-ref': setDesignExternalRef,
	'POST /v1/designs/{id}/approve': approveDesign,
	'POST /v1/designs/{id}/duplicate': duplicateDesign,
	'POST /v1/designs/{id}/reject': rejectDesign,
	'POST /v1/designs/{id}/erase-personal-data': eraseDesign,
	'POST /v1/share-links': createShareLink,
	'GET /v1/share-links': listShareLinks,
	'PATCH /v1/share-links/{id}': updateShareLink,
	'DELETE /v1/share-links/{id}': withdrawShareLink,
	'PATCH /v1/designs/{id}': correctDesign,
	'POST /v1/designs/{id}/correction-links': mintCorrectionLink,
	'GET /v1/designs/{id}/designer': readDesigner,
	'POST /v1/designs/{id}/correction-proofs': proofCorrection,
	'POST /v1/proofs': createProof,
	'POST /v1/assets': createAsset,
	'GET /v1/assets/{id}/content': readAssetContent,
	'POST /v1/assets/{id}/improve-resolution': improveAssetResolution,
	'POST /v1/assets/{id}/cut-out': cutOutAsset,
	'GET /v1/assets/{id}/cut-out': readAssetCutOut,
	'POST /v1/assets/{id}/threads': threadCopyAsset,
	'DELETE /v1/sessions/{id}': deleteSession,
	'GET /v1/events': listEvents,
	'GET /v1/webhooks': listWebhooks,
	'PATCH /v1/webhooks/{id}': updateWebhook,
	'POST /v1/webhooks/{id}/deliveries/redeliver-failed': redeliverFailed,
	'POST /v1/webhooks/{id}/ping': pingWebhook,
	'POST /v1/pixel/designer': askDesignerPixel,
};

/** Operations no client calls by name: it follows a link another answer
 *  handed it (`uploadUrl`, `proofUrl`), so they are not in consumed.json.
 *  What the fake answers through one is held to the reference all the same. */
export const LINKED: Partial<Record<OperationKey, Handler>> = {
	'GET /p/{token}': readProofImage,
	'PUT /u/{token}': uploadAssetBytes,
};

/** Sent by `POST /v1/webhooks/{id}/ping`, never emitted as an event. */
export const PINGED_WEBHOOK = PING_TYPE;

/** Webhook events the fake can deliver through `/__fake/webhooks/emit`;
 *  a proof page's decision delivers `design.approved` or `design.rejected`,
 *  and its request for changes `design.changes_requested`. */
export const SERVED_WEBHOOKS: readonly string[] = [
	'blank.deleted',
	'blank.restored',
	'blank.updated',
	'design.approved',
	'design.changes_requested',
	'design.deleted',
	'design.edited',
	'design.personal_data_erased',
	'design.rejected',
	'order.cancelled',
	'order.delivery_changed',
	'order.failed',
	'order.held',
	'order.released',
	'order.status_changed',
	'order.submitted',
	'order.tracking_added',
	'order.tracking_changed',
	'share_link.regenerated',
	'share_link.revoked',
	'store_access.off',
	'store_access.requested',
	'template.deleted',
	'template.live_changed',
	'template.published',
];
