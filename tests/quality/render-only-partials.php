<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$templateRoots = array_merge(glob($root . '/App/Modules/*/templates') ?: [], glob($root . '/App/Core/*/templates') ?: [], glob($root . '/App/Core/*/*/templates') ?: []) ?: [];
if ($templateRoots === []) { throw new RuntimeException('No module templates found.'); }
$forbiddenFunctions = array_fill_keys([
    'all',
    'auth',
    'config',
    'db',
    'db_select',
    'delete',
    'insert',
    'one',
    'run',
    'setting',
    'update',
    'val',
    'admin_list_url',
    'admin_per_page',
    'admin_per_page_options',
    'public_status_page_limit',
    'render_mentions',
    'render_status_body',
    'status_comment_can_delete',
    'status_comment_count',
    'status_comment_like_count',
    'status_comment_user_liked',
    'status_comments',
    'status_feed_cursor_params',
    'status_feed_next_url',
    'status_image_alt_text',
    'status_image_url',
    'status_images_enabled',
    'status_links_for_content',
    'status_permalink_label',
    'status_tag_suggestions',
    'status_user_liked',
    'status_video_embed_allowed',
    'status_video_embed_url',
    'embedAllowed',
    'embedUrl',
    'status_video_thumbnail_sources',
], true);
$forbiddenStaticOwners = array_fill_keys([
    'Cache',
    'Core',
    'LinkMetadata',
    'Notifications',
    'StatusLinks',
    'UserRoles',
], true);
$failures = [];
// Page handlers compose components; only technical renderers and templates emit markup.
$production = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/App', FilesystemIterator::SKIP_DOTS)) as $sourceFile) {
    if ($sourceFile->getExtension() !== 'php') { continue; }
    $source = (string) file_get_contents($sourceFile->getPathname());
    $production .= $source . "\n";
    $handler = str_replace('\\', '/', substr($sourceFile->getPathname(), strlen($root) + 1));
    if (preg_match('~(?:Pages|Dashboard)\.php$~', $handler) !== 1
        && preg_match('~^App/Modules/[^/]+/src/(?:Posts/)?(?:Exports|Syndication)\.php$~', $handler) !== 1) { continue; }
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_INLINE_HTML && trim($token[1]) !== '') {
            $failures[] = $handler . ':' . $token[2] . ' must not contain inline HTML.';
        }
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('~</?[a-z][a-z0-9]*(?:\s|>)~i', $token[1]) === 1) {
            $failures[] = $handler . ':' . $token[2] . ' must not construct HTML strings.';
        }
    }
}

$files = [];
foreach ($templateRoots as $templateRoot) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateRoot, FilesystemIterator::SKIP_DOTS)) as $file) { $files[] = $file; }
}

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());

    if (!is_string($source)) {
        $failures[] = 'Cannot read ' . $file->getPathname();
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('/\bget_defined_vars\s*\(/', $source) === 1) {
        $failures[] = $relative . ' must pass explicit presentation inputs instead of the entire PHP context.';
    }
    if (preg_match('~^App/(?:Modules|Core)/([^/]+)/templates/parts/(.+)\.php$~', $relative, $match)) {
        $name = $match[1] . '/' . $match[2];
        if (!str_contains($production, "'" . $name . "'") && !str_contains($production, '"' . $name . '"')) { $failures[] = 'Unreferenced module partial: ' . $relative; }
    }

    preg_match_all('/^use ([\\\\\w]+)(?: as (\w+))?;/m', $source, $importMatches, PREG_SET_ORDER);
    $imports = [];
    foreach ($importMatches as $import) {
        $alias = $import[2] ?? substr(strrchr($import[1], '\\'), 1);
        $imports[$alias] = $import[1];
    }
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
            continue;
        }

        $next = $index + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }

        $name = $token[1];
        $owner = substr(strrchr('\\' . $name, '\\'), 1);

        if (($tokens[$next] ?? null) === '(' && isset($forbiddenFunctions[$name])) {
            $failures[] = $relative . ':' . $token[2] . ' calls query-capable helper ' . $name . '().';
            continue;
        }

        $nextToken = $tokens[$next] ?? null;

        if (is_array($nextToken) && $nextToken[0] === T_DOUBLE_COLON) {
            $class = $imports[$name] ?? ltrim($name, '\\');
            if (preg_match('~^TinyCat\\\\(?:Support\\\\(?:Html|View|Localization|Dates|Text|Format|Components|Version)|Core\\\\Security\\\\Csrf|(?:Core\\\\(?:Identity|Notifications)|Social\\\\(?:Profiles|Automation|Presentation))\\\\Views)$~', $class) !== 1) {
                $failures[] = $relative . ':' . $token[2] . ' must compose presentation components instead of calling ' . $class . '::.';
            }
        }

        if (
            (isset($forbiddenStaticOwners[$owner])
                || preg_match('~^\\\\?TinyCat\\\\(?:Database\\\\(?:Connection|Query|Queries|Transactions)|Settings\\\\Store|Status\\\\(?:Feed|ReadCache))$~', $name) === 1)
            && is_array($nextToken)
            && $nextToken[0] === T_DOUBLE_COLON
        ) {
            $failures[] = $relative . ':' . $token[2] . ' calls service owner ' . $name . '::.';
        }
    }

}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL {$failure}\n";
    }

    exit(1);
}

echo "PASS render-only module template boundary (" . count($files) . " templates)\n";
