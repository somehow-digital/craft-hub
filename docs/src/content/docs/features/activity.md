---
title: Activity log
description: See what users did, as Craft tells it.
---

_Hub → Activity_ lists what users did, whether it was done in the control panel, on the site or on the command line. It needs the _View the activity log_ permission.

Admins can turn it off with `enableActivity`. Nothing is recorded then, and the page is hidden.

## What's recorded

Hub records what users would call a change, as Craft's events tell it, along with what they do with Hub itself.

| Area           | Examples                                                                                                               |
| -------------- | ---------------------------------------------------------------------------------------------------------------------- |
| **Content**    | Entries, assets and other elements created, updated, deleted, restored, reverted, moved, merged, enabled or disabled.  |
| **Accounts**   | Sign-ins, failed sign-ins and sign-outs, impersonation, password changes and resets, verified emails, sign-in methods. |
| **Access**     | Groups and permissions changed, admin granted or revoked, users suspended, locked, activated or deactivated.           |
| **Structure**  | Sections, entry types, fields, volumes, user groups, sites, filesystems, routes and GraphQL schemas and tokens.        |
| **System**     | Plugins installed or uninstalled, settings and project config applied, utilities run, the database backed up.          |
| **Hub itself** | Labels, maintainers, checks and filters changed, items labeled, exports, downloads, and bulk actions.                  |

Some things are deliberately left out:

- Drafts and revisions, until they're applied or reverted to. Turn on `activityDrafts` to record drafts created and deleted too.
- Nested elements, which are saved along with their owners.
- Elements saved again or propagated to other sites.

### Batches

Activities done together are recorded as a **batch**: element actions, bulk actions and project config changes applied from the YAML files. The log lists the batch's own activity, and you can open it to see the activities within it.

Batches that do nothing leave nothing behind.

## The list

Each activity shows what was done, to what, by whom and when. Web requests also record the user's IP address.

- **Search** by the name of the subject or the user, or by IP address.
- **Filter** by kind of action, kind of subject, user (or activities without one, like a failed sign-in), and a range of days.
- **Sort** by date, user, subject or action.
- Activities are paginated, and grouped by day while sorted by date.

Kinds of action are _Created_, _Updated_, _Deleted_, _Restored_, _Status changed_, _Access changed_, _Sign-in_, _Downloaded_ and _Ran_. Each can be narrowed to a single action, like “Suspended” or “Groups changed”.

Activities keep the names of their users and subjects as they were, so they outlive them.

### One item

Every detail page shows a panel with the latest activity of its item, linking to all of it in the log. That list shows what was done to this item alone.

For updated elements, an activity lists the fields and attributes that changed.

## Retention

Activities are kept for 90 days by default. Change it with `activityRetentionDays`, or set it to `0` to keep them forever. Expired activities are deleted along with Craft's garbage collection.

See [configuration](/guide/configuration/).
