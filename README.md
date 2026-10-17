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

A read-only overview of the content structure, which helps editors decide what can be cleaned up.
Nothing can be edited or deleted from within Hub.

* **Overview**: a summary of everything below, scoped to the selected site:
  * **To do**: counts worth a closer look, grouped by area and each linking to the matching list. *Entries*: unreferenced,
    stale and disabled entries and drafts. *Assets*: unused assets, images without alternative text, large files and
    oversized images. *Structure*: unused entry types and fields; empty sections, entry types, fields and volumes.
  * **Health checks**: how many sections, entry types, fields and volumes pass their checks, split by the most urgent
    failed one, along with the most common issue.
  * **Inventory**: totals for entries, assets, sections, entry types, fields and volumes, linking to their lists.
* **Entries**: entries, entry types, sections and fields, each on its own tab.
  * **Entries**: the entries of all sections, with section, entry type, status, relations, post date and last update.
    They can be searched by title or slug, filtered by section, entry type, status, whether they are referenced and
    whether they are stale, sorted and are paginated. Titles link to the entry's edit page.
  * **Sections**: type, entry types, URLs, entry/draft counts, references and last update.
    The detail page shows site settings, counts per entry type, the reference fields (Entries fields that can
    reference the section's entries), the Link fields that can link to them and the entries to review (unreferenced or
    stale).
  * **Types**: classified by how they are used: *Section*, *Nested* (Matrix, CKEditor),
    *Reference* (can be referenced through an Entries field), *Link* (can be linked to through a Link field),
    *Has URLs*, *Unused* and *Empty*. The detail page shows
    every usage with entry counts, the field layout and the entries to review (unreferenced or stale).
  * **Fields**: type, the layouts the field is placed in, and its data (nested entries or relations). The detail page
    shows the entry types and sections a field offers, along with counts per entry type and section.
* **Assets**: files and volumes, each on its own tab.
  * **Files**: the files of all volumes, with volume, file kind, dimensions, size, relations and upload date. They can be
    searched by filename or title, filtered by volume, file kind, usage and missing alternative text, sorted and are
    paginated. The detail page shows the file's metadata (path, URL, MIME type, dimensions, focal point, uploader and
    dates), a preview for images, its title and alternative text per site, the elements relating to it and the
    image transforms generated for it.
  * **Volumes**: filesystem, file kinds, URLs, used/unused asset counts, total size, images without alternative text,
    relations and last upload. The detail page shows counts and sizes per file kind, the Assets and Link fields that
    can relate to the volume's assets and the assets to review (unused, images missing alternative text, or large
    files).
* **Review**: every detail page has a *Review* tab that lists checks for that record, most urgent first, with what to
  do about each failed one. The tab shows the number of failed checks and holds the lists of entries or assets to
  review.
  * **Sections**: no entries, entry types without entries in the section, entries that can't be reached (no URLs and
    no Entries or Link field), unreferenced, stale and disabled entries, and drafts.
  * **Entry Types**: unassigned, no entries, sections and fields without entries of the type, fields of the layout that
    hold no data anywhere, and the entry checks of sections.
  * **Fields**: not placed in any layout, no data, offered entry types without entries, sources without entries,
    similar fields of the same type (e.g. `teaserImage` and `teaserImg`) and missing instructions.
  * **Volumes**: no assets, no Assets or Link field, unused assets, images without alternative text, large files and
    oversized images.
  * **Files**: unused, missing alternative text (on all or some sites), large file, oversized image, a camera,
    screenshot or download default filename (e.g. `IMG_1234.jpg`) and failed transforms.
  * **Entries**: unreferenced without a URL, stale, related images without alternative text, related entries that are
    disabled, pending or expired, and drafts. Relations of nested entries count towards their owner.
* **Entry sidebar**: lists the elements that relate to the entry through any relation field (e.g. Entries or Link
  fields), along with the field type.

All lists can be searched, filtered and sorted by clicking a column header. The filters are kept in the URL. The entries
and files lists are filtered and sorted on the server, as there can be many of them.
Counts cover all sites by default and can be narrowed to a single site with the site filter.

## Permissions

Grant the *Hub → Access the content structure overview* permission to user groups that should see Hub.
Admins always have access. The links to Craft's native settings pages are only shown to admins.

## Configuration

Create `config/hub.php` to override the defaults:

```php
return [
	// Entries not updated within this many months are listed as stale.
	'staleThresholdMonths' => 12,
	// Files larger than this many megabytes are listed as large.
	'largeFileThresholdMb' => 5,
	// Images wider or taller than this many pixels are listed as oversized.
	'largeImageThresholdPx' => 4000,
];
```

## Limitations

* "Unreferenced" entries are entries not referenced through any relation field. Links from rich text
  (e.g. `{entry:123}` reference tags) and Link fields aren't taken into account there.
* "Unused" assets are assets not related to through any relation field. References from rich text aren't taken
  into account.
* The entry sidebar only lists relations. Reference tags in rich text aren't shown there.
* Usage of regular fields is based on field layouts. Whether a field is empty is only
  tracked for nested entry and relation fields.

---

[somehow digital.](https://somehow.digital/)
