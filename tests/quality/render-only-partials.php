<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$partsRoot = $root . '/Public/parts';
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
    'site_footer_html',
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
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $partsRoot,
    FilesystemIterator::SKIP_DOTS
));

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());

    if (!is_string($source)) {
        $failures[] = 'Cannot read ' . $file->getPathname();
        continue;
    }

    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $next = $index + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }

        $name = $token[1];
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (($tokens[$next] ?? null) === '(' && isset($forbiddenFunctions[$name])) {
            $failures[] = $relative . ':' . $token[2] . ' calls query-capable helper ' . $name . '().';
            continue;
        }

        $nextToken = $tokens[$next] ?? null;

        if (
            isset($forbiddenStaticOwners[$name])
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

echo "PASS render-only partial boundary\n";
