<?php
if (!defined('ABSPATH')) exit;

/**
 * Install health: what to tell an administrator when this copy was not installed
 * from a release.
 *
 * The plugin already refuses to do anything dangerous from a source copy, so
 * none of this is about stopping an attack. It is about the three ways an
 * install can be quietly wrong in a manner nobody notices until it bites, each
 * of which looks fine on the day it happens:
 *
 * **A second copy.** WordPress identifies a plugin by its folder, so two folders
 * are two plugins to it, sharing one set of tables and one set of scheduled
 * jobs. An update or a deactivation can land on whichever one you did not mean.
 *
 * **A folder named after a branch.** GitHub's Download ZIP produces
 * imperial-dominion-online-main, which runs perfectly and then turns the next
 * proper install into a second copy rather than an update. This is the cause of
 * the problem above, caught one step earlier.
 *
 * **A .git directory.** The whole history of the project in the web root. The
 * shipped .htaccess refuses it on Apache, and nginx ignores .htaccess entirely,
 * so whether it is actually exposed is a question about this site rather than
 * about this plugin -- and the only honest way to answer it is to ask the site.
 *
 * Ported from the same check in Imperial Barons Online, which had it first.
 */
class IDO_Health {

    /** The folder a release unpacks to, and the one WordPress will update. */
    const SLUG = 'imperial-dominion-online';

    /** Directories that exist in a checkout and in no release. */
    const DEV_DIRS = ['tests', 'tools', 'docs'];

    /**
     * The only dotted name a release carries.
     *
     * Everything else beginning with a dot is tooling that came along by
     * accident: .gitattributes, .gitignore, .github, an editor's .vscode, a
     * Mac's .DS_Store. None of them belong on a web server, and listing the ones
     * known today would miss whatever the next tool invents -- so the check
     * reads the directory and treats this as the exception rather than
     * enumerating the rule.
     */
    const KEEP_DOTTED = ['.htaccess'];

    /** @return array<int, array{level:string,title:string,body:string}> */
    public static function issues(): array {
        $out = [];
        foreach ([self::check_duplicates(), self::check_git(), self::check_folder(),
                  self::check_dev_dirs(), self::check_dotted()] as $issue) {
            if ($issue) $out[] = $issue;
        }
        return $out;
    }

    /** The folder this copy lives in, e.g. "imperial-dominion-online-main". */
    public static function folder(): string {
        return basename(untrailingslashit(IDO_PATH));
    }

    /**
     * Another copy of the plugin in wp-content/plugins.
     *
     * Found by looking at the directory rather than at what is loaded, which
     * matters: a second copy that is installed but not activated is invisible to
     * anything running inside the first one, and it is still what the next
     * update will land on.
     */
    private static function check_duplicates(): ?array {
        $others = self::other_copies();
        if (!$others) return null;

        return [
            'level' => 'error',
            'title' => 'There is more than one copy of this plugin installed',
            'body'  => '<p>This copy runs from <code>' . esc_html(self::folder())
                . '</code>, and these are also in <code>wp-content/plugins</code>: <code>'
                . implode('</code>, <code>', array_map('esc_html', $others)) . '</code>.</p>'
                . '<p>Every copy shares the same tables and the same scheduled jobs, and WordPress treats'
                . ' them as separate plugins, so an update or a deactivation can land on the wrong one.'
                . ' Keep the one in <code>' . esc_html(self::SLUG) . '</code> and delete the rest from the'
                . ' <a href="' . esc_url(admin_url('plugins.php')) . '">Plugins</a> screen. Deleting a copy'
                . ' does not touch the game: the tables belong to the site, not to the folder.</p>',
        ];
    }

    /** Installed under a branch-named folder, which makes the next proper install a second copy. */
    private static function check_folder(): ?array {
        if (self::folder() === self::SLUG) return null;

        return [
            'level' => 'warning',
            'title' => 'The plugin folder is not named ' . self::SLUG,
            'body'  => '<p>This copy is installed as <code>wp-content/plugins/'
                . esc_html(self::folder()) . '</code>, which is what GitHub\'s <em>Download ZIP</em>'
                . ' produces: it names the folder after the branch.</p>'
                . '<p>The game runs perfectly well like this. The trouble comes later: WordPress knows a'
                . ' plugin by its folder, so installing a release adds a <em>second</em> copy instead of'
                . ' updating this one, and you end up with two plugins over one set of tables. Deactivate'
                . ' the plugin, rename the folder to <code>' . esc_html(self::SLUG) . '</code> over FTP or'
                . ' your host\'s file manager, then activate it again. The game data is in the database and'
                . ' is not affected.</p>',
        ];
    }

    /** A .git directory means the whole project history is sitting in the web root. */
    private static function check_git(): ?array {
        if (!is_dir(IDO_PATH . '.git')) return null;

        $reachable = self::git_reachable();
        $has_htaccess = file_exists(IDO_PATH . '.htaccess');

        $body = '<p>This copy came from a clone or a hand-made archive, so <code>' . esc_html(self::folder())
            . '/.git</code> is inside <code>wp-content/plugins</code>. It holds every version of every file'
            . ' the project has ever had, including anything committed by mistake and removed later.</p>';

        if ($reachable === true) {
            $body .= '<p><strong>It is readable over the web on this site right now.</strong> The plugin ships'
                . ' an <code>.htaccess</code> that refuses it, but your server is not applying it'
                . ($has_htaccess
                    ? ' &mdash; nginx does not read <code>.htaccess</code> at all.'
                    : ', and that file is missing from this copy.')
                . '</p>';
        } elseif ($reachable === false) {
            $body .= '<p>A request for it from outside was refused, so this server is not serving it today.'
                . ' That can change with a host or configuration change, which is why it is worth removing'
                . ' anyway.</p>';
        } else {
            $body .= '<p>Whether this server would serve it could not be checked from here.</p>';
        }

        $body .= '<p>The fix is not to keep <code>.git</code> on a server at all. Install a release, or build'
            . ' one with <code>php tools/build-zip.php</code> as the README describes. Deleting the'
            . ' <code>.git</code> directory by hand works too and leaves the game untouched.</p>';

        return [
            'level' => $reachable === true ? 'error' : 'warning',
            'title' => $reachable === true
                ? 'The repository history is readable on this site'
                : 'This copy contains a .git directory',
            'body'  => $body,
        ];
    }

