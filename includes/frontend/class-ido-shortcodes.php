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
