---
title: Checks
description: Diagnostics that flag items to clean up.
---

Checks power the **Diagnostics** tabs. Each flags the items a [saved filter](/features/saved-filters/) of its kind matches, so its count on the index page links to exactly the list of flagged items.

Manage them under _Hub → Settings → Diagnostics_ with the _Manage diagnostic checks_ permission, in a table per kind: entries, assets, users, entry types, sections, fields and volumes.

- Failed checks are listed first, most urgent first.
- Checks of the same severity and passed ones follow the order of the table. Reorder them by dragging.

## Default checks

Hub's own checks. Each flags the items of the predefined filter of the same name, which is shown in their form but can't be changed. Their thresholds, like the months after which entries are stale, are those of their filters.

| Kind            | Checks                                                                                            |
| --------------- | ------------------------------------------------------------------------------------------------- |
| **Entries**     | Unreferenced, stale, with drafts, disabled.                                                       |
| **Assets**      | Unreferenced, images without alternative text, large files, oversized images.                     |
| **Users**       | Locked, admins without two-step verification, idle, pending, suspended, unverified email changes. |
| **Entry types** | Unused, empty.                                                                                    |
| **Sections**    | Empty.                                                                                            |
| **Fields**      | Unused, empty.                                                                                    |
| **Volumes**     | Empty.                                                                                            |

They can't be deleted. You can disable them right in the table, and give them another severity in their form. Disabled checks are shown and computed nowhere.

## Custom checks

Create a check for a kind with:

- the predefined or custom filter whose items it flags
- a name, what's wrong and what to do about it
- optionally a text shown while it passes
- a severity and an icon (that of the filter by default)

A filter can't be deleted while checks flag its items.

## Where they appear

- On the detail pages of items of their kind.
- Counting flagged items, on the _Diagnostics_ tab of the kind's index page.
- Checks of entries are counted on section and entry type pages, and those of assets on volume pages, each linking to the list of flagged items among theirs.
