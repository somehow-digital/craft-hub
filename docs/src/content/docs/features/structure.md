---
title: Structure
description: Entry types, sections, fields and volumes.
---

_Structure_ has a page for each of the things that shape your content. Each lists its items and has a detail page.

## Entry types

Classified by how they're used:

| Class         | Meaning                                     |
| ------------- | ------------------------------------------- |
| **Section**   | Used by a section.                          |
| **Nested**    | Used in Matrix or CKEditor.                 |
| **Reference** | Can be referenced through an Entries field. |
| **Link**      | Can be linked to through a Link field.      |
| **Has URLs**  | Has URLs.                                   |
| **Unused**    | Used by no section and no nested field.     |
| **Empty**     | Used, but has no entries.                   |

The detail page shows its settings, every usage with entry counts, and the field layout.

**Diagnostics**: unused and empty entry types.

## Sections

**Columns**: type, entry types, URLs, entry and draft counts, references and last edit.

The detail page shows its settings, site settings, preview targets, counts per entry type, the Entries fields that can reference its entries and the Link fields that can link to them.

**Diagnostics**: empty sections.

## Fields

**Columns**: type, the layouts the field is placed in, and its data (nested entries or references).

The detail page shows its settings, the entry types, sections and volumes it offers, and counts per entry type, section and volume.

**Diagnostics**: unused and empty fields.

## Volumes

**Columns**: filesystem, file kinds, URLs, assets in use and without references, total size, images without alternative text, references and last upload.

The detail page shows its settings, counts and sizes per file kind, the Assets and Link fields that can relate to its assets, and its field layout.

**Diagnostics**: empty volumes.

## Diagnostics counts

The pages of sections and entry types also count the entries each [check](/features/checks/) of entries flags among theirs. Volumes do the same for assets. Each count links to the list of them.
