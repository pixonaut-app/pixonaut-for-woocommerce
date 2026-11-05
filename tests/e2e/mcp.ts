// An MCP client of the tests site's MCP Adapter, signed in as the page's
// user: what an agent setting up a store talks to. It runs the plugin's
// abilities through the adapter's `execute-ability` tool, as a client of the
// default server does.
import type { Page } from '@playwright/test';
import { expect, restUrl } from './fixtures';

/** The MCP Adapter's default server. */
const SERVER_ROUTE = '/mcp/mcp-adapter-default-server';

/** The protocol version this client speaks, sent on every call of a session. */
const PROTOCOL_VERSION = '2025-06-18';

/** The default server's tool that runs any public ability. */
const EXECUTE_TOOL = 'mcp-adapter-execute-ability';

export type McpClient = {
	/** Runs an ability and gives its answer; fails the test when it is refused. */
	run: <T = Record<string, unknown>>(
		ability: string,
		input: Record<string, unknown>,
	) => Promise<T>;
};

/** Opens a session as the user signed in on $page. */
export async function mcpSession(page: Page): Promise<McpClient> {
	const nonce = await (
		await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')
	).text();
	let id = 0;
	const headers: Record<string, string> = {
		'X-WP-Nonce': nonce,
		Accept: 'application/json, text/event-stream',
	};
	const call = async (method: string, params: object) => {
		const response = await page.request.post(restUrl(SERVER_ROUTE), {
			headers,
			data: { jsonrpc: '2.0', id: ++id, method, params },
		});
		expect(response.ok(), `MCP ${method}`).toBe(true);
		return response;
	};

	const opened = await call('initialize', {
		protocolVersion: PROTOCOL_VERSION,
		capabilities: {},
		clientInfo: { name: 'pixonaut-e2e', version: '1' },
	});
	headers['Mcp-Session-Id'] = opened.headers()['mcp-session-id'] ?? '';
	headers['MCP-Protocol-Version'] = PROTOCOL_VERSION;
	expect(headers['Mcp-Session-Id'], 'the server opened a session').not.toBe('');

	return {
		run: async (ability, input) => {
			const answer = await (
				await call('tools/call', {
					name: EXECUTE_TOOL,
					arguments: { ability_name: ability, parameters: input },
				})
			).json();
			const result = answer.result ?? {};
			expect(
				result.isError,
				`${ability}: ${JSON.stringify(result.content ?? answer.error)}`,
			).toBe(false);
			expect(result.structuredContent?.success, ability).toBe(true);
			return result.structuredContent.data;
		},
	};
}
