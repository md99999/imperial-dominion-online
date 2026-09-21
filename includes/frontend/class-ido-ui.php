<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end helpers: page URLs, the status bar, navigation, flash notices and
 * form scaffolding. The look is a terminal, drawn with CSS rather than ANSI.
 */
class IDO_UI {
    /**
     * key => [page title, slug, shortcode, nav label]
     * The order is the order of play: rule, grow, arm, march, scheme, trade, read.
     */
    const PAGES = [
        'throne'   => ['Throne Room', 'imperial-dominion-online', 'ido_throne', 'Throne'],
        'lands'    => ['Lands', 'imperial-dominion-online-lands', 'ido_lands', 'Lands'],
        'military' => ['Muster Field', 'imperial-dominion-online-muster', 'ido_military', 'Muster'],
        'war'      => ['War Room', 'imperial-dominion-online-war', 'ido_war', 'War'],
        'covert'   => ['Spy Court', 'imperial-dominion-online-spies', 'ido_covert', 'Spies'],
        'market'   => ['Market', 'imperial-dominion-online-market', 'ido_market', 'Market'],
        'gazette'  => ['Gazette', 'imperial-dominion-online-gazette', 'ido_gazette', 'Gazette'],
        'rankings' => ['Rankings', 'imperial-dominion-online-rankings', 'ido_rankings', 'Rankings'],
        'guide'    => ['How to Play', 'imperial-dominion-online-how-to-play', 'ido_guide', 'How to Play'],
    ];

    public static function url(string $key, array $args = []): string {
        static $cache = [];
        if (!isset($cache[$key])) {
            $ids = get_option('ido_page_ids', []);
            $url = '';
            if (!empty($ids[$key]) && get_post_status($ids[$key]) === 'publish') {
                $url = (string) get_permalink($ids[$key]);
            }
            if (!$url && isset(self::PAGES[$key])) {
                $page = get_page_by_path(self::PAGES[$key][1]);
                $url = $page ? (string) get_permalink($page) : home_url('/' . self::PAGES[$key][1] . '/');
            }
            $cache[$key] = $url;
        }
        return $args ? add_query_arg($args, $cache[$key]) : $cache[$key];
    }

    public static function enqueue_assets(): void {
        wp_register_style('imperial-dominion-online', IDO_URL . 'assets/css/imperial-dominion-online.css', [], IDO_VERSION);
        wp_register_script('imperial-dominion-online', IDO_URL . 'assets/js/imperial-dominion-online.js', [], IDO_VERSION, true);
        global $post;
        if (!is_singular() || !$post) return;
        foreach (self::PAGES as $def) {
            if (has_shortcode($post->post_content, $def[2])) {
                wp_enqueue_style('imperial-dominion-online');
                wp_enqueue_script('imperial-dominion-online');
                return;
            }
        }
    }

    public static function flash(string $type, string $message): void {
        $key = 'ido_flash_' . get_current_user_id();
        $list = get_transient($key);
        if (!is_array($list)) $list = [];
        $list[] = [$type, $message];
        set_transient($key, $list, 5 * MINUTE_IN_SECONDS);
    }

    public static function render_flashes(): string {
        $key = 'ido_flash_' . get_current_user_id();
        $list = get_transient($key);
        if (!$list || !is_array($list)) return '';
        delete_transient($key);
        $out = '';
        foreach ($list as $flash) {
            $type = isset($flash[0]) ? (string) $flash[0] : 'info';
            $text = isset($flash[1]) ? (string) $flash[1] : '';
            $out .= '<div class="ido-flash ido-flash-' . esc_attr($type) . '">' . nl2br(esc_html($text)) . '</div>';
        }
        return $out;
    }

    /** Opens a POST form for a game action; close it with </form>. */
    public static function form_open(string $action, string $class = ''): string {
        return '<form method="post" class="ido-form ' . esc_attr($class) . '">'
            . '<input type="hidden" name="ido_action" value="' . esc_attr($action) . '">'
            . wp_nonce_field('ido_action', 'ido_nonce', true, false);
    }

