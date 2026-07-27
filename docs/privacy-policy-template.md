# Privacy policy — what Saito actually processes

**This is not a ready-to-publish privacy policy.** It is an inventory of what
the software does, so that whoever runs an installation can write one without
having to read the source. The legal parts — who the controller is, the legal
basis, retention periods, how to exercise data-subject rights — depend on the
installation and belong to its operator. Have the result reviewed.

Put the finished HTML into `config/saito_config.php` under `Saito.privacy`; it
is rendered at `/pages/privacy` and linked from the footer.

## What the forum stores by itself

**Account data.** Username, email address, password (bcrypt hash), plus
whatever the member fills in voluntarily: real name, location, homepage,
signature, avatar. Editable and, apart from the username, optional.

**Postings.** Subject, text, category, timestamps, and the thread structure.
Read state, bookmarks and "was helpful" marks are stored per member.

**IP addresses — only if switched on.** `store_ip` in the admin settings
decides whether the IP address of the author is stored *with a posting*. A
second setting, `store_ip_anonymized`, truncates it first. Both are off unless
an admin enabled them. There is no automatic deletion — if you switch this on,
say in the policy how long you keep it, and be aware that "until the posting is
deleted" is the honest answer with the current code.

Failed logins are counted per IP address in the cache for 15 minutes as
brute-force protection. That counter expires by itself and is not stored in the
database.

**Uploads.** Images a member uploads are stored on the server and are readable
by anyone who can read the posting they are attached to.

**Session cookie.** One cookie named `Saito`, needed to stay logged in. "Stay
signed in on this device" extends it. No cookie is set for visitors who do not
log in.

**No third-party content.** Avatars are either uploaded or generated locally
(identicons) — there is no Gravatar request. No CDN, no external fonts, no
tracking pixels. The one exception is the maintenance page
(`templates/Pages/forum_disabled.php`), which still pulls a Google font; if that
page is in use, either mention it or remove the import.

**Server log files** are written by the web server, not by Saito, and typically
contain IP address, time, URL and user agent. Whether and how long they are kept
is a decision of the hosting setup — describe what your server actually does.

## If analytics are enabled

`Saito.headHtml` injects an operator-chosen snippet into every page. If it is
empty, there is nothing to declare.

**Plausible Analytics** (what macnemo.de uses) is worth describing accurately,
because it is markedly less invasive than the usual:

- It sets **no cookies** and needs no consent banner on that basis.
- It does **not store IP addresses**. To recognise a returning visitor within a
  day it computes a hash from IP address, user agent, domain and a salt that
  rotates daily; after that rotation the earlier visits can no longer be linked
  to a person.
- It builds no cross-site profile and passes nothing to advertising networks.
- **Self-hosted** (macnemo.de uses its own instance at
  `plausible.panxatony.net`), the data never leaves the operator's own
  infrastructure — so there is no transfer to a third party to declare at all.
  Say so explicitly; it is the strongest sentence in the whole section.
- Collected are: page URL, referrer, browser, operating system, device type and
  a coarse country/region derived from the IP address.

If you ever switch to the hosted plausible.io, that last point changes: then it
*is* a processor, and the policy needs a processing agreement and the storage
location (EU) mentioned.

## Still to be filled in by the operator

- Controller: name, address, contact (usually the same as the imprint).
- Legal basis for each purpose.
- Retention periods — in particular for postings, accounts and, if enabled,
  stored IP addresses.
- Data-subject rights and where to address them.
- What happens on account deletion. Saito can anonymise a user's postings
  (`EntriesTable::anonymizeEntriesForUser`) rather than delete them; whichever
  you choose, describe it.
- Your hosting arrangement and any processor agreement covering it.
