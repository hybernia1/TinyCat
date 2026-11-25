<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';

define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require_once $root . '/App/Core/Runtime/autoload.php'; \TinyCat\Core\Runtime\Application::start();

// Rendering fixtures must not depend on an installed workspace/CI database.
$database = \tinycat_test_database();
$database->exec('CREATE TABLE social_post_links (content_id INT UNSIGNED, link_id INT UNSIGNED)');
$database->exec('CREATE TABLE social_post_images (content_id INT UNSIGNED, path TEXT)');
$database->exec('CREATE TABLE social_links (id INTEGER, normalized_url TEXT, url_hash CHAR(64), provider VARCHAR(200), link_type VARCHAR(200), title TEXT, description TEXT, image_url TEXT, video_id VARCHAR(200), audio_url TEXT, created_at DATETIME, updated_at DATETIME)');
\TinyCat\Database\Connection::set($database);
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Core\Identity\Authentication::reset(null, true);

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
};

$privacy = \TinyCat\Support\Components::render('ui/privacy-page', [
    'siteName' => 'Fixture', 'siteCaptchaEnabled' => false, 'siteGoogleAnalyticsConfigured' => false,
    'sections' => new \TinyCat\Support\Markup(\TinyCat\Support\Components::render('site/privacy-section', ['siteName' => 'Fixture', 'privacyLimitRows' => []])),
]);
$intro = \TinyCat\Support\Localization::t('privacy.intro', ['site' => 'Fixture']);
$assert($intro !== 'privacy.intro' && substr_count(html_entity_decode(strip_tags($privacy)), $intro) === 1, 'The Social privacy introduction appears once inside the core information page.');
preg_match_all('~<h2\b[^>]*>(.*?)</h2>~s', $privacy, $privacyHeadings);
$privacyHeadings = array_map(static fn (string $heading): string => trim(html_entity_decode(strip_tags($heading))), $privacyHeadings[1]);
foreach (['data_title', 'recovery_title'] as $section) {
    $assert(count(array_keys($privacyHeadings, \TinyCat\Support\Localization::t('privacy.' . $section), true)) === 1, 'Shared privacy sections appear once with Social enabled: ' . $section);
}
$socialData = \TinyCat\Support\Localization::t('privacy.data_content', ['site' => 'Fixture']);
$assert(substr_count(html_entity_decode(strip_tags($privacy)), $socialData) === 1, 'Social retains its own data disclosure without duplicating core account and recovery sections.');
$sidebar = \TinyCat\Social\Site\Sidebar::resource(['tags' => [], 'authors' => [], 'site_footer_html' => '<strong>Retired custom footer</strong>']);
$assert(!array_key_exists('footer', $sidebar) && isset($sidebar['privacy_url']), 'Sidebar resources ignore retired footer input and retain the system privacy link.');
$errorData = ['status' => 500, 'title' => 'Error <unsafe>', 'intro' => 'Try again', 'locale' => 'en', 'homeUrl' => '/nested/', 'homeLabel' => 'Home', 'searchUrl' => '', 'searchLabel' => 'Search'];
foreach (['error', 'errorFallback'] as $template) {
    $errorHtml = \TinyCat\Support\View::render($template, $errorData, $root . '/App/Core/Ui/templates');
    $assert(str_contains($errorHtml, 'href="/nested/"') && str_contains($errorHtml, 'Error &lt;unsafe&gt;') && !str_contains($errorHtml, '/search/all'), 'Error templates use prepared portable actions and escape titles: ' . $template);
}
$bootstrapError = \TinyCat\Support\View::render('errorFallback', array_diff_key($errorData, array_flip(['homeUrl', 'homeLabel', 'searchUrl', 'searchLabel'])), $root . '/App/Core/Ui/templates');
$assert(str_contains($bootstrapError, 'href="/"') && str_contains($bootstrapError, '500'), 'Emergency rendering remains compatible with a cached updater that only passes the original error metadata.');

