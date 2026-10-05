# Imperial Dominion Online

**A turn-based empire building and conquest game for WordPress.**

The old empire is broken. Its provinces lie open, its granaries are unguarded, and every ruler
with a banner and a few hundred acres believes the purple is theirs. Claim land, raise an empire,
feed it, arm it, and take what your neighbours cannot hold.

Imperial Dominion Online is played entirely in the browser through ordinary WordPress pages.
Players sign in to your WordPress site and rule.

---

## Disclaimer: you install this at your own risk

**This software is provided as is, without warranty of any kind, and you run it at your own risk.
The author accepts no responsibility for any loss, damage, downtime, data loss or compromise
arising from installing or running it.**

Every effort is made to write this carefully: input is validated, output is escaped, queries are
prepared, admin actions check capabilities and nonces, and the design decisions behind all of it
are written down rather than assumed. None of that is a guarantee. New vulnerabilities are found in
software every day, in WordPress, in PHP, in plugins, and in this one. Nobody can promise otherwise,
and anybody who does is selling something.

What that means in practice:

- **Keep backups**, and know how to restore them.
- **Keep WordPress, PHP and this plugin updated.** The honest answer for an old install is to update.
- **Do not run it on a site you cannot afford to have broken**, at least not before you have tried it
  somewhere you can.
- **League play deserves a second thought**, because it is the only part that accepts data from
  outside your site. It is off by default, its endpoint is a separate switch that is also off by
  default, and both are off deliberately. Turning them on is a decision to accept that exposure.

If you find a security problem, please report it privately: see [SECURITY.md](SECURITY.md).

This is the plain-language version of the warranty disclaimer in the GPL, under which this plugin is
licensed. Sections 15 and 16 of [the licence](LICENSE) are the legally operative text, and they say
the same thing at greater length.

---

## Inspiration and attribution

Imperial Dominion Online is inspired by **Barren Realms Elite**, the BBS door game that, from the
early 1990s, had players dialling in to spend a handful of turns a day building an empire,
trading on a player-driven market and raiding their rivals.

It is a **completely new game** and not specific to the old BRE.

- Original setting, lore, names, buildings, troops, titles and rules.
- Its own economy, combat maths, covert system and round structure.
- A web-based way of playing built for WordPress, with pages, forms and a terminal-styled
  interface drawn in CSS rather than ANSI.

It contains no code, text or artwork from Barren Realms Elite and is not affiliated with or
endorsed by its creators or rights holders.

---

## Requirements

- WordPress 7.0 or newer
- PHP 8.0 or newer
- MySQL or MariaDB (InnoDB recommended)
- **HTTPS, with a valid certificate.** Every host worth using insists on it, most issue one free
  through Let's Encrypt, and players are signing in to your site with a password.

Local play will run over plain http and nothing stops you, but it means a ruler's WordPress login
crosses the network in the clear. **League play requires HTTPS and will not accept an `http://`
address for itself or for a peer**, because a league carries signed packets and a shared secret
between sites. That rule holds in development as well as production: there is no setting that turns
it off.

## Installing

**Install the built zip, not the repository.** A zip of the whole repository will run, but it
carries the test suite, the build tool and about twice the files it needs. Download a release, or
build one yourself with the two commands below.

1. Copy this folder into `wp-content/plugins/` and activate **Imperial Dominion Online**.
   Activation creates the tables, writes the default settings and opens **Round 1**.
2. Go to **Imperial Dominion &rarr; Dashboard** and press **Create any missing game pages**.
   That creates the nine pages below, each holding a single shortcode. Every title but the front
   page carries an `Imperial Dominion - ` prefix, so the game's pages sort together and cannot be
   confused with the rest of the site. Pressing the button again renames any page whose title has
   drifted from this list.
3. Adjust the game under **Imperial Dominion &rarr; Settings**. If you want a link in the site's
   own menu, set **Assign menu to theme location** there: it creates a one-item menu pointing at
   the game's front page. The other eight pages are kept out of menus a theme builds automatically
   from the page list, since the game carries its own navigation on every screen.
4. On a quiet site, set up a real cron (see **Maintenance** below). WP-Cron only fires when
   somebody visits, which is no good for a game where turns arrive at midnight.

### Building the installable zip

The archive is built from a checkout by one script, which reads the version straight out of the
plugin header so an archive can never claim a version the code does not carry:

```
php tools/build-zip.php
```

It writes `imperial-dominion-online-<version>.zip` beside the checkout, or into a directory you
name as the first argument. It needs PHP's `zip` extension, which some builds ship without
enabling:

```
php -d extension=php_zip.dll tools/build-zip.php
```

**If `php` is not on your PATH**, which is usual on Windows and on any machine where PHP came
bundled with something else rather than being installed on its own, call the binary by its full
path. Local (Flywheel) keeps one per PHP version, so the version number in the path changes as
Local updates:

