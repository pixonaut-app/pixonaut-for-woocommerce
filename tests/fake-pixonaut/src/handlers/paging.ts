// Pixonaut's paged lists: `page` from 1 and `pageSize` rows a page. The
// reference bounds both, and the request is validated before a handler runs.

const FIRST_PAGE = 1;
/** The reference's default `pageSize`. */
const DEFAULT_PAGE_SIZE = 50;

export type Paged<T> = {
	rows: T[];
	total: number;
	page: number;
	pageSize: number;
};

function numberOr(value: string | null, fallback: number): number {
	return value === null ? fallback : Number(value);
}

/** The page of `rows` the query asks for. */
export function pageOf<T>(rows: T[], query: URLSearchParams): Paged<T> {
	const page = numberOr(query.get('page'), FIRST_PAGE);
	const pageSize = numberOr(query.get('pageSize'), DEFAULT_PAGE_SIZE);
	const start = (page - FIRST_PAGE) * pageSize;
	return {
		rows: rows.slice(start, start + pageSize),
		total: rows.length,
		page,
		pageSize,
	};
}
