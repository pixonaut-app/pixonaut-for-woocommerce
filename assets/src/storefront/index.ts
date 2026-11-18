import { domReady } from '../shared/dom-ready';
import { createLabeler } from '../shared/labels';
import { drawAttributeSwatches } from './attribute-swatches';
import { CartEdit } from './cart-edit';
import { readChoicePrices } from './choice-prices';
import { ProductDesigner } from './designer';
import { drawOptionPrices } from './option-prices';
import { PersonaliseButton } from './personalise-button';
import { fitPreviews } from './preview-fit';
import { ReadsAhead } from './reads-ahead';
import { reportUnavailable } from './report';
import { loadSdk } from './sdk-loader';
import { readSettings } from './settings';
import { dropBrokenPosters } from './stage-poster';
import { drawStepNumbers } from './step-numbers';
import { readSwatchLooks } from './swatch-looks';
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
	dropBrokenPosters(document);
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
		// One read for every part, so their swatches share the amounts.
		const choicePrices = readChoicePrices(container);
		const form = container.closest('form');
		// Without swatches, the prices go in the theme's own fields.
		if (!settings.attributeSwatches && choicePrices !== null && form !== null) {
			drawOptionPrices(form, choicePrices);
		}
		new ProductDesigner(container, {
			loadSdk: () => loadSdk(settings.sdk),
			label,
			report: (productId, side, reason) =>
				reportUnavailable(settings.reportUrl, productId, side, reason),
			cartEdit: (edit) => new CartEdit(edit),
			readsAhead: new ReadsAhead(
				settings.readsAheadUrl,
				Number(container.dataset.pixonautProduct),
			),
			navigate: (url) => window.location.assign(url),
			...(settings.themeAccent ? { themeOptions: themeMountOptions } : {}),
			...(settings.attributeSwatches
				? {
						attributeSwatches: (part: HTMLElement) =>
							drawAttributeSwatches(
								part,
								readSwatchLooks(container),
								choicePrices,
							),
					}
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