```
"C:/Users/<you>/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"     -d extension=php_zip.dll tools/build-zip.php
```

XAMPP keeps its binary at `C:/xampp/php/php.exe`, and MAMP under
`/Applications/MAMP/bin/php/php<version>/bin/php`. Any PHP 7.4 or later will do: the script only
reads files and writes a zip, so it does not need the version the plugin runs on.

**What it leaves out**, and why each one matters if you ever package this by hand instead:

| Left out | Reason |
| --- | --- |
| `tests/` | The suite, including tests that create and drop real tables |
| `tools/` | This script; nothing in it belongs on a live site |
| `docs/` | Design documentation, never read at runtime, about half the weight |
| Anything starting with `.` | `.git`, `.gitignore`, and tooling directories such as `.claude`, which can hold an entire second checkout in a git worktree |

That last rule is deliberately a rule rather than a list. Naming the dotted directories known at
the time is how a whole second copy of the plugin once shipped inside an archive.

Everything that remains is needed at runtime: `sql/` is read when the tables are created,
`maintenance/` holds the CLI entry points a server cron calls, and `README.md`, `SECURITY.md` and
`LICENSE` are what a plugin is expected to carry.

### If you install the repository anyway

No code runs that should not. Every PHP file in the plugin refuses to run unless WordPress loaded
it, every file under `tests/` and `tools/` refuses to run unless it was started from a command
line, and `uninstall.php` runs only during an uninstall. Those guards are plain PHP and work on
every server.

What a repository archive adds is **readable files**, not runnable ones: the design docs, the
schema, and -- if you archived your own working copy rather than using GitHub's download button --
the whole `.git` directory, which holds every version of every file ever committed.

Two things in the plugin push back on that:

- **`index.php`** in every directory, so a listing shows nothing wherever the host leaves indexing
  on. Plain files, so this works everywhere.
- **`.htaccess`** at the plugin root, which returns 404 for any path inside a dot-directory and for
  `.sql`, `.md` and similar readable files.

`.htaccess` is Apache and LiteSpeed only. **nginx ignores it entirely**, and on nginx the schema
and the docs in a repository archive stay readable. It uses only `mod_alias` directives, which
need just the `FileInfo` override, deliberately: `Options -Indexes` and `<FilesMatch>` with
`Require` both need overrides a host may not grant, and where it does not the result is a 500 on
every request rather than a rule that quietly does nothing.

None of which is a substitute for installing the built zip, which simply does not contain any of
it.

### The pages

| Page | Shortcode | What it is |
| --- | --- | --- |
| Imperial Dominion | `[ido_guide]` | The front page: the game's name, the rules and the standings |
| Imperial Dominion - Empire | `[ido_empire]` | The state of the empire, and what one turn currently yields |
| Imperial Dominion - Lands | `[ido_lands]` | Settle wilderness, raise buildings and siege weapons, and trade |
| Imperial Dominion - War Dept | `[ido_war]` | The muster, the war room and the spy court, on one page |
| Imperial Dominion - Gazette | `[ido_gazette]` | Public news of the round |
| Imperial Dominion - Rankings | `[ido_rankings]` | Standings and the Hall of Fame |
| Imperial Dominion - League | `[ido_league]` | The league table, the open muster, and what you have given (league play only) |

### The rules, on a page of your own

`[ido_how_to_play]` renders the whole guide — turns, the economy, war, covert work, the market,
titles and rounds — on any ordinary page or post. It is the same text the game's own guide screen
shows, read live from the current settings, so a board granting 12 turns a day says 12.

It carries no navigation, no status bar and no footer, and it never assumes the reader is playing:
a visitor is invited to sign in, a signed-in reader without an empire is invited to claim one, and
a ruler already in the round is offered nothing. Drop it anywhere, including a page open to the
public.

| Attribute | Default | What it does |
| --- | --- | --- |
| `heading` | `yes` | `heading="no"` drops the world name and game title, for a page with its own |
| `cta` | `yes` | `cta="no"` drops the sign-in panel, leaving the rules alone |

### The gazette, on a page or in a widget

`[ido_news]` renders the Imperial Gazette anywhere: a sidebar widget, a front page, a post. The
**Gazette page itself is public too**, so a visitor who has never signed in can read it.

That is deliberate, and it is the same argument as the public rules: nobody joins a game they
cannot see the shape of. A list of wars fought, empires founded, floods, hanged spies and the lead
changing hands is a board that is plainly alive, which is a better invitation than any description
of one. Nothing in it is private. The gazette names empires and rulers, which are names players
chose for themselves, and never a WordPress account.

