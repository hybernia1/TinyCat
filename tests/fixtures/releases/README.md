# Frozen compatibility source

`bridge-6.0.0-dev.9.1-source.zip` freezes the last shared-publication runtime
needed to reach the domain-owned storage model. It is a test input, not another
active application or a distributed release. SHA-256:

```text
9620cebe0f1fb03d6a49673ebc3daef43a54e53800ffdaedc1bdb660cfeb3df5
```

The 979 source files occupy about 1.26 MB compressed. They contain runtime,
assets, translations, immutable migrations and only the build helpers needed
to construct a temporary signed bridge. Configuration, signing keys, storage,
uploads, development dependencies, tests and generated assets are excluded.

The existing update rehearsal builds it using an ephemeral test signing key,
checks the legacy 5.16 → dev.9.1 file transition, then exercises dev.9.1 → current,
repetition and exact rollback. Domain DDL and data preservation remain in the
existing Blog/MySQL and module lifecycle fixtures; there is no second test matrix.
Keep this fixture immutable while this update path is supported. A tagged
historical source may replace the archive once that tag is part of the repository.
