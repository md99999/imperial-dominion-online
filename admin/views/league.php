<?php
if (!defined('ABSPATH')) exit;
/**
 * The League screen: the one place a game master sets up and manages league
 * play. It has three quite different states, and the screen is written as
 * three screens rather than one with everything hidden.
 *
 * 1. Not opted in. An explanation and a switch, and nothing else exists.
 * 2. Opted in, no league. Found one, or join one with an invitation.
 * 3. In a league. Members, invitations, the kill switch, and leaving.
 */

$enabled = IDO_League::enabled();
$league  = IDO_League::league();
$notice  = isset($_GET['ido_notice']) ? sanitize_text_field(wp_unslash($_GET['ido_notice'])) : '';
$invite  = get_transient('ido_league_invitation');
if ($invite) delete_transient('ido_league_invitation');
?>
<div class="wrap">
    <h1>League Play</h1>

    <?php if (IDO_League_URL::dev_notice() !== '') : ?>
        <div class="notice notice-error"><p>
            <strong><?php echo esc_html(IDO_League_URL::dev_notice()); ?></strong><br>
            Set by <code>IDO_LEAGUE_ALLOW_PRIVATE_HOSTS</code> in <code>wp-config.php</code>, and honoured
            because WordPress reports this as a
            <?php echo esc_html(function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production'); ?>
            environment. On a production site the constant is read and ignored.
        </p></div>
    <?php endif; ?>

    <?php if ($notice !== '') : ?>
        <div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

<?php if (!$enabled) : ?>

    <div class="notice notice-info inline"><p>
        <strong>League play is off.</strong> This site is playing its own game, and nothing here is
        installed or reachable.
    </p></div>

    <h2>What league play is</h2>
    <p style="max-width:44em">
        A league joins this site to other WordPress sites running this game. Your rulers stop warring
        with each other and become one side: they raise an army together and march on another site,
        which takes days to arrive, resolves there, and comes home with spoils.
    </p>
    <p style="max-width:44em">
        It is a different game, not an extra feature, and it is entirely optional. Most sites will
        never turn it on, and nothing about local play needs it.
    </p>

    <h2>What turning it on does</h2>
    <ul class="ul-disc" style="max-width:44em">
        <li>Creates the league tables. Nothing is created until you opt in.</li>
        <li>Nothing is exposed to the internet by this switch. The endpoint other member sites
            deliver to is <em>off by default</em> and has to be turned on separately in Settings, and
            even then it only exists once this site has actually joined a league.</li>
        <li>Hands the league control of the settings that decide who wins: turns a day, starting
            resources, costs and combat percentages. They become read-only here, because a site that
            granted its own rulers 200 turns a day would win a league without ever fighting well.</li>
        <li>Requires this site to be reachable over HTTPS at a public address.</li>
    </ul>

    <h2>Before you turn it on</h2>
    <div class="notice notice-warning inline" style="max-width:44em">
        <p><strong>You enable this at your own risk.</strong></p>
        <p>
            This plugin is provided as is, without warranty of any kind. The author accepts no
            responsibility for any loss, damage, downtime, data loss or compromise arising from
            installing or running it. That is the GPL's disclaimer in plain language, and it applies to
            the whole plugin, not only to league play.
        </p>
        <p>
            Every effort is made to write this carefully and securely: packets are signed and verified
            before anything parses them, peer addresses are checked against where they actually resolve,
            and the reasoning is written down rather than assumed. <strong>None of that is a
            guarantee.</strong> New vulnerabilities are found in software every day, in WordPress, in
            PHP, in plugins, and in this one.
        </p>
        <p>
            League play is the only part of this game that accepts data from outside your site. That is
            why it is off until you turn it on, why the endpoint is a second switch that is also off,
            and why both say so plainly instead of being enabled for you.
        </p>
        <p>
            Before enabling it: keep backups and know how to restore them, keep WordPress, PHP and this
            plugin updated, and do not run it on a site you cannot afford to have broken.
        </p>
    </div>

    <p>
        <?php echo IDO_Admin::form_open('league_opt_in', 'ido_league'); ?>
            <label style="display:block; margin-bottom:.75em">
                <input type="checkbox" name="accept_risk" value="1" required>
                I understand that I enable league play at my own risk, and that this plugin comes with
                no warranty of any kind.
            </label>
            <button type="submit" class="button button-primary">Enable league play</button>
        </form>
    </p>

<?php elseif (!$league) : ?>

    <div class="notice notice-warning inline"><p>
        League play is enabled, and this site is not in a league yet. Found one, or join one with an
        invitation from the site that founded it.
    </p></div>

    <div style="display:flex; gap:2em; flex-wrap:wrap; align-items:flex-start">

        <div class="card" style="max-width:36em">
            <h2>Found a league</h2>
            <p>This site becomes the originator: it owns the ruleset, the calendar, and who is admitted.</p>
            <?php echo IDO_Admin::form_open('league_found', 'ido_league'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ido-league-name">League name</label></th>
                        <td><input name="league_name" id="ido-league-name" type="text" class="regular-text"
                                   maxlength="100" required placeholder="the Westmarch League"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ido-max-sites">Member sites</label></th>
                        <td>
                            <input name="max_sites" id="ido-max-sites" type="number" min="2"
                                   max="<?php echo esc_attr(IDO_League::MAX_SITES); ?>" value="12" class="small-text">
                            <p class="description">
                                The ceiling is <?php echo esc_html(IDO_League::MAX_SITES); ?>.
                                Around <?php echo esc_html(IDO_League::ADVISED_SITES); ?> is as large as a league
                                stays readable, and 8 to 12 is a good first one. It is easy to admit another site
                                and awkward to ask one to leave.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ido-round-days">Round length</label></th>
                        <td>
                            <input name="round_days" id="ido-round-days" type="number" min="1" max="365"
                                   value="90" class="small-text"> days
                            <p class="description">
                                Longer than a local round, because a league exchange takes days. Every member
                                wipes together.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ido-muster-days">Muster window</label></th>
                        <td>
                            <input name="muster_days" id="ido-muster-days" type="number" min="1" max="30"
                                   value="5" class="small-text"> days
                            <p class="description">How long rulers have to contribute to a march after one is called.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">March delay</th>
                        <td>
                            <input name="delay_min_days" type="number" min="0" max="30" value="3" class="small-text">
                            to
                            <input name="delay_max_days" type="number" min="0" max="30" value="8" class="small-text">
                            days
                            <p class="description">
                                Drawn by the <em>receiving</em> site, each way, so an attacker cannot choose when
                                their army lands. The wait is the point of the mechanic, not a limitation.
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" class="button button-primary">Found the league</button>
            </form>
        </div>

        <div class="card" style="max-width:36em">
            <h2>Join a league</h2>
            <p>
                Paste the invitation the originator sent you. It is a one-time token that expires, and it
                is not a password: nothing in it is worth stealing for long.
            </p>
            <?php echo IDO_Admin::form_open('league_join', 'ido_league'); ?>
                <p>
                    <label class="screen-reader-text" for="ido-invitation">Invitation</label>
                    <textarea name="invitation" id="ido-invitation" rows="5" class="large-text code"
                              required placeholder="Paste the invitation here"></textarea>
                </p>
                <button type="submit" class="button button-primary">Read the invitation</button>
            </form>
        </div>
    </div>

    <h2>Turn it off again</h2>
    <p>
        <?php echo IDO_Admin::form_open('league_opt_out', 'ido_league'); ?>
            <label><input type="checkbox" name="drop_tables" value="1"> also remove the league tables</label>
            <button type="submit" class="button">Disable league play</button>
        </form>
    </p>

<?php else : ?>

    <?php
    $members  = IDO_League_Setup::members($league);
    $invites  = IDO_League::is_originator() ? IDO_League_Setup::open_invites($league) : [];
    $pending  = (string) $league->status === 'pending';
    $round_trip = (int) $league->muster_days + 2 * (int) $league->delay_max_days;
    ?>

    <?php if ($pending) : ?>
        <div class="notice notice-warning"><p>
            <strong>Enrolment in progress.</strong> This site has read the invitation to
            <em><?php echo esc_html($league->league_name); ?></em>. It is not a member yet, and nothing is
            sent or accepted until it is.
        </p></div>

        <h2>Finish joining</h2>
        <ol style="max-width:46em">
            <li><strong>Present the invitation.</strong> This site calls the hub with its one-time token.
                The hub then calls back to prove you control
                <code><?php echo esc_html(IDO_League::own_url()); ?></code> and hold the invitation, so
                nobody can enrol a site they do not run.
                <p>
                    <?php echo IDO_Admin::form_open('league_present', 'ido_league'); ?>
                        <button type="submit" class="button button-primary">Present the invitation</button>
                    </form>
                </p>
            </li>
            <li><strong>Wait for the originator to approve this site</strong>, then collect the shared
                secret. It travels over TLS inside the answer to this site's own request, so nobody ever
                copies it by hand.
                <p>
                    <?php echo IDO_Admin::form_open('league_collect', 'ido_league'); ?>
                        <button type="submit" class="button">Check approval and collect the secret</button>
                    </form>
                </p>
            </li>
        </ol>
        <p class="description" style="max-width:46em">
            The hub has to be able to reach this site over HTTPS for step one to work, which means incoming
            packets must be switched on under Settings. On two sites on one machine it also means the
            development allowance described in <code>docs/TWO-SITE-TESTING.md</code>.
        </p>
    <?php endif; ?>

    <?php if (!$pending && !IDO_League::endpoint_enabled()) : ?>
        <div class="notice notice-warning inline"><p>
            <strong>This site cannot receive league packets.</strong>
            <?php echo esc_html(IDO_League::endpoint_status()); ?>
            Marches sent from here are resolved by the defending site and the result is delivered back
            to this address, so until the endpoint is open no army can come home.
            <a href="<?php echo esc_url(admin_url('admin.php?page=ido_settings')); ?>">Open it in Settings</a>.
        </p></div>
    <?php endif; ?>

    <?php if (IDO_League::paused() && !$pending) : ?>
        <div class="notice notice-warning inline"><p>
            <strong>League traffic is paused.</strong> Nothing is sent or accepted. The league is otherwise intact.
        </p></div>
    <?php endif; ?>

    <h2><?php echo esc_html($league->league_name); ?></h2>
    <table class="widefat striped" style="max-width:60em">
        <tbody>
            <tr><th scope="row" style="width:16em">This site's role</th>
                <td><?php echo IDO_League::is_originator() ? 'Originator and hub' : 'Member'; ?></td></tr>
            <tr><th scope="row">Hub</th><td><code><?php echo esc_html($league->hub_url); ?></code></td></tr>
            <tr><th scope="row">This site</th><td><code><?php echo esc_html($league->site_url); ?></code></td></tr>
            <tr><th scope="row">Members</th>
                <td><?php echo esc_html(sprintf('%d of %d', IDO_League_Setup::member_count($league), (int) $league->max_sites)); ?></td></tr>
            <tr><th scope="row">Round</th>
                <td><?php echo esc_html(sprintf('%d days', (int) $league->round_days)); ?></td></tr>
            <tr><th scope="row">Muster window</th>
                <td><?php echo esc_html(sprintf('%d days', (int) $league->muster_days)); ?></td></tr>
            <tr><th scope="row">March delay</th>
                <td><?php echo esc_html(sprintf('%d to %d days each way, drawn by the receiving site',
                    (int) $league->delay_min_days, (int) $league->delay_max_days)); ?></td></tr>
            <tr><th scope="row">Longest exchange</th>
                <td><?php echo esc_html(sprintf('%d days from calling a muster to the army coming home', $round_trip)); ?></td></tr>
            <tr><th scope="row">Incoming packets</th>
                <td><?php echo esc_html(IDO_League::endpoint_status()); ?></td></tr>
            <tr><th scope="row">Ruleset</th>
                <td>
                    version <?php echo esc_html((int) $league->ruleset_version); ?>
                    <code title="The hash of the governed settings that rides in every packet"><?php
                        echo esc_html(substr((string) $league->fingerprint, 0, 16)); ?>&hellip;</code>
                </td></tr>
        </tbody>
    </table>

    <h2>Member sites</h2>
    <table class="widefat striped" style="max-width:60em">
        <thead><tr><th>Site</th><th>Address</th><th>Status</th><th>Last heard</th><th></th></tr></thead>
        <tbody>
        <?php if (!$members) : ?>
            <tr><td colspan="5">No other sites yet. Create an invitation below and send it to another administrator.</td></tr>
        <?php else : foreach ($members as $member) : ?>
            <tr>
                <td><?php echo esc_html($member->site_name); ?>
                    <?php if ((int) $member->is_hub === 1) : ?><span class="description">(hub)</span><?php endif; ?></td>
                <td><code><?php echo esc_html($member->site_url); ?></code></td>
                <td><?php echo esc_html($member->status); ?></td>
                <td><?php echo esc_html(IDO_League::when($member->last_contact_at)); ?></td>
                <td>
                    <?php if (IDO_League::is_originator() && (int) $member->is_hub === 0
                              && (string) $member->status === 'pending') : ?>
                        <?php echo IDO_Admin::form_open('league_approve', 'ido_league'); ?>
                            <input type="hidden" name="member_id" value="<?php echo esc_attr((int) $member->id); ?>">
                            <button type="submit" class="button button-small button-primary">Approve</button>
                        </form>
                        <?php echo IDO_Admin::form_open('league_decline', 'ido_league'); ?>
                            <input type="hidden" name="member_id" value="<?php echo esc_attr((int) $member->id); ?>">
                            <button type="submit" class="button-link delete">Decline</button>
                        </form>
                    <?php elseif ((string) $member->status === 'active' && $member->secret_issued_at) : ?>
                        <span class="description">paired</span>
                    <?php elseif ((string) $member->status === 'active') : ?>
                        <span class="description">approved, awaiting collection</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if (IDO_League::is_originator()) : ?>
        <h2>Invitations</h2>

        <?php if ($invite) : ?>
            <div class="notice notice-success"><p><strong>Send this to the other administrator. It is shown once.</strong></p>
                <p><textarea rows="4" class="large-text code" readonly onclick="this.select()"><?php
                    echo esc_textarea($invite); ?></textarea></p>
                <p class="description">
                    This is a one-time token, not the shared secret. The secret is generated at the end of
                    the handshake and sent to the joining site over TLS, so nobody ever copies it by hand.
                    The token expires in <?php echo esc_html(IDO_League_Setup::INVITE_DAYS); ?> days.
                </p>
            </div>
        <?php endif; ?>

        <?php echo IDO_Admin::form_open('league_invite', 'ido_league'); ?>
            <input type="text" name="note" class="regular-text" maxlength="100"
                   placeholder="Who is this for? (for your own records)">
            <button type="submit" class="button">Create an invitation</button>
        </form>

        <?php if ($invites) : ?>
            <table class="widefat striped" style="max-width:60em; margin-top:1em">
                <thead><tr><th>For</th><th>Expires</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invites as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row->note !== '' ? $row->note : '(no note)'); ?></td>
                        <td><?php echo esc_html(IDO_League::when($row->expires_at)); ?></td>
                        <td>
                            <?php echo IDO_Admin::form_open('league_revoke_invite', 'ido_league'); ?>
                                <input type="hidden" name="invite_id" value="<?php echo esc_attr((int) $row->id); ?>">
                                <button type="submit" class="button-link delete">Revoke</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <h2>Controls</h2>
    <p>
        <?php echo IDO_Admin::form_open('league_pause', 'ido_league'); ?>
            <input type="hidden" name="paused" value="<?php echo IDO_League::paused() ? '0' : '1'; ?>">
            <button type="submit" class="button">
                <?php echo IDO_League::paused() ? 'Resume league traffic' : 'Pause all league traffic'; ?>
            </button>
        </form>
        <span class="description">
            The kill switch. It stops this site sending and accepting packets without deactivating the
            plugin or leaving the league.
        </span>
    </p>
    <p>
        <?php echo IDO_Admin::form_open('league_leave', 'ido_league'); ?>
            <button type="submit" class="button button-link-delete"
                    onclick="return confirm('Leave this league? Local war returns at the next round boundary.');">
                Leave the league
            </button>
        </form>
    </p>

<?php endif; ?>
</div>
