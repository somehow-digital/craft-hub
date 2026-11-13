---
title: Commands
description: Console commands for references and snapshots.
---

Hub adds two console commands. Both can also run in the queue.

## `hub/references`

Indexes the reference tags in the content of all elements again, like the links and images of rich text.

```bash
php craft hub/references
php craft hub/references --queue
```

Run it after changing content without saving elements, e.g. with Craft's _Find and Replace_ utility. See [references and edits](/features/references-and-edits/).

| Option    | Description                 |
| --------- | --------------------------- |
| `--queue` | Index in the queue instead. |

## `hub/snapshots`

Takes the snapshot of the statistics of today, unless there is one already.

```bash
php craft hub/snapshots
php craft hub/snapshots --queue --force
```

Use it from a cron job for snapshots on a fixed schedule. See [snapshots](/features/snapshots/).

| Option    | Description                                     |
| --------- | ----------------------------------------------- |
| `--force` | Replace the snapshot of today, if there is one. |
| `--queue` | Take it in the queue instead.                   |
