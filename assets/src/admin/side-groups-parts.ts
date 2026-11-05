// The parts of a side's Groups setting drawn the same for every item: the
// page's elements, the template's groups read from the plugin's route, and a
// field's words to rename.
import type {
	FieldItem,
	TemplateField,
	TemplateGroup,
} from './form-arrangement';

/** The words the server translated (`GroupsStrings`). */
export type Strings = Record<string, string> & {
	types?: Record<string, string>;
};

/** `raw` read as JSON; `fallback` when it is empty or not JSON. */
export function parse<T>(raw: string | undefined, fallback: T): T {
	try {
		return raw === undefined || raw === '' ? fallback : (JSON.parse(raw) as T);
	} catch {
		return fallback;
	}
}

/** A new `tag` element of class `className`, holding `text`. */
export function element<K extends keyof HTMLElementTagNameMap>(
	tag: K,
	className: string,
	text = '',
): HTMLElementTagNameMap[K] {
	const made = document.createElement(tag);
	made.className = className;
	made.textContent = text;
	return made;
}

/** The template's groups for `templateId`; null when Pixonaut could not be
 *  asked. */
export async function templateGroups(
	url: string,
	nonce: string,
	templateId: string,
): Promise<TemplateGroup[] | null> {
	const route = new URL(url, window.location.href);
	route.searchParams.set('id', templateId);
	try {
		const response = await fetch(route, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return null;
		}
		const answer = (await response.json()) as { groups?: TemplateGroup[] };
		return Array.isArray(answer.groups) ? answer.groups : null;
	} catch {
		return null;
	}
}

/** The words of `item` to rename under More: its help, a text field's
 *  placeholder and each offered value's words, the template's own shown as
 *  each field's placeholder. `changed` is called after each edit. */
export function fieldWords(
	item: FieldItem,
	field: TemplateField | undefined,
	say: (key: string) => string,
	changed: () => void,
): HTMLElement {
	const words = element('div', 'pixonaut-groups__words');
	const text = (key: 'help' | 'placeholder', placeholder: string) => {
		const input = element('input', 'pixonaut-groups__input');
		input.type = 'text';
		input.value = item[key] ?? '';
		input.placeholder = placeholder;
		input.setAttribute('aria-label', say(key));
		input.addEventListener('input', () => {
			item[key] = input.value;
			changed();
		});
		words.append(element('label', '', say(key)), input);
	};
	text('help', field?.help ?? '');
	if (field?.type === 'text') {
		text('placeholder', field.label);
	}
	const options = field?.options ?? [];
	if (options.length > 0) {
		words.append(
			element(
				'span',
				'pixonaut-groups__choices-label',
				say('choices').replace('%s', String(options.length)),
			),
		);
		const list = element('div', 'pixonaut-groups__choices');
		for (const option of options) {
			const input = element('input', 'pixonaut-groups__input');
			input.type = 'text';
			input.value = item.options?.[option] ?? '';
			input.placeholder = option;
			input.setAttribute('aria-label', option);
			input.addEventListener('input', () => {
				item.options = { ...item.options, [option]: input.value };
				changed();
			});
			list.append(input);
		}
		words.append(list);
	}
	return words;
}
