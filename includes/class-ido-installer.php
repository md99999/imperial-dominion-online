<?php
if (!defined('ABSPATH')) exit;

class IDO_Installer {

    public static function activate(): void {
        self::install_schema();
        if (get_option(IDO_Settings::OPTION) === false) {
            update_option(IDO_Settings::OPTION, IDO_Settings::defaults());
        }
        // Opening the first round is a convenience, not a requirement: if it
        // fails, the plugin should still activate and say so on the dashboard
        // rather than dying inside the activation hook.
        if (!IDO_Rounds::current()) {
            try {
                IDO_Rounds::start();
            } catch (IDO_Game_Exception $e) {
                update_option('ido_activation_error', $e->getMessage());
            }
        }
        IDO_Maintenance::schedule();
    }

    public static function deactivate(): void {
        IDO_Maintenance::unschedule();
    }

    public static function maybe_upgrade(): void {
        if (get_option('ido_db_version') !== IDO_DB_VERSION) {
            self::migrate_settings();
            self::migrate_page_ids();
            self::install_schema();
        }
    }

    /**
     * Carries the recorded page ids across when a screen is renamed. Without
     * this the game forgets which WordPress page held the old screen and the
     * next press of "create pages" makes a duplicate beside it.
     */
    private static function migrate_page_ids(): void {
        $ids = get_option('ido_page_ids');
        if (!is_array($ids)) return;

        $renamed = ['throne' => 'empire'];
        $changed = false;
        foreach ($renamed as $old => $new) {
            if (array_key_exists($old, $ids)) {
                if (!array_key_exists($new, $ids)) $ids[$new] = $ids[$old];
                unset($ids[$old]);
                $changed = true;
            }
        }
        if ($changed) update_option('ido_page_ids', $ids);
    }

    /**
     * Carries settings across when a key is renamed, so a game master who had
     * tuned a value does not silently get the default back.
     */
    private static function migrate_settings(): void {
        $saved = get_option(IDO_Settings::OPTION);
        if (!is_array($saved)) return;

        $renamed = [
            'raze_refund_percent' => 'demolish_refund_percent',
            'starting_knights'    => 'starting_legionnaires',
        ];
        $changed = false;
        foreach ($renamed as $old => $new) {
            if (array_key_exists($old, $saved)) {
                if (!array_key_exists($new, $saved)) {
                    $saved[$new] = $saved[$old];
                    $changed = true;
                }
                unset($saved[$old]);
                $changed = true;
            }
        }
        if ($changed) update_option(IDO_Settings::OPTION, $saved);
    }

    /** Runs sql/install.sql through dbDelta, substituting the table prefix. */
    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        self::rename_legacy_columns();
        $sql = file_get_contents(IDO_PATH . 'sql/install.sql');
        if ($sql === false) return;
        $sql = str_replace(
            ['{prefix}', '{charset_collate}'],
            [$wpdb->prefix, $wpdb->get_charset_collate()],
            $sql
        );
        dbDelta($sql);
        update_option('ido_db_version', IDO_DB_VERSION);
    }

    /**
     * Renames columns that changed name between versions, before dbDelta runs.
     * dbDelta only ever adds columns, so without this a rename would silently
     * abandon the old column and everything counted in it.
     */
    private static function rename_legacy_columns(): void {
        global $wpdb;
        $table = IDO_DB::t('kingdoms');
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) return;

        $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . $table . '`');
        if (!is_array($columns)) return;

        // 1.8.0 renamed the troop types, and 1.15.0 renamed them again when the
        // game took its Roman theme. The columns are renamed rather than added,
        // so standing armies carry over instead of being wiped, and any troops
        // sitting on the market keep pointing at something real. The 1.8.0 names
        // are still listed so a site that skipped that release lands in the same
        // place: each rename runs in turn, u_warden -> u_knight -> u_legionnaire.
        $troops = [
            'u_levy'        => 'u_pawn',
            'u_warden'      => 'u_knight',
            'u_reaver'      => 'u_squire',
            'u_siege_train' => 'u_rook',
            'u_knight'      => 'u_legionnaire',
            'u_squire'      => 'u_centurion',
            'u_rook'        => 'u_ballista_legion',
        ];
        foreach ($troops as $old => $new) {
            if (in_array($old, $columns, true) && !in_array($new, $columns, true)) {
                $wpdb->query('ALTER TABLE `' . $table . '` CHANGE `' . $old . '` `' . $new . '` bigint(20) NOT NULL DEFAULT 0');
                $wpdb->query($wpdb->prepare(
                    'UPDATE ' . IDO_DB::t('listings') . ' SET item_key = %s WHERE item_key = %s',
                    substr($new, 2), substr($old, 2)
                ));
                // Later renames in this list have to see the new name, or a
                // chained rename would only ever run its first step.
                $columns = array_map(static fn($c) => $c === $old ? $new : $c, $columns);
            }
        }

        // 1.15.0 renamed the counting house to the mint. Buildings are not
        // tradeable, so only the column and the queued build orders move.
        if (in_array('b_counting_house', $columns, true) && !in_array('b_mint', $columns, true)) {
            $wpdb->query('ALTER TABLE `' . $table . '` CHANGE `b_counting_house` `b_mint` bigint(20) NOT NULL DEFAULT 0');
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . IDO_DB::t('constructions') . ' SET building = %s WHERE building = %s',
                'mint', 'counting_house'
            ));
        }

        // 1.5.0 dropped runestones: gold is the only currency now. The acres
        // under runeworks return to wilderness, which is what dropping the
        // column does on its own, since wilderness is land less what stands on it.
        if (in_array('b_runeworks', $columns, true)) {
            $wpdb->query('ALTER TABLE `' . $table . '` DROP COLUMN `b_runeworks`');
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . IDO_DB::t('constructions') . ' WHERE building = %s', 'runeworks'
            ));
        }
        if (in_array('runestones', $columns, true)) {
            $wpdb->query('ALTER TABLE `' . $table . '` DROP COLUMN `runestones`');
            // Any runestones still on the market go with them; the goods no
            // longer exist, so returning them to the seller is not possible.
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . IDO_DB::t('listings') . ' WHERE item_key = %s', 'runestones'
            ));
        }

        // 1.2.0 renamed bastions to fortifications.
        if (in_array('b_bastion', $columns, true) && !in_array('b_fortification', $columns, true)) {
            $wpdb->query('ALTER TABLE `' . $table . '` CHANGE `b_bastion` `b_fortification` int(11) NOT NULL DEFAULT 0');
            // Building orders already in the queue name their type as a string.
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . IDO_DB::t('constructions') . ' SET building = %s WHERE building = %s',
                'fortification', 'bastion'
            ));
        }
    }

    public static function drop_schema(): void {
        global $wpdb;
        foreach (IDO_DB::TABLES as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . IDO_DB::t($table));
        }
    }
}
