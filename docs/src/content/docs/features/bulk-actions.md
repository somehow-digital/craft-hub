---
title: Bulk actions
description: Clean up many items at once.
---

## Selecting

Select rows by their checkbox, or by clicking them, which keeps the other rows selected. Shift selects the rows between the one clicked and the one clicked before.

- The selection is kept per list for the rest of the session, so it survives filtering, sorting and paging. Narrow the list with one set of filters, select, change the filters and select more.
- The **Select** menu selects, unselects or inverts the rows of the page, and selects all items matching the current filters across all pages.
- The bar names how many items are selected and how many of them the current filters leave out.
- Up to 1,000 items can be selected at once.

**Review selection** lists only the selected items, whatever the filters. Items unselected there are faded until the list is loaded again. Actions are offered there and apply to the whole selection, as far as the user may change each item.

:::note
Selecting several items needs the _Bulk-edit …_ permission of the page, and each action its own permission. See [permissions](/reference/permissions/).
:::

## Actions

| Items                          | Actions                                                                                                                                                                                                                        |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **All lists but transforms**   | Label. Checking or unchecking a label adds it to or removes it from all selected items once applied. Partly checked means some items have it. The items stay selected. Offered while labels exist or the user may create some. |
| **Entries**                    | Export · enable or disable · change author (replace or add) · move to another section · delete drafts · delete.                                                                                                                |
| **Assets**                     | Export · download · move to another folder · generate image transforms · delete transforms · delete.                                                                                                                           |
| **Users**                      | Export · add to or remove from groups · suspend or unsuspend · delete (transferring their entries to another user).                                                                                                            |
| **Trashed entries and assets** | Export · restore · restore with relations · delete permanently. See [trash](/features/trash/).                                                                                                                                 |
| **Transforms**                 | Delete. Selected on the asset's page only.                                                                                                                                                                                     |

Sections, entry types, fields, volumes and user groups can only be labeled. They're never deleted through Hub, but on Craft's settings pages.

### Details

- **Change author**: replaces the authors with the user picked, or adds it to them, as far as the user may change authors and the author may author the entries.
- **Move entries**: as Craft does, to sections having their entry type.
- **Delete drafts**: permanent, keeping the unsaved changes of each user.
- **Move assets**: to a folder of any volume the user may save files in, skipping files whose filename the folder already has.
- **Generate transforms**: for the images, in the queue, leaving existing ones as they are.
- **Delete transforms**: they're generated again when next requested.
- **Users**: adding and removing groups is limited to groups the user may assign. Moderating (suspending) and deleting need those permissions. Users can't change their own groups, suspend or delete themselves.

## Previews

Actions show a preview in a dialog first, where you pick what they change the items to and confirm.

### Deleting

Items are moved to the trash, from which they can be restored. The preview tells how many are skipped as the user may not delete them, then shows totals colored by whether items are deleted too, changed or kept.

Each of the first 25 items expands to what deleting it takes along, linked to their pages:

| Items       | Deleting takes along                                                                                                 |
| ----------- | -------------------------------------------------------------------------------------------------------------------- |
| **Entries** | Nested entries and drafts. Children in a structure move up a level. Elements referencing them lose those references. |
| **Assets**  | Generated transforms. Users whose photo they are. Elements referencing them lose those references.                   |
| **Users**   | The number of entries they're an author of, and the user these are transferred to.                                   |

### Other actions

- **Change author, move, folder, transforms, groups**: pick the target in the dialog. Sections name how many entries each takes, and groups how many users are in them.
- **Delete drafts**: lists them by entry.
- **Restore with relations**: lists the trashed elements restored along with each item.

### Exporting

Pick one of Craft's exporters for the element type, or those of plugins, and the format of the formattable ones: CSV, XLSX, JSON, XML or YAML. The items the user may view are exported, which is recorded in the activity log.

## After an action

- Items the action was applied to are unselected.
- Skipped ones stay selected and listed, so they can be reviewed again.
- Once nothing is left selected, the list is shown again with its filters.

## Context menu

Right-click a row to open its context menu: open the item in a new tab, select or unselect it, label it, and run the same actions on that item only. Shift-right-click keeps the browser's own menu.

Users who may not bulk-edit a list have neither checkboxes nor the bar, but the context menu, applying the actions they may apply to one item at a time.