$modal = \TinyCat\Support\Components::render('ui/modal', [
    'root_attributes' => ['id' => 'snapshot-modal', 'class' => 'modal', 'role' => 'dialog', 'aria-modal' => 'true', 'aria-labelledby' => 'snapshot-modal-title'],
    'panel_attributes' => ['class' => 'modal-panel', 'data-snapshot' => 'a&b'],
    'title_id' => 'snapshot-modal-title',
    'title' => 'Title <unsafe>',
    'close_label' => 'Close', 'close_icon' => new \TinyCat\Support\Markup(\TinyCat\Support\Html::icon('close')),
    'body_class' => 'modal-body',
    'body' => new \TinyCat\Support\Markup('<p id="snapshot-body">Safe body</p>'),
]);
$assert(str_contains($modal, 'Title &lt;unsafe&gt;'), 'Modal title is escaped.');
$assert(str_contains($modal, 'data-snapshot="a&amp;b"'), 'Prepared modal attributes are escaped.');
$assert(str_contains($modal, '<p id="snapshot-body">Safe body</p>'), 'Prepared modal body HTML is preserved.');

$composerBody = \TinyCat\Support\Components::render('status/composer-body', [
    'constraints' => \TinyCat\Social\Posts\Resource::constraints(),
    'viewer' => \TinyCat\Social\Profiles\Profiles::resource(['id' => 1, 'username' => 'alice', 'avatar_exists' => 0]),
    'value' => 'Draft', 'image' => [], 'enabled' => false,
    'submit_name' => 'save', 'submit_value' => '1', 'submit_label' => 'Save', 'submit_icon' => 'save',
]);
$assert(str_contains($composerBody, 'class="status-compose-row"'), 'Composer body exposes the shared composer layout.');
$assert(str_contains($composerBody, 'name="save"'), 'Composer body can render the edit submit action.');
$assert(!str_contains($composerBody, 'status-schedule-modal'), 'The shared composer does not expose the scheduler modal.');

$time = \TinyCat\Support\Components::render('status/time', ['item' => ['url' => '/status/7', 'published_at' => '2026-08-09T12:00:00+00:00', 'published_label' => '9 August 2026 12:00', 'permalink_label' => 'Alice'], 'open_modal' => false]);
$assert(str_contains($time, 'href="/status/7"'), 'Status time link uses the canonical permalink.');
$assert(str_contains($time, '<time datetime="'), 'Status time link includes a machine-readable date.');
$assert(!str_contains($time, 'data-modal-open'), 'Status time link respects the non-modal rendering option.');

$statusCard = \TinyCat\Support\Components::render('status/card', [
    'item' => \TinyCat\Social\Posts\Resource::post(['id' => 7, 'author_id' => 1, 'author_name' => 'Alice', 'published_at' => '2026-08-09 12:00:00'], null),
    'anchor' => false, 'open_modal' => false, 'action' => '/',
]);
$assert(!str_contains($statusCard, 'id="status-7"'), 'Modal status cards can omit the feed anchor.');
$assert(str_contains($statusCard, 'data-modal-parent-url="/api/statuses/7"'), 'Status cards consume their prepared modal route.');
$assert(preg_match('/status-time-button[^>]*data-modal-open/', $statusCard) !== 1, 'Modal status cards keep their time link in the current modal.');

$link = \TinyCat\Support\Components::render('publishing/link', ['link' => ['url' => 'https://www.youtube.com/watch?v=abc123', 'title' => 'Demo <video>', 'embed_url' => 'https://www.youtube.com/embed/abc123', 'embed_allowed' => true, 'thumbnail_url' => 'https://i.ytimg.com/vi/abc123/mqdefault.jpg', 'thumbnail_mobile_url' => 'https://i.ytimg.com/vi/abc123/default.jpg', 'thumbnail_webp_url' => '']]);
$assert(str_contains($link, 'media="(max-width: 759px)" srcset="https://i.ytimg.com/vi/abc123/default.jpg"') || str_contains($link, 'srcset="https://i.ytimg.com/vi/abc123/default.jpg" media="(max-width: 759px)"'), 'A media source selects the tiny image on mobile regardless of pixel density.');
$assert(str_contains($link, 'data-embed-url="https://www.youtube.com/embed/abc123"'), 'Video card emits a normalized embed URL.');
$assert(str_contains($link, 'Demo &lt;video&gt;'), 'Video card escapes its title.');
$assert(str_contains($link, 'src="https://i.ytimg.com/vi/abc123/mqdefault.jpg"') && !str_contains($link, 'hqdefault'), 'Desktop uses only the medium image without a high-resolution source.');

