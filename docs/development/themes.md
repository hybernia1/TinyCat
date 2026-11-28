# Minimal public themes

A theme changes the public shell around module content. It is optional and has
no module activation, services, routes, SQL or installation hooks. Core continues
to own the HTML document, metadata, security context and asset delivery.
Administration and authentication use core presentation independently of the
selected public theme.

## Structure

```text
App/Themes/company/
├── theme.json
├── layouts/
│   └── public.php
├── parts/                 # optional render-only PHP fragments
│   └── footer.php
└── assets/
    ├── theme.css          # optional
    ├── theme.js           # optional
    └── logo.svg           # optional
```

The complete theme stays under the HTTP-protected `App` directory. Its assets
use the shared compiler and are published in the installed `/assets/build/` output.
All available theme assets are built during deployment. After manually adding or
editing a theme, run `php App/Console.php assets` in the target installation;
HTTP never compiles a missing theme asset. Theme files are
trusted deployment code; there is no theme upload or package manager.

```json
{
  "id": "company",
  "label": "Company",
  "layout": "layouts/public.php",
  "styles": ["assets/theme.css"]
}
```

The folder and manifest ID must match, using lowercase ASCII letters, numbers and
hyphens, starting with a letter. `styles` and `scripts` are optional lists of
existing CSS and JavaScript files below the theme's own `assets/` directory. Unknown manifest
fields, missing layouts and escaping paths are rejected. Metadata discovery
does not execute PHP. Only the explicitly selected local theme is rendered;
placing a theme on disk never selects it automatically.

Choose its ID through the existing site theme setting (`site.theme`). Local themes appear in this selection; an unavailable saved ID is retained
and marked as unavailable. Functional modules do not register themes.
Automatic selection uses the core layout; a removed theme or an old saved module
theme ID also falls back to core without rewriting installation settings.

## Layout

```php
<?php
declare(strict_types=1);
/** @var string $publicHeader */
/** @var string $content */
/** @var string $consent */
/** @var string $publicFooter */
?>
<?= $publicHeader ?>
<main id="main-content" class="section" tabindex="-1">
    <div class="container"><?= $content ?></div>
</main>
<?= $consent ?>
<?= $publicFooter ?>
```

The renderer supplies prepared document data, including `title`, `appName`, `nav`,
`authUser`, and the four trusted HTML fragments above. Escape text with the
shared HTML helper. Render exactly one `main-content` target and preserve consent
UI. Layouts must not create a second document or perform data queries. Fragments
may use the existing PHP view renderer with explicitly supplied data.

Use existing UI components, tokens and the mobile/desktop breakpoint. Theme
scripts are optional standard public assets; they must not recreate framework
dialogs, forms or lifecycle behavior. Local theme labels are deployment metadata;
use existing localization for rendered UI.

There is deliberately no arbitrary PHP override of module components. Initial
HTML and AJAX continue using the same owner component definitions. The theme
controls the surrounding layout; modules retain data, permissions and content.
Projects needing only administration disable the public website in settings.
Login, recovery and account pages use the minimal core access shell and do not
load the public theme. Installing a module never enables the public website.
See the [public surface contract](../contracts/public-surface.md).

The executable example is in [theme fixtures](../../tests/fixtures/themes/).
The [shared presentation guide](partials.md) describes component ownership.