| Attribute | Default | What it does |
| --- | --- | --- |
| `limit` | `12` | How many items, from 1 to 100 |
| `compact` | `no` | `compact="1"` is widget mode: see below |
| `title` | `The Imperial Dominion Gazette` | The heading above the list; `title=""` drops it |
| `heading` | `yes`, off in compact | The world name and game title above the heading |
| `cta` | `yes` | `cta="no"` drops the invitation to take an empire |

Twelve is the default because a sidebar has to be readable at a glance rather than scrolled, and
twelve is roughly a day of a busy board. The Gazette page itself still shows a hundred.

For a sidebar, reach for compact:

```
[ido_news limit="10" compact="1"]
```

`compact="1"` means *suitable for a narrow column*, which turns out to be three things rather
than one.

**One line of heading, then the news.** The page-style embed prints the world name, the game title
and a "... Gazette" heading beneath them both, which is three lines saying much the same thing
before a reader reaches anything worth reading. Compact keeps the title and drops the other two.

**A tighter line.** A relative time above each item instead of a date and a type chip beside it,
and no round summary.

**None of the house-keeping**, so ten slots hold ten things a stranger would find interesting.

`[ido_news compact="1" heading="yes"]` puts the world and game names back above the title if you
want them.

What it drops is `league` and `market`. League news carries the drama and the plumbing under one
name: a march won, a muster's escrow accounting, and "the heralds have lost their place in the
ledgers", which is a database restore notice and the worst possible line to greet a prospective
player with. On a league board that traffic would otherwise fill every slot in a ten-line widget.
Market lines go for being repetitive rather than for being noise; they read fine on the page,
where there is room. Everything else stays, including `round` and `reset`, because a new round
beginning with the land unclaimed is the best recruiting line the game has.

The Gazette page is unaffected and still shows everything.

#### Putting it in a sidebar widget

**Block themes** (Twenty Twenty-Three, Twenty Twenty-Four, Twenty Twenty-Five and most themes
released since 2023) have no Widgets screen. Go to **Appearance -> Editor**, open the template or
template part that holds the area you want, add a **Shortcode** block where you want the news, and
paste the tag into it.

**Classic themes** use **Appearance -> Widgets**. Add a **Shortcode** block to the sidebar, or a
**Text** or **Custom HTML** widget, and paste the tag in.

Either way the tag is the same:

```
[ido_news limit="10" compact="1"]
```

If the widget or block already has a title of its own, drop the plugin's so the heading is not
said twice:

```
[ido_news limit="10" compact="1" title=""]
```

The stylesheet is loaded for you. The plugin normally decides whether a page needs it by looking
for its shortcodes in the post content, and a widget is not post content, so it also checks the
widget options directly -- `widget_block` for block widgets, `widget_text` and `widget_custom_html`
for the classic pair. That check is what keeps the CSS in the page head: without it the stylesheet
arrives after the markup and the widget visibly repaints on every cold load.

Two things worth knowing before you put it on a busy front page:

- The gazette is **per round**. When a round ends and the next opens, the widget empties and fills
  again from the new round, which is correct but looks like a fault if it takes you by surprise.
- Items are **deleted after 14 days** by the daily job (`news_retention_days`). A board that goes
  quiet for a fortnight shows an empty widget reading *The criers have nothing to report.*

### What reaches the gazette

| Event | Written when |
| --- | --- |
| `founding` | An empire is claimed |
| `war` | A march is fought, naming the acres, plunder or siege train won |
| `covert` | A mission leaves its mark, or an agent is caught and hanged |
| `title` | A ruler crosses a net worth threshold and takes a higher rank |
| `market` | A single trade worth 100,000 gold or more changes hands |
| `rankings` | The lead changes hands, checked once a day |
| `barbarians` | Raiders take gold and grain from a leading empire |
| `disaster` | A drought, a plague of insects or a flood |
| `defeat` / `relief` | An empire is ruined, or given relief |
| `round` / `reset` | A round begins or ends, or the board is refounded |
| `league` | Musters, marches and results between sites (league play only) |

Covert lines never name who sent the agent, in either direction. A body on the gates is public;
whose body it is stays a rumour, because a gazette that printed it would turn every failed mission
into a declaration of war the ruler never made. Market lines name neither party, for the same
reason: who is buying iron in quantity is exactly what a rival would pay an agent to find out, and
the spy court is where that answer belongs.

---

## League play (optional, off by default)

A league joins this site to other WordPress sites running this game. Rulers here stop warring with
each other and become one side: they raise an army together and march on another site, which takes
days to arrive, resolves there, and comes home with spoils.

**It is entirely optional and off unless you turn it on.** A site that never opts in has no league
tables, no public endpoint and no extra attack surface. Most sites will play locally and never
touch it.

