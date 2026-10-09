# Groups across services

CardDAV services disagree about what a contact group *is*. Contacts Hub
translates between them, so you can pull from a service that does groups
one way and push to a service that does them another. This page explains
how that works, what survives the trip, and where the edges are.

## Three ways to say "this contact is in that group"

| Convention | What it looks like on the wire | Who uses it |
|---|---|---|
| **Group vCards** (`passthrough`) | A separate vCard per group, marked `X-ADDRESSBOOKSERVER-KIND:group` (or vCard 4 `KIND:group`), listing its members as `X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:<contact UID>` lines. The contacts themselves carry no group information. | iCloud, Infomaniak, most sabre/dav servers |
| **Categories / tags** (`categories`) | A `CATEGORIES:Family,Work` line on each contact. There is no group record anywhere: a group exists only as long as some contact names it. | **Nextcloud** itself, Mailo, servers that drop group vCards |
| **One collection per group** (`collections`) | Every group is its own address book (a server-side "folder"). | Rare. Detected by the capability test, **not supported for sync**, see below |

Every endpoint has a **group strategy** that says which of these it speaks.
The capability test's write probe sets it from what the server actually
keeps, which beats guessing from the preset name. You can change it by hand
on the endpoint form.

The Nextcloud side of a job is always `categories`. That's how the
Contacts app shows groups. Nextcloud would store group vCards if you sent
it some (iOS does), but the Contacts app ignores them, so nobody would see
them.

## How a sync translates

Every job runs between one Nextcloud address book and one endpoint, so
there are only four cases. Contacts Hub turns both sides into the same
internal shape before comparing them: categories become group objects with
stable identifiers. The comparison never needs to know which convention a
side uses. Translation happens only at the moment of writing.

| Job | Endpoint strategy | What gets written |
|---|---|---|
| **Pull** (endpoint → Nextcloud) | `passthrough` | Each group vCard's membership becomes a `CATEGORIES` entry on the member contacts in Nextcloud. The group vCards themselves are not copied. |
| **Pull** (endpoint → Nextcloud) | `categories` | `CATEGORIES` carried across as-is. |
| **Push** (Nextcloud → endpoint) | `passthrough` | Each Nextcloud category becomes a real Apple-style group vCard on the endpoint, with a member line for each contact carrying it. |
| **Push** (Nextcloud → endpoint) | `categories` | `CATEGORIES` carried across as-is. |

On a write to a `categories` side, a contact's `CATEGORIES` is **replaced**
with the groups it belongs to on the source. It isn't merged with them.
The job is one-way, so the source decides.

On a pull from a group-vCard endpoint, a group change touches only the group
vCard: adding someone to "Family" on iCloud does not edit their contact. So
when a group changes on the endpoint, Contacts Hub re-sends the member
contacts it affects — its members now and its members at the last sync —
but only those whose categories in Nextcloud don't already match. A group
seen for the first time costs nothing for members that are already right,
and a pull with no group changes writes nothing.

