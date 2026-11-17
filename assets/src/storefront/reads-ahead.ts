import type { SplitMountOptions } from '../types/sdk';
import { MS_PER_S, READS_MAX_AGE_S, type SideReads } from './server-reads';

/** The mount options a side's reads are made for (`ReadsAhead::TARGET_KEYS`). */
const TARGET_KEYS = ['templateId', 'blankId', 'placementId'] as const;

/** What a side's reads were made for. */
export type ReadTarget = Partial<
	Pick<SplitMountOptions, (typeof TARGET_KEYS)[number]>
>;

/** One target's reads, as the server answers them. */
type AheadRead = ReadTarget & { reads: SideReads };

/** The longest the ask waits for the page to fall idle once the designer
 *  is shown: the reads are wanted before the shopper's next switch. */
const IDLE_TIMEOUT_MS = 2000;

/**
 * The server's reads for the sides the product's variations mount on another
 * template, blank or placement than the page's own (`ReadsAhead`), asked for
 * once the page is idle: a switch to such a variation then mounts its side
 * from them, with no read of its own to wait for.
 */
export class ReadsAhead {
	private reads: AheadRead[] = [];

	private asked = false;

	/** When the reads came, on `Date.now()`'s clock. */
	private readAt = 0;

	constructor(
		private readonly url: string | undefined,
		private readonly productId: number,
		private readonly fetcher: typeof fetch = (input, init) =>
			fetch(input, init),
		private readonly now: () => number = () => Date.now(),
	) {}

	/** Asks for the reads of every target but those in `printed`, which the
	 *  page has reads of its own for, once, when the page falls idle; again
	 *  once they grew too old. A failed ask leaves each switch to read as it
	 *  did. */
	ask(printed: readonly ReadTarget[]): void {
		if (this.asked || this.url === undefined || this.url === '') {
			return;
		}
		this.asked = true;
		whenIdle(() => {
			void this.fetchReads(this.url as string, printed);
		});
	}

	/** The reads to mount `options` from, and what they were read for:
	 *  `printed` when they were made for its template, blank and placement,
	 *  else the reads asked ahead for them; undefined for none. */
	readsFor(
		options: ReadTarget,
		printed: SideReads | undefined,
		printedFor: ReadTarget | undefined,
	): { reads: SideReads; readFor: ReadTarget } | undefined {
		if (
			printed !== undefined &&
			printedFor !== undefined &&
			sameTarget(options, printedFor)
		) {
			return { reads: printed, readFor: printedFor };
		}
		// Their photo links open for a while only, as the page's own reads'.
		if (
			this.reads.length > 0 &&
			(this.now() - this.readAt) / MS_PER_S > READS_MAX_AGE_S
		) {
			this.reads = [];
			this.asked = false;
		}
		const ahead = this.reads.find((read) => sameTarget(options, read));
		if (ahead === undefined) {
			return printed === undefined || printedFor === undefined
				? undefined
				: { reads: printed, readFor: printedFor };
		}
		const { reads, ...readFor } = ahead;
		return { reads, readFor };
	}

	private async fetchReads(
		url: string,
		printed: readonly ReadTarget[],
	): Promise<void> {
		try {
			const asked = new URL(url, window.location.href);
			asked.searchParams.set('product', String(this.productId));
			for (const target of printed) {
				asked.searchParams.append('printed[]', targetKey(target));
			}
			const response = await this.fetcher(asked, {
				credentials: 'same-origin',
			});
			if (!response.ok) {
				return;
			}
			const body: unknown = await response.json();
			const reads = (body as { reads?: unknown } | null)?.reads;
			this.reads = Array.isArray(reads) ? (reads as AheadRead[]) : [];
			this.readAt = this.now();
		} catch {
			// Each switch reads for itself, as without the reads.
		}
	}
}

/** A target's key as the server names it (`ReadsAhead::key()`). */
function targetKey(target: ReadTarget): string {
	return TARGET_KEYS.map((key) => target[key] ?? '').join('|');
}

function sameTarget(first: ReadTarget, second: ReadTarget): boolean {
	return TARGET_KEYS.every((key) => first[key] === second[key]);
}

function whenIdle(run: () => void): void {
	if (typeof requestIdleCallback === 'function') {
		requestIdleCallback(run, { timeout: IDLE_TIMEOUT_MS });
	} else {
		setTimeout(run, IDLE_TIMEOUT_MS);
	}
}
