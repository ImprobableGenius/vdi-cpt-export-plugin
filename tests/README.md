# Standalone exporter regression tests

From the plugin root, run:

```sh
php tests/run.php
```

Requires PHP CLI 7.4+; no WordPress installation, Composer, PHPUnit, network,
or database is needed. Each test prints `PASS` or `FAIL`, followed by totals.
The process exits with status `0` on success and `1` on any failure. PHP
warnings/notices are treated as failures. Validated on PHP 8.5.9.

## Approach

`wp-stubs.php` provides resettable, in-memory WP taxonomy, term, post, query,
metadata, filter, and ACF fixtures. The runner loads the actual implementation
from `includes/`, invokes private `write_export()` via reflection with a
`tmpfile()` stream, and parses complete CSV/JSON outputs. Temporary streams
are closed even on exceptions. Each case starts with a fresh fixture.

Coverage includes:

- Built-in/private taxonomy discovery, all five internal exclusions, unrelated
  post types, sorted columns, and allowlisted portable definitions.
- Assigned children with unassigned parent records, direct relationships only,
  duplicate assignments, shared terms across posts and 205-post pagination,
  and identical slugs in different taxonomies.
- No taxonomies, no assignments, empty taxonomies, and `all` scope unused/empty
  terms. CSV retains direct assignments under either scope.
- `get_all_terms()` pagination over 205 terms, including an ancestor outside
  the first page; offset advancement, global deduplication, and invalid limits.
- Multiple metadata values and nested arrays, protected keys, taxonomy-aware
  exclusion filters, invalid filter returns, and unsupported metadata errors.
- Relationship query errors, missing/error ancestors, multi-term cycles,
  self-parent cycles, and all-term query errors, with actionable error text.
- CSV BOM, core/ACF column order and values, comma-separated taxonomy names, quotes,
  commas, multiline titles, Unicode, and matching JSON post/ACF values.
- Empty post streams and JSON object shapes for empty ACF/relationships.

## Limits

These are stub regression tests, not WordPress integration tests. They do not
exercise admin permissions, nonces, redirects, HTTP download headers, actual
WP SQL/cache behavior, or real ACF discovery. Error cases assert that
`write_export()` throws; they do not assert that its staged temporary output
is empty, since `stream_export()` is responsible for discarding failed staging
before sending a download. No implementation files are changed by this suite.