Set it up under **Imperial Dominion &rarr; League Play**, which has three states: not opted in, opted
in but not in a league, and in one. Opting in creates the tables. Then either found a league, which
makes this site the originator that owns the ruleset and the calendar, or join one by pasting an
invitation.

What a league takes over:

- **The settings that decide who wins** become the league's: turns a day, starting resources, costs,
  combat percentages. A site that granted its own rulers 200 turns a day would win a league without
  ever fighting well. Cosmetic settings stay local.
- **The round calendar**, length and start, so members wipe together and a season is comparable.

A few things worth knowing before you enable it:

- Your site must be reachable over **HTTPS at a public address**. The hub calls back to prove you
  control it, so `http://` and development addresses are refused.
- The **endpoint other member sites deliver to is off by default.** Opting in does not open it and
  joining a league does not open it: you turn it on yourself under **Settings &rarr; League play**,
  and until you do, this site cannot receive marches or results. To put it beyond the reach of the
  admin screens entirely, add `define( 'IDO_LEAGUE_DISABLE_ENDPOINT', true );` to `wp-config.php`,
  which a compromised administrator account cannot undo.
- Invitations carry a **one-time token that expires, never the shared secret**. The secret is
  generated at the end of the handshake and sent to the joining site over TLS, so nobody copies it
  by hand.
- There is a **kill switch** that stops all league traffic without deactivating the plugin or
  leaving the league.

The full design, including the packet format, the threat model and what is still open, is in
[docs/CROSS-SITE.md](docs/CROSS-SITE.md). To try it on two Local sites on one machine, see
[docs/TWO-SITE-TESTING.md](docs/TWO-SITE-TESTING.md): it needs one deliberate, environment-gated
allowance for private addresses, and HTTPS is still required even there.

### How two sites pair

1. The originator founds a league and generates an **invitation**: a one-time token that expires in
   seven days. Send it however you like, email included, because it is not worth stealing for long.
2. The joining administrator pastes it in, then presses **Present the invitation**. Their site calls
   the hub with the token.
3. **The hub calls back.** The joining site answers with an HMAC of the hub's nonce, keyed by the
   invitation token, which proves two things at once: it controls the address it claims, and it holds
   the invitation. Nobody can enrol a site they do not run, and a stolen token is useless without it.
4. The originator **approves** the member on the League screen. An invitation alone is not enough:
   two administrators agreeing is the point.
5. The joining site presses **Collect the secret**. The shared secret is issued once, inside the TLS
   response to a request that site made itself, so it never travels by email, never appears in a
   link, and is never copied by a person. The invitation is spent in the same write.

What a human handles is a token that expires. What the software handles is the long-term key.

### Packets, and the queues either side of them

Nothing happens inside the request that asks for it. A packet is signed and queued, and cron sends
it; an arriving packet is verified, staged, and applied by cron when its wait is over. Three things
follow from that, and all three are deliberate:

- **The delay belongs to the receiver, and is not a setting.** Three to six days, drawn fresh for
  every packet from the CSPRNG by the site receiving it, so a sender cannot shorten its own march or
  know what the defender will have standing when it lands. Three is the floor because the wait is
  counted in daily cron runs: a packet arriving today is fought on the third daily tick after it, and
  the seventh day belongs to the ride home. The result rides home in a day, so a ruler sees the army in battle one morning and reads the dispatches
  the next. News waits for nothing, and only the daily run may land a march, so the dispatches arrive
  overnight rather than at any hour.
- **A defender learns nothing until the battle is fought**, including the administrator. Not that a
  march is inbound, not its size, not the day it is due, not a count in a queue. Surprise is the
  mechanic, and a defender who could see something coming would reinforce, recall an army or empty
  the treasury without it even being cheating.
- **The endpoint stays cheap.** It writes one row and returns. A battle cannot resolve half way
  through an HTTP timeout, and a request that only stages is hard to abuse.
- **A peer being down loses nothing.** The outbound queue retries with backoff and gives up after
  eight attempts with the reason recorded, rather than dropping a packet or hammering a dead site.

**News packets** are the first exchange, and the least dangerous: how many empires are playing, what
the site is worth in total, its largest empire, whether it is accepting marches, and when it read its
own database. Never player names, never per-empire figures, and never anything about musters or
marches in flight, because a league table must not become an early-warning system. Those figures are
a claim signed by the claimant, so they are stored with the date claimed, shown as claims, and
nothing is ever ranked on them.

### A march, start to finish

1. A ruler **calls a muster** against a member site, and commits the first force. One muster to a
   site at a time.
2. Other rulers **join** over the next five days. Committing takes the troops out of the empire
   immediately, so the same army cannot stand at home and march at once. A pledge can be withdrawn
   while the window is open, and not after.
3. One ruler may **send their agent** with the army. One agent to a march: the first to offer takes
   the slot. He rides ahead and tries to open the walls, and if he is caught he hangs.
