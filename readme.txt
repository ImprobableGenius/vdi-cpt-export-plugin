=== VDI CPT + ACF Export ===
Contributors: vincentdesign
Tags: export, csv, custom post type, acf, advanced custom fields
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export public post type posts, built-in/custom taxonomies, and scalar Advanced Custom Fields to CSV or JSON from Tools.

== Description ==

Adds **Tools → CPT + ACF Export**. Choose a public post type and download a UTF-8 CSV (with BOM for Excel) containing:

* Core columns: ID, Title, Status, Date
* Scalar ACF fields (text, number, select, checkbox, dates, etc.) when ACF is active and field groups target that post type
* Attached built-in and custom taxonomy assignments as comma-separated term names

Choose JSON for versioned taxonomy definitions, deduplicated terms, ancestors, term metadata, and exact post assignments. JSON defaults to assigned terms plus ancestors, with an option to include all terms in attached taxonomies. Protected term metadata and internal site-management taxonomies are excluded. Non-protected metadata may contain sensitive values; see README.md for the exclusion filter. Custom taxonomy registration code is not exported and there is no importer.

Exports use automatically deleted system temporary files before download, so errors do not yield partial files. Temporary disk space must be available.

Complex ACF types (repeaters, relationships, images, groups, etc.) are skipped in v0.

== Installation ==

1. Zip the `vdi-cpt-acf-export` folder (or download a release zip).
2. In WordPress admin: Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate.
3. Optionally place the folder under `wp-content/mu-plugins/` (load the main PHP file from an MU loader if needed).
4. Install and activate Advanced Custom Fields if you need custom field columns (core columns work without ACF).

== Frequently Asked Questions ==

= Who can export? =

Users with the `export` capability (typically Administrators).

= What if there are no posts? =

You are redirected back to the Tools page with an info notice. An empty CSV is not downloaded.

= What if ACF is missing? =

A warning appears on the Tools page. Export still works for core columns and attached taxonomies.

== Changelog ==

= 0.2.0 =
* Add CSV taxonomy assignment columns and versioned JSON taxonomy exports.
* Include ancestors, term metadata, and optional all-term scope.
* Stage downloads before headers and add regression tests.

= 0.1.0 =
* Initial MVP: Tools UI, field-group discovery, paged CSV stream, fail-closed security.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
