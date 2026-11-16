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

The compatibility bridge is retired now that the supported update baseline is
6.0.4. This archive is retained as historical evidence; current rehearsals use
the published `v6.0.4` tag directly and do not build bridge packages.