$pagination = \TinyCat\Support\Components::render('ui/pagination', [
    'navigation' => \TinyCat\Support\AdminViews::admin_pagination_view_data(
        ['page' => 2, 'last_page' => 5, 'total' => 50, 'from' => 11, 'to' => 20],
        '/api/users',
        '#users-list',
        [],
        'page',
        2,
        '/admin/users'
    ),
    'target' => '#users-list',
]);
$assert(str_contains($pagination, 'aria-current="page">2</span>'), 'Pagination marks the current page.');
$assert(str_contains($pagination, 'data-history="/admin/users?page=3"'), 'Pagination preserves the browser history URL.');
$assert(str_contains($pagination, 'data-ajax-target="#users-list"'), 'Pagination preserves its AJAX target.');
$assert(str_contains($pagination, 'class="pagination-list"') && str_contains($pagination, 'class="pagination-link is-active"'), 'Pagination groups controls using the shared styles instead of spreading numbers across the page.');
$assert(str_contains($pagination, 'class="pagination-link pagination-prev"') && str_contains($pagination, 'class="pagination-link pagination-next"'), 'Pagination includes previous and next controls.');
$gaps = \TinyCat\Support\Components::render('ui/pagination', ['navigation' => \TinyCat\Support\AdminViews::admin_pagination_view_data(['page' => 10, 'last_page' => 20], '/api/users', '#users-list'), 'target' => '#users-list']);
$assert(substr_count($gaps, 'class="pagination-ellipsis"') === 2, 'Non-adjacent page ranges are separated by ellipses.');


$comment = \TinyCat\Support\Components::render('status/comment', [
    'item' => \TinyCat\Social\Posts\Resource::comment([
        'id' => 13, 'content_id' => 7, 'author_name' => 'Alice <unsafe>', 'author_id' => 1,
        'body' => 'Hello #tinycat <unsafe>', 'created_at' => '2026-08-09 12:01:00', 'likes_count' => 4, 'replies' => [],
    ], null),
    'viewer' => null, 'context' => '', 'show_replies' => false, 'show_reply_form' => false, 'preview' => true,
]);
$assert(str_contains($comment, 'href="/tag/tinycat"') && str_contains($comment, '&lt;unsafe&gt;'), 'Comment renders canonical segments and escapes literal body text.');
$assert(str_contains($comment, 'Alice &lt;unsafe&gt;'), 'Comment still escapes prepared author text.');
$assert(str_contains($comment, 'data-comment-like-count') && str_contains($comment, '>4</span>'), 'Comment renders its prepared reaction count.');

$notification = \TinyCat\Core\Notifications\Views::items(['notifications' => [[
    'id' => 21, 'type' => 'content_mention', 'actor_name' => 'Bob <unsafe>',
    'subject_id' => 7, 'subject_url' => '/status/7', 'subject_text' => 'Notification content text',
    'created_at' => '2026-08-09T12:02:00+00:00', 'read_at' => null,
]]], true);
$assert(str_contains($notification, 'href="/status/7"') && str_contains($notification, 'data-url="/api/notifications/21?open=1"') && str_contains($notification, 'data-method="PATCH"'), 'Unread notifications mark read through the API and retain a read-only destination.');
$assert(str_contains($notification, 'Notification content text'), 'Notification renders its content excerpt.');
$assert(str_contains($notification, \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t('notifications.messages.content_mention', ['actor' => 'Bob <unsafe>']))), 'Notification translates raw event data with escaped actor text.');

if (class_exists(DOMDocument::class)) {
    $document = new DOMDocument();
    $loaded = @$document->loadHTML('<!doctype html><html><body>' . $modal . $pagination . $comment . $notification . '</body></html>');
    $assert($loaded, 'Template snapshots are parseable HTML.');
    $dialog = $document->getElementById('snapshot-modal');
    $assert($dialog instanceof DOMElement && $dialog->getAttribute('role') === 'dialog', 'Modal exposes dialog semantics.');
}