4. The window closes. If the army clears the minimum it **marches**; if not, everything is returned
   and the gazette records a war called and not raised.
5. Three to six days later the defending site **fights it**, against whatever happened to be
   standing, and sends the result back. A loss is paid by every empire on the defending site, in
   proportion to each one's share of what is taken.
6. The dispatch arrives and waits a day. That is the day a ruler sees the army **in battle**: the
   news is in hand and not yet read.
7. The next daily tick opens it. **Survivors go back to whoever sent them**, unit by unit, and
   spoils are split by what each ruler risked.

If no dispatch ever comes, the army is given up for lost after a fortnight and what remains of it
comes home. Losing an army to a network failure is worse than the small risk of settling one twice,
and settling twice cannot happen anyway.

### The league table

`[ido_league]` shows where the site stands, the muster being raised now, the table, what you
personally have given, and the recent exchanges. It is created only when this site is in a league,
and disappears from the navigation when it leaves.

The table is built on one rule: **rank on what was witnessed, display what was asserted, and label
the difference.** A battle is witnessed by two sites, since the defender computes it and signs the
result and the attacker holds the same document, so neither can invent it alone. Net worth and
empire counts are the other kind: a claim signed by the claimant, which proves the packet arrived
unaltered and nothing about whether it was true. So wealth is shown dimmed with the date it was
claimed, and **nothing is ever ranked on it**.

Scoring exists to close the obvious doors. Holding a wall pays the same as carrying a field, or
every site would empty its garrison and the only skill left would be guessing who marched this week.
Losing scores zero rather than negative, so nobody profits from arranging somebody else's defeat.
Repeat exchanges with the same peer decay, so the weakest member cannot be farmed.

### The league owns the calendar and the rules

**One season, one instant.** Seasons are computed from the league's founding moment in fixed steps of
its round length, in UTC, so every member arrives at the same two timestamps from the same two
numbers with nothing to coordinate and nothing to drift. A member offline through a rollover still
lands on the right season. A season ending at each site's local midnight would end up to a day apart,
and for that day one site would be playing a fresh round while another finished the old one, with
packets crossing between them.

A site joining mid-round adopts the shared calendar at its next tick: the local round ends and the
next one is aligned to the season.

**The numbers that decide who wins belong to the league**, not to the site: turns a day, starting
resources, costs, combat percentages, the market tax. They are laid over this site's settings at every
read rather than copied into them, so the league row stays the single source of truth and the game
master's own values wait untouched for when the site leaves. Those fields show as the league's on the
Settings screen, and are ignored on save as well as disabled, because a disabled input is a courtesy
to the browser rather than a rule.

A joining member adopts them at the next round boundary, never mid-round: changing turns a day under
players who planned around them is unfair in a way that has nothing to do with cheating. The
originator's own settings *are* the ruleset, so they are in force from the start.

Every packet carries a fingerprint of those settings. A march or a result whose fingerprint does not
match is refused, because both are arithmetic over shared numbers. News is accepted anyway: it asserts
nothing about the rules, and refusing it would blind both sites to each other for as long as the drift
lasted.

### What league mode does to local play

**There is no war within a site while its league runs.** Every ruler is on the same side, and the
enemy is another site. Local marches are refused, and so are covert missions against a neighbour:
your agent rides with the army instead.

Both are refused in the service rather than hidden from the screen. A page that does not offer an
order is not the same as a game that will not carry one out, because the order is a POST and a POST
can be sent by anybody who has seen the form once.

The War Dept says so and points at the League page, where armies are actually raised. Leaving a
league gives local war back.

### Relief for a ruined empire

One empire beaten flat is not the same as a board beaten flat. An empire whose net worth falls under
a quarter of a founding grant **and** which holds no soldiers is ruined, and is resettled
automatically a day later with a founding grant and a crown truce.

- **Both conditions**, so an empire caught between armies is never swept up by it.
- **Relief, not a new identity.** It keeps its name, its ruler, its war record and its place in the
  standings. Only what it holds is restored, and nothing is ever taken away: each value is raised to
  the founding figure rather than set to it.
- **Once a round**, recorded on the empire, so it cannot become a strategy. Tanking does not pay
  anyway: to qualify you must destroy more than relief gives back.
- **Announced in the gazette**, because a silent restoration looks like a bug to everyone else.
- The day in between is deliberate. Losing has to be felt, and `defeat_grace_hours` sets how long.

In league play a relieved empire **rejoins when its truce ends**. It cannot pledge to a muster while
sheltering under relief: the grant exists to get a ruined ruler playing again, and shipping it off to
somebody else's war for a fractional share of the spoils is the fastest way to be ruined twice.

### Starting the board over

