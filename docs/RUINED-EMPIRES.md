## An empire reduced to nothing

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

### The recommendation: automatic relief, once a round

Rebuild the empire to the founding package when it falls below a floor, automatically, and announce
it in the gazette.

- **Trigger**: net worth below a settable threshold, defaulted well under what a new empire is
  worth, and no standing army. Both conditions, so a rich empire that happens to be between
  armies is never caught.
- **What happens**: land, gold, grain, iron, peasants and starting troops restored to the founding
  values. The empire keeps its name, its ruler, its war record and its place in the standings.
  This is relief, not a new identity.
- **Once per round**, recorded on the empire, so it cannot become a strategy.
- **Announced publicly**, because a silent restoration looks like a bug to everyone else and like a
  favour to the suspicious.

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
- The screens say so plainly: the empire has fallen, relief arrives on the next daily tick after
  a given time. Not an error, not silence.

**How long is a day, exactly.** Restoration runs on the daily tick, so a grace of 24 hours means
an empire defeated at three in the afternoon is restored at the tick after the following midnight:
somewhere between 24 and 48 hours later. Setting the grace to zero instead restores overnight, 
between 0 and 24 hours, which may be closer to what most sites want. It is a setting either way,
`defeat_grace_hours`, and the default is 24.

### `is_defeated` finally has a use

The column exists and nothing has ever set it. Defeat is the right name for this state: an empire
that has fallen below the floor is marked defeated, relief is applied on the next tick, and the
flag is cleared. It also gives the grant and the league code an honest way to skip an empire that
is mid-restoration rather than treating it as a going concern.
