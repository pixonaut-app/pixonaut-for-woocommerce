// Printed on, picked from an integrated printshop's catalogue: the "Choose
// from" field says where the picker searches, a catalogue product picked is
// turned into the blank Pixonaut has it as (imported now when it has none),
// and a blank still being imported is read again until it is done.
import type { SearchRequest, SearchUrls } from './catalogue-search';

/** The blank a catalogue product is in Pixonaut, as the pick route answers. */
export type PickedBlank = { id: string; text: string; importing: boolean };

/** Wait between reads of a blank Pixonaut is still importing. */
export const IMPORT_POLL_MS = 5000;

/** Most reads of an importing blank: ten minutes, well past an import. */
export const MAX_IMPORT_POLLS = 120;

/** The "Choose from" field; null when the workspace has no catalogue. */
export function catalogueSource(root: ParentNode): HTMLSelectElement | null {
	return root.querySelector<HTMLSelectElement>('.pixonaut-blank-source');
}

/** Whether the picker searches a printshop's catalogue rather than blanks. */
export function searchesCatalogue(source: HTMLSelectElement | null): boolean {
	return source !== null && source.value !== '';
}

/** Where Printed on searches: the chosen printshop's catalogue, else the blanks. */
export function blankSearch(
	root: Pick<HTMLElement, 'dataset'>,
	urls: SearchUrls,
	source: HTMLSelectElement | null,
): SearchRequest {
	return (q): { url: string; data: Record<string, string> } => {
		if (source !== null && searchesCatalogue(source)) {
			return {
				url: root.dataset.catalogueUrl ?? '',
				data: { q, connection: source.value },
			};
		}
		return { url: urls.blanks, data: { q } };
	};
}

/** Shows the catalogue hint only while a catalogue is chosen. */
export function showSourceHint(
	root: ParentNode,
	source: HTMLSelectElement,
): void {
	const hint = root.querySelector<HTMLElement>('.pixonaut-catalogue-hint');
	if (hint) {
		hint.hidden = !searchesCatalogue(source);
	}
}

/**
 * Asks the plugin for the blank a catalogue product is: the one it was
 * imported as, else one Pixonaut imports now. Answers the message to show
 * when it cannot.
 */
export async function pickCatalogueBlank(
	root: Pick<HTMLElement, 'dataset'>,
	connection: string,
	product: string,
	name: string,
): Promise<PickedBlank | { error: string }> {
	const fallback = root.dataset.importFailed ?? '';
	try {
		const response = await fetch(root.dataset.catalogueBlankUrl ?? '', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': root.dataset.restNonce ?? '',
			},
			body: JSON.stringify({ connection, product, name }),
		});
		const answer = (await response.json()) as Partial<PickedBlank> & {
			message?: string;
		};
		if (!response.ok || typeof answer.id !== 'string' || answer.id === '') {
			return { error: answer.message ?? fallback };
		}
		return {
			id: answer.id,
			text: answer.text ?? name,
			importing: answer.importing === true,
		};
	} catch {
		return { error: fallback };
	}
}

/** Shows why a catalogue product could not be picked, or hides it for null. */
export function showImportFailed(
	root: ParentNode,
	message: string | null,
): void {
	const line = root.querySelector<HTMLElement>('.pixonaut-blank-import-failed');
	if (!line) {
		return;
	}
	line.hidden = message === null;
	const text = line.querySelector<HTMLElement>('[role="alert"]');
	if (text) {
		text.textContent = message ?? '';
	}
}

/** Whether the blank's parts say Pixonaut is still importing it. */
export function importingIn(blankHtml: string): boolean {
	return blankHtml.includes('data-importing');
}

/** Whether the blank's parts say Pixonaut's import of it failed. */
export function importFailedIn(blankHtml: string): boolean {
	return blankHtml.includes('data-import-failed');
}

/** Whether the panel shows a blank Pixonaut is still importing. */
export function showsImporting(root: ParentNode): boolean {
	return root.querySelector('.pixonaut-blank-importing') !== null;
}
