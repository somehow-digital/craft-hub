<img src="src/icon.svg" alt="Hub Logo" width="96" height="96" />

# `Hub` for Craft CMS
> Content Overview for [Craft CMS](https://craftcms.com/).

## Requirements

* Craft CMS 5.11.0 or later.
* PHP 8.2 or later.

## Installation

Install this plugin from the Plugin Store or via Composer.

#### Plugin Store

Go to the “Plugin Store” in your project’s Control Panel, search for
“hub” and click on the “Install” button in its modal window.

#### Composer

```sh
composer require somehow-digital/craft-hub
./craft plugin/install hub
```

## Features

An overview of the content structure, which helps editors decide what can be cleaned up, and clean it up through the
bulk actions of its lists.

Hub adds two navigation items, *Data* and *Structure*. Each of their pages lists its elements on the first tab and
shows the diagnostics concerning all of them on a second *Diagnostics* tab: how many items each of its checks flags,
each linking to the list of them (see [Checks](#checks)).

* **Data**: entries, assets and users.
  * **Entries**: the entries of all sections, with section, entry type, status, creator (authors), references, post date and last edit.
    They can be searched by title or slug, filtered by section, entry type, status, whether they are referenced,
    whether they are stale and whether they have drafts, sorted and are paginated. Titles link to the entry's edit page, and the last column to its
    section's preview targets, through a menu when there are several, as does the detail page's *View entry* button.
    The detail page shows the entry on each site, when and by whom it was last edited, the elements referencing it, and, for entries with versioning, a
    *History* tab listing its revisions (with the current one marked, their creators and notes) and its saved drafts.
    *Diagnostics*: unreferenced, stale and disabled entries and drafts.
  * **Assets**: the assets of all volumes, with volume, file kind, dimensions, size, references, upload date and uploader. They can
    be searched by filename or title, filtered by volume, file kind, usage and missing alternative text, sorted and are
    paginated. The detail page shows the file's metadata (path, URL, MIME type, dimensions, focal point, uploader and
    dates), a preview for images, its title and alternative text per site, the elements referencing it and the
    image transforms generated for it. *Diagnostics*: assets with no references, images without alternative text, large
    files and oversized images.
  * **Users**: the users, with status, email, groups, the number of entries they are an author of, whether they have
    set up two-step verification (an authenticator app or a passkey), last login and creation date. They can be
    searched by name, username or email, filtered by status, group (or no group), how recently they signed in, two-step
    verification, number of entries, creation date, admins and unverified email changes, sorted and are paginated. The
    user groups are listed above them, with their users, permissions and whether they require two-step verification.
    The detail page shows the user's account details, their permissions along with whether they are granted directly or
    through a group, the entries they are an author of and the files they uploaded, and the elements referencing them.
    Users aren't site-specific, so they aren't scoped to the selected sites. *Diagnostics*: locked users, admins without
    two-step verification, idle, pending and suspended users, and unverified email changes.
* **Structure**: entry types, sections, fields and volumes.
  * **Entry Types**: classified by how they are used: *Section*, *Nested* (Matrix, CKEditor),
    *Reference* (can be referenced through an Entries field), *Link* (can be linked to through a Link field),
    *Has URLs*, *Unused* and *Empty*. The detail page shows
    every usage with entry counts and the field layout.
    *Diagnostics*: unused and empty entry types.
  * **Sections**: type, entry types, URLs, entry/draft counts, references and last edit.
    The detail page shows site settings, counts per entry type, the reference fields (Entries fields that can
    reference the section's entries) and the Link fields that can link to them. *Diagnostics*: empty sections.
  * **Fields**: type, the layouts the field is placed in, and its data (nested entries or references). The detail page
    shows the entry types and sections a field offers, along with counts per entry type and section.
    *Diagnostics*: unused and empty fields.
  * **Volumes**: filesystem, file kinds, URLs, in use/no references asset counts, total size, images without alternative
    text, references and last upload. The detail page shows counts and sizes per file kind, the Assets and Link fields
    that can relate to the volume's assets.
    *Diagnostics*: empty volumes.
* **Diagnostics**: every detail page has a *Diagnostics* tab that lists the checks of its item, most urgent first, with
  what to do about each failed one. The pages of sections and entry types also count the entries each check of entries
  flags among theirs, and those of volumes the assets each check of assets flags, linking to the list of them on their
  *Entries* or *Assets* tab. The tab shows the number of failed checks. Diagnostics can be disabled by admins under
  *Hub → Settings → Diagnostics*, which removes the *Diagnostics* tabs of the index and detail pages.
* **Entry sidebar**: lists the elements that reference the entry through any relation field (e.g. Entries or Link
  fields) or a reference tag in another field (e.g. a link in rich text), along with the field type.

## References and edits

**References** are relations, made by relation fields like Entries, Assets or Link fields, and reference tags by ID in
the content of other fields, like the links and images of rich text (e.g. `{entry:123@1:url}`). Craft keeps no record of
reference tags, so Hub indexes them as elements are saved. The index is built in the queue once Hub is installed or
updated; content changed without saving elements, e.g. by Craft's *Find and Replace* utility, is indexed again with
`./craft hub/references` (or `./craft hub/references --queue`). Reference tags of drafts and revisions aren't indexed,
and neither are those by anything but an ID (e.g. `{entry:news/hello}`).

**Edits** tell whether entries are stale. Craft's update date changes with every save, also those of scripts, imports,
propagating entries to other sites or changing their status, so Hub records when entries were last edited by a person:
the saves of signed-in users outside the queue that change an entry's fields or attributes other than its status.
Entries existing when Hub is installed or updated are given the date of their newest revision or change tracked by
Craft made by a user, or of their last update if Craft knows neither. Entries never edited since fall back to their
last update.

All lists can be searched, filtered and sorted by clicking a column header, and filtered by diagnostics: the most urgent
failed check of each item, or whether it passes all checks. Lists are filtered, sorted and counted on the server and load
their results in place as filters change; the filters are kept in the URL. The entries, files and users lists are paginated.
The filter bar only shows the saved filters, the search and the filters set, each removed by its ×; the others are
added from the *Filter* menu, which opens the one picked.
Counts cover all sites by default and can be narrowed to a single site with the site filter.

## Labels

Items of every list can be labeled, with any number of labels; the labels are the same for all lists. A label has a
name, an icon and a color, and is shown after the title of the items labeled with it, by its icon in its color, with its
name as tooltip. The button following an item's labels, shown while hovering its row, labels it right away. Every list
can be filtered by its items' labels, or by unlabeled items.

Labels are created, changed, ordered and deleted under *Hub → Settings → Labels*, by users with the *Manage labels* permission;
deleting a label unlabels all items labeled with it. Each label shows the lists
it's used in with the number of their items labeled with it, linking to them filtered by it, and can be cleared: removed
from all items labeled with it while the label is kept. Items are labeled by users with the *Label …*
permission of their page.

Labels can be disabled by admins under *Hub → Settings → Labels*: the lists then show no labels and can neither be
labeled nor filtered by them. The labels themselves can still be managed, and items keep their labels for when labels
are enabled again.

## Checks

The checks of the *Diagnostics* tabs are managed under *Hub → Settings → Diagnostics*, by users with the *Manage diagnostic checks*
permission, in a table per kind: entries, assets, users,
entry types, sections, fields and volumes. Failed checks are listed first, the most urgent first; checks of the same
severity and passed ones are listed in the order of the table, which they're reordered in by dragging them.

Every check flags the items a saved filter of its kind matches (see [Saved filters](#saved-filters)), so its count on
the index page links to exactly the list of the flagged items.

* **Default checks**: Hub's own checks, each flagging the items of the predefined filter of the same name, which is shown
  in their form but can't be changed. Their thresholds, like the months after which entries are stale, are those of
  their filters.
  * **Entries**: unreferenced, stale, with drafts and disabled.
  * **Assets**: unreferenced, images without alternative text, large files and oversized images.
  * **Users**: locked, admins without two-step verification, idle, pending, suspended and unverified email changes.
  * **Entry Types**: unused and empty.
  * **Sections**: empty.
  * **Fields**: unused and empty.
  * **Volumes**: empty.

  They can't be deleted, but disabled, right in the table, and given another severity in their form. Disabled checks
  are shown and computed nowhere.
* **Custom checks**: created for a kind, with the predefined or custom filter of the kind whose items they flag, a name,
  what's wrong and what to do about it, optionally a text shown while they pass, a severity and an icon (that of the
  filter by default). A filter can't be deleted while checks flag its items.

The checks are listed on the detail pages of the items of their kind, and, counting the items they flag, on the
*Diagnostics* tab of the index page of their kind. Checks of entries are counted on section and entry type pages too,
and those of assets on volume pages, each linking to the list of the flagged items among theirs.

## Saved filters

The lists of the *Data* and *Structure* pages offer saved filters at the start of their filter bar. Picking one sets the
list's filters to those it saved, keeping the sorting, and loads the results like any other filter; the filters it sets
can be changed further from there. The saved filter the list shows is checked and named on the button.

* **Predefined filters**: Hub's own filters of each list, e.g. *Unreferenced entries*, *Entries with drafts*,
  *Unreferenced assets*, *Images without alternative text*, *Admins without two-step verification*, *Unused fields* or
  *Empty sections*. They can't be deleted, but disabled, reordered and renamed. Those with a threshold, like *Stale
  entries*, *Large files*, *Oversized images*, *Idle users*, *Stale entry types* and *Stale sections*, can be given
  another one in their form.
* **Custom filters**: created for a list, with a name, an optional description shown as their tooltip and an icon.
  Their *Matching* condition narrows the list to the items it matches: entries, assets and users have Craft's own
  conditions, including those on their custom fields, e.g. entries whose *Image* field is empty; entry types, sections,
  fields and volumes have Hub's: their name, handle, type, usage, URLs, number of entries or assets, fields in the
  layout, instructions and the like. The condition applies along with the list's other
  filters, which can be set as well, and is kept in the URL as `?filter=<uid>`.
* **My filters**: each user saves the filters a list shows under a name of their own, right in the list; saving under
  the name of one of their filters replaces its filters. They're renamed and deleted in the list too, and only the user
  who saved them sees them.

Predefined and custom filters are offered to everyone who may view the list. They're managed under *Hub → Settings →
Filters*, by users with the *Manage saved filters* permission, in a table per list, in the order the lists offer them;
disabled filters aren't offered in the filter bar, though checks still flag their items. The *Filters* tab is the first
of the settings page. Users' own filters need no permission but that of viewing the list.

## Bulk actions

The rows of every list can be selected by their checkbox, or by clicking them, which keeps the other rows selected:
shift selects or unselects the rows between the one clicked and the one clicked before.

The selection is kept for the rest of the session, per list, so it survives filtering, sorting and paging through the
list: editors can narrow the list down with one set of filters, select what they need, change the filters and select
more. The *Select* menu selects, unselects or inverts the rows of the page, and selects or unselects all items matching
the current filters at once, across all pages. The bar names how many items are selected and how many of them the
current filters leave out. Up to 1,000 items can be selected at once.

*Review selection* then lists only the selected items, whatever the filters, sorted and paginated like the list.
Items unselected there are faded until the list is loaded again, so they can be selected again. The actions are
offered there and apply to the whole selection, as far as the user may change each item. Selecting several items
needs the *Bulk-edit …* permission of the page, and each action its own permission (see
[Permissions](#permissions)):

* **All lists but transforms**: labels, for users who may label the items while there are labels or they may create some. A dialog lists
  the labels: those of all selected items are checked, those of some of them partly checked; checking or unchecking a label adds it to or
  removes it from all of them once applied, leaving the other labels as they are. The items stay selected.
* **Entries**: enable, disable or delete (to the trash).
* **Assets**: download them, delete their generated transforms, which are generated again when they're next requested,
  or delete them (to the trash).
* **Users**: suspend or unsuspend, for users who may moderate users. Users can't suspend themselves.
* **Transforms** of an asset: delete.

Sections, entry types, fields, volumes and user groups, the structure, can only be labeled: they're never deleted
through the Hub, but on Craft's settings pages.

Deleting shows a preview first, in a dialog where the deletion is confirmed. It tells that the items are moved to
the trash, from which they can be restored, and how many are skipped as the user may not delete them. The totals of all
items follow, colored by whether they are deleted too, changed or kept, then the items: each expands to what deleting it
takes along, listing the first of them, linked to their pages (the first 25 items are detailed this way):

* **Entries**: their nested entries and drafts, their children in a structure, which move up a level, and the elements
  referencing them, which lose those references.
* **Assets**: their generated transforms, the users whose photo they are, and the elements referencing them.

The items the action was applied to are unselected; skipped ones stay selected and listed, so they can be reviewed
again. Once nothing is left selected, the list is shown again with its filters. The transforms of an asset are selected
on their page only.

Right-clicking a row opens its context menu: it opens the item in a new tab, selects or unselects it, labels it and
offers the same actions, applied to that item only. Shift-right-clicking a row keeps the browser's own menu. Users who
may not bulk-edit a list have neither checkboxes nor the bar there, but the context menu, applying the actions they
may apply to one item at a time.

## Permissions

Hub's permissions are granted to user groups or users along with Craft's *Access Hub* permission. There are two primary
ones, *Access data* and *Access structure*, each with a permission per page nested under it, which has the
permissions of what can be done with the page's items nested under it in turn:

* **Access data**
  * **View entries**: *Data → Entries*, and the entries tabs of other pages.
    * Label entries · Bulk-edit entries · Enable and disable entries · Delete entries
  * **View assets**: *Data → Assets*, and the assets tabs of other pages.
    * Label assets · Bulk-edit assets · Download assets · Delete asset transforms · Delete assets
  * **View users**: *Data → Users*, with its user groups tab and the users' personal data.
    * Label users · Bulk-edit users · Suspend users (and unsuspend them)
* **Access structure**
  * **View entry types**, **View sections**, **View fields**, **View volumes**: their pages below *Structure*.
    * Label … · Bulk-edit … (e.g. *Label fields*, *Bulk-edit fields*)
* **Manage labels**: creating, changing, ordering and deleting labels.
* **Manage diagnostic checks**: the custom checks of the diagnostics tabs.
* **Manage saved filters**: the predefined and custom filters the lists offer to everyone.
* **View the activity log**: *Hub → Activity*.

A page needs both the permission of its area and its own. *Bulk-edit …* selects several items at once, to label
them or apply actions to all of them; without it, the actions are applied to one item at a time, from its context menu.
Craft's own permissions of the items still apply to the actions, like saving or deleting an entry, or moderating users
to suspend them.

The navigation only shows the pages the user may view, and links to pages they may not open are shown as plain text.
Admins have all permissions. The links to Craft's native settings pages are only shown
to admins.

## Configuration

The settings can be changed by admins on the tabs of *Hub → Settings* (*Settings → Plugins → Hub* leads there too),
read-only while admin changes aren't allowed, or by creating `config/hub.php`, which takes precedence over the settings
saved in the control panel:

```php
return [
	// Whether items can be labeled and lists filtered by their labels.
	'enableLabels' => true,
	// Whether the index and detail pages have a diagnostics tab.
	'enableDiagnostics' => true,
	// Whether the index and detail pages have a statistics tab.
	'enableStatistics' => true,
	// Whether what users do is recorded in the activity log.
	'enableActivity' => true,
	// Activities are kept for this many days; 0 keeps them forever.
	'activityRetentionDays' => 90,
	// Whether drafts created and deleted are recorded too, rather than only once they're applied.
	'activityDrafts' => false,
];
```

Thresholds, like the months after which entries are stale, are set per predefined filter (see
[Saved filters](#saved-filters)).

## Limitations

* "Unreferenced" entries and assets with "no references" are those no relation or indexed reference tag targets (see
  *References and edits*). Templates querying them directly, and links by their URL, aren't taken into account.
* The statistics of reference fields (e.g. on section pages) count relations only.
* Usage of regular fields is based on field layouts. Whether a field is empty is only
  tracked for nested entry and relation fields.

---

[somehow digital.](https://somehow.digital/)
