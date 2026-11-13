---
title: References and edits
description: How Hub tells what is referenced and what is stale.
---

## References

A **reference** is either:

- a relation made by a relation field, like Entries, Assets or Link fields, or
- a reference tag by ID in another field's content, like the links and images of rich text, e.g. `{entry:123@1:url}`.

Being the photo of a user counts as a reference of an asset too, as deleting the asset takes it from them.

Craft keeps no record of reference tags, so Hub indexes them as elements are saved.

| When                                                                | What happens                                                        |
| ------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Hub is installed or updated                                         | The index is built in the queue.                                    |
| Content changes without saving elements, e.g. by _Find and Replace_ | Run `./craft hub/references` (or `./craft hub/references --queue`). |

:::note
Reference tags of drafts and revisions aren't indexed, and neither are tags by anything but an ID, e.g. `{entry:news/hello}`.
:::

## Edits

Craft's update date changes with every save, also those of scripts, imports, propagating entries to other sites or changing their status. So Hub records when entries were **last edited by a person**: saves of signed-in users outside the queue that change an entry's fields or attributes other than its status.

This tells whether entries are stale.

- Entries existing when Hub is installed or updated get the date of their newest revision or change tracked by Craft made by a user, or of their last update if Craft knows neither.
- Entries never edited since fall back to their last update.
