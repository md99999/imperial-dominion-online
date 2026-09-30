<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers the nine game shortcodes, plus the older names some of
 * them used to answer to. Each renders the shared frame (status
 * bar, navigation, notices) around a view from includes/frontend/views.
 */
class IDO_Shortcodes {

    /**
     * Shortcodes that have since been renamed. A site running an older version
     * still has the old tag sitting in its page content, and a page that stops
     * rendering is worse than a tag whose name has aged, so the old names keep
     * working for good.
     */
    const LEGACY = [
        'ido_throne' => 'empire',
    ];

    public static function register(): void {
        foreach (IDO_UI::PAGES as $key => $def) {
            add_shortcode($def[2], static function () use ($key) {
                return IDO_Shortcodes::render($key);
            });
        }
        foreach (self::LEGACY as $tag => $key) {
            add_shortcode($tag, static function () use ($key) {
                return IDO_Shortcodes::render($key);
            });
        }

        // The rules on their own, for an ordinary page or post: no navigation,
        // no status bar, nothing that assumes the reader is playing.
        add_shortcode('ido_how_to_play', ['IDO_Shortcodes', 'render_how_to_play']);

        // The gazette on its own, for a sidebar widget, a front page or a post.
        add_shortcode('ido_news', ['IDO_Shortcodes', 'render_news']);
    }

    /**
     * The How to Play content with none of the game's furniture around it, so
     * it can sit in a marketing page, an announcement post or a sidebar
     * without dragging the navigation and a status bar along with it.
     *
     * Every figure still comes from live settings, so an embedded copy cannot
     * drift away from the rules the game is actually enforcing.
     *
     *   [ido_how_to_play]                 heading and a closing call to action
     *   [ido_how_to_play heading="no"]    for a page that has its own title
     *   [ido_how_to_play cta="no"]        rules only, nothing asking for a signup
     */
    public static function render_how_to_play($atts = []): string {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return '<p>[Imperial Dominion Online: How to Play]</p>';
        }

        $atts = shortcode_atts([
            'heading' => 'yes',
            'cta'     => 'yes',
        ], is_array($atts) ? $atts : [], 'ido_how_to_play');

        $show = static function ($value): bool {
            return !in_array(strtolower(trim((string) $value)), ['no', 'false', '0', ''], true);
        };

        wp_enqueue_style('imperial-dominion-online');

        // The view reads $kingdom to decide whether to invite the reader to
        // claim one. Suppressing the call to action is the same as having one.
        $round   = IDO_Rounds::current();
        $kingdom = is_user_logged_in() && $round ? IDO_Kingdom::current() : null;
        if (!$show($atts['cta'])) {
            $kingdom = (object) ['id' => 0];
        }

        ob_start();
        echo '<div class="ido-game ido-embed ido-page-guide">';
        if ($show($atts['heading'])) {
            echo '<div class="ido-title">' . esc_html(IDO_Game::dominion()) . '</div>';
            echo '<div class="ido-subtitle">' . esc_html(IDO_Game::NAME) . '</div>';
        }
        include IDO_PATH . 'includes/frontend/views/guide.php';
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * The gazette with none of the game's furniture around it, so it can sit in
     * a sidebar widget, a front page or a post.
     *
     *   [ido_news]                     twelve most recent items
     *   [ido_news limit="25"]          more of them, up to 100
     *   [ido_news heading="no"]        for a widget that has its own title
     *   [ido_news cta="no"]            no invitation to claim an empire
     *
     * Twelve is the default because the likeliest home for this is a sidebar,
     * where it has to be readable at a glance rather than scrolled, and twelve
     * is roughly a day of a busy board. A page wanting the full run can ask for
     * it, and the gazette page itself still shows a hundred.
     */
    public static function render_news($atts = []): string {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return '<p>[Imperial Dominion Online: Gazette]</p>';
        }

        $atts = shortcode_atts([
            'limit'   => 12,
            'heading' => 'yes',
            'cta'     => 'yes',
        ], is_array($atts) ? $atts : [], 'ido_news');

