# VDI CPT + ACF Export

WordPress plugin (v0.1.0) that exports public custom post type posts and scalar [Advanced Custom Fields](https://www.advancedcustomfields.com/) values to CSV from **Tools → CPT + ACF Export**.

Greenfield MVP — no Composer, no XLSX, no WP-CLI, no AJAX.

## Purpose

Agency-owned CSV export without paid WP All Export Pro. Streams a spreadsheet-friendly file with core post columns plus scalar ACF fields discovered from field groups (not from the first post alone).

## Install

1. **Zip upload:** Zip the `vdi-cpt-acf-export` directory → Plugins → Add New → Upload Plugin → Activate.
2. **MU-plugin:** Copy the folder under `wp-content/mu-plugins/` and require `vdi-cpt-acf-export.php` from a small MU loader, or symlink as needed.
3. Activate **Advanced Custom Fields** if you need custom field columns (core columns export without ACF).

Requires WordPress 6.0+ and PHP 7.4+.

## Usage

1. Go to **Tools → CPT + ACF Export**.
2. Select a public post type.
3. Click **Download CSV**.

Filename pattern: `{post_type}-export-{Y-m-d}.csv` (site timezone via `current_time`).

### CSV columns

1. **ID**, **Title**, **Status**, **Date**
2. Then ACF columns in field-group discovery order, using each field’s **label** (falls back to field name)

### Notices / edge cases

| Situation | Behavior |
| --- | --- |
| ACF inactive | Warning on Tools page; core columns still export |
| No scalar field groups for public CPTs | Warning on Tools page; core columns still export |
| Selected CPT has no matching groups | CSV contains core columns only |
| Zero matching posts | Redirect + info notice “No posts matched.” — no empty CSV |
| No public post types | Info notice; Download button disabled |
| Missing capability / bad nonce / invalid CPT | Redirect + dismissible error notice (fail closed) |

## Security

* Capability: `export`
* Nonce: `wp_nonce_field` / `wp_verify_nonce` on action `vdi_cpt_acf_export` (soft verify so failures redirect with a plain-language notice)
* Post type: `sanitize_key` + whitelist against `get_post_types( ['public' => true] )`
* Output: stream only via `php://output` — no CSV written under the web root

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
    class-exporter.php            # admin_post handler, paged WP_Query, CSV stream
  readme.txt                      # WordPress.org-style stub
  README.md                       # Developer notes (this file)
```

* **Field discovery:** `acf_get_field_groups( ['post_type' => ...] )` + `acf_get_fields()` — stable columns even when posts have empty meta
* **Values:** `get_field( $name, $post_id )` then flatten scalars / checkbox arrays
* **Query:** pages of 200, `post_status => any`, `orderby => ID ASC`
* **Encoding:** UTF-8 with BOM (`\xEF\xBB\xBF`) for Excel

## License

GPLv2 or later (WordPress plugin norms).
