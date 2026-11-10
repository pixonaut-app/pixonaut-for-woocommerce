import { domReady } from '../shared/dom-ready';
import { createLabeler } from '../shared/labels';
import { drawAttributeSwatches } from './attribute-swatches';
import { CartEdit } from './cart-edit';
import { ProductDesigner } from './designer';
import { PersonaliseButton } from './personalise-button';
import { fitPreviews } from './preview-fit';
import { reportUnavailable } from './report';
import { loadSdk } from './sdk-loader';
import { readSettings } from './settings';
import { drawStepNumbers } from './step-numbers';
import { markThemeLook, readThemeLook, themeMountOptions } from './theme-look';
import './storefront.css';
import './form-placeholder.css';
import './attribute-swatches.css';
import './attribute-rows.css';

domReady(() => {
	const settings = readSettings();
	if (settings === null) {
		return;
	}
	const label = createLabeler(settings.labels);
	fitPreviews(document);
	for (const container of document.querySelectorAll<HTMLElement>(
		'.pixonaut-form',
	)) {
		const look = readThemeLook(container);
		markThemeLook(container, look);
		const badges = look.accent !== undefined;
		const numbered = container.dataset.pixonautGroupNumbers !== 'off';
		if (badges || !numbered) {
			drawStepNumbers(container, { badges, numbered });
		}
		new ProductDesigner(container, {
			loadSdk: () => loadSdk(settings.sdk),
			label,
			report: (productId, side, reason) =>
				reportUnavailable(settings.reportUrl, productId, side, reason),
			cartEdit: (edit) => new CartEdit(edit),
			navigate: (url) => window.location.assign(url),
			...(settings.themeAccent ? { themeOptions: themeMountOptions } : {}),
			...(settings.attributeSwatches
				? { attributeSwatches: drawAttributeSwatches }
				: {}),
			...(settings.debug
				? {
						debug: (message: string, details: unknown) =>
							console.debug(`Pixonaut: ${message}`, details),
					}
				: {}),
		}).start();
	}
	for (const container of document.querySelectorAll<HTMLElement>(
		'.pixonaut-personalise[data-pixonaut-variations]',
	)) {
		new PersonaliseButton(container).start();
	}
});
