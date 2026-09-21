# Imperial Dominion Online

**A turn-based empire building and conquest game for WordPress.**

The old empire is broken. Its provinces lie open, its granaries are unguarded, and every ruler
with a banner and a few hundred acres believes the throne is theirs. Claim land, raise a kingdom,
feed it, arm it, and take what your neighbours cannot hold.

Imperial Dominion Online is played entirely in the browser through ordinary WordPress pages.
Players sign in to your WordPress site and rule.

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

## Installing

1. Copy this folder into `wp-content/plugins/` and activate **Imperial Dominion Online**.
   Activation creates the tables, writes the default settings and opens **Round 1**.
2. Go to **Imperial Dominion &rarr; Dashboard** and press **Create any missing game pages**.
   That creates the eight pages below, each holding a single shortcode.
3. Adjust the game under **Imperial Dominion &rarr; Settings**.
4. On a quiet site, set up a real cron (see **Maintenance** below). WP-Cron only fires when
   somebody visits, which is no good for a game where turns arrive at midnight.

### The pages

| Page | Shortcode | What it is |
| --- | --- | --- |
| Throne Room | `[ido_throne]` | The state of the kingdom, and what one turn currently yields |
| Lands | `[ido_lands]` | Settle wilderness, raise buildings, raze them |
| Muster Field | `[ido_military]` | Train and disband troops |
| War Room | `[ido_war]` | Pick a target, commit a force, read the dispatches |
| Spy Court | `[ido_covert]` | Hire an agent and send them out |
| Market | `[ido_market]` | Post lots, buy what other rulers have posted |
| Gazette | `[ido_gazette]` | Public news of the round |
| Rankings | `[ido_rankings]` | Standings and the Hall of Fame |

---

## How a round is played

Each ruler gets **one kingdom per round**, tied to their WordPress account.

**Turns are the currency.** You are granted 30 turns a day (configurable), stored up to 90.
Every order costs turns, and every turn spent pays out your income at that instant. Turns sitting
unspent earn nothing at all, which is what keeps the game moving.

**Land and buildings.** Send settlers to claim wilderness, then raise buildings on it. The bigger
your kingdom, the fewer acres a scouting party finds and the more each one costs, until taking land
from a neighbour is cheaper than settling it. Building orders finish on the daily tick.

| Building | What it does |
| --- | --- |
| Homesteads | House 30 peasants each, and peasants pay the taxes |
| Farmsteads | 85 grain a turn |
| Counting Houses | 60 gold a turn |
| Foundries | 25 iron a turn |
| Barracks | Trim up to 35% from the gold price of training |
| Fortifications | Up to +50% defence |

**The army.** Peasants become soldiers, so every regiment costs you tax revenue as well as gold
and iron. Offence counts only when you march and defence only when you are marched upon, so an
army built for one job is nearly useless at the other.

| Troops | Offence | Defence | Notes |
| --- | --- | --- | --- |
| Pawns | 0 | 3 | Cheap conscripts |
| Knights | 1 | 9 | The backbone of any kingdom expecting to be hit |
| Squires | 9 | 1 | Raiders, worthless at home |
| Rooks | 18 | 4 | The only reliable answer to fortifications |

**War.** A march costs 2 turns and resolves the moment you commit, against whatever the defender
has standing at that instant. Both sides get a written report. You may only attack kingdoms worth
between 40% and 250% of your own net worth, at most three times each a day. New kingdoms hold a
72-hour crown truce, which ends the moment they attack somebody.

- **Conquest** takes acres, and the buildings standing on them.
- **Raid** strips gold, grain and iron.
- **Siege** throws down buildings, fortifications first.

**The spy court.** An agent is the most expensive thing a kingdom can own, costing 500,000 gold,
and no ruler may keep more than one. Missions can fail, and a failed mission
often ends with the agent on a rope: replacing them means paying the full price again.
Reconnaissance tells you what a rival is actually holding; the other missions burn granaries,
wreck forges or set peasants against their lord.

**The market.** Rulers post grain, iron and troops at their own prices. Goods leave
your stores the moment you post them and return if the lot expires or you withdraw it. The crown
takes 5% of every sale.

**The round ends** after 45 days. The standings are carved into the Hall of Fame, every kingdom is
retired, and the next round opens automatically, so a player who joins late is never permanently
behind.

---

## Maintenance

Two scheduled ticks keep the world turning:

- **Daily** grants turns, finishes building work, clears old gazette items and winds up a
  finished round.
- **Hourly** returns expired market lots and catches a round whose time ran out between daily runs.

Both refuse to run twice in the same period, so WP-Cron and a system cron can be configured
together without anybody getting two days of turns. For a real cron:

```
0 * * * * php /path/to/wp-content/plugins/imperial-dominion-online/maintenance/hourly_maintenance.php
5 0 * * * php /path/to/wp-content/plugins/imperial-dominion-online/maintenance/daily_maintenance.php
```

The admin **Maintenance** screen shows when each tick last ran and can run either on demand.

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
- Everything rendered is escaped at the point of output, including player-supplied kingdom and ruler
  names, which are validated on the way in as well.

---

## Roadmap

Phase 1 (this release) is a complete game on a single WordPress site.

Phase 2 is **inter-site war**: letting the kingdoms of one WordPress site combine their forces
against a game hosted on another. See [docs/CROSS-SITE.md](docs/CROSS-SITE.md) for the design
questions that have to be answered first, above all how a war packet is signed, verified and
replayed exactly once.

## Licence

GPLv2 or later. See [LICENSE](LICENSE).
