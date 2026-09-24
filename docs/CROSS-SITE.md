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

## Packet security

### Sign, do not encrypt

The instinct is to encrypt a packet so it cannot be tampered with. Encryption does not do that.
Encryption hides content; what we need is proof that a packet came from the peer and arrived
unaltered, which is **authentication**. They are different jobs and need different primitives.

Encryption without authentication is worse than none, because many ciphertexts are malleable: an
attacker who cannot read a packet can still flip bits in it and change what it says. Any design
that reasons "it is encrypted, therefore it cannot have been tampered with" is already broken.

So: **every packet is signed; encryption is optional and, for this game, unnecessary.** There is
nothing confidential in "Vaelmark sent 800 squires": both ends know it, and the defender is about
to be told anyway. Signing alone buys integrity and origin, which is all the game needs.

    $signature = hash_hmac('sha256', $body, $secret);         // sending
    hash_equals($expected, $received_signature);              // verifying, timing-safe

`hash_equals()` rather than `===`, so the comparison does not leak the signature a byte at a time
through its timing.

If confidentiality is ever wanted, do not reach for a cipher directly. Use authenticated
encryption, which does both jobs in one primitive and is in PHP core:

    sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($json, $header, $nonce, $key);

Never AES-CBC with a hand-rolled MAC, never ECB, never a cipher without a tag.

### The secret

At least 32 bytes from `random_bytes()`, generated at pairing, exchanged out of band by the two
administrators, and stored in the `ido_sites` row. Never in a file, never in the repository, never
in a packet, and never the same for two peers.

Be honest about the limit: WordPress has no secret store. A secret in the database is readable by
anything with database access, which includes every other plugin on the site. Putting it in
`wp-config.php` as a constant moves it out of the database but into a file the web server can
read. Neither is a vault. This is a reason to keep the blast radius small, one secret per pairing,
and to make rotation easy.

### Verify before you parse

Order matters. Check the signature against the **raw received bytes** before any parser touches
them, so malformed input is rejected by a constant-time comparison rather than by a parser.

Sign the exact bytes that travel, and transmit them base64-encoded. Signing a re-serialised
structure invites canonicalisation bugs, where sender and receiver disagree about key order or
whitespace and every packet fails. Base64 also survives mail transports that would otherwise
re-wrap lines and break the signature.

### Parsing, with the assumption that the sender is hostile

    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);

- **JSON only, never `unserialize()`.** `unserialize()` on untrusted input is a remote code
  execution vector through object injection. `json_decode()` with `true` yields arrays and scalars
  only: no objects, nothing that can run.
- **Size cap before parsing**, 64 KB or so, and a depth cap as above. Both stop a small packet
  that expands into an enormous structure.
- **Whitelist every field**: name, type, range. Reject unknown keys rather than ignoring them, so
  a field added by an attacker is an error rather than a silent no-op.
- **Strings from a packet get the same character rules as a kingdom name**, and are escaped at
  output like everything else. A packet supplies names that end up in the gazette.
- **Numbers are clamped** through `IDO_Game::clamp()`, which already exists for the overflow work.
- Nothing from a packet is ever written to a file, used in a path or filename, included, evaluated,
  passed to a shell, or used to build SQL by concatenation.

### Replay, ordering and the deliberate delay

A valid packet captured and sent twice must not sack the same kingdom twice. Each packet carries a
UUID, a per-peer sequence number and a timestamp. The receiver records processed UUIDs and refuses
a repeat, in the same guarded write that applies the effect, so a duplicate arriving twice at once
cannot slip through between the check and the write.

The usual advice is a tight timestamp window, and that is wrong here: the design deliberately
delays packets three to five days in each direction, so a window has to be long, a fortnight or
so. The UUID record is what actually prevents replay; the timestamp only discards the absurdly
old.

### The receiving endpoint

**A registered REST route, not a PHP file in the plugin folder.**

    register_rest_route('ido/v1', '/packet', [
        'methods'             => 'POST',
        'callback'            => ['IDO_League_Endpoint', 'receive'],
        'permission_callback' => '__return_true',   // the signature is the gate
    ]);

A loose `receive.php` in the plugin directory is reachable on its own, has to bootstrap WordPress
by guessing at the path to `wp-load.php`, and runs before any of WordPress's protections exist.
That pattern is behind a long list of plugin vulnerabilities. A route runs with WordPress already
loaded, only answers the method it declares, and lives somewhere a reviewer can find.