A board can be beaten flat: every empire in ruins, nothing to build from, no way back inside a round.
When every empire together is worth less than a quarter of what they were founded with, the board
**refounds itself** on the next daily run. A game master can also do it from **Settings**, behind two
confirmations.

**It is a new install with the players kept.** Every empire keeps its name and its account and loses
everything else: land, buildings, armies, treasury, siege weapons and agents are replaced by the same
founding grant a new ruler gets, and everyone gets the same opening truce at the same moment. The
Hall of Fame is not touched, because a board being beaten flat is part of its history.

**All rank and all scores are forfeit**, and in a league the record goes too: every exchange that
round stops counting toward this site's score. A site that could take a fresh founding grant and keep
its league points would have found the best move in the game. While the truce holds, the site cannot
be marched on and cannot march — protection is not a shield to attack from behind — and a march
already in flight is turned away with the attacker's force returned intact.

**Current state:** Phase 2 is complete. Opting in, founding, invitations, the enrolment handshake,
the packet queues, news, the muster with its window and escrow, the march, battle resolution, spoils,
the agent who rides ahead, the return leg, the escrow timeout, the league table and page, local war
standing down, the board reset and its grace period, relief for a ruined empire, the cron workers,
leaving and the kill switch are all built and tested. Evicting a member is designed and not yet
built, and handing a league to a new originator is still an open question.

---

## How a round is played

Each ruler gets **one empire per round**, tied to their WordPress account.

**A turn is never spent on nothing.** Fortifications stop lifting defence past a point and barracks
stop discounting past theirs, so the game refuses an order that would go beyond it and says how many
would still count. The Lands screen shows the ceiling before you order. In a game where turns are the
currency, a building that quietly does nothing is a trap rather than a choice.

**A night's produce, if you want it.** `daily_yield_turns` grants every empire the produce of
that many turns on the daily tick, without a turn being spent. 0 is the original rule &mdash;
nothing arrives unless a ruler spends a turn on it &mdash; and 1 is the gentlest way to stop a
board stalling between visits, since a ruler who misses a day otherwise returns to exactly what
they left. Upkeep and appetite are charged as on any turn, so an empire that cannot feed itself
still will not, and neither barbarians nor disasters are rolled on a night nobody ordered.

**Turns are the currency.** You are granted 25 turns a day (configurable), stored up to 100, and a
new empire is founded with 25.
Every order costs turns, and every turn spent pays out your income at that instant. Turns sitting
unspent earn nothing at all, which is what keeps the game moving.

**People have to be fed.** A hundred peasants eat 140 grain a turn, so one farmstead feeds about
two homesteads of people rather than eight. That is what makes the map a decision: population costs
land that would otherwise have made gold, and an army eats on top of it. Both figures are settings,
and lowering the appetite turns farms back into an afterthought.

**Land and buildings.** Send settlers to claim wilderness, then raise buildings on it. The bigger
your empire, the fewer acres a scouting party finds and the more each one costs, until taking land
from a neighbour is cheaper than settling it. Building orders finish on the daily tick.

Every one of these figures is a setting under **Settings &rarr; The economy**, and the figure the
game uses is the figure the screen quotes: a building's output is stated once and read from there
by the economy, the guide and the admin alike.

| Building | What it does |
| --- | --- |
| Homesteads | House 30 peasants each, and peasants pay the taxes |
| Farmsteads | 85 grain a turn. One feeds about two homesteads of people |
| Mints | 60 gold a turn |
| Foundries | 25 iron a turn |
| Barracks | Trim up to 35% from the gold price of training |
| Fortifications | Up to +50% defence |

**The army.** Peasants become soldiers, so every regiment costs you tax revenue as well as gold
and iron. Offence counts only when you march and defence only when you are marched upon, so an
army built for one job is nearly useless at the other.

| Troops | Offence | Defence | Notes |
| --- | --- | --- | --- |
| Pawns | 0 | 3 | Cheap conscripts |
| Legionnaires | 1 | 9 | The backbone of any empire expecting to be hit |
| Centurions | 9 | 1 | Officers who lead from the front, worthless at home |
| Ballistae Legions | 18 | 4 | The only reliable answer to fortifications |

**Siege weapons.** Catapults are built in the siege yards on the Lands page, not mustered with the
army. They are neither a building nor a troop: they stand on no acre and take no peasant out of
the fields, they cost what a fortification costs, and they finish on the same daily tick a building
does. What they cost instead is troops and risk.

| Weapon | Offence | Defence | Crew | Notes |
| --- | --- | --- | --- | --- |
| Catapults | 5 | 3 | 5 legionnaires | Fight on attack and defence, and change hands when a battle is lost |

