---
title: Limitations
description: What Hub does not take into account.
---

## References

- "Unreferenced" entries and assets with "no references" are those no relation or indexed reference tag targets. See [references and edits](/features/references-and-edits/).
- Templates querying them directly, and links by their URL, aren't taken into account.
- The statistics of reference fields, e.g. on section pages, count relations only.

## Snapshots

- The distinct sources and targets of references are counted per site. For several sites they're the sum of each site's, which may count the same element more than once.
- Values that depend on a threshold (like stale entries) or on the time (like pending entries) are kept as they were when the snapshot was taken.
- Stale sections and entry types are told by the current threshold, as of the day of the snapshot.
- Authors, uploaders, file extensions and whether entry types have URLs aren't kept, so those cards have no history.
- Items deleted since are counted in a snapshot's totals, but charted as segments of none of the current ones.

## Fields

- Usage of regular fields is based on field layouts.
- Whether a field is empty is only tracked for nested entry and relation fields.
