## An empire reduced to nothing

**Status: built in 2.10.0.** The automatic relief described here is in the plugin
(`IDO_Board::mark_ruined()` and `IDO_Board::relieve_due()`, run by the daily tick). The sections
below were written as the case for it and now describe what it does; the one part not built is the
manual restore at the end.

### Zero is already the floor

Nothing can go negative. Every decrease runs through `IDO_Kingdom::pay()`, which guards the column
with `>= cost` and fails the whole write rather than allowing a negative, and `IDO_Game::clamp()`
floors at zero on the way in. Conquest cannot take an empire's last acre. Measured on a flattened
empire: an attempt to spend 500 gold against a balance of zero is refused and the balance stays
zero.

So the question is not whether an empire can go negative. It is whether an empire at zero can get
back up.

### It can, but barely, and that is the problem

An empire stripped to one acre, one homestead, no gold, no grain and no people does recover:
population regrows from zero at about three a turn, because growth has a floor rather than being
purely proportional. But:

- with no farmsteads there is no grain, so the few peasants who arrive immediately begin to starve
- gold income is roughly half a coin per peasant per turn, so about four gold a turn
- a farmstead costs 300 gold, which is something like seventy-five turns of income
- at ten turns a day that is a week of play to build the single building that stops the starving

That is technically a recovery and practically an invitation to stop playing. Nobody grinds a week
to get back to where the round started, and an empire in that state contributes nothing to a league
march either, which in league mode makes it a dead weight on the whole site rather than merely a
sad story.

### Automatic relief, once a round

The empire is rebuilt to the founding package when it falls below a floor, automatically, and it is
announced in the gazette.

- **Trigger**: net worth below `defeat_threshold_percent` of a founding grant, a quarter by
  default, **and** no soldiers. Both conditions, so a rich empire that happens to be between
  armies is never caught. Setting the threshold to 0 turns relief off.
- **What happens**: the founding package is applied, with each value raised to the founding figure
  and never lowered to it, so relief cannot take anything away. The empire keeps its name, its
  ruler, its war record and its place in the standings. This is relief, not a new identity.
- **A crown truce comes with it**, recorded in `relief_until`.
- **Once per round**, recorded on the empire in `reliefs_used`, so it cannot become a strategy.
- **Announced publicly**, both when the empire falls and when it is resettled, because a silent
  restoration looks like a bug to everyone else and like a favour to the suspicious.

### Why automatic beats asking an administrator

A manual reset needs somebody to notice, and the player it would help is the one least likely to
still be logging in to ask. It also puts an administrator in the position of granting favours,
which is uncomfortable in a game they are usually also playing.

### The exploit, and why it is small

The obvious worry is a player tanking deliberately to collect a fresh start. It does not pay,
because the threshold sits below what a founding package is worth: to qualify you must first
destroy more than the reset gives back. Giving away an army and a treasury to receive a smaller
army and a smaller treasury is not a strategy, it is a loss.

The one case worth guarding is a player who is losing slowly and would rather restart than
continue. The once-a-round limit handles it: they may do it, once, and they still carry the round
they had.

### Keep the manual one too

An administrator should still be able to restore or remove an empire by hand, logged in the audit
trail, for the cases automation should not try to judge: a bugged empire, a returning player, a
test account. Rare, deliberate, and recorded.

**Only removal is built.** The Kingdoms screen can delete an empire, and logs it; there is no
manual restore yet.

### The day in between

Relief does not land the moment an empire falls. The empire is marked defeated, and restored on
the first daily tick at least a day later.

That pause is worth having. A defeat that is undone within the minute never happened, and the
ruler never reads the report that explains it: they refresh, everything is fine, and the only
lesson learned is that losing costs nothing. A day is long enough to be felt and short enough that
nobody leaves over it.

It also suits the machinery. The daily tick is already the thing that grants turns and finishes
building work, so restoration is one more job it does, on a schedule players already understand.

**What the wait looks like**

- `defeated_at` records the moment. `is_defeated` stays set until relief.
- No turns are granted while defeated. The grant already skips these empires, so this needs
  nothing new, and the founding allowance arrives with the relief.
- The empire cannot be attacked and does not count as a league contributor. There is nothing left
  to take, and a march that includes a defeated empire would be counting troops that are gone.
- The gazette says so plainly: the empire lies in ruins and will be resettled within a given number
  of hours. A ruler who tries to pledge to a muster is told the same. There is no dedicated notice
  on the empire's own screens yet.

**After relief, in a league.** The empire rejoins the league when its relief truce ends. Until
then it cannot pledge to a muster: the grant exists to get a ruined ruler playing again, and
sending it to somebody else's war is the fastest way to be ruined twice.

**How long is a day, exactly.** Restoration runs on the daily tick, so a grace of 24 hours means
an empire defeated at three in the afternoon is restored at the tick after the following midnight:
somewhere between 24 and 48 hours later. Setting the grace to zero instead restores overnight, 
between 0 and 24 hours, which may be closer to what most sites want. It is a setting either way,
`defeat_grace_hours`, and the default is 24.

### `is_defeated` finally has a use

The column existed long before anything set it. Defeat is the right name for this state: an empire
that has fallen below the floor is marked defeated, relief is applied on the first daily tick after
the wait, and the flag is cleared. It also gives the grant and the league code an honest way to skip
an empire that is mid-restoration rather than treating it as a going concern.

### A whole board, rather than one empire

When every empire on a site is ruined together, relief one at a time is not enough, and the board
itself is refounded. That is covered in the README under *Starting the board over* and in
[CROSS-SITE.md](CROSS-SITE.md#a-board-reset-and-the-grace-period-that-follows). A reset clears
`defeated_at`, `relief_until` and `reliefs_used` along with everything else.
