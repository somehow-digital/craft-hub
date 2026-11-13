---
title: Permissions
description: Which permissions Hub adds and how they apply.
---

Hub's permissions are granted to user groups or users along with Craft's _Access Hub_ permission.

There are two primary ones, _Access data_ and _Access structure_. Each has a permission per page nested under it, which has the permissions of what can be done with the page's items nested under it in turn.

## Permissions

| Permission                   | Allows                                                                                 |
| ---------------------------- | -------------------------------------------------------------------------------------- |
| **View my work**             | _Hub → My Work_ and its dashboard widget.                                              |
| **View the activity log**    | _Hub → Activity_.                                                                      |
| **Manage labels**            | Creating, changing, ordering and deleting labels.                                      |
| **Manage maintainers**       | Setting who maintains the sections, entry types, fields and volumes the user may view. |
| **Manage diagnostic checks** | The default and custom checks of the diagnostics tabs.                                 |
| **Manage saved filters**     | The predefined and custom filters the lists offer to everyone.                         |

### Access data

| Page                | Page permission        | Permissions nested under it                                                                                             |
| ------------------- | ---------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| **Entries**         | _View entries_         | Label · Bulk-edit · Export · Enable and disable · Change the authors · Move to another section · Delete drafts · Delete |
| **Trashed entries** | _View trashed entries_ | Export · Restore · Delete permanently                                                                                   |
| **Assets**          | _View assets_          | Label · Bulk-edit · Export · Download · Move to another folder · Generate transforms · Delete transforms · Delete       |
| **Trashed assets**  | _View trashed assets_  | Export · Restore · Delete permanently                                                                                   |
| **Users**           | _View users_           | Label · Bulk-edit · Export · Change the groups · Suspend (and unsuspend) · Delete                                       |

_View users_ also covers the user groups tab and the users' personal data. Trashed pages are nested under their page.

### Access structure

_View entry types_, _View sections_, _View fields_ and _View volumes_ open their pages below _Structure_. Each has _Label …_ and _Bulk-edit …_ nested under it, e.g. _Label fields_.

## How they apply

- A page needs both the permission of its area and its own.
- _Bulk-edit …_ selects several items at once. Without it, actions apply to one item at a time, from its context menu.
- Craft's own permissions still apply to the actions: saving or deleting an entry, moving it to a section, changing the authors of peer entries, assigning a user group, and moderating or deleting users.
- Admins have all permissions.

## What users see

Hub shows no more than Craft does. Lists, detail pages and references only hold the entries of sections and the assets of volumes the user may view in Craft, peer entries and assets included, and nested entries by the entry owning them.

Statistics, the structure pages and the activity log aren't limited by them, as they have permissions of their own.

The navigation only shows pages the user may view, and links to pages they may not open are shown as plain text. Links to Craft's native settings pages are only shown to admins.
