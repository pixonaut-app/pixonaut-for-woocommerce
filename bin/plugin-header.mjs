// Reads `Name: value` fields from a plugin header or a readme.txt header
// block.

/**
 * The value of `name`, trimmed, or undefined when the field is absent.
 *
 * @param {string} text
 * @param {string} name
 * @returns {string | undefined}
 */
export function header(text, name) {
	const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	return text
		.match(new RegExp(`^[\\s*#@]*${escaped}:[ \\t]*(.*)$`, 'mi'))?.[1]
		?.trim();
}

/** Plugin header fields readme.txt repeats, in the order it lists them. */
export const README_HEADERS = [
	'Requires at least',
	'Tested up to',
	'Requires PHP',
];
