# Archive index fixtures

Indexes of one product category, and what the server-rendered archive lists
for each address, for a theme that filters archives in the browser to test
against. `tests/php/Integration/Catalogue/ArchiveIndexTest.php` checks every
address against the server's own listing and writes these files:

```sh
node bin/php.mjs --hpos=off env PIXONAUT_ARCHIVE_FIXTURES=/tmp/archive-index vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ArchiveIndexTest
docker cp <tests-cli container>:/tmp/archive-index/. tests/fixtures/archive-index/
pnpm exec biome format --write tests/fixtures/archive-index
```

Each case is a pair:

- `<case>.index.json`: the index, as `GET /pixonaut/v1/archive-index/<archive>`
  answers it.
- `<case>.expected.json`: one entry per address: `query` (the address's
  arguments as the shop writes them: a filter by its key, `a,b` for several
  values, `min_price`, `max_price`, `orderby`), and what the archive shows
  there: `cards` (card ids, in order), `links` (each card's link, its
  arguments in any order), `images` (each card's picture's `src`) and, for
  filtered addresses, `counts` (by filter key and value id: the cards shown
  with that value added).

The cases: three posters (Safari, Skog, Hav) with a size, a framing (none,
frame, hanger) and a frame colour that applies only to frames and hangers,
listed once (`listed-once`), one card per framing (`split-framing`), per
framing only while a framing is filtered (`split-framing-while-filtered`),
per colour (`split-colour`), per framing and per colour only while filtered
(`split-framing-colour-while-filtered`), with a picture of each framed
variation (`split-framing-pictures`, `split-framing-while-filtered-pictures`),
and beside simple, grouped, on-sale and sold-out products
(`simple-grouped-sold-out`, and with sold-out products hidden,
`simple-grouped-sold-out-hidden`).

The rules that turn an index and an address into these lists are the
contract of pixonaut-app/pixonaut-for-woocommerce#267; the tests apply them
in `tests/php/Support/ArchiveIndexClient.php`.
