<?php
declare(strict_types=1);
namespace TinyCat\Updates;

use TinyCat\Http\Request;
use TinyCat\Http\Session;
use TinyCat\Site\Layout;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Users\Access;


/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function administration(): void
    {
        Access::require_admin();

        $release = Session::flash('updater_release');
        $release = is_array($release) ? $release : Manager::cachedRelease();
        $result = Session::flash('updater_result');
        $result = is_array($result) ? $result : [];
        $error = trim((string) Session::flash('updater_error'));
        $maintenance = Manager::maintenanceState();
        $extensions = [
            'cURL' => extension_loaded('curl'),
            'Sodium' => extension_loaded('sodium'),
            'ZIP / Phar' => class_exists('ZipArchive') || class_exists('PharData'),
        ];
        $releaseCompatible = !is_array($release) || !array_key_exists('compatible', $release) || !empty($release['compatible']);
        $canInstall = !in_array(false, $extensions, true) && $releaseCompatible;
        $history = Manager::migrationHistory();
        $available = is_array($release) && !empty($release['available']);
        $actions = View::render('actions', compact('release', 'available', 'canInstall'), __DIR__ . '/templates');
        $releaseNotesModal = is_array($release) && trim((string) ($release['notes'] ?? '')) !== ''
            ? View::render('parts/modal', ['id' => 'updates-release-notes', 'title' => Localization::t('updates.changelog'),
                'body' => '<pre class="code-block"><code>' . \TinyCat\Support\Html::e((string) $release['notes']) . '</code></pre>'], \TinyCat\Support\Paths::basePath('App/Support/templates'))
            : '';

        Layout::render([
            'title' => Localization::t('updates.title'),
            'current' => '/admin/updates',
            'actions' => $actions,
        ], \TinyCat\Support\AdminViews::listCard(Localization::t('updates.release_title'), 'download', 'updates-overview', '', new \TinyCat\Support\Markup(View::render('administration', compact('release', 'result', 'error', 'maintenance', 'extensions', 'releaseCompatible', 'canInstall', 'history', 'releaseNotesModal'), __DIR__ . '/templates')), primary: true));
    }
}
