# VDI CPT + ACF Export

WordPress plugin (v0.2.0) that exports public post type posts, attached taxonomies, and scalar [Advanced Custom Fields](https://www.advancedcustomfields.com/) values to CSV or JSON from **Tools → CPT + ACF Export**.

Greenfield MVP — no Composer, no XLSX, no WP-CLI, no AJAX.

## Purpose

Agency-owned CSV export without paid WP All Export Pro. Produces a spreadsheet-friendly CSV with core post columns, scalar ACF fields discovered from field groups (not from the first post alone), and taxonomy assignments. Versioned JSON additionally preserves portable taxonomy definitions, deduplicated terms, ancestors, metadata, and relationships.

## Install

1. **Zip upload:** Zip the `vdi-cpt-acf-export` directory → Plugins → Add New → Upload Plugin → Activate.
2. **MU-plugin:** Copy the folder under `wp-content/mu-plugins/` and require `vdi-cpt-acf-export.php` from a small MU loader, or symlink as needed.
3. Activate **Advanced Custom Fields** if you need custom field columns (core columns export without ACF).

Requires WordPress 6.0+ and PHP 7.4+.

## Usage

1. Go to **Tools → CPT + ACF Export**.
2. Select a public post type.
3. Choose **CSV** (default) or **JSON**.
4. For JSON, choose assigned terms plus ancestors (default), or all terms in attached taxonomies.
5. Click **Download export**.

Filename pattern: `{post_type}-export-{Y-m-d}.{csv|json}` (site timezone via `current_time`).

### CSV columns

1. **ID**, **Title**, **Status**, **Date**
2. Then ACF columns in field-group discovery order, using each field’s **label** (falls back to field name)
3. Then `Taxonomy: {taxonomy_name}` columns in taxonomy-name order. Each cell contains directly assigned term **names**, separated by a comma and space (for example, `News, Events`); no assignments produces an empty cell. Standard CSV quoting keeps the list within one column and preserves quotes and Unicode. Names containing commas are preserved as-is, so splitting the cell on commas is ambiguous; use the JSON export when exact term identities are needed.

### JSON taxonomy export (schema version 1)

Top-level fields: `schema_version`, `post_type`, `term_scope`, `acf_fields`, `taxonomies`, `posts`, and `terms`.

- `taxonomies`: descriptive allowlisted definitions including names, labels, hierarchy, the selected object type, public/UI/REST settings, and portable rewrite settings. Callbacks and registration code are not exported.
- `posts`: core `ID`, `Title`, `Status`, `Date`, flattened `acf` values keyed by field name, and `taxonomies` mapping taxonomy names to direct term references.
- `terms`: deduplicated records with `taxonomy`, `source_term_id`, `name`, `slug`, `description`, `parent` (reference or `null`), and `meta` (keys with arrays of values, preserving multiple/structured values).
- References are `{ "taxonomy": "category", "source_term_id": 123 }`. Source IDs are not destination IDs. Identical slugs in different taxonomies remain distinct.
- Assigned scope includes ancestor records but does **not** add ancestor assignments to posts. All scope also includes unused/empty terms, fetched in pages.

Both formats discover taxonomies attached to the selected post type, including built-in categories, tags, post formats, and private custom taxonomies. Site-management taxonomies `nav_menu`, `link_category`, `wp_theme`, `wp_template_part`, and `wp_pattern_category` are excluded. Unattached taxonomies are never exported. ACF taxonomy fields remain skipped; taxonomy assignments come from WordPress relationships, not ACF metadata.

Protected term metadata (normally underscore-prefixed keys) is excluded. Other metadata can contain sensitive data; review it before sharing exports. Exclude additional keys with:

```php
add_filter( 'vdi_cpt_acf_export_excluded_term_meta_keys', function ( $keys, $taxonomy ) {
    $keys[] = 'private_api_token';
    return $keys;
}, 10, 2 );
```

Objects/resources in non-excluded metadata, invalid ancestry, and taxonomy query/JSON encoding failures stop the export with an error notice. Downloads are staged in automatically deleted system temporary files before response headers, not stored under the web root. This requires temporary disk space proportional to the output (JSON uses an additional term stream); memory holds a post/term batch plus deduplication keys, not the full document. This is not a snapshot: concurrent content edits can affect paged results.

There is no importer. A future importer must register destination custom taxonomies separately, map source term IDs, restore parents, and assign direct relationships; definitions do not recreate plugin PHP behavior.

### Notices / edge cases

| Situation | Behavior |
| --- | --- |
| ACF inactive | Warning on Tools page; core and taxonomy columns still export |
| No scalar field groups for public CPTs | Warning on Tools page; core and taxonomy columns still export |
| Selected CPT has no matching groups | Core and taxonomy columns are exported |
| Zero matching posts | Redirect + info notice “No posts matched.” — no empty CSV |
| No public post types | Info notice; Download button disabled |
| Missing capability / bad nonce / invalid CPT | Redirect + dismissible error notice (fail closed) |

## Security

* Capability: `export`
* Nonce: `wp_nonce_field` / `wp_verify_nonce` on action `vdi_cpt_acf_export` (soft verify so failures redirect with a plain-language notice)
* Post type: `sanitize_key` + whitelist against `get_post_types( ['public' => true] )`
* Output: validated system temporary streams, then download — no export files written under the web root

## v0 limits

* Scalar-friendly ACF types only: text, textarea, number, range, email, url, password, wysiwyg, select, radio, button_group, true_false, date_picker, date_time_picker, time_picker, checkbox (comma-joined)
* Skipped (no JSON yet): repeater, flexible_content, group, clone, relationship, post_object, page_link, user, taxonomy, image, file, gallery, link, google_map, oembed, accordion, tab, message, etc.
* Wysiwyg values are tag-stripped
* No field checklist UI, status filters, XLSX, CLI, or AJAX progress (v1+)

## Next steps (v1+)

* Optional field checklist and post-status filters
* Repeater / flexible content as JSON (or wide columns)
* Image / relationship flattening (ID or URL)
* Paged AJAX or WP-CLI for very large datasets
* Re-import is out of scope unless requested

## Architecture

```
vdi-cpt-acf-export/
  vdi-cpt-acf-export.php          # Bootstrap, constants, plugins_loaded boot
  includes/
    class-admin-page.php          # Tools menu, form, transient notices
    class-field-discovery.php     # acf_get_field_groups / acf_get_fields
    class-exporter.php            # admin_post handler, paged WP_Query, CSV/JSON writer
    class-taxonomy-export.php     # definitions, batched assignments, terms/ancestors
  readme.txt                      # WordPress.org-style stub
  README.md                       # Developer notes (this file)
```

* **Field discovery:** `acf_get_field_groups( ['post_type' => ...] )` + `acf_get_fields()` — stable columns even when posts have empty meta
* **Values:** `get_field( $name, $post_id )` then flatten scalars / checkbox arrays
* **Query:** pages of 200, `post_status => any`, `orderby => ID ASC`
* **Encoding:** UTF-8 with BOM (`\xEF\xBB\xBF`) for Excel

## Validation

Run `php tests/run.php` for standalone WordPress-stub regression tests (no dependencies). These cover CSV/JSON output, discovery, ancestry, metadata, term scope, pagination, deduplication, and failure paths. They do not replace integration testing in WordPress with ACF and real custom taxonomies.

## License

GPLv2 or later (WordPress plugin norms).