// Account pages compose the same form definitions as resource-loaded dialogs.
$loginPage = \TinyCat\Support\Components::render('users/auth-page', \TinyCat\Core\Identity\Resource::authenticationForm('login', '/search') + ['old' => ['username' => '<Member>'], 'error' => '<Failed>']);
$assert(str_contains($loginPage, 'action="/api/users/sessions"') && str_contains($loginPage, '&lt;Member&gt;') && str_contains($loginPage, '&lt;Failed&gt;'), 'The direct login page preserves canonical endpoints, previous input and escaped errors.');
$disabledRegistration = \TinyCat\Core\Identity\Resource::authenticationForm('register', '');
$disabledRegistration['registration_enabled'] = false;
$disabledRegistration = \TinyCat\Support\Components::render('users/auth-page', $disabledRegistration);
$assert(!str_contains($disabledRegistration, '<form') && str_contains($disabledRegistration, 'alert-info'), 'Disabled registration renders its notice without a form.');
$recoveryData = ['reset' => false, 'token' => '', 'password_max' => \TinyCat\Core\Identity\Passwords::maxLength(), 'password_min' => \TinyCat\Core\Identity\Passwords::minLength(), 'captcha' => ['enabled' => false], 'login_url' => '/pl/login', 'privacy_url' => '/pl/privacy'];
$recoveryPage = \TinyCat\Support\Components::render('users/recovery-page', $recoveryData);
$assert(str_contains($recoveryPage, 'action="/api/users/password-resets"') && str_contains($recoveryPage, 'type="email"') && !str_contains($recoveryPage, 'name="password"'), 'Recovery requests use the email form.');
$resetPage = \TinyCat\Support\Components::render('users/recovery-page', array_replace($recoveryData, ['reset' => true, 'token' => '"><unsafe>']));
$assert(str_contains($resetPage, 'action="/api/users/password-resets/confirm"') && str_contains($resetPage, '&quot;&gt;&lt;unsafe&gt;') && substr_count($resetPage, 'autocomplete="new-password"') === 2, 'Password resets preserve the escaped token and both constrained password fields.');
$assert(substr_count($resetPage, 'data-max-bytes="' . \TinyCat\Core\Identity\Passwords::maxLength() . '"') === 2 && substr_count($resetPage, 'data-min-bytes="' . \TinyCat\Core\Identity\Passwords::minLength() . '"') === 2 && str_contains($resetPage, 'href="/pl/login"'), 'Recovery shares byte-based password constraints and localized navigation.');
$logoutPage = \TinyCat\Support\Components::render('users/logout-page');
$assert(str_contains($logoutPage, 'method="post"') && str_contains($logoutPage, 'data-api-method="DELETE"') && str_contains($logoutPage, 'data-confirm-variant="danger"') && str_contains($logoutPage, 'name="_csrf"'), 'Logout retains server CSRF, DELETE transport and the shared confirmation lifecycle.');

