# Database tables

All tables use the site's `$wpdb->prefix` followed by `ido_`. They are created by
`sql/install.sql` through `dbDelta()` and dropped by `uninstall.php`.

| Table | Holds |
| --- | --- |
| `ido_rounds` | One row per round: name, status (`active`, `completed`), start and end dates |
| `ido_kingdoms` | One row per player per round. Resources, buildings (`b_*`), troops (`u_*`), siege weapons (`catapults`), turns, net worth, truce, war record |
| `ido_constructions` | Outstanding build orders, completed on the daily tick. `kind` says whether a row is a building or a siege weapon |
| `ido_listings` | Market lots. Goods are escrowed out of the seller's empire while a lot is open |
| `ido_battles` | Every resolved battle, with the report each side reads |
| `ido_ops` | Every covert mission, with the report each side reads |
| `ido_news` | Public gazette items for a round |
| `ido_hall` | Archived standings from completed rounds |
| `ido_admin_log` | Audit trail of game master actions |

## League tables

Created from `sql/league.sql` only when a site opts in to league play, so a site that never does
carries none of them. Leaving a league keeps them, and the record in them; they are dropped when an
administrator opts out and chooses to drop them, or by `uninstall.php` with the delete setting on.

| Table | Holds |
| --- | --- |
| `ido_leagues` | The league this site belongs to: its ids, hub URL, ruleset and fingerprint, calendar, member cap, the kill switch, and `grace_until` while this site is rebuilding after a board reset |
| `ido_sites` | One row per peer site: its URL, the shared secret, sequence counters, status, and the news it last published about itself |
| `ido_invites` | Invitations this site issued. The token is stored hashed, with its expiry and who spent it |
| `ido_packets_in` | Inbound packets, verified and staged until their `process_after`, then processed by cron |
| `ido_packets_out` | The outbound queue, retried with backoff until sent or given up after eight attempts |
| `ido_league_marches` | Every muster and march, in either direction, with its status, outcome and report |
| `ido_league_contributions` | What each empire committed to a march, what came home, and its share of the spoils |

## Columns worth knowing

**`ido_kingdoms.b_*` and `u_*`** are one column per building and troop type. The key in
`IDO_Buildings::all()` and `IDO_Units::all()` *is* the column suffix, so adding a type means
adding a column and bumping `IDO_DB_VERSION`.

**`land_in_progress`** counts acres committed to unfinished building orders. Wilderness is
`land - (standing buildings) - land_in_progress`, so a ruler can never order work on acres they
have already promised elsewhere, or on acres an invader has since taken
(`IDO_Construction::trim_to_land()` cancels orders after a land loss).

**`protection_until`** is the crown truce. `IDO_Kingdom::drop_protection()` ends it the moment the
ruler attacks somebody.

**`is_defeated`, `defeated_at`, `relief_until` and `reliefs_used`** carry the relief cycle for a
ruined empire: marked defeated with the moment it fell, resettled a day later, sheltered by a relief
truce until `relief_until`, and never relieved twice in a round. See
[RUINED-EMPIRES.md](RUINED-EMPIRES.md).

**`last_turn_grant`** is a date, and the daily grant is written as
`UPDATE ... WHERE last_turn_grant <> today`, which is what makes the tick safe to run twice.

## Indexes

`ido_kingdoms` carries a unique key on `(round_id, user_id)`: one empire per player per round,
enforced by the database rather than by application logic. `(round_id, networth)` backs the
standings query, and the battle and op tables are indexed on both empire columns so a ruler's
dispatches load with one index scan each.
