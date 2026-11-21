<?php
declare(strict_types=1);
namespace TinyCat\Support;

/** Owned source paths are published only as content-addressed public outputs. */
final class Assets
{
    public static function asset(string $path): string
    {
        $source = AssetGraph::read($path);
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $minify = match ($type) {
            'css' => ClientAssets::option('performance.minify_css'),
            'js' => ClientAssets::option('performance.minify_js'),
            default => false,
        };
        $compiled = CompiledAssets::asset($path, $minify);
        if ($compiled !== null) { return $compiled; }
        if ($type === 'css') { $source = AssetGraph::stylesheet([$path]); }
        if ($type === 'js') { $source = AssetGraph::javascript(AssetGraph::order([$path])); }
        $name = substr((string) preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($path, PATHINFO_FILENAME)), 0, 100);
        $url = Minifier::cachedAssetUrl($path, 'asset-' . strtolower($name), $source, $type, $minify);
        return $url ?? throw new \RuntimeException('Could not publish owned asset: ' . $path);
    }
}