// Layout composition must preserve a single document and select the correct shell.
set_error_handler(static function (int $level, string $message): never { throw new ErrorException($message, 0, $level); });
try {
    ob_start();
    \TinyCat\Core\Ui\Page::render(['title' => 'Public <unsafe>', 'current' => '/search', 'meta' => ['description' => 'Description <unsafe>']], '<p id="page-fixture">Prepared content</p>');
    $publicLayout = (string) ob_get_clean();
    $assert(substr_count($publicLayout, '<!doctype html>') === 1 && substr_count($publicLayout, '<body') === 1, 'Public shell produces exactly one HTML document.');
    $assert(str_contains($publicLayout, 'class="navbar"') && !str_contains($publicLayout, 'data-admin-shell'), 'Guest pages use the public shell.');
    $assert(str_contains($publicLayout, 'Public &lt;unsafe&gt;') && str_contains($publicLayout, 'id="page-fixture"'), 'Head metadata is escaped and prepared content is preserved.');
    $assert(substr_count($publicLayout, ' defer></script>') === 4, 'Split layout keeps scoped script delivery.');
    $assert(substr_count($publicLayout, '<title>') === 1 && substr_count($publicLayout, 'name="csrf-token"') === 1, 'The public metadata fragment does not duplicate common head fields.');
    $assert(str_contains($publicLayout, 'rel="canonical"') && str_contains($publicLayout, 'property="og:title"') && str_contains($publicLayout, 'data-interface-routes='), 'Public metadata and locale routing survive document unification.');
    $metadataPage = ['title' => 'Public <unsafe>', 'current' => '/search', 'meta' => ['description' => 'Description <unsafe>']];
    $metadata = \TinyCat\Core\Ui\Metadata::document($metadataPage);
    ob_start();
    \TinyCat\Core\Ui\Page::render($metadataPage, '<p id="page-fixture">Prepared content</p>', $metadata);
    $assert((string) ob_get_clean() === $publicLayout, 'Reusing resource metadata preserves the entire default HTML document byte-for-byte.');
    $metadata['meta_title'] = 'Prepared <metadata>';
    ob_start();
    \TinyCat\Core\Ui\Page::render($metadataPage, '<p>Prepared content</p>', $metadata);
    $reused = (string) ob_get_clean();
    $assert(str_contains($reused, 'property="og:title" content="Prepared &lt;metadata&gt;"'), 'The shared document consumes supplied metadata rather than recomputing it.');
    $originalOptions = \TinyCat\Core\Runtime\Startup::$options;
    try {
        $defaultHomepage = \TinyCat\Core\Runtime\EntryPages::homepage()?->id;
        $assert($defaultHomepage === 'tinycat.status.home', 'Adding the login candidate preserves the default Social homepage.');
        $siteFields = \TinyCat\Core\Settings\Settings::section_site()['fields'];
        $assert(!in_array('site.footer_html', array_column($siteFields, 'key'), true), 'The settings editor no longer exposes the retired custom footer.');
        $homepageFields = array_values(array_filter($siteFields, static fn (array $field): bool => $field['key'] === 'site.homepage'));
        $assert(isset($homepageFields[0]['options']['tinycat.accounts.login']), 'Site settings expose the Identity-owned login homepage choice.');
        \TinyCat\Core\Runtime\Startup::$options = static fn (string $key, mixed $default = null): mixed => $key === 'site.homepage' ? 'tinycat.accounts.login' : ($originalOptions !== null ? $originalOptions($key, $default) : $default);
        $loginHomepage = \TinyCat\Core\Runtime\EntryPages::homepage();
        $assert($loginHomepage?->id === 'tinycat.accounts.login', 'The selected login entry is resolved by the shared homepage registry.');
        $assert(!in_array('/login', array_column(\TinyCat\Core\Runtime\EntryPages::navigation(), 'href'), true), 'The login choice does not add a duplicate login navigation item.');
        ob_start();
        ($loginHomepage->render)();
        $loginDocument = (string) ob_get_clean();
        $assert(str_contains($loginDocument, 'name="username"') && str_contains($loginDocument, 'name="password"'), 'Selected login homepage renders the existing authentication form with Social active.');
        $assert(!str_contains($loginDocument, 'class="navbar"') && !str_contains($loginDocument, 'data-notification') && !str_contains($loginDocument, 'googletagmanager'), 'Authentication prepares only the access shell even with Social active.');
        $accessSources = \TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries('/account'));
        $assert(!str_contains(implode(' ', $accessSources), 'App/Modules/') && !in_array('App/Core/Ui/assets/global-search.js', $accessSources, true) && !in_array('App/Core/Notifications/assets/index.js', $accessSources, true), 'The access asset graph excludes functional modules, global search and inbox previews.');
        $publicCalls = 0;
        \TinyCat\Http\Router::page('fixture.public-surface', '/fixture-public-surface', static function () use (&$publicCalls): void { $publicCalls++; }, static function () use (&$publicCalls): array { $publicCalls++; return ['ok' => true]; });
        \TinyCat\Core\Runtime\Startup::$options = static fn (string $key, mixed $default = null): mixed => $key === 'site.public_enabled' ? false : ($originalOptions !== null ? $originalOptions($key, $default) : $default);
        $assert(!\TinyCat\Http\Router::dispatch('/fixture-public-surface', 'GET') && !\TinyCat\Http\Router::dispatch('/fixture-public-surface', 'POST'), 'Disabled public routes are unavailable before handler and method negotiation.');
        $assert(\TinyCat\Http\Router::pageResource('fixture.public-surface', '/fixture-public-surface') === null && \TinyCat\Http\Router::pageDefinitions('fixture.public-surface') === [] && $publicCalls === 0, 'Disabled public resources and navigation do not evaluate owner callbacks.');
        $assert(\TinyCat\Core\Identity\Login::auth_safe_next_url('/fixture-public-surface') === '', 'Closed public destinations cannot become a member login landing.');
        \TinyCat\Core\Runtime\Startup::$options = $originalOptions;
        $assert(\TinyCat\Http\Router::pageResource('fixture.public-surface', '/fixture-public-surface') === ['ok' => true] && $publicCalls === 1, 'Re-enabling the public surface restores registered resources without registration or build.');
        \TinyCat\Core\Runtime\Startup::$options = static fn (string $key, mixed $default = null): mixed => $key === 'site.homepage' ? 'fixture.missing' : ($originalOptions !== null ? $originalOptions($key, $default) : $default);
        $assert(\TinyCat\Core\Runtime\EntryPages::homepage() === null, 'A missing selected homepage does not switch to another active module.');
        $missingField = array_values(array_filter(\TinyCat\Core\Settings\Settings::section_site()['fields'], static fn (array $field): bool => $field['key'] === 'site.homepage'))[0];
        $assert(isset($missingField['options']['fixture.missing']), 'The editor preserves and identifies an unavailable saved homepage.');
        \TinyCat\Core\Runtime\Startup::$options = $originalOptions;
        $content = "<!-- removable -->\n<div>  <span>Text</span>  </div><pre>  Keep\n spacing</pre><textarea>  Draft\n text</textarea>";
        $renderDocument = static function () use ($content): string {
            ob_start();
            \TinyCat\Core\Ui\Document::render(['title' => 'Minification'], $content);
            return (string) ob_get_clean();
        };
        \TinyCat\Core\Runtime\Startup::$options = static fn (string $key, mixed $default = null): mixed => $key === 'performance.minify_html' ? false : ($originalOptions !== null ? $originalOptions($key, $default) : $default);
        $rawDocument = $renderDocument();
        \TinyCat\Core\Runtime\Startup::$options = static fn (string $key, mixed $default = null): mixed => $key === 'performance.minify_html' ? true : ($originalOptions !== null ? $originalOptions($key, $default) : $default);
        $minifiedDocument = $renderDocument();
        $assert($minifiedDocument === \TinyCat\Support\Minifier::minifyHtml($rawDocument) && strlen($minifiedDocument) < strlen($rawDocument), 'The shared document honors HTML minification after shell composition.');
        $assert(str_contains($minifiedDocument, "<pre>  Keep\n spacing</pre><textarea>  Draft\n text</textarea>"), 'Document minification preserves preformatted text and drafts.');
    } finally {
        \TinyCat\Core\Runtime\Startup::$options = $originalOptions;
    }
    // Social page composition must honor the same selected theme as core pages.
    $themeRoot = $root . '/tests/fixtures/themes';
    $localTheme = \TinyCat\Core\Ui\Theme::load('example', $themeRoot);
    $assert($localTheme !== null && \TinyCat\Core\Ui\Theme::choices($themeRoot) === ['example' => 'Example theme'], 'Theme metadata is discovered without a functional module.');
    $assert(\TinyCat\Core\Ui\Theme::load('../example', $themeRoot) === null && \TinyCat\Core\Ui\Theme::load('missing', $themeRoot) === null, 'Theme selection rejects traversal and tolerates a removed theme.');
    ob_start();
    $localTheme?->render(['title' => 'Local theme', 'public' => true, 'current' => '/example'], '<p data-owner-content>Owner content</p>');
    $localLayout = (string) ob_get_clean();
    $assert(str_contains($localLayout, 'data-example-theme') && str_contains($localLayout, 'data-owner-content') && substr_count($localLayout, '<!doctype html>') === 1, 'A local PHP layout preserves owner content inside the shared document.');
    $assert(str_contains($localLayout, 'name="csrf-token"'), 'A local layout uses common assets and document security metadata.');
    $themePage = ['title' => 'Local theme', 'public' => true, 'current' => '/example'];
    $themeMetadata = \TinyCat\Core\Ui\Metadata::document($themePage);
    $themeMetadata['meta_title'] = 'Prepared theme <metadata>';
    ob_start();
    $localTheme?->render($themePage, '<p data-owner-content>Owner content</p>', $themeMetadata);
    $assert(str_contains((string) ob_get_clean(), 'property="og:title" content="Prepared theme &lt;metadata&gt;"'), 'Local themes forward prepared metadata through the same common document.');

    require_once $root . '/tools/support/workspace.php';
    $manifestRoot = \TinyCat\Tools\Workspace::directory('tests/theme-' . bin2hex(random_bytes(6)));
    $manifestDirectory = $manifestRoot . '/App/Themes/example';
    mkdir($manifestDirectory, 0700, true);
    file_put_contents($manifestDirectory . '/public.php', '<?php throw new RuntimeException("Theme PHP executed during discovery.");');
    $manifest = ['id' => 'example', 'label' => 'Metadata only', 'layout' => 'public.php'];
    try {
        file_put_contents($manifestDirectory . '/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $assert(\TinyCat\Core\Ui\Theme::choices($manifestRoot) === ['example' => 'Metadata only'], 'Reading theme choices does not execute layout PHP.');
        foreach ([['layout' => '../outside.php'], ['id' => 'another'], ['unknown' => true], ['styles' => ['../escape.css']], ['scripts' => 'invalid']] as $invalid) {
            file_put_contents($manifestDirectory . '/theme.json', json_encode(array_replace($manifest, $invalid), JSON_THROW_ON_ERROR));
            $rejected = false;
            try { \TinyCat\Core\Ui\Theme::load('example', $manifestRoot); }
            catch (\InvalidArgumentException) { $rejected = true; }
            $assert($rejected, 'Invalid theme declarations are rejected before rendering: ' . (string) array_key_first($invalid));
        }
    } finally {
        unlink($manifestDirectory . '/theme.json');
        unlink($manifestDirectory . '/public.php');
        rmdir($manifestDirectory); rmdir(dirname($manifestDirectory)); rmdir(dirname($manifestDirectory, 2)); rmdir($manifestRoot);
    }
    $originalOptions = \TinyCat\Core\Runtime\Startup::$options;
    try {
        \TinyCat\Core\Runtime\Startup::$options = static fn (string $key, mixed $default = null): mixed => $key === 'site.theme' ? 'tinycat.site.theme' : ($originalOptions !== null ? $originalOptions($key, $default) : $default);
        ob_start();
        \TinyCat\Social\Site\PublicPages::render('home', ['page' => ['title' => 'Core layout', 'current' => '/social', 'meta' => ['url' => '/social']], 'context' => []]);
        $selectedLayout = (string) ob_get_clean();
        $assert(str_contains($selectedLayout, 'data-public-page="home"') && substr_count($selectedLayout, '<!doctype html>') === 1, 'A retired module theme selection falls back to the common document with Social content.');
        $assert(!str_contains($selectedLayout, '<h1 class="m-0">'), 'The public shell does not duplicate the module page heading.');
        $themeFields = array_values(array_filter(\TinyCat\Core\Settings\Settings::section_site()['fields'], static fn (array $field): bool => $field['key'] === 'site.theme'));
        $assert(isset($themeFields[0]['options']['tinycat.site.theme']), 'A retired saved theme is shown as unavailable without registering a module theme.');
        ob_start();
        \TinyCat\Core\Ui\Page::render(['title' => 'Login', 'current' => '/login', 'surface' => 'access'], '<p data-auth-content>Login</p>');
        $assert(str_contains((string) ob_get_clean(), 'data-auth-content'), 'Authentication retains its common core presentation.');
    } finally {
        \TinyCat\Core\Runtime\Startup::$options = $originalOptions;
    }
    \TinyCat\Core\Identity\Authentication::reset(['id' => 1, 'username' => 'Admin <unsafe>', 'role' => 'admin', 'status' => 'active', 'theme' => 'dark'], true);
    ob_start();
    \TinyCat\Core\Ui\Page::render(['title' => 'Administration', 'current' => '/admin/moderation/reports'], '<p>Admin content</p>');
    $adminLayout = (string) ob_get_clean();
    ob_start();
    \TinyCat\Core\Ui\Admin\Definitions::page('social.bot-creation', ['items' => [], 'filters' => [], 'sorting' => [], 'pagination' => []], '<button data-custom-list-action>Custom action</button>');
    $customList = (string) ob_get_clean();
    $assert(str_contains($customList, 'id="bot-accounts-list"') && substr_count($customList, 'data-custom-list-action') === 1 && str_contains($customList, 'data-admin-shell'), 'The common admin page composes the list and owner actions in one shared shell.');

    $assert(str_contains($adminLayout, 'data-admin-shell') && !str_contains($adminLayout, 'class="navbar"'), 'Admin pages use only the admin shell.');
    $assert(str_contains($adminLayout, 'noindex,nofollow') && str_contains($adminLayout, 'data-theme="dark"'), 'Admin robots policy and account theme survive the split.');
    $assert(substr_count($adminLayout, '<!doctype html>') === 1 && substr_count($adminLayout, '<title>') === 1 && substr_count($adminLayout, 'name="csrf-token"') === 1, 'Administration uses one complete common document.');
    $assert(!str_contains($adminLayout, 'data-settings-presentation=') && str_contains($adminLayout, 'data-date-relative=') && !str_contains($adminLayout, 'rel="canonical"') && !str_contains($adminLayout, 'googletagmanager'), 'Administration retains its explicit editor context without public metadata or analytics.');
    $assert(str_contains($adminLayout, 'open data-active="true"') && str_contains($adminLayout, 'aria-current="page"'), 'Prepared navigation expands and marks the active group.');
    $assert(preg_match('~class="admin-topbar-title".*?<strong>.*?#flag.*?Administration</strong>~s', $adminLayout) === 1, 'The admin title keeps the active section icon after list headers are removed.');
    $assert(str_contains($adminLayout, 'Admin &lt;unsafe&gt;'), 'Prepared administration identity is escaped.');
    ob_start();
    \TinyCat\Core\Ui\Page::render(['title' => 'Custom administration', 'current' => '/crm', 'surface' => 'admin'], '<p>Custom administration</p>');
    $customAdmin = (string) ob_get_clean();
    preg_match_all('~<script src="([^"]+)"~', $customAdmin, $customScripts);
    $assert(str_contains($customAdmin, 'data-admin-shell') && $customScripts[1] === \TinyCat\Support\ClientAssets::scripts('/admin'), 'An explicit administration surface renders its matching asset profile outside the admin URL prefix.');
} finally {
    restore_error_handler();
    \TinyCat\Core\Identity\Authentication::reset(null, true);
}

$assert(\TinyCat\Core\Ui\Surface::Administration->assetPath(['current' => '/crm']) === '/admin', 'An explicit admin surface selects admin assets on a nonstandard path.');
$assert(\TinyCat\Core\Ui\Surface::Access->assetPath(['current' => '/private-entry']) === '/login', 'An explicit access surface selects access assets on a nonstandard path.');
$assert(\TinyCat\Core\Ui\Surface::Administration->assetPath(['asset_path' => '/admin/bots']) === '/admin/bots', 'An owner can select a matching surface asset profile.');
try {
    \TinyCat\Core\Ui\Surface::Administration->assetPath(['asset_path' => '/social']);
    $assert(false, 'Cross-surface explicit asset profiles must be rejected.');
} catch (\InvalidArgumentException) {
    $assert(true, 'Cross-surface explicit asset profiles are rejected.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo "PASS monolith presentation templates ({$checks} checks)\n";
