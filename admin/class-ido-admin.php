<?php
if (!defined('ABSPATH')) exit;

/**
 * The game master screens. Every page checks manage_options and every form
 * checks its own nonce, so nothing here can be driven from outside wp-admin.
 */
class IDO_Admin {

    const CAP = 'manage_options';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_ido_admin', [__CLASS__, 'handle']);
    }

    public static function menu(): void {
        add_menu_page(
            'Imperial Dominion', 'Imperial Dominion', self::CAP, 'ido_dashboard',
            [__CLASS__, 'render_dashboard'], 'dashicons-shield', 56
        );
        add_submenu_page('ido_dashboard', 'Dashboard', 'Dashboard', self::CAP, 'ido_dashboard', [__CLASS__, 'render_dashboard']);
        add_submenu_page('ido_dashboard', 'Rounds', 'Rounds', self::CAP, 'ido_rounds', [__CLASS__, 'render_rounds']);
        add_submenu_page('ido_dashboard', 'Empires', 'Empires', self::CAP, 'ido_kingdoms', [__CLASS__, 'render_kingdoms']);
        add_submenu_page('ido_dashboard', 'League Play', 'League Play', self::CAP, 'ido_league', [__CLASS__, 'render_league']);
        add_submenu_page('ido_dashboard', 'Settings', 'Settings', self::CAP, 'ido_settings', [__CLASS__, 'render_settings']);
        add_submenu_page('ido_dashboard', 'Maintenance', 'Maintenance', self::CAP, 'ido_maintenance', [__CLASS__, 'render_maintenance']);
    }

    /** Renders one of the files in admin/views. */
    private static function view(string $name): void {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You do not have permission to manage this game.', 'imperial-dominion-online'));
        }
        $file = IDO_PATH . 'admin/views/' . $name . '.php';
        if (is_readable($file)) include $file;
    }

    public static function render_dashboard(): void   { self::view('dashboard'); }
    public static function render_rounds(): void      { self::view('rounds'); }
    public static function render_kingdoms(): void      { self::view('kingdoms'); }
    public static function render_league(): void      { self::view('league'); }
    public static function render_settings(): void    { self::view('settings'); }
    public static function render_maintenance(): void { self::view('maintenance'); }

    /** Opens an admin form; close it with </form>. */
    public static function form_open(string $task, string $page): string {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="ido_admin">'
            . '<input type="hidden" name="task" value="' . esc_attr($task) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr($page) . '">'
            . wp_nonce_field('ido_admin_' . $task, 'ido_admin_nonce', true, false);
    }

    /** All admin form posts land here. */
    public static function handle(): void {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You do not have permission to manage this game.', 'imperial-dominion-online'), '', ['response' => 403]);
        }
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';
        $page = isset($_POST['page']) ? sanitize_key(wp_unslash($_POST['page'])) : 'ido_dashboard';
        check_admin_referer('ido_admin_' . $task, 'ido_admin_nonce');

        $notice = '';
        switch ($task) {
            case 'save_settings':
                $values = isset($_POST['settings']) && is_array($_POST['settings'])
                    ? wp_unslash($_POST['settings']) : [];
                IDO_Settings::update($values);
                // The WP-Cron events follow the setting immediately, so turning
                // it off actually stops them rather than waiting for a reload.
                IDO_Maintenance::apply_schedule();
                $menu_notice = IDO_Menu::apply();
                IDO_Log::admin('settings', 'Settings updated.');
                $notice = trim('Settings saved. ' . $menu_notice);
                break;

            case 'start_round':
                $days = isset($_POST['round_days']) ? max(1, (int) $_POST['round_days']) : 0;
                $round = IDO_Rounds::start($days);
                $notice = sprintf('%s has begun.', $round->round_name);
                break;

            case 'end_round':
                $round = IDO_Rounds::current();
                if ($round) {
                    $notice = IDO_Rounds::conclude($round);
                    IDO_Log::admin('round_end', $notice);
                } else {
                    $notice = 'No round is running.';
                }
                break;

            case 'run_hourly':
                $notice = IDO_Maintenance::hourly(true, 'admin');
                break;

            case 'run_daily':
                $notice = IDO_Maintenance::daily(true, 'admin');
                break;

            case 'create_pages':
                $notice = self::create_pages();
                // The front page may have only just come into existence.
                $menu_notice = IDO_Menu::apply();
                if ($menu_notice) $notice .= ' ' . $menu_notice;
                break;

            // League play. Every one of these is behind manage_options and a
            // nonce like the rest, and each throws rather than half-doing
            // something when the state is wrong.
            case 'league_opt_in':
                // The box is required in the markup, and checked again here:
                // a required attribute is a courtesy to the browser, not a
                // control, and this one is worth actually enforcing.
                if (empty($_POST['accept_risk'])) {
                    $notice = 'League play was not enabled: the risk acknowledgement was not accepted.';
                    break;
                }
                $notice = IDO_League_Setup::opt_in();
                break;

            case 'league_opt_out':
                $notice = IDO_League_Setup::opt_out(!empty($_POST['drop_tables']));
                break;

            case 'league_found':
                try {
                    $league = IDO_League_Setup::found([
                        'league_name'    => isset($_POST['league_name']) ? wp_unslash($_POST['league_name']) : '',
                        'max_sites'      => isset($_POST['max_sites']) ? (int) $_POST['max_sites'] : 12,
                        'round_days'     => isset($_POST['round_days']) ? (int) $_POST['round_days'] : 90,
                        'muster_days'    => isset($_POST['muster_days']) ? (int) $_POST['muster_days'] : 5,
                    ]);
                    $notice = sprintf('%s has been founded. Invite the other sites next.', $league->league_name);
                } catch (IDO_Game_Exception $e) {
                    $notice = $e->getMessage();
                }
                break;

            case 'league_invite':
                try {
                    $blob = IDO_League_Setup::invite(isset($_POST['note']) ? wp_unslash($_POST['note']) : '');
                    // Held for one page load and shown once. Not put in the URL:
                    // a redirect lands in server logs and browser history, and an
                    // invitation does not belong in either.
                    set_transient('ido_league_invitation', $blob, MINUTE_IN_SECONDS * 5);
                    $notice = 'Invitation created. Send it to the other administrator.';
                } catch (IDO_Game_Exception $e) {
                    $notice = $e->getMessage();
                }
                break;

            case 'league_revoke_invite':
                $notice = IDO_League_Setup::revoke_invite(isset($_POST['invite_id']) ? (int) $_POST['invite_id'] : 0);
                break;

            case 'league_join':
                try {
                    IDO_League_Setup::join(isset($_POST['invitation']) ? (string) wp_unslash($_POST['invitation']) : '');
                    $notice = 'Invitation read and the enrolment recorded. The handshake with the hub comes next.';
                } catch (IDO_Game_Exception $e) {
                    $notice = $e->getMessage();
                }
                break;

            case 'league_present':
                try {
                    $notice = IDO_League_Enrol::present();
                } catch (IDO_Game_Exception $e) {
                    $notice = $e->getMessage();
                }
                break;

            case 'league_collect':
                try {
                    $notice = IDO_League_Enrol::collect();
                } catch (IDO_Game_Exception $e) {
                    $notice = $e->getMessage();
                }
                break;

            case 'league_approve':
                try {
                    $notice = IDO_League_Enrol::approve(isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0);
                } catch (IDO_Game_Exception $e) {
                    $notice = $e->getMessage();
                }
                break;

            case 'league_decline':
                try {
                    $notice = IDO_League_Enrol::decline(isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0);
                } catch (IDO_Game_Exception $e) {
                    $notice = $e->getMessage();
                }
                break;

            case 'league_run_traffic':
                // The button stands in for the daily run, so a game master
                // testing a league does not have to wait for midnight to see a
                // march land.
                $notice = trim(IDO_Maintenance::league_traffic(true) . ' ' . IDO_Maintenance::league_news());
                if ($notice === '') $notice = 'Nothing was waiting in either queue.';
                break;

            case 'league_pause':
                $notice = IDO_League_Setup::set_paused(!empty($_POST['paused']));
                break;

            case 'league_leave':
                $notice = IDO_League_Setup::leave();
                break;

            case 'delete_kingdom':
                $kingdom_id = isset($_POST['kingdom_id']) ? (int) $_POST['kingdom_id'] : 0;
                $notice = self::delete_kingdom($kingdom_id);
                break;
        }

        $url = add_query_arg(
            ['page' => $page, 'ido_notice' => rawurlencode($notice)],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    /** Creates the nine WordPress pages, each holding one game shortcode, and renames any whose name has changed. */
    private static function create_pages(): string {
        $ids = get_option('ido_page_ids', []);
        if (!is_array($ids)) $ids = [];
        $created = 0;
        $kept = 0;
        $renamed = 0;
        $claimed = [];

        $skipped_league = 0;
        foreach (IDO_UI::PAGES as $key => $def) {
            [$title, $slug, $shortcode] = $def;

            // The League page is not made until there is a league. Pressing this
            // button again after founding or joining one creates it.
            if (!IDO_UI::page_applies($key)) { $skipped_league++; continue; }
            $existing = !empty($ids[$key]) ? get_post($ids[$key]) : get_page_by_path($slug);

            // If a slug changes, the page under the old slug may still be
            // recorded against another entry. Never let two menu entries own
            // the same page: renaming it for one would break the other.
            if ($existing && in_array((int) $existing->ID, $claimed, true)) {
                $existing = null;
            }

            if ($existing && $existing->post_status !== 'trash') {
                $claimed[] = (int) $existing->ID;
                $ids[$key] = (int) $existing->ID;
                // A page whose name the game has since changed is brought back
                // into line, so pressing this button always leaves the pages
                // named the way the game names them. Content is left alone.
                if ($existing->post_title !== $title) {
                    wp_update_post(['ID' => (int) $existing->ID, 'post_title' => $title]);
                    $renamed++;
                } else {
                    $kept++;
                }
                continue;
            }
            $post_id = wp_insert_post([
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_content' => '[' . $shortcode . ']',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ], true);
            if (!is_wp_error($post_id)) {
                $ids[$key] = (int) $post_id;
                $claimed[] = (int) $post_id;
                $created++;
            }
        }

        update_option('ido_page_ids', $ids);
        IDO_Log::admin('pages', sprintf('Created %d game pages, renamed %d, kept %d.', $created, $renamed, $kept));
        return sprintf('%d pages created, %d renamed to match the game, %d already correct.%s',
            $created, $renamed, $kept,
            $skipped_league > 0
                ? ' The League page is not created until this site is in a league; press this again once it is.'
                : '');
    }

    /** Removes one empire and everything hanging off it. */
    private static function delete_kingdom(int $kingdom_id): string {
        global $wpdb;
        $kingdom = IDO_Kingdom::find($kingdom_id);
        if (!$kingdom) return 'No such empire.';

        $wpdb->delete(IDO_DB::t('constructions'), ['kingdom_id' => $kingdom_id], ['%d']);
        $wpdb->delete(IDO_DB::t('listings'), ['seller_kingdom_id' => $kingdom_id], ['%d']);
        $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $kingdom_id], ['%d']);

        IDO_Log::admin('delete_kingdom', sprintf('Deleted empire %s (id %d).', $kingdom->kingdom_name, $kingdom_id));
        return sprintf('%s has been struck from the rolls.', $kingdom->kingdom_name);
    }

    /** Prints the notice passed back through the redirect. */
    public static function notice(): void {
        if (empty($_GET['ido_notice'])) return;
        $notice = sanitize_text_field(rawurldecode(wp_unslash($_GET['ido_notice'])));
        if ($notice === '') return;
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
}
