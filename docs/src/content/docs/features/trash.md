---
title: Trash
description: Restore deleted entries and assets.
---

While `enableTrash` is on (see [configuration](/guide/configuration/)), the _Entries_ and _Assets_ pages have a **Trash** tab listing what was deleted, with when and by whom, as recorded in the activity log.

## Permissions

| Action             | Entries                      | Assets                      |
| ------------------ | ---------------------------- | --------------------------- |
| View the trash     | _View trashed entries_       | _View trashed assets_       |
| Restore            | _Restore entries_            | _Restore assets_            |
| Delete permanently | _Delete entries permanently_ | _Delete assets permanently_ |

Craft's own rules still apply.

:::note
Entries deleted along with their owner are restored with it, so they aren't listed. Neither are assets deleted along with their volume.
:::

## Restore with relations

Restores the items along with the trashed entries and assets related to them by relation fields, like Entries and Assets fields: those they relate to and those relating to them, by their nested entries too.

The related ones are restored as far as the user may restore entries or assets and Craft lets them. Assets are only restored if their file was kept.

## Asset files

Craft deletes the file of an asset as it's moved to the trash, after which the asset can't be restored.

With `keepTrashedFiles` on, Hub has Craft keep the files of all assets moved to the trash, wherever they're deleted, so they can be restored. The _File_ column of the trash tells whether an asset's file was kept.

:::caution
It's off by default, as the files of public volumes stay reachable by their URLs until they're deleted.
:::

Once an asset is deleted permanently, its file is deleted too:

- right away, or
- for assets Craft's garbage collection deletes after its `softDeleteDuration`, along with Craft's garbage, unless another asset has taken its place since.