    public static function number_field(string $name, int $value = 0, int $min = 0, string $class = ''): string {
        return '<input type="number" inputmode="numeric" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value)
            . '" min="' . esc_attr((string) $min) . '" step="1" class="ido-num ' . esc_attr($class) . '">';
    }

    public static function status_bar(object $kingdom): string {
        $round = IDO_Rounds::current();
        $days_left = IDO_Rounds::days_left($round);

        $cells = [
            'Turns'      => IDO_Game::fmt($kingdom->turns),
            'Land'       => IDO_Game::fmt($kingdom->land) . ' acres',
            'Gold'       => IDO_Game::fmt($kingdom->gold),
            'Grain'      => IDO_Game::fmt($kingdom->grain),
            'Iron'       => IDO_Game::fmt($kingdom->iron),
            'Peasants'   => IDO_Game::fmt($kingdom->peasants),
            'Net worth'  => IDO_Game::fmt($kingdom->networth),
        ];

        $out = '<div class="ido-status">';
        $out .= '<div class="ido-status-head"><span class="ido-kingdom">' . esc_html($kingdom->kingdom_name) . '</span>'
            . '<span class="ido-ruler">' . esc_html(IDO_Game::title((int) $kingdom->networth)) . ' ' . esc_html($kingdom->ruler_name) . '</span>';
        if ($round) {
            $out .= '<span class="ido-round">' . esc_html($round->round_name);
            if ($days_left !== null) {
                $out .= ' &middot; ' . esc_html(sprintf(_n('%d day left', '%d days left', $days_left, 'imperial-dominion-online'), $days_left));
            }
            $out .= '</span>';
        }
        $out .= '</div><div class="ido-status-grid">';
        foreach ($cells as $label => $value) {
            $out .= '<div class="ido-stat"><span class="ido-stat-label">' . esc_html($label) . '</span>'
                . '<span class="ido-stat-value">' . esc_html($value) . '</span></div>';
        }
        $out .= '</div>';

        if (IDO_Kingdom::is_protected($kingdom)) {
            $out .= '<div class="ido-protected">Crown truce until '
                . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($kingdom->protection_until)))
                . '. No one may march on you, and you may not march on them.</div>';
        }
        return $out . '</div>';
    }

    public static function nav(string $current): string {
        $out = '<nav class="ido-nav">';
        foreach (self::PAGES as $key => $def) {
            $class = 'ido-nav-item' . ($key === $current ? ' ido-nav-current' : '');
            $out .= '<a class="' . esc_attr($class) . '" href="' . esc_url(self::url($key)) . '">' . esc_html($def[3]) . '</a>';
        }
        return $out . '</nav>';
    }

    /** Opens a bordered panel; close it with </div>. */
    public static function panel_open(string $title = '', string $class = ''): string {
        $out = '<div class="ido-panel ' . esc_attr($class) . '">';
        if ($title !== '') {
            $out .= '<h3 class="ido-panel-title">' . esc_html($title) . '</h3>';
        }
        return $out;
    }

    /** Unread battle and covert reports waiting for a ruler. */
    public static function unread_counts(object $kingdom): array {
        global $wpdb;
        return [
            'battles' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d AND defender_seen = 0',
                (int) $kingdom->id
            )),
            'ops' => (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . IDO_DB::t('ops') . ' WHERE target_kingdom_id = %d AND target_seen = 0',
                (int) $kingdom->id
            )),
        ];
    }

    /** Marks incoming reports as read once the ruler has opened the war room. */
    public static function mark_reports_seen(object $kingdom): void {
        global $wpdb;
        $wpdb->update(IDO_DB::t('battles'), ['defender_seen' => 1], ['defender_kingdom_id' => (int) $kingdom->id, 'defender_seen' => 0], ['%d'], ['%d', '%d']);
        $wpdb->update(IDO_DB::t('ops'), ['target_seen' => 1], ['target_kingdom_id' => (int) $kingdom->id, 'target_seen' => 0], ['%d'], ['%d', '%d']);
    }
}
