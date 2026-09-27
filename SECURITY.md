# Security policy

Imperial Dominion Online runs on other people's WordPress sites, and with league play enabled it
accepts data from the public internet. Vulnerabilities here matter more than the game does: the
WordPress installation is worth more than any amount of cheating.

Reports are welcome, including ones that turn out to be nothing.

## Reporting a vulnerability

**Email <sysop@maddogproductions.online>.**

Please report privately first rather than opening a public issue, so sites running the plugin can
be updated before the details are widely known. You are welcome to publish afterwards, and credit
will be given unless you would rather it was not.

Useful in a report, in rough order of usefulness:

- what an attacker can do, in one sentence
- the steps to reproduce it, and whether league play was enabled
- the plugin version, the WordPress version and the PHP version
- a proof of concept, if you have one

There is no bounty. This is a hobby project given away under the GPL, and it would be dishonest to
imply otherwise.

## What to expect

A reply within a week, and an honest answer about whether it will be fixed and roughly when. A
fix for anything that lets an attacker reach the WordPress installation will be prioritised over
everything else in the project.

If a report is declined, the reason will be given plainly rather than left to silence.

## Supported versions

The latest release. This is a single-maintainer project and there is no capacity to backport fixes
to older versions, so the honest answer for an old install is to update.

## Scope

**In scope, and most interesting first:**

- Anything reaching the WordPress installation: remote code execution, SQL injection, arbitrary file
  read or write, authentication bypass.
- The league packet endpoint: the handler is written on the assumption that the sending site is
  hostile, and a way to defeat that assumption is a real finding. Signature verification, the order
  of operations before verification, replay, parser abuse, and anything that causes a write before a
  packet has verified.
- Server-side request forgery through peer URLs. This is the sharpest risk in the design and the
  place most worth attacking.
- Stored or reflected XSS, including second-order: strings arriving in a packet become empire names
  in the gazette and in admin screens.
- Missing capability or nonce checks on any admin action.
- Game logic that lets a player create resources from nothing, go negative, overflow a value, or
  act on an empire that is not theirs.

**Out of scope, because they are properties of the design rather than defects:**

- **A compromised member site owns its own game state.** League play is a federation of people who
  broadly trust each other. A site administrator can edit their own database, and no protocol
  prevents it. The defences are plausibility checks and public standings, which make cheating
  visible rather than impossible. This is documented at length in `docs/CROSS-SITE.md` and is not a
  vulnerability report.
- **Self-reported figures in league packets can be false.** A site signs its own claims about its
  size; the signature proves origin, not truth. The league table is built on corroborated results
  for exactly this reason.
- **A shared secret in the database is readable by anything with database access**, including every
  other plugin on the site. WordPress has no secret store. Per-pairing secrets and rotation limit
  the blast radius; they do not eliminate it.
- Anything requiring an administrator account to already be compromised, unless the plugin makes the
  consequences meaningfully worse than WordPress already does. `IDO_LEAGUE_DISABLE_ENDPOINT` exists
  precisely so one thing survives that case.
- Reports produced by a scanner with no demonstrated impact.

## Reducing exposure

- **League play is off by default.** A site that never enables it has no league tables and no
  endpoint.
- **The endpoint is a separate switch and is also off by default.** It is registered only when the
  site has opted in, joined a league, and switched it on.
- **It can be locked shut outside the database.** Add this to `wp-config.php` and nothing in the
  admin screens can open it, including an attacker holding an administrator account:

      define( 'IDO_LEAGUE_DISABLE_ENDPOINT', true );

- Each pairing has its own secret, so a compromised peer exposes one link rather than the league.
- There is a kill switch on the League screen that stops all league traffic without deactivating the
  plugin.

## Design notes

The threat model is written out in [docs/CROSS-SITE.md](docs/CROSS-SITE.md), including the parts
that cannot be defended and say so. Reading it before reporting may save you time, and disagreeing
with it is itself a useful report.
