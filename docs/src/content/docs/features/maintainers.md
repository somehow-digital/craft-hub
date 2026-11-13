---
title: Maintainers
description: Name who maintains sections, entry types, fields and volumes.
---

Sections, entry types, fields and volumes can have **maintainers**, so editors know who to ask for help. A maintainer is any number of users, user groups or both.

Maintainers are off by default. Admins switch them on under _Hub → Settings → Maintainers_.

They have no permissions of their own. Besides being shown, they make what they maintain part of their [My Work](/features/my-work/).

## Setting maintainers

Users with the _Manage maintainers_ permission set them on the **Overview** tab of the page of a section, entry type, field or volume.

- They're kept in the database like labels, not in the project config. So they can be changed wherever content is edited, and aren't deployed.
- Changes are recorded in the activity log.

## Where they're shown

- In a **Maintainers** column of the lists of sections, entry types, fields, volumes, entries and assets.
- On the Hub page and the edit sidebar of a user, as what they maintain.
- On entries and assets, by where they're stored:

  | Item             | Maintainers of                                           |
  | ---------------- | -------------------------------------------------------- |
  | **Asset**        | Its volume.                                              |
  | **Entry**        | Its section and entry type.                              |
  | **Nested entry** | Its field and entry type, along with those of its owner. |

- On the Hub pages and edit sidebar of entries and assets, along with those of the fields of their field layouts, for whoever edits them, even without access to the Hub.
