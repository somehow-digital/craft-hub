---
title: Statistics
description: Summary cards that break down what a list or page holds.
---

The index and detail pages of _Data_ and _Structure_ have a **Statistics** tab. Its cards break down what the list or item holds, and link to the filters that show the items behind a number.

Admins can turn the tab off with `enableStatistics`. See [configuration](/guide/configuration/).

## Cards

Cards load their data when you open their panel. A card charts its total and segments over time from the [snapshots](/features/snapshots/) taken. The live numbers stay as they are.

| Page            | Cards                                                                                                             |
| --------------- | ----------------------------------------------------------------------------------------------------------------- |
| **Entries**     | Entries by section, entry type and author, in sections and nested entry fields, recently edited, stale, activity. |
| **Assets**      | Assets by volume, extension and uploader, alternative text coverage, size, with and without references.           |
| **Users**       | Users by status, user group, permissions, two-step verification, admins, sign-ins and idle users.                 |
| **Entry types** | Usage (sections, nested entry, reference and link fields), in use, unused, empty, authors, last edited, activity. |
| **Sections**    | Type, entry types, with URLs, with entries, referenced, authors, last edited, activity.                           |
| **Fields**      | Field types, in use, unused, empty, placements in field layouts, instances per field, data, relations.            |
| **Volumes**     | Types, with URLs, with assets, empty, uploaders, alternative text, references.                                    |

The detail pages of sections, entry types, fields and volumes show the cards of the single item.

## History

Cards show how their numbers changed over the last 30 days, 90 days, a year or all snapshots. See [snapshots](/features/snapshots/) for how they're taken and what they keep.

History starts with the first snapshot.
