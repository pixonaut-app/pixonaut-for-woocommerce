// Renders the generated sections of the developer references from the data
// bin/docs/reference-data.php collects. Each renderer returns Markdown for
// the space between a page's BEGIN and END GENERATED markers.

/**
 * @typedef {{ type: string, name: string, description: string }} HookParam
 * @typedef {{
 *   name: string, type: 'action' | 'filter', deprecated: boolean, file: string,
 *   line: number, args: number | null, documented: boolean, summary: string,
 *   description: string, since: string, params: HookParam[], example: string,
 *   deprecation: string,
 * }} Hook
 * @typedef {{ old: string, new: string, version: string, type: 'action' | 'filter' }} RenamedHook
 * @typedef {{ text: string, shown: string }} Label
 * @typedef {{
 *   key: string, default: unknown, type: string, title: string,
 *   description: string, options: Record<string, string> | unknown[],
 * }} Setting
 * @typedef {{ id: string, title: string, description: string, customer: boolean, template: string }} Email
 * @typedef {{ route: string, methods: string[] }} RestRoute
 * @typedef {{ name: string, version: string, override: string, summary: string }} Template
 * @typedef {{
 *   hooks: Hook[], renamed: RenamedHook[], labels: Record<string, Label>,
 *   settings: Setting[], emails: Email[], rest_routes: RestRoute[],
 *   templates: Template[],
 * }} ReferenceData
 */

/**
 * Escapes text for a Markdown table cell.
 *
 * @param {string} text
 */
export function cell(text) {
	return String(text).replaceAll('|', '\\|').replaceAll('\n', ' ');
}

/**
 * Inline code for a table cell.
 *
 * @param {string} text
 */
function code(text) {
	return `\`${cell(text)}\``;
}

/**
 * @param {string[]} header
 * @param {string[][]} rows
 */
function table(header, rows) {
	return [
		`| ${header.join(' | ')} |`,
		`|${header.map(() => '---|').join('')}`,
		...rows.map((row) => `| ${row.join(' | ')} |`),
	].join('\n');
}

/** @param {Hook} hook */
function renderHook(hook) {
	const parts = [`### \`${hook.name}\``, hook.summary];
	if (hook.description !== '') {
		parts.push(hook.description);
	}
	if (hook.deprecation !== '') {
		parts.push(`**Deprecated** ${hook.deprecation}`);
	}
	if (hook.params.length > 0) {
		parts.push(
			table(
				['Parameter', 'Type', 'Description'],
				hook.params.map(({ name, type, description }) => [
					code(name),
					code(type),
					cell(description),
				]),
			),
		);
	}
	parts.push(`Since ${hook.since}. Fired in \`${hook.file}\`.`);
	if (hook.example !== '') {
		parts.push(`\`\`\`php\n${hook.example}\n\`\`\``);
	}
	return parts.join('\n\n');
}

/**
 * @param {string} heading
 * @param {Hook[]} hooks
 */
function renderHookGroup(heading, hooks) {
	const body =
		hooks.length === 0 ? 'None yet.' : hooks.map(renderHook).join('\n\n');
	return `## ${heading}\n\n${body}`;
}

/** @param {ReferenceData} data */
export function renderHooks(data) {
	const current = data.hooks.filter((hook) => !hook.deprecated);
	return [
		renderHookGroup(
			'Filters',
			current.filter((hook) => hook.type === 'filter'),
		),
		renderHookGroup(
			'Actions',
			current.filter((hook) => hook.type === 'action'),
		),
	].join('\n\n');
}

/** @param {ReferenceData} data */
export function renderDeprecatedHooks(data) {
	const renamed = data.renamed.map((hook) => [
		code(hook.old),
		hook.type,
		hook.version,
		`Renamed to ${code(hook.new)}`,
	]);
	const retired = data.hooks
		.filter((hook) => hook.deprecated)
		.map((hook) => [code(hook.name), hook.type, '', cell(hook.deprecation)]);
	const rows = [...renamed, ...retired];
	if (rows.length === 0) {
		return 'No hook is deprecated.';
	}
	return table(['Hook', 'Type', 'Deprecated in', 'Instead'], rows);
}

/** @param {ReferenceData} data */
export function renderLabelKeys(data) {
	return table(
		['Key', 'Default text', 'Shown'],
		Object.entries(data.labels).map(([key, { text, shown }]) => [
			code(key),
			cell(text),
			cell(shown),
		]),
	);
}

/** @param {unknown} value */
function settingValue(value) {
	if (value === '') {
		return 'empty';
	}
	return code(typeof value === 'string' ? value : JSON.stringify(value));
}

/** @param {Setting} setting */
function renderSetting(setting) {
	const parts = [
		`### \`${setting.key}\``,
		`**${setting.title}** (${setting.type}). ${setting.description}`,
		`Default: ${settingValue(setting.default)}.`,
	];
	const options = Object.entries(setting.options);
	if (options.length > 0) {
		parts.push(
			table(
				['Value', 'Shown as'],
				options.map(([value, label]) => [code(value), cell(String(label))]),
			),
		);
	}
	return parts.join('\n\n');
}

/** @param {ReferenceData} data */
export function renderSettings(data) {
	if (data.settings.length === 0) {
		return 'The plugin has no settings yet.';
	}
	return data.settings.map(renderSetting).join('\n\n');
}

/** @param {ReferenceData} data */
export function renderEmails(data) {
	if (data.emails.length === 0) {
		return 'The plugin adds no emails yet.';
	}
	return table(
		['ID', 'Email', 'Sent to', 'Template', 'Description'],
		data.emails.map((email) => [
			code(email.id),
			cell(email.title),
			email.customer ? 'Customer' : 'Store',
			code(email.template),
			cell(email.description),
		]),
	);
}

/** @param {ReferenceData} data */
export function renderRestRoutes(data) {
	if (data.rest_routes.length === 0) {
		return 'The plugin registers no REST routes yet.';
	}
	return table(
		['Route', 'Methods'],
		data.rest_routes.map(({ route, methods }) => [
			code(route),
			methods.join(', '),
		]),
	);
}

/** @param {ReferenceData} data */
export function renderTemplates(data) {
	if (data.templates.length === 0) {
		return 'The plugin ships no templates yet.';
	}
	return table(
		['Template', 'Version', 'Override in the theme', 'Description'],
		data.templates.map(({ name, version, override, summary }) => [
			code(name),
			version,
			code(override),
			cell(summary),
		]),
	);
}
