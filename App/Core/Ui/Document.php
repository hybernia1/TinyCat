<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Core\Ui\View;

/** Shared document structure; each surface supplies explicit data and its shell. */
final class Document
{
    /** @param array<string, mixed> $page
     * @param array<string, mixed>|null $document */
    public static function render(array $page, string $content, ?array $document = null): void
    {
        $prepared = DocumentData::prepare($page, $document);
        $prepared['content'] = $content;
        $prepared['public'] = (bool) ($page['public'] ?? false);
        self::renderPrepared($prepared, View::render('public-shell', $prepared, __DIR__ . '/templates'));
    }

    /** @param array<string, mixed> $prepared */
    public static function renderPrepared(array $prepared, string $shell): void
    {
        $html = self::frame([
            'title' => $prepared['pageTitle'], 'csrfToken' => $prepared['csrfToken'],
            'htmlAttributes' => [
                'lang' => \TinyCat\Support\Localization::locale(),
                'data-theme' => $prepared['theme'] !== 'system' ? $prepared['theme'] : null,
                'data-interface-prefix' => $prepared['interfacePrefix'], 'data-interface-routes' => $prepared['interfaceRoutes'],
                'data-interface-locales' => $prepared['interfaceLocales'], 'data-date-format' => $prepared['dateFormat'],
                'data-date-relative' => $prepared['dateRelative'] ? 'true' : 'false',
            ],
            'bodyAttributes' => [
                'class' => $prepared['bodyClasses'] !== '' ? $prepared['bodyClasses'] : null,
                'data-application-version' => $prepared['applicationVersion'],
                'data-current-user-id' => (int) ($prepared['authUser']['id'] ?? 0),
                'data-icon-sprite' => \TinyCat\Support\Assets::asset('App/Core/Ui/assets/icons.svg'),
                'data-fallback-avatar' => \TinyCat\Support\Assets::asset('App/Core/Ui/assets/fallback/avatar.svg'),
                'data-fallback-image' => \TinyCat\Support\Assets::asset('App/Core/Ui/assets/fallback/image.svg'),
            ],
            'metadata' => new \TinyCat\Core\Ui\Markup(View::render('head', $prepared, __DIR__ . '/templates')),
            'styles' => $prepared['styleUrls'], 'scripts' => $prepared['scriptUrls'], 'flashToasts' => $prepared['flashToasts'],
            'analyticsMeasurementId' => $prepared['analyticsConfigured'] && $prepared['analyticsConsent'] === 'granted' ? $prepared['googleMeasurementId'] : '',
        ], new \TinyCat\Core\Ui\Markup($shell));
        if ((bool) \TinyCat\Core\Runtime\Startup::option('performance.minify_html', false)) { $html = \TinyCat\Support\Minifier::minifyHtml($html); }
        echo $html;
    }

    /** Render prepared data only: no settings, authentication, session or database access.
     * @param array<string, mixed> $data */
    public static function frame(array $data, \TinyCat\Core\Ui\Markup $content): string
    {
        $data += ['title' => '', 'csrfToken' => '', 'htmlAttributes' => [], 'bodyAttributes' => [], 'metadata' => new \TinyCat\Core\Ui\Markup(''),
            'styles' => [], 'scripts' => [], 'flashToasts' => [], 'analyticsMeasurementId' => '', 'skipLink' => true, 'colorScheme' => true, 'robots' => ''];
        foreach (['htmlAttributes', 'bodyAttributes'] as $group) {
            foreach (array_keys($data[$group]) as $name) {
                if (!is_string($name) || preg_match('/^(?:lang|class|data-[a-z0-9-]+)$/D', $name) !== 1) { throw new \InvalidArgumentException('Unsupported document attribute.'); }
            }
        }
        $data['content'] = $content;
        return View::render('document', $data, __DIR__ . '/templates');
    }
}
