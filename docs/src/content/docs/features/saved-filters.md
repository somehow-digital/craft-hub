---
title: Saved filters
description: Save and reuse list filters.
---

The lists of _Data_ and _Structure_ offer saved filters at the start of their filter bar. Picking one sets the filters it saved, keeping the sorting, and loads the results. You can change the filters it sets from there. The active one is checked and named on the button.

## Kinds

| Kind                   | Who creates it                    | Managed                    | Can be                                      |
| ---------------------- | --------------------------------- | -------------------------- | ------------------------------------------- |
| **Predefined filters** | Hub                               | _Hub → Settings → Filters_ | Disabled, reordered, renamed.               |
| **Custom filters**     | Users with _Manage saved filters_ | _Hub → Settings → Filters_ | Created, changed, deleted.                  |
| **My filters**         | Each user                         | Right in the list          | Saved, renamed, deleted. Only you see them. |

Predefined and custom filters are offered to everyone who may view the list. Managing them needs the _Manage saved filters_ permission. Disabled filters aren't offered in the filter bar, though checks still flag their items.

### Predefined filters

Examples: _My entries_, _My drafts_, _Unreferenced entries_, _Entries with drafts_, _Unreferenced assets_, _Images without alternative text_, _Admins without two-step verification_, _Unused fields_, _Empty sections_.

They can't be deleted. Those with a threshold, like _Stale entries_, _Large files_, _Oversized images_, _Idle users_, _Stale entry types_ and _Stale sections_, can be given another one in their form.

Thresholds and their defaults:

| Filter                                                 | Default   | Flags                                                       |
| ------------------------------------------------------ | --------- | ----------------------------------------------------------- |
| _Stale entries_, _Stale entry types_, _Stale sections_ | 12 months | Entries no person has edited within this many months.       |
| _Idle users_                                           | 12 months | Active users who haven't signed in within this many months. |
| _Large files_                                          | 5 MB      | Files larger than this.                                     |
| _Oversized images_                                     | 4000 px   | Images wider or taller than this.                           |

### Custom filters

Created for a list, with a name, an optional description shown as tooltip and an icon.

Their **Matching** condition narrows the list to the items it matches:

- **Entries, assets and users** use Craft's own conditions, including those on custom fields, e.g. entries whose _Image_ field is empty.
- **Entry types, sections, fields and volumes** use Hub's: name, handle, type, usage, URLs, number of entries or assets, fields in the layout, instructions and the like.

The condition applies along with the list's other filters, and is kept in the URL as `?filter=<uid>`.

### My filters

Save the filters a list shows under a name of your own. Saving under the name of one of your filters replaces its filters. They need no permission but that of viewing the list.

## Relative filters

The filters by users (the author, who last edited entries, the uploader of assets) offer **Me** first, the user viewing the list. It's kept in the URL as `me`, e.g. `?author=me`.

Saved filters keep it as it is, so one filter lists each user's own items. The predefined _My entries_, _Last edited by me_, _My drafts_ and _My uploads_ do, and so does any custom filter or check setting them.

:::note
Without a signed-in user, e.g. on the command line, `me` matches no one.
:::
