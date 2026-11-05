// Tests reach local fakes only: fetch to any other host fails before a
// request leaves the machine.
const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

const realFetch = globalThis.fetch;

globalThis.fetch = (input, init) => {
	const url = new URL(input instanceof Request ? input.url : String(input));
	if (!LOOPBACK_HOSTS.has(url.hostname)) {
		return Promise.reject(new Error(`Tests may not reach ${url.origin}.`));
	}
	return realFetch(input, init);
};