A catapult is not an army. It goes nowhere and does nothing until legionnaires haul it and work it,
five to a catapult, and the rule bites both ways: a catapult nobody is manning adds nothing to your
walls, and a siege train sent without its crews in the same force is refused. This is the one thing
that makes a legionnaire worth taking on an attack, since on its own it carries an offence of 1.

A catapult is also the only part of your strength a beaten enemy can take from you rather than
merely destroy. Every weapon you haul out is in the wager: weapons left at home are never at stake
when you attack, and every weapon you have men for is at stake when you are attacked. Whichever
side loses, the winner drags home **30%** of the loser's stake and a further **10%** is smashed on
the field, so a defeat costs 40% of what was in the fight. Both shares are settings.

**War.** A march costs 2 turns and resolves the moment you commit, against whatever the defender
has standing at that instant. Both sides get a written report. You may only attack empires worth
between 40% and 250% of your own net worth, at most three times each a day. New empires hold a
72-hour crown truce, which ends the moment they attack somebody.

- **Conquest** takes acres, and the buildings standing on them.
- **Raid** strips gold, grain and iron.
- **Siege** throws down buildings, fortifications first.

Whatever kind of attack it is, the catapults at stake change hands on the result.

**The spy court.** Two kinds of spy, differing in what they may do as much as in what they cost.
An **informer** is cheap, can only run reconnaissance, and is caught far more often. An **agent**
costs a good deal more, will run any mission, and is the only one who may ride ahead of an army in
league play. No ruler may keep more than one of each. Missions can fail, and a failed mission often
ends with the spy on a rope: replacing them means paying the price again. Reconnaissance tells you
what a rival is actually holding; the other missions burn granaries, wreck forges or set peasants
against their lord.

**The market.** Rulers post grain, iron and troops at their own prices. Goods leave
your stores the moment you post them and return if the lot expires or you withdraw it. The crown
takes 5% of every sale.

**Masterless empires** (optional, off by default). A board with three players has nothing to
march on, and this game is about marching. Switched on, the round carries provinces of the old
empire that no living ruler holds: real empires with land, granaries, walls and an army, attacked
through exactly the same combat, spoils and reports as a person. They are spread across a ladder of
strengths rather than all made mature, because a march is only allowed against an empire worth
40%-250% of your own and a field of uniformly powerful provinces would be invisible to everybody
who was not already powerful.

They are never counted as players: not in the rankings, the hall of fame, the player count the
barbarians wait on, or the measure of whether a board has been beaten flat. Barbarians and
disasters leave them alone. They never appear during league play, where there is no war within a
site at all. The War Dept marks them, so you always know whether there is a person on the other end.

Each one remembers the empire it was built to be and closes a share of the gap back to it every
day, so a province you strip is worth marching on again in a week rather than never. Farming one
pays less each time without any rule saying so, because spoils are a share of what is there.

**They strike back, if you let them** (a second switch, also off). They never march first. A
province marches only on a ruler who has attacked *it*, only within its memory, and only through
the ordinary rules: your crown truce, the net worth band, the daily limit on hitting one target.
No ruler is set upon more than once a night however many hold a grudge, and a grudge expires, so a
feud ends when you stop picking at it. It resolves on the daily tick, so you read what happened
over breakfast:

> Conquest of Tarquica was thrown back from your walls. Your losses: 120 pawns, 118 legionnaires.

**Weather and raiders.** Two things happen to an empire that nobody aimed at it. **Barbarians**
raid the leading empires for a share of gold and grain, as a brake on a runaway lead; see
[docs/BARBARIANS.md](docs/BARBARIANS.md). **Disasters** fall on anybody: a drought burns
farmsteads, insects eat stored grain, a flood sweeps away homesteads, each taking 7% of the one
thing, about one turn in sixty. Never two at once, and never during a crown truce, relief or a
board grace period. Destroyed buildings hand their acres back to wilderness. See
[docs/DISASTERS.md](docs/DISASTERS.md).

**The round ends** after 45 days. The standings are carved into the Hall of Fame, every empire is
retired, and the next round opens automatically, so a player who joins late is never permanently
behind.

---

## Maintenance

Two scheduled ticks keep the world turning:

- **Daily** grants turns, finishes building work, clears old gazette items and winds up a
  finished round.
- **Hourly** returns expired market lots and catches a round whose time ran out between daily runs.

### The Cron Maintenance Log

**Imperial Dominion Online -> Maintenance** keeps a log of both scheduled jobs: one line per job
per day, for the last ten days.

| Column | What it is |
| --- | --- |
| Day | The game day, in the site's timezone |
| Ran at | When the run that did the work finished |
| Started by | WP-Cron, `system cron`, or *Run now by* whoever pressed the button |
| Started | How many times the job was set going that day, including the ones that stood down |
| What it did | The summary from the run that did the work |

