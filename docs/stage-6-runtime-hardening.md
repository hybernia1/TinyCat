# Stage 6 runtime hardening report

Stage 6 audits network fetches, raster decoding, cache/minification and signed
package operations against the archived 2.5 experiment. It keeps the 2.0.25
monolith: no production file, class, repository, service, guard or compatibility
layer was added.

## Network and redirect policy

The combined `LinkMetadata` already retained the useful 2.5 behavior: HTTP and
HTTPS only, public IPv4/IPv6 destinations, rejection when any resolved A or AAAA
record is private/reserved, bounded bodies/timeouts and at most three manually
validated redirects. Both stream and cURL transports keep automatic redirects
disabled, so every `Location` target passes through the same URL and DNS policy.

One 2.5 guard invariant had been lost during the earlier merge: URLs containing
a username or password were accepted. They are now rejected. IPv4-mapped IPv6
addresses are also inspected as IPv4 explicitly, preventing values such as
`::ffff:127.0.0.1` from depending on version-specific filter behavior. These
checks remain private methods of `LinkMetadata`; the 2.5 `Host` and
`PublicUrlGuard` split was not copied.

## Raster upload and decoding policy

Avatar, status-image and site-image uploads now call one small procedural
metadata check before GD decodes untrusted content. It verifies all of the
following together:

- the PHP-reported upload size is positive and exactly matches `filesize()` of
  the temporary upload;
- the actual bytes remain within the owner-specific upload limit;
- `getimagesize()` identifies an allowed MIME type;
- width and height are positive and at most 8192 pixels;
- source pixels are at most 16,777,216, bounding decoded-memory exposure before
  GD allocation.

The dimension/pixel protection already present for avatars and status images is
therefore preserved and now also covers site logos and favicons. JPEG, PNG, GIF
and WebP decoders suppress warnings from malformed inputs and still require a
real `GdImage`. EXIF orientation behavior is preserved; successful rotations
release the replaced source allocation instead of retaining it until request
shutdown. Remote status-link thumbnails retain their existing 5 MiB response,
8192-pixel dimension and 20-million-pixel bounds.

## Cache and minifier comparison

The archived 2.5 `CacheStore` differs from `Cache` only by namespace, class name,
base-path/config lookup and its dependency on `PlatformRuntime`. The archived
`AssetOptimizer` likewise differs from `Minifier` only by namespace, class name
and calls to `CacheStore`. Their algorithms, lazy static initialization, atomic
generated-file writes, Memcached fallback and minification behavior are
otherwise identical. No production change was accepted because importing those
renames/dependencies would add architecture without fixing behavior.

## Signed package operations

The combined package manager remains the owner of core updates, extension
packages, signatures, migrations, backup and rollback. Its existing shared
validation primitive now rejects paths case-insensitively and rejects a file
whose path is also an ancestor of another file. The rule covers core manifests,
deletion lists and extension inventories. This closes deterministic Windows
case collisions and file-versus-directory extraction conflicts before any
package is promoted.

The release rehearsal now proves all of these operational cases:

- a signed artifact upgrades an exact archived `v2.0.25` tree;
- applying the same signed artifact again leaves the candidate inventory
  unchanged and creates no backup copies for unchanged files;
- an interruption after maintenance state is persisted is detected by a new
  process and can be recovered explicitly;
- rollback restores the byte-exact 2.0.25 managed inventory and boot;
- a fresh candidate artifact boots independently;
- the disposable MySQL installer creates the product schema, reconnects,
  repeats every idempotent step and removes its database after 14 checks.

## Verification and decision

`tests/runtime-hardening.php` runs 23 URL/raster checks, including private and
reserved IPv4/IPv6 literals, credentials, IPv4-mapped loopback, verified-size,
MIME, malformed input and pixel-memory cases. Package regressions cover core and
extension collision forms. The PHPStan level-8 baseline shrank by three obsolete
entries to 733 findings.

The complete preflight passes 20 groups: deterministic style for 157 PHP files,
syntax lint for 158 files, zero new PHPStan errors, repository security, route
and presentation contracts, query budgets `3/6/4/8/7`, cache/minifier tests,
signed package/update/rollback rehearsals and the real disposable MySQL
installer. `App/` remains 18 files and 15 class-bearing files.

Accept Stage 6. The proven 2.5 security and operational behavior is now folded
into existing monolithic owners without reintroducing its runtime dependency
tree. Performance-scale work remains Stage 7.
