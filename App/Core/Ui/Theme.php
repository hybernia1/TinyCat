<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Support\{Paths, View};

/** Local public layouts. Reading a manifest never executes theme PHP. */
final readonly class Theme
{
    /** @param list<string> $styles
     * @param list<string> $scripts */
    private function __construct(public string $id, public string $label, private string $directory, private string $layout, private array $styles, private array $scripts) {}

    /** @return array<string, string> */
    public static function choices(?string $root = null): array
    {
        $choices = [];
        $root ??= Paths::basePath();
        foreach (glob($root . '/App/Themes/*/theme.json') ?: [] as $file) {
            $theme = self::load(basename(dirname($file)), $root);
            if ($theme !== null) { $choices[$theme->id] = $theme->label; }
        }
        return $choices;
    }

    public static function load(string $id, ?string $root = null): ?self
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $id) !== 1) { return null; }
        $root ??= Paths::basePath();
        $base = realpath($root . '/App/Themes');
        $directory = realpath($root . '/App/Themes/' . $id);
        if ($base === false || $directory === false || dirname($directory) !== $base || !is_file($directory . '/theme.json')) { return null; }
        $manifest = json_decode((string) file_get_contents($directory . '/theme.json'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_diff(array_keys($manifest), ['id', 'label', 'layout', 'styles', 'scripts']) !== []
            || ($manifest['id'] ?? null) !== $id || !is_string($manifest['label'] ?? null) || trim($manifest['label']) === ''
            || !is_string($manifest['layout'] ?? null) || !self::template($directory, $manifest['layout'])) {
            throw new \InvalidArgumentException('Invalid theme manifest: ' . $id);
        }
        $assets = [];
        foreach (['styles' => 'css', 'scripts' => 'js'] as $kind => $extension) {
            $items = $manifest[$kind] ?? [];
            if (!is_array($items) || !array_is_list($items)) { throw new \InvalidArgumentException('Theme assets must be lists.'); }
            foreach ($items as $path) {
                $file = is_string($path) ? realpath($directory . '/' . $path) : false;
                $assetRoot = realpath($directory . '/assets');
                if (!is_string($path) || preg_match('~^assets/[A-Za-z0-9_/-]+\.' . $extension . '$~D', $path) !== 1
                    || $file === false || $assetRoot === false || !is_file($file)
                    || !str_starts_with(str_replace('\\', '/', $assetRoot), str_replace('\\', '/', $directory) . '/')
                    || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $assetRoot) . '/')) { throw new \InvalidArgumentException('Invalid theme asset.'); }
            }
            $assets[$kind] = array_map(static fn (string $path): string => 'App/Themes/' . $id . '/' . $path, $items);
        }
        return new self($id, $manifest['label'], $directory, $manifest['layout'], $assets['styles'], $assets['scripts']);
    }

    /** @param array<string, mixed> $page
     * @param array<string, mixed>|null $document */
    public function render(array $page, string $content, ?array $document = null): void
    {
        $page['styles'] = array_values(array_unique([...(array) ($page['styles'] ?? []), ...$this->styles]));
        $page['scripts'] = array_values(array_unique([...(array) ($page['scripts'] ?? []), ...$this->scripts]));
        $prepared = DocumentData::prepare($page, $document);
        $prepared['content'] = $content;
        Document::renderPrepared($prepared, View::render($this->layout, $prepared, $this->directory));
    }

    private static function template(string $directory, string $path): bool
    {
        if (preg_match('~^[A-Za-z0-9_/-]+\.php$~D', $path) !== 1) { return false; }
        $file = realpath($directory . '/' . $path);
        return $file !== false && is_file($file) && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $directory) . '/');
    }
}