        $show = static function ($value): bool {
            return !in_array(strtolower(trim((string) $value)), ['no', 'false', '0', ''], true);
        };

        // Clamped rather than trusted: this number reaches a LIMIT clause, and
        // a page author typing 100000 should get a long list rather than a
        // query that takes the site down with it.
        $ido_news_limit = max(1, min(100, (int) $atts['limit']));

        wp_enqueue_style('imperial-dominion-online');

        $round   = IDO_Rounds::current();
        $kingdom = is_user_logged_in() && $round ? IDO_Kingdom::current() : null;

        ob_start();
        echo '<div class="ido-game ido-embed ido-page-gazette">';
        if ($show($atts['heading'])) {
            echo '<div class="ido-title">' . esc_html(IDO_Game::dominion()) . '</div>';
            echo '<div class="ido-subtitle">' . esc_html(IDO_Game::NAME) . '</div>';
        }
        include IDO_PATH . 'includes/frontend/views/gazette.php';
        if ($show($atts['cta']) && !$kingdom) {
            echo '<p class="ido-dim"><a class="ido-btn" href="'
                . esc_url(IDO_UI::url('guide')) . '">Take an empire</a></p>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function render(string $key): string {
        // Shortcodes also run in admin and REST contexts (block editor previews); keep those cheap.
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return '<p>[Imperial Dominion Online: ' . esc_html(IDO_UI::PAGES[$key][0]) . ']</p>';
        }
        wp_enqueue_style('imperial-dominion-online');
        wp_enqueue_script('imperial-dominion-online');

        ob_start();
        echo '<div class="ido-game ido-page-' . esc_attr($key) . '">';
        // The world first, the software second: players belong to a dominion.
        echo '<div class="ido-title">' . esc_html(IDO_Game::dominion()) . '</div>';
        echo '<div class="ido-subtitle">' . esc_html(IDO_Game::NAME) . '</div>';

        $round = IDO_Rounds::current();
        $kingdom = is_user_logged_in() && $round ? IDO_Kingdom::current() : null;

        if ($kingdom) {
            IDO_Maintenance::catch_up($kingdom);
            $kingdom = IDO_Kingdom::reload($kingdom);
            IDO_Kingdom::touch($kingdom);
            echo IDO_UI::status_bar($kingdom);
        }
        echo IDO_UI::nav($key);
        echo IDO_UI::render_flashes();

        // The rules are public: anyone may read how the game is played, signed
        // in or not, because nobody joins a game they cannot see the shape of.
        // This is also the page a visitor lands on, so anyone who is not yet
        // playing gets the welcome and the leaderboard above the rules.
        if ($key === 'guide') {
            if (!$kingdom) {
                include IDO_PATH . 'includes/frontend/views/welcome.php';
            }
            include IDO_PATH . 'includes/frontend/views/guide.php';
        } elseif ($key === 'gazette') {
            // Public for the same reason the rules are public: nobody joins a
            // game they cannot see the shape of, and a gazette full of wars,
            // floods and hanged spies is the shape of this one. It names only
            // what rulers named themselves -- empires and rulers, never a
            // WordPress account -- so there is nothing here to withhold.
            include IDO_PATH . 'includes/frontend/views/gazette.php';
            if (!$kingdom) {
                include IDO_PATH . 'includes/frontend/views/welcome.php';
            }
        } elseif (!is_user_logged_in()) {
            include IDO_PATH . 'includes/frontend/views/welcome.php';
        } elseif (!$round) {
            echo '<div class="ido-panel"><p>No round is running. The heralds will announce the next one.</p>';
            if (current_user_can('manage_options')) {
                echo '<p><a class="ido-btn" href="' . esc_url(admin_url('admin.php?page=ido_rounds')) . '">Open a round</a></p>';
            }
            echo '</div>';
        } elseif (!$kingdom) {
            include IDO_PATH . 'includes/frontend/views/found.php';
        } else {
            $view = IDO_PATH . 'includes/frontend/views/' . $key . '.php';
            if (is_readable($view)) {
                include $view;
            }
        }

        echo IDO_UI::footer();
        echo '</div>';
        return (string) ob_get_clean();
    }
}