When a group disappears from the source, it is removed from a group-vCard
destination under either deletion policy. The *mirror*/*archive* choice
applies to contacts. On a categories destination there is nothing to remove:
the members simply lose the category.

### Bridging two services that disagree

Bridging is two jobs sharing one Nextcloud address book, so the Nextcloud
address book always sits in the middle as the common format. For example,
iCloud (group vCards) to Mailo (categories):

```
iCloud ──pull──▶ Nextcloud address book ──push──▶ Mailo
group vCards     CATEGORIES                       CATEGORIES
```

And the reverse, Mailo to iCloud: Mailo's categories arrive in Nextcloud as
categories, and the push job turns each one into a group vCard on iCloud.
Any combination of `passthrough` and `categories` works in either
direction, because both are translated to and from the Nextcloud address
book.

## Identity: how a category becomes a group, and what that costs

A category has a name but no identifier, and a group vCard needs a UID.
Contacts Hub derives one from the name: a version-5 UUID of the name with
case and surrounding whitespace ignored. The same name gives the same UID
on every run and every server, with no mapping table to keep. "Family" and
"family " are treated as one group. The display name keeps the casing
Nextcloud shows. When several casings exist, the alphabetically first one
wins, so the name doesn't flip between runs.

That choice has visible consequences:

- **Renaming a category is a delete plus a create** on a group-vCard
  endpoint. The derived UID changes with the name, so the old group is
  deleted and a new one created. Group membership is carried over
  correctly, but the far side sees a new group, not a renamed one. Anything
  on that service that pointed at the old group, like a sharing rule, won't
  follow. The same goes for a category no contact carries any more: its
  group is deleted from the endpoint.
- **The same name can exist twice on the endpoint.** Say an iCloud account
  already had a "Family" group (with Apple's own UID) before you started
  pushing to it. Contacts Hub's derived "Family" is a different identity,
  so iCloud ends up with two cards called "Family". The run report of a
  push warns about this in one line, naming the groups involved. It doesn't
  merge them for you: deciding which identity wins needs your judgement.
  Merge them by hand in the service's own app, and the next run settles.
  Pulls into Nextcloud never warn about this: there, both become the same
  category, which is the merge.
- **You can tell the groups Contacts Hub made from the ones it found.** A
  derived UID is a version-5 UUID, so the first character of its third
  block is `5` (`xxxxxxxx-xxxx-5xxx-...`). Apple and most clients use
  random version-4 UUIDs.

## What is cleaned up on the way

- **References to contacts that no longer exist are dropped.** A group
  vCard is just a list of UIDs, and nothing on the server checks that those
  contacts still exist. Long-lived address books collect references to
  contacts deleted years ago. Contacts Hub removes them before comparing
  and writing, so they aren't copied to the other side. The run report
  says how many it found, one line per group, not one per member.
- **The archive marker is not a group.** With the *archive* deletion
  policy, a contact deleted from the source is kept on the destination and
  tagged with the job's archive category, or added to its archive group.
  That tag is Contacts Hub's own bookkeeping and is never synced across as
  a group of its own. On a categories destination such as Nextcloud, the
  archive category is **added** to the contact's own, so an archived
  contact stays in its groups there, as it does on a group-vCard
  destination.
- **A group that vanished from the push destination is recreated.** If you
  empty the endpoint by hand, the next push rebuilds both the contacts and
  their groups.

## Folders and multiple address books

Some services offer several address books per account. Others only let you
organise contacts into groups. These are different things:

- **Several address books on the service** (separate collections): use
  one job per address book. Each job pairs exactly one Nextcloud address
  book with exactly one remote collection, chosen on the endpoint. To
  mirror three remote address books, create three endpoints (same server,
  same credentials, different collection) and three jobs.
- **"Folders" that are really groups**, as in iCloud and most phone address
  books: these are group vCards, covered above.
- **A server that models every group as its own collection**
  (`collections`) is **not supported**. The capability test can detect it.
  A job using such an endpoint still syncs contacts normally, but group
  membership doesn't cross over. The job form notes this, and the run
  report warns whenever a group change is skipped. Supporting it needs a
  contact to be tracked in several places at once, which is a database
  change, not a setting.

## Known limitations

- **Category renames show up as delete plus create** on group-vCard
  endpoints (see *Identity* above).
- **Duplicate group identities** (same name, two UIDs) are reported, not
  merged.
- **vCard 4 group members.** A group is recognised whether it says
  `X-ADDRESSBOOKSERVER-KIND:group` or vCard 4's `KIND:group`. Members are
  only read from Apple's `X-ADDRESSBOOKSERVER-MEMBER` lines, which is what
  every tested service writes. A server writing pure vCard 4 `MEMBER`
  lines instead would show its groups as empty.
- **Google Contacts** exposes no groups over CardDAV at all, neither group
  vCards nor `CATEGORIES`, and isn't supported anyway (see
  [presets.md](presets.md#google-contacts)).
- **Nextcloud's Contacts app only shows categories.** A group vCard that
  another client pushed straight into a Nextcloud address book is still
  synced (a real group vCard beats a derived one with the same UID), but
  you won't see it in the Contacts app.

See also [presets.md](presets.md) for each service's default group
strategy and how it was established.
