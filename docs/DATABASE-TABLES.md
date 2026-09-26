# Database tables

All tables use the site's `$wpdb->prefix` followed by `ido_`. They are created by
`sql/install.sql` through `dbDelta()` and dropped by `uninstall.php`.

| Table | Holds |
| --- | --- |
| `ido_rounds` | One row per round: name, status (`active`, `completed`), start and end dates |
| `ido_kingdoms` | One row per player per round. Resources, buildings (`b_*`), troops (`u_*`), siege engines (`catapults`), turns, net worth, truce, war record |
| `ido_constructions` | Outstanding build orders, completed on the daily tick. `kind` says whether a row is a building or a siege engine |
| `ido_listings` | Market lots. Goods are escrowed out of the seller's empire while a lot is open |
| `ido_battles` | Every resolved battle, with the report each side reads |
| `ido_ops` | Every covert mission, with the report each side reads |
| `ido_news` | Public gazette items for a round |
| `ido_hall` | Archived standings from completed rounds |
| `ido_admin_log` | Audit trail of game master actions |

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

**`last_turn_grant`** is a date, and the daily grant is written as
`UPDATE ... WHERE last_turn_grant <> today`, which is what makes the tick safe to run twice.

## Indexes

`ido_kingdoms` carries a unique key on `(round_id, user_id)`: one empire per player per round,
enforced by the database rather than by application logic. `(round_id, networth)` backs the
standings query, and the battle and op tables are indexed on both empire columns so a ruler's
dispatches load with one index scan each.
