// Faults a test injects per operation: an error status with a code, or an
// answer that does not come in time.
import type { OperationKey } from './spec';

/** How long a `timeout` fault holds the request when the test names no
 *  time: past any client timeout the plugin sets. */
export const DEFAULT_TIMEOUT_FAULT_MS = 60_000;

export type Fault =
	| {
			kind: 'status';
			status: number;
			/** The `error` code of the body; none for a bare status. */
			error?: string;
			/** Further fields of the body beside `error`, such as a cancel's
			 *  `cause` or `retryable`. */
			fields?: Record<string, FaultField>;
			headers?: Record<string, string>;
	  }
	| { kind: 'timeout'; ms?: number };

export type FaultField = string | number | boolean;

type Armed = { fault: Fault; remaining: number | null };

export type Faults = {
	/** Arm `fault` for `operation`'s next `times` requests, or every one. */
	set(operation: OperationKey, fault: Fault, times?: number): void;
	/** The fault the next request to `operation` meets, if any. */
	take(operation: OperationKey): Fault | undefined;
	list(): Record<string, { fault: Fault; remaining: number | null }>;
	clear(): void;
};

export function createFaults(): Faults {
	const armed = new Map<OperationKey, Armed>();
	return {
		set(operation, fault, times) {
			armed.set(operation, { fault, remaining: times ?? null });
		},
		take(operation) {
			const entry = armed.get(operation);
			if (entry === undefined) {
				return undefined;
			}
			if (entry.remaining !== null) {
				entry.remaining -= 1;
				if (entry.remaining <= 0) {
					armed.delete(operation);
				}
			}
			return entry.fault;
		},
		list() {
			return Object.fromEntries(armed);
		},
		clear() {
			armed.clear();
		},
	};
}
