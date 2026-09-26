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
    GET    ido/v1/standings   hub only: the league table, aggregated from what members have sent

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

I do not know what the original inter-BBS games capped this at, and would rather say so than invent
a number. What can be reasoned about:

- **Secrets stay linear** under hub-and-spoke: one per member, not one per pair. Ten sites is ten
  secrets, not forty-five.
- **The real limits are social and legible.** A league is people who broadly trust each other and
  who notice when a member's numbers look wrong. A standings table spanning thirty sites is noise
  nobody reads, and nobody watching means nobody catching.
- **Traffic is bounded by participation**, not membership, since only committed forces generate
  packets.

So the cap is a judgement, not a technical ceiling.

**The decision: up to 20 sites, configurable, defaulting lower.**

- `league_max_sites` is set by the originator and enforced by the hub at enrolment. A league that
  is full refuses a token with a clear reason rather than a generic failure, so an administrator
  is not left guessing.
- **20 is the ceiling**, not the default. A league that size is a real tournament: enough sites
  that the same two empires are not meeting every exchange, and enough standings to be worth
  reading.
- Somewhere around **8 to 12 is the comfortable middle**, and a first league is better small. It
  is easy to admit another site and awkward to ask one to leave.

Worth knowing before choosing 20: the limits that bite first are not technical. Secrets stay
linear, and traffic follows participation rather than membership, so the hub is not the problem. A
twenty-site league is hard because twenty administrators have to stay reachable, agree on a
ruleset, keep their crons running and notice when a member's numbers look wrong. Watching is what
keeps a league honest, and it does not scale as easily as the packets do.

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

1. Empires commit forces. Troops leave the muster immediately and show as in transit, so the same
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

Two things have to be right for this not to deadlock the league.

**It is enforced by the receiver, not the sender.** A sending site that has been compromised will
ignore its own limit, so the check that matters is the defending site refusing a second war packet
from the same peer inside the window. The sending side enforces it too, but that is courtesy to
the player, not a control.

**Only war packets count.** Results, news, ruleset syncs and enrolment traffic are exempt and must
be, or a site attacked by three peers in one day could not answer any of them, and the league
would jam within a week. The limit is on *starting* a fight, never on finishing one.

The window is 24 hours from the last accepted war packet, per peer pair, recorded on the receiving
side. A packet refused for the limit gets a distinct, honest response so the sender can tell it
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
home. Against a 45-day round that is a handful of league exchanges at most, which is the intended
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

## The League screen

A single admin page under Imperial Dominion, and the one place a game master manages all of this.

**When no league exists**, it offers two things: found a league, or join one with an invitation.
Founding asks for a name, a member cap defaulting well below the 20 maximum, the ruleset it will
publish, the round calendar and the delay range. Joining asks for the invitation blob and nothing
else, since everything else arrives with it.

**Once in a league**, the page shows what a game master actually needs:

- the league name, whether this site is the originator, and the ruleset version in force
- the member list with each site's URL, status, last contact and rules fingerprint match
- pending enrolments awaiting approval, for an originator
- the invitation generator, showing a token once and never again
- the packet queues, inbound and outbound, with what is due and when
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

So a league **closes marching before the round ends**, by the worst-case round trip, calculated as
twice the maximum delay rather than written down as a fixed number. With a three to eight day
range that is the last sixteen days; narrow the range and the cutoff narrows with it. The last stretch
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

The sixteen day close is two times the maximum delay, and it should be calculated that way rather
than written down. A league that narrows its range to three to five days gets a ten day cutoff for
free, and one that widens it to a fortnight gets a twenty-eight day cutoff without anyone having to
remember to change a second number.

    $cutoff_days = 2 * $league['delay_max_days'];

### The one that still needs deciding

Whether a site can change round length while a league is running. The honest answer is no: the
league owns the calendar, members wipe together, and a member running a different length is a
member playing a different game. Changing it should take effect at the next boundary, like every
other league setting, and should be the originator's to change.

## Threat model

Written for review rather than reassurance. Where something cannot be defended, it says so.

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

**Parsing.** `json_decode` with depth and size caps, associative mode, never `unserialize()`.
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

## A caution

The moment this plugin accepts data from another site, the attack surface changes completely:
every assumption Phase 1 makes about input coming from a signed-in WordPress user with a nonce
stops holding. The packet handler should be written as if the sending site is hostile, because
one day one of them will be compromised.
