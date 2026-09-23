<?php
if (!defined('ABSPATH')) exit;

/**
 * The game's place in the site's own navigation.
 *
 * Nine pages appearing in a theme menu is nine too many: the game has its own
 * navigation across the top of every screen, so the site only ever needs one
 * way in. This class keeps the other eight out of any menu the theme builds
 * from the page list, and can hang a single-item menu on a theme location.
 */
class IDO_Menu {

    /** The menu this plugin creates and looks after. */
    const MENU_NAME = 'Imperial Dominion';

    public static function init(): void {
        // Themes with no assigned menu fall back to listing pages. Both filters
        // are needed: one for wp_list_pages(), one for wp_page_menu().
        add_filter('wp_list_pages_excludes', [__CLASS__, 'exclude_pages']);
        add_filter('wp_page_menu_args', [__CLASS__, 'exclude_from_page_menu']);
    }

    /** Every game page except the one front door. */
    public static function hidden_page_ids(): array {
        $ids = get_option('ido_page_ids', []);
        if (!is_array($ids)) return [];

        unset($ids['guide']);                    // the way in stays visible
        return array_values(array_filter(array_map('intval', $ids)));
    }

    public static function exclude_pages($excludes) {
        $excludes = is_array($excludes) ? $excludes : [];
        return array_merge($excludes, self::hidden_page_ids());
    }

    public static function exclude_from_page_menu($args) {
        $hidden = self::hidden_page_ids();
        if (!$hidden) return $args;

        $existing = isset($args['exclude']) && $args['exclude'] !== ''
            ? array_map('intval', array_filter(explode(',', (string) $args['exclude'])))
            : [];
        $args['exclude'] = implode(',', array_unique(array_merge($existing, $hidden)));
        return $args;
    }

    /**
     * What the menu item says to a visitor. The page title carries an "ID - "
     * prefix so the game's pages sort together in the admin; that prefix has no
     * business in the site's own navigation.
     */
    public static function menu_label(): string {
        $title = IDO_UI::PAGES['guide'][0];
        return trim(preg_replace('/^ID\s*-\s*/', '', $title)) ?: $title;
    }

    /** Theme locations a menu can be assigned to, for the settings dropdown. */
    public static function locations(): array {
        $locations = get_registered_nav_menus();
        return is_array($locations) ? $locations : [];
    }

    /** The menu this plugin manages, or null if it has not been made yet. */
    public static function menu(): ?object {
        $menu = wp_get_nav_menu_object(self::MENU_NAME);
        return $menu instanceof WP_Term ? $menu : null;
    }

    /**
     * Applies the chosen theme location.
     *
     * With a location set, a menu holding just the game's front page is created
     * if needed and hung there. With none set, the menu is left alone but
     * unhooked from any location it was holding, so turning this off never
     * deletes something a site owner may have since edited by hand.
     *
     * @return string a sentence describing what happened, for the admin notice
     */
    public static function apply(): string {
        $wanted = sanitize_key((string) IDO_Settings::get('menu_location'));
        $locations = get_theme_mod('nav_menu_locations', []);
        if (!is_array($locations)) $locations = [];
        $menu = self::menu();

        if ($wanted === '' || !array_key_exists($wanted, self::locations())) {
            if (!$menu) return '';
            $released = false;
            foreach ($locations as $location => $menu_id) {
                if ((int) $menu_id === (int) $menu->term_id) {
                    unset($locations[$location]);
                    $released = true;
                }
            }
            if ($released) {
                set_theme_mod('nav_menu_locations', $locations);
                return 'The game menu is no longer assigned to a theme location.';
            }
            return '';
        }

        $menu_id = $menu ? (int) $menu->term_id : 0;
        if (!$menu_id) {
            $created = wp_create_nav_menu(self::MENU_NAME);
            if (is_wp_error($created)) {
                return 'The game menu could not be created: ' . $created->get_error_message();
            }
            $menu_id = (int) $created;
        }

        $page_ids = get_option('ido_page_ids', []);
        $home_id = is_array($page_ids) && !empty($page_ids['guide']) ? (int) $page_ids['guide'] : 0;
        if (!$home_id) {
            return 'Create the game pages first: there is no front page to put in the menu.';
        }

        // Only add the item if it is not already there, so a site owner who has
        // renamed it or added items of their own keeps their changes.
        $already = false;
        foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
            if ((int) $item->object_id === $home_id && $item->object === 'page') {
                $already = true;
                break;
            }
        }
        if (!$already) {
            wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-object-id' => $home_id,
                'menu-item-object'    => 'page',
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
                'menu-item-title'     => self::menu_label(),
            ]);
        }

        $locations[$wanted] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);

        $label = self::locations()[$wanted] ?? $wanted;
        return sprintf('The game menu is assigned to the %s location.', $label);
    }
}