`permission_callback` returning true deserves a comment in the code, because it looks like the
classic mistake. It is deliberate: the caller is another server, not a logged-in user, so there is
no cookie and no nonce to check. **The HMAC is the authentication.** What must never happen is the
opposite error of leaning on WordPress authentication here, which would mean a peer needed an
account.

Details that matter:

- **Verify the raw body.** `$request->get_body()` gives the bytes as received; that is what the
  signature covers. Never verify a re-serialised copy of the parsed parameters.
- **Send it as `text/plain`**, base64 of the JSON. With `application/json` WordPress parses the
  body into parameters before the handler runs, which is work done on unverified input.
- **Check the length first**, before verifying and long before parsing.
- **Do almost nothing synchronously.** Verify, record the packet as pending, return. The work
  happens on the next cron tick. A request that only writes one row is hard to abuse, cannot time
  out mid-battle, and leaves the packet available for inspection.
- **Answer in generalities.** A handler that explains *why* verification failed is an oracle for
  whoever is probing it. Log the detail locally; return a bare status.
- **A duplicate is a success, not an error.** If the UUID has been seen, return the same accepted
  status as the first time. Returning an error makes a sender retry forever.

Useful statuses: 202 accepted, 400 malformed, 401 signature failed, 413 too large, 429 too many.

One caveat worth knowing: security plugins and a few hosts disable or filter the REST API. If a
league member hits that, the fallback is `admin-post.php` with a `nopriv` action, which is equally
WordPress-loaded and equally unauthenticated by design. The endpoint logic should not care which
one delivered the bytes.

### If the transport is email

Email is the more hazardous of the two options, and the reasons are worth stating:

- **The From header is decoration.** Anyone can forge it. The signature is the identity, and the
  envelope must never be trusted for anything.
- **Reading a mailbox means storing mailbox credentials** in WordPress, with the same no-vault
  problem as above, and those credentials are usually worth more than the game. Use a dedicated
  mailbox that can reach nothing else, and an app password where the provider supports one.
- **Anyone can email that address.** The handler must therefore treat every message as hostile
  input: size-cap, verify, then parse, and discard anything that fails at any step without
  attempting to be helpful about it.
- **Attachments are never processed.** The packet is base64 text in the body. Nothing arriving by
  mail is ever written to disk as a file.
- **Mail is lossy and reorders freely**, so retries and idempotency are mandatory rather than
  optional.

**HTTPS is the safer default**, and the earlier argument for email does not hold up: a WordPress
site is already a public web server, so there is no firewall rule to negotiate. A signed POST to a
REST route gives TLS in transit, a synchronous success or failure that makes retries deterministic,
no stored mailbox credentials, and no MIME layer to mangle a signature.

The envelope should be identical either way, so the transport stays a detail. Build HTTPS first
because it can be tested synchronously; add email afterwards as an alternative carrier if the
slower, patchier feel is wanted for its own sake.

## Founding a league

A league is created by one site, the **originator**, which is also the hub. It has a name, an id,
and a ruleset. Everything about fairness follows from the originator owning that ruleset and the
members accepting it.

### What the originator sets

- **Name and id.** The name is what players see: "the Westmarch League". The id is a UUID that
  never changes, so a rename does not orphan the members.
- **The ruleset**, which is the game-affecting settings listed earlier: turns a day, turn cap,
  starting turns and resources, building and training costs, explore yield, combat percentages,
  target bands, truce length, agent cost and limit, market tax.
- **The round calendar**, which matters more than it sounds. See below.
- **Exchange cadence and the delay range**, the three to five days, so every member waits the same.

Each ruleset carries a **version number**, bumped whenever the originator changes anything, and a
**fingerprint**, the hash that rides in every packet. A member running version 4 against a league
on version 5 is told which setting differs rather than silently losing.

### Rounds have to be synchronised, not merely the same length

Giving every site a 45-day round is not enough. If the sites start their rounds on different days,
a site whose round began last week fields mature kingdoms against a site that wiped yesterday, and
it can keep doing so forever by timing its own resets.

**The league owns the round calendar**: length *and* start date. Members wipe together and begin
together. That also gives a league something worth having, a shared season with a shared ending,
and makes the Hall of Fame comparable across sites.

A site can still run local rounds on its own clock before joining a league. Joining means adopting
the league's calendar at the next boundary.

### Joining, and when the settings bite

The originator issues an invitation carrying the league id, the hub URL and a shared secret
exchanged out of band. The joining administrator enters it once; the hub confirms; the member
stores the ruleset and shows those settings read-only in the admin, marked as set by the league.

