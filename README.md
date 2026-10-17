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

Hub adds two navigation items, *Data* and *Structure*. Each of their pages lists its elements on the first tab and
shows the diagnostics concerning all of them on a second *Diagnostics* tab: counts worth a closer look, each linking to
the matching list, and for structure pages, how many pass their checks, split by the most urgent failed one, along with
the most common issue.

* **Data**: entries and assets.
  * **Entries**: the entries of all sections, with section, entry type, status, references, post date and last update.
    They can be searched by title or slug, filtered by section, entry type, status, whether they are referenced,
    whether they are stale and whether they have drafts, sorted and are paginated. Titles link to the entry's edit page.
    *Diagnostics*: unreferenced, stale and disabled entries and drafts.
  * **Assets**: the assets of all volumes, with volume, file kind, dimensions, size, references and upload date. They can
    be searched by filename or title, filtered by volume, file kind, usage and missing alternative text, sorted and are
    paginated. The detail page shows the file's metadata (path, URL, MIME type, dimensions, focal point, uploader and
    dates), a preview for images, its title and alternative text per site, the elements referencing it and the
    image transforms generated for it. *Diagnostics*: assets with no references, images without alternative text, large
    files and oversized images.
* **Structure**: entry types, sections, fields and volumes.
  * **Entry Types**: classified by how they are used: *Section*, *Nested* (Matrix, CKEditor),
    *Reference* (can be referenced through an Entries field), *Link* (can be linked to through a Link field),
    *Has URLs*, *Unused* and *Empty*. The detail page shows
    every usage with entry counts, the field layout and the entries to review (unreferenced or stale).
    *Diagnostics*: checks, unused and empty entry types.
  * **Sections**: type, entry types, URLs, entry/draft counts, references and last update.
    The detail page shows site settings, counts per entry type, the reference fields (Entries fields that can
    reference the section's entries), the Link fields that can link to them and the entries to review (unreferenced or
    stale). *Diagnostics*: checks and empty sections.
  * **Fields**: type, the layouts the field is placed in, and its data (nested entries or references). The detail page
    shows the entry types and sections a field offers, along with counts per entry type and section.
    *Diagnostics*: checks, unused and empty fields.
  * **Volumes**: filesystem, file kinds, URLs, in use/no references asset counts, total size, images without alternative
    text, references and last upload. The detail page shows counts and sizes per file kind, the Assets and Link fields
    that can relate to the volume's assets and the assets to review (no references, images missing alternative text, or
    large files).
    *Diagnostics*: checks and empty volumes.
* **Diagnostics**: every detail page has a *Diagnostics* tab that lists checks for that record, most urgent first, with what to
  do about each failed one. Where there is a next step, a failed check links to it: the list of affected entries or
  assets, the edit page of the entry or asset, or Craft's settings page of the section, entry type, field or volume
  (for admins only). The tab shows the number of failed checks and holds the lists of entries or assets to review.
  * **Sections**: no entries, entry types without entries in the section, entries that can't be reached (no URLs and
    no Entries or Link field), unreferenced, stale and disabled entries, and drafts.
  * **Entry Types**: unassigned, no entries, sections and fields without entries of the type, fields of the layout that
    hold no data anywhere, and the entry checks of sections.
  * **Fields**: not placed in any layout, no data, offered entry types without entries, sources without entries,
    similar fields of the same type (e.g. `teaserImage` and `teaserImg`) and missing instructions.
  * **Volumes**: no assets, no Assets or Link field, assets with no references, images without alternative text, large
    files and oversized images.
  * **Files**: no references, missing alternative text (on all or some sites), large file, oversized image, a camera,
    screenshot or download default filename (e.g. `IMG_1234.jpg`) and failed transforms.
  * **Entries**: unreferenced without a URL, stale, referenced images without alternative text, referenced entries that are
    disabled, pending or expired, and drafts. References of nested entries count towards their owner.
* **Entry sidebar**: lists the elements that reference the entry through any relation field (e.g. Entries or Link
  fields), along with the field type.

All lists can be searched, filtered and sorted by clicking a column header, and filtered by diagnostics: the most urgent
failed check of each item, or whether it passes all checks. Lists are filtered, sorted and counted on the server and load
their results in place as filters change; the filters are kept in the URL. The entries and files lists are paginated.
Counts cover all sites by default and can be narrowed to a single site with the site filter.

## Permissions

Grant the *Hub → Access the content structure overview* permission to user groups that should see Hub.
Admins always have access. The links to Craft's native settings pages are only shown to admins.

## Configuration

The thresholds can be changed under *Settings → Plugins → Hub*, or by creating `config/hub.php`, which takes
precedence over the settings saved in the control panel:

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
* Assets with "no references" are assets not referenced through any relation field. References from rich text aren't
  taken into account.
* The entry sidebar only lists references from relation fields. Reference tags in rich text aren't shown there.
* Usage of regular fields is based on field layouts. Whether a field is empty is only
  tracked for nested entry and relation fields.

---

[somehow digital.](https://somehow.digital/)