    /** The development directories, which no release has ever contained. */
    private static function check_dev_dirs(): ?array {
        $found = array_values(array_filter(self::DEV_DIRS,
            static fn($dir) => is_dir(IDO_PATH . $dir)));
        if (!$found) return null;

        return [
            'level' => 'warning',
            'title' => 'This copy carries directories a release does not',
            'body'  => '<p><code>' . implode('</code>, <code>', array_map('esc_html', $found))
                . '</code> are in your site and nothing needs them. None of them will run &mdash; every file'
                . ' in them refuses to execute outside a command line &mdash; but they are readable and they'
                . ' are dead weight. Delete them where they sit, or replace this with a release.</p>',
        ];
    }

    /**
     * Anything dotted that a release does not carry.
     *
     * Found by reading the directory rather than by checking names, which is the
     * point: .git has its own finding above because it is a different order of
     * problem, and everything else dotted is clutter that arrived with a source
     * copy. A list of known names would have missed .gitattributes, which is
     * what prompted this, and would miss the next one too.
     *
     * Files as well as directories. The original check asked is_dir(), so every
     * dotted *file* -- .gitattributes and .gitignore among them -- went
     * unnoticed however carefully it was looking.
     */
    private static function check_dotted(): ?array {
        $entries = @scandir(untrailingslashit(IDO_PATH));
        if (!$entries) return null;

        $strays = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (strpos($entry, '.') !== 0) continue;
            if (in_array($entry, self::KEEP_DOTTED, true)) continue;
            if ($entry === '.git') continue;            // reported in full above
            $strays[] = $entry;
        }
        if (!$strays) return null;

        sort($strays);
        return [
            'level' => 'warning',
            'title' => 'This copy carries tooling files a release does not',
            'body'  => '<p>These are in <code>' . esc_html(IDO_PATH) . '</code> and belong to the '
                . 'project rather than to your site: <code>'
                . implode('</code>, <code>', array_map('esc_html', $strays))
                . '</code>.</p>'
                . '<p>None of them does anything on a web server, and the plugin ships an '
                . '<code>.htaccess</code> that refuses dotted paths where the server reads it '
                . '&mdash; which nginx does not. They are safe to delete.</p>',
        ];
    }

    /** Other directories in wp-content/plugins holding this plugin's main file. */
    private static function other_copies(): array {
        $dir  = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
        $here = self::folder();
        $found = [];

        $entries = @scandir($dir);
        if (!$entries) return $found;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $here) continue;
            if (!is_dir($dir . '/' . $entry)) continue;
            if (file_exists($dir . '/' . $entry . '/' . self::SLUG . '.php')) $found[] = $entry;
        }
        return $found;
    }

    /**
     * Asks this site, over HTTP, whether it will serve the clone's .git/HEAD.
     *
     * Worth the request because the answer is genuinely unknowable from inside
     * PHP: it depends on the web server, which the plugin cannot see. The shipped
     * .htaccess settles it on Apache and does nothing on nginx, so "you have a
     * .git directory" and "anyone can read your history" are different claims and
     * only the site can say which one is true.
     *
     * Cached for a day, and only ever asked when a .git directory exists.
     *
     * @return bool|null true served, false refused, null could not tell
     */
    public static function git_reachable(bool $fresh = false): ?bool {
        $key = 'ido_git_reachable';

        if (!$fresh) {
            $cached = get_transient($key);
            if ($cached !== false) {
                return $cached === 'yes' ? true : ($cached === 'no' ? false : null);
            }
        }

        $url = plugins_url('.git/HEAD', IDO_PATH . self::SLUG . '.php');
        // Redirections followed, or a site that sends http to https looks safe
        // when the https copy would have handed the file over.
        $response = wp_remote_get($url, ['timeout' => 5, 'redirection' => 3, 'sslverify' => false]);

        if (is_wp_error($response)) {
            $result = null;
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if ($code === 200 && strpos($body, 'ref:') === 0) {
                $result = true;                                     // served: that is a real HEAD
            } elseif (in_array($code, [401, 403, 404, 410, 451], true)) {
                $result = false;                                    // refused outright
            } else {
                $result = null;                                     // something else answered; do not guess
            }
        }

        set_transient($key, $result === true ? 'yes' : ($result === false ? 'no' : 'unknown'), DAY_IN_SECONDS);
        return $result;
    }

    /**
     * The admin notice, on the Plugins screen and the game's own screens.
     *
     * Not on every screen in wp-admin. Somebody writing a post does not need to
     * be told about a plugin folder, and a notice that appears everywhere is one
     * people learn to scroll past, including on the day it says something new.
     */
    public static function notice(): void {
        if (!current_user_can('manage_options')) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        if ($id !== 'plugins' && strpos($id, 'ido_') === false) return;

        foreach (self::issues() as $issue) {
            printf('<div class="notice notice-%s"><p><strong>%s &mdash; %s</strong></p>%s</div>',
                $issue['level'] === 'error' ? 'error' : 'warning',
                esc_html(IDO_Game::NAME), esc_html($issue['title']), $issue['body']);
        }
    }
}