**League settings take effect at the start of the member's next round, not on joining.** Changing
turns a day or training costs under players who planned around them is unfair in a way that has
nothing to do with cheating, and a round is short enough to wait for. The exception is a setting
that only affects league play, which can apply at once because nothing local depends on it.

Leaving a league releases the settings back to local control at the next boundary, for the same
reason.

### What the hub does not get to do

The hub holds the ruleset and the calendar. It does **not** resolve battles, hold kingdoms, or
arbitrate outcomes: the defending site always computes its own. A compromised hub should be able
to disrupt a league's schedule, which is annoying, rather than rewrite anyone's kingdom, which
would be fatal.

## League war: how a march between sites resolves

The shape, settled: a site marches on another site. Kingdoms commit forces, the packet crosses,
the defending site resolves it, and a result packet comes home days later carrying survivors and
spoils. Strength decides it, and a strong defence turns the outcome around on the attacker. This
is the BRE inter-BBS idea, and the delay is the point.

Three decisions inside that shape change the game entirely, and they are worth making deliberately
rather than discovering.

### 1. Compare committed forces, not whole sites

Tempting to weigh site against site. It does not survive contact: a league with a fifty-kingdom
site and a five-kingdom site would never see a fair fight, and the small site would be farmed.

**The battle compares what was actually committed.** Site size only decides how much a site can
afford to send, which is a real advantage without being an automatic win. The maths is the one
Phase 1 already uses, `IDO_Military::attack()` over an explicit force array, with the committed
forces of every participating kingdom summed on each side.

### 2. Only what is committed is at risk

"A percentage of the assets of the site attacked" needs a sharper answer to the question *whose*.

Taking a slice of every kingdom on the losing site punishes players who never agreed to the war,
for a decision their administrator made. One ruler logs in to find their army thinner because
somebody else picked a fight. That is the fastest way to empty a league.

**Kingdoms opt in by committing.** A kingdom that sends nothing neither gains nor loses. Spoils go
to the kingdoms that contributed, in proportion to what they risked. The site is the banner; the
kingdoms are the participants.

### 3. Spoils must not snowball

The obvious version, where a winner absorbs a share of the loser's army, compounds: a site that
wins once is stronger for the next exchange, wins again, and a league is decided in a fortnight.
A three to five day cycle makes this worse, not better, because there is no time to recover between
blows.

Two ways to keep spoils meaningful without a runaway:

- **Take gold and stores, not soldiers.** Plunder is the classic reward and does not directly
  raise the winner's military strength, so it has to be converted through the same training costs
  everyone else pays.
- **Captured troops become peasants, not troops.** Prisoners put to work is thematically right and
  gives the winner growth rather than an army, which the loser can rebuild against.

Whatever the mix, **cap the take against what the loser committed** rather than against everything
they own, so a site cannot be stripped by one unlucky exchange.

### The sequence

1. Kingdoms commit forces. Troops leave the muster immediately and show as in transit, so the same
   army cannot be committed twice while a packet is in flight.
2. The packet is signed and sent, and the delay is applied at the receiving end so the sender
   cannot shorten it.
3. On the tick after the delay expires, the **defending site resolves the battle** against the
   defenders standing at that moment. Not at the moment of sending: the attacker commits blind,
   and that uncertainty is the feature.
4. A result packet returns, itself delayed. It carries survivors, spoils and a report.
5. The attacking site applies it on arrival, releases the escrow, and posts to the gazette. If the
   result never arrives, the escrow times out and the troops come home, on the reasoning that
   losing an army to a network failure is worse than the small chance of a double release.

### What stays true from the local game

The defender always computes their own outcome. The attacker's packet asserts only what left.
Both sites run the same maths because the ruleset fingerprint says so. Every write that applies a
result goes through the same guarded, clamped path as everything else, so nothing overflows and
nothing goes negative.

## What Phase 1 already provides

- Combat resolution is one service (`IDO_Military::attack()`) that takes an explicit force array,
  so a remote force can be fed into the same maths without duplicating it.
- Resource and troop movement all runs through `IDO_Kingdom::pay()`, which is atomic and guarded,
  so escrow can be built on it rather than beside it.
- `IDO_Lock` gives a named lock for a critical section that spans several rows.
- Battles are already persisted with both reports, which is the natural place to record a remote
  battle's UUID.

## What would need adding

- A `ido_leagues` table: league id and name, hub URL, ruleset version, ruleset, fingerprint, round
  calendar, whether this site is the originator.
- A `ido_sites` table: peer site URL, shared secret, sequence counters, trust status.
- The league ruleset applied locally, with those settings locked in the admin and a fingerprint
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