The count and the summary are kept deliberately apart. A tick can be started many times in a day
and most of those starts do nothing, because the second arrival finds the lock held or finds the
work already done. **A start that stands down never overwrites the run that did the work** --
otherwise midnight's real run would show as "skipped: it already ran today" from a 1am duplicate,
and a working site would read as a broken one.

So a job started three times with one line of work is normal and healthy; it means cron is
reaching the site from more than one direction, which is the arrangement this plugin recommends.
The row worth looking at is one with starts and nothing done: that line shows the reason instead,
whether it was no round running or a lock that never cleared.

**Running WP-Cron and a real cron together is safe.** Each tick takes a named database lock
before doing anything, so only one run of a kind happens at a time whatever started it, and a
second run arriving mid-way stands down instead of repeating the work. Each also refuses to run
twice in the same period, and the work underneath is idempotent. You do not need to disable
WP-Cron, which many shared hosts will not allow anyway.

The simplest cron is one line that fetches WordPress's own cron entry point. It runs every
scheduled task that is due, the game's included, so it covers the rest of the site too:

```
*/15 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

That route needs **Use wp cron** left at 1, since it runs the events that are *scheduled*, and 0
schedules none. It also needs the site reachable over HTTP from wherever the cron runs.

Behind HTTP authentication, or with WP-Cron scheduling off, call the plugin's own scripts instead:

```
0 * * * * php /path/to/wp-content/plugins/imperial-dominion-online/maintenance/hourly_maintenance.php
5 0 * * * php /path/to/wp-content/plugins/imperial-dominion-online/maintenance/daily_maintenance.php
```

The admin **Maintenance** screen shows when each tick last ran and can run either on demand.

### Deleting the plugin

Deleting Imperial Dominion Online **keeps every game table by default**: empires, rounds,
battles, the Hall of Fame and your settings all survive, so reinstalling resumes the game
mid-round. Deactivating never touches the data either.

To have the data removed on delete, tick **Delete every game table when this plugin is deleted**
under Settings first. That is irreversible and there is no export, so it is off unless you say
otherwise.

---

## Security notes

- Every front-end order is a POST carrying a nonce, verified in `IDO_Actions::handle()` before
  anything happens. Orders redirect afterwards, so a reload cannot repeat them.
- Every admin screen checks `manage_options`, and every admin form checks its own nonce through
  `check_admin_referer()`.
- All database access goes through `$wpdb->prepare()`, `$wpdb->insert()` or `$wpdb->update()`.
  Table and column names are never taken from request data: building and troop keys are validated
  against the data classes before they reach a query.
- Spending is atomic. `IDO_Kingdom::pay()` writes one guarded `UPDATE ... WHERE gold >= cost` and
  checks the affected row count, so two requests from the same ruler cannot spend the same gold
  twice. Battles and market purchases additionally take a named lock.
- Everything rendered is escaped at the point of output, including player-supplied empire and ruler
  names, which are validated on the way in as well.
- **A column name never comes from a request.** Building, unit, weapon and market keys are checked
  against the data classes that define them, and the two functions that write empire columns keep
  their own allowlist and throw on anything else, rather than trusting their callers to have checked
  first. `tests/integration-input.php` fires SQL, shell and traversal payloads through the real
  service layer at a real database and then verifies the tables, the row counts and the balances are
  untouched.
- League play adds the only public, unauthenticated surface in the plugin, and it is off twice over:
  league play is opt in, and the endpoint is a separate switch that is also off by default. Packets
  are signed with a per-pairing secret generated by `random_bytes()`, verified against the raw
  received bytes before any parser touches them, and peer URLs are checked against the addresses
  they actually resolve to.

**Found something?** Please report it privately to <sysop@maddogproductions.online> rather than
opening a public issue. See [SECURITY.md](SECURITY.md) for what is in scope, what is deliberately
out of it, and what to expect.

---

## Roadmap

**Phase 1** is a complete game on a single WordPress site.

**Phase 2**, inter-site war, is built: the empires of one site combine their forces and march on
another, as described under [League play](#league-play-optional-off-by-default). The board reset
and relief for a ruined empire, which exist so that a beaten site or ruler can come back, shipped
with it.

What is still open is listed under *Gaps* in [docs/CROSS-SITE.md](docs/CROSS-SITE.md#gaps-what-this-design-has-not-answered).
The ones most likely to matter first:

- **Evicting a member.** The originator can decline an enrolment but cannot yet remove a paired
  site.
- **A league whose originator disappears** has no way to hand the ruleset and calendar to anyone
  else.
- **A round boundary shared to the instant.** Each site still ends its round on its own clock
  rather than at one moment the league publishes.
- **Restoring a database** from backup rolls back sequence numbers, and nothing yet detects it.

## Licence

GPLv2 or later. See [LICENSE](LICENSE).
