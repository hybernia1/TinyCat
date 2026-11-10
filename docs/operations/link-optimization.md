# One-off link optimization package

This separate maintenance package is for TinyCat 4.8.0 and newer. Its script is
not included in normal update or installation packages and has no web route,
scheduler entry or administration UI. Run it from outside the web root and remove
the extracted package after the optimization window. Keep the private backup.

Preview only (no database writes or metadata/image downloads):

```sh
php optimize-links.php --root=/var/www/tinycat
```

Review `normalized`, `recovered`, `merged`, `orphans` and `uncertain` in the JSON
report. Original post URLs can restore lost `www`, fragments and legacy map URLs
cut at a coordinate apostrophe. A repair is made only when all post references
unambiguously agree. Distinct hosts are never globally equated.

During a maintenance window, apply the plan with a new private backup filename:

```sh
php optimize-links.php --root=/var/www/tinycat --apply --backup=/private/tinycat-links-before.json
```

The tool locks link data on MySQL, creates and verifies a JSON snapshot of links
and their post relations before writing, and commits changes in one transaction.
An existing backup is never overwritten. Any database failure rolls back the
changes; the backup remains available. Use a normal full database backup as well
when operating a production installation. Stop concurrent maintenance and bot
imports for the maintenance window.

Merges preserve post associations and favor cached metadata with an existing local
image. Orphan link records are removed, but image files, post text and bot GUID
history are not changed. Uncertain URL recovery is skipped and reported; ordinary
normalization of those records can still occur. Run the preview again afterwards:
the change lists should be empty, although unresolved `uncertain` cases may remain.

The JSON backup is a data snapshot, not an automatically executed restore script.
Retain it privately for database-assisted recovery. This tool does not refresh
metadata over the network and does not delete itself or backups automatically.
