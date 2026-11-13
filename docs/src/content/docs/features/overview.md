---
title: Overview
description: What Hub adds to the control panel.
---

Hub shows how your content is structured, helps editors decide what can be cleaned up, and lets them clean it up through the bulk actions of its lists.

## Navigation

| Item          | Pages                                                                                   |
| ------------- | --------------------------------------------------------------------------------------- |
| **Data**      | [Entries](/features/entries/), [Assets](/features/assets/), [Users](/features/users/)   |
| **Structure** | [Entry types, sections, fields and volumes](/features/structure/)                       |
| **Hub**       | [My Work](/features/my-work/), the [activity log](/features/activity/) and the settings |

Users only see the pages they may open. See [permissions](/reference/permissions/).

## On every list

Each page of _Data_ and _Structure_ lists its elements on the first tab. Further tabs show **Diagnostics** (the [checks](/features/checks/) concerning them), [statistics](/features/statistics/) and, for entries and assets, the [trash](/features/trash/).

- Search, filter and sort by clicking a column header.
- Filter by diagnostics: the most urgent failed check of each item, or items passing all checks.
- Pick a [saved filter](/features/saved-filters/) or [label](/features/labels/) to narrow the list.
- Select rows for [bulk actions](/features/bulk-actions/).
- The filter bar shows the saved filters, the search and the filters set, each removed by its ×. Add others from the _Filter_ menu.
- The entries, files and users lists are paginated.
- Narrow counts to a single site with the site filter. It's kept for the session.

Lists are filtered, sorted and counted on the server, and the filters are kept in the URL.

## On every detail page

- The item's settings and everything related to it.
- A **Diagnostics** tab: the checks of the item, most urgent first, with what to do about each failed one.
- The item's [labels](/features/labels/), which users who may label it can change right there.
- A panel with its latest activity, for users who may view the activity log.

:::tip
Admins can turn diagnostics off under _Hub → Settings → Diagnostics_. This removes the diagnostics tabs everywhere.
:::

## Edit sidebar

The edit pages of entries, assets, users and user groups, and their slideouts, get a **Hub** panel in the sidebar, for users who may view the item's Hub page. Its sections start collapsed, and their headings sum them up.

| Section                 | Shows                                                                                    |
| ----------------------- | ---------------------------------------------------------------------------------------- |
| **Labels**              | Its labels, changeable through _Edit_ and saved at once.                                 |
| **Diagnostics**         | The checks it fails, most urgent first, or that it passes all of them.                   |
| **Last edited**         | Of entries: when they were last edited by a person, and by whom.                         |
| **References**          | The elements referencing it, with the field type, and the users whose photo an asset is. |
| **Referenced elements** | The elements it references, in its nested entries too.                                   |
| **Maintains**           | Of users: what they maintain. See [maintainers](/features/maintainers/).                 |
| **Activity**            | Its latest activity.                                                                     |

The sections a user expanded stay expanded in that browser. Drafts and revisions show the panel of the element they're of, and revisions show its labels but can't change them.
