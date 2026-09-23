# Phase 2: inter-site war (design notes, not implemented)

The goal is to let the kingdoms of one WordPress site combine their forces and march on a game
hosted by a *different* WordPress site, with each site keeping authority over its own kingdoms.

Nothing here is built yet. These are the questions that have to be answered first, written down
while the Phase 1 design is fresh.

## The hard part is not the transport

Whether a war packet travels by HTTPS request or by email to a dedicated game mailbox, the same
four problems have to be solved. Email is attractive because it needs no inbound firewall rule on
either site; it is also slow, silently lossy, and trivially forged unless every packet is signed.
The recommendation is to design the packet so that the transport is interchangeable, and to build
the HTTPS path first because it can be tested synchronously.

**1. Authenticity.** A packet must prove it came from the site it claims. Give each pair of sites
a shared secret established once, out of band, by the two administrators. Sign the canonical JSON
body with HMAC-SHA256 and reject anything whose signature does not verify, before parsing the
body any further. Never accept a packet whose secret arrived in the packet itself.

**2. Replay.** A valid packet captured and sent twice must not sack the same kingdom twice. Every
packet carries a UUID, a timestamp and a monotonically increasing sequence number per sending
site. The receiver stores processed UUIDs, rejects anything older than a short window (say 15
minutes), and rejects a sequence number it has already seen. Processing must be idempotent even
if the same packet is somehow delivered twice at once, so record the UUID in the same guarded
write that applies the effect.

**3. Authority.** A sending site may only speak for its own kingdoms, and may only assert that a
force *left*. The receiving site decides what that force accomplishes: it owns the defender's
numbers and runs the combat maths. A packet that says "you lost 400 acres" is never trusted; a
packet that says "Vaelmark sent 800 squires and 40 rooks" is. The result travels back as a
second packet, and the sending site applies casualties only when it arrives.

**4. Settlement.** Losses on the attacking side are only known after the defender resolves the
battle, so the attacking kingdom's troops must be held in escrow from the moment the packet is sent
until the result comes back, and released with a timeout if it never does. This is the piece that
Phase 1 deliberately avoids by resolving everything locally and instantly.

## The league owns the rules, not the sites

A site that grants its rulers 200 turns a day, or founds kingdoms with ten times the starting
gold, wins a league without ever fighting well. Every setting that affects the game has to be the
league's to set, not each site's.

**Two classes of setting.** Cosmetic ones stay local: the world name, page titles, whether new
kingdoms may be founded. Game-affecting ones are league-governed and identical everywhere:

- turns per day, the turn cap, starting turns
- every starting resource, and the starting land
- explore yield and cost, build cost, training costs
- the conquest share, target bands, hits per target, truce length
- agent cost and the agent limit
- market tax and round length

**Distribute, then verify.** The hub holds the league ruleset and sends it with the pairing
handshake. A member site stores it and shows those settings read-only in the admin, marked as set
by the league, so there is nothing to argue about locally.

Distribution alone is not enough, because a site can change its copy back. Every packet therefore
carries a **rules fingerprint**: a hash of the governed settings in a fixed order. The hub
compares it against the league's own and refuses packets that do not match, naming the setting
that differs. A site that has drifted is told why it is being ignored rather than silently losing.

**The fingerprint does not stop a determined cheat**, and it is important to be honest about that.
A site administrator owns their database and can set a kingdom's land to whatever they like without
touching a single setting. The fingerprint catches drift and misconfiguration, which is most of
it. Beyond that there are two defences worth having:

- **Plausibility checks at the hub.** Given the ruleset, there is a ceiling on how much a kingdom
  can grow between exchanges: so many turns, each worth so much income. A kingdom that gains more
  than the rules allow is flagged, and the hub can hold its packets for a human to look at.
- **Publish everything.** Every member's standings, visible to every member. Cheating that nobody
  can see is a problem; cheating in public is a short-lived one, because leagues are voluntary and
  a site that is obviously inflated gets dropped.

The honest summary is that league play is a **federation of people who broadly trust each other**,
with mechanisms that make accidental divergence impossible and deliberate cheating visible. It is
not, and cannot be, a system that makes a hostile host safe to play against.

## Where the code and the secrets live

The league code goes in `includes/league/` and is committed like the rest of the plugin. Keeping
it out of the repository would mean no history for the most security-sensitive part of the game,
which is exactly backwards.

**No secret is ever committed, including a placeholder.** A shared secret is generated at pairing
and stored in the `ido_sites` table; it never appears in a file, a constant or a default. A
default secret in a public repository is the classic way a federated system is broken: every
install shares one key and the repository tells an attacker what it is. If the pairing screen
needs a starting value, it generates one with `wp_generate_password()` and shows it once.

Packets are stored as text in `ido_packets`, not written to disk. That is a security decision
rather than a storage preference: nothing arriving from another site should ever become a file.

`.gitignore` carries entries for `league-local/`, `*.secret`, `*.key`, `*.pem` and `.env` files,
so local fixtures and captured packets used in testing cannot be committed by accident.

## What Phase 1 already provides

- Combat resolution is one service (`IDO_Military::attack()`) that takes an explicit force array,
  so a remote force can be fed into the same maths without duplicating it.
- Resource and troop movement all runs through `IDO_Kingdom::pay()`, which is atomic and guarded,
  so escrow can be built on it rather than beside it.
- `IDO_Lock` gives a named lock for a critical section that spans several rows.
- Battles are already persisted with both reports, which is the natural place to record a remote
  battle's UUID.

## What would need adding

- A `ido_sites` table: peer site URL, shared secret, sequence counters, trust status.
- The league ruleset, stored locally, with those settings locked in the admin and a fingerprint
  recomputed whenever they change.
- A `ido_packets` table: UUID, direction, type, payload, status, processed timestamp.
- An escrow table, or `away_*` columns on `ido_kingdoms`, for forces in transit.
- A queue and a cron worker, since remote battles cannot resolve inside the request that starts
  them. This is where the queued combat path from the original design question comes back.
- An admin screen for pairing with another site, which must require an explicit confirmation from
  *both* administrators before any packet is accepted.

## A caution

The moment this plugin accepts data from another site, the attack surface changes completely:
every assumption Phase 1 makes about input coming from a signed-in WordPress user with a nonce
stops holding. The packet handler should be written as if the sending site is hostile, because
one day one of them will be compromised.
