<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$demoPagination = [
    'total' => 42,
    'page' => 2,
    'per_page' => 10,
    'last_page' => 5,
    'from' => 11,
    'to' => 20,
    'has_prev' => true,
    'has_next' => true,
    'prev_page' => 1,
    'next_page' => 3,
];
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(config('app.name', 'TinyCat')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/tinycat.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('editor/editor.css')) ?>">
    <script src="<?= e(asset('js/tinycat.js')) ?>" defer></script>
    <script src="<?= e(asset('editor/modal.js')) ?>" defer></script>
    <script src="<?= e(asset('editor/editor.js')) ?>" defer></script>
</head>
<body>
    <header class="navbar">
        <div class="container navbar-inner">
            <strong><?= e(config('app.name', 'TinyCat')) ?></strong>
            <nav class="nav-links" aria-label="Main">
                <a class="nav-link inline-flex items-center gap-2" href="/" aria-current="page"><?= icon('home') ?> <span>Home</span></a>
                <button class="btn btn-sm btn-primary" type="button" data-modal-open="welcome-modal"><?= icon('info') ?> <span>Modal</span></button>
            </nav>
        </div>
    </header>

    <main class="section">
        <div class="container stack" style="--stack-gap: 24px;">
            <div class="split">
                <div class="stack" style="--stack-gap: 8px;">
                    <span class="badge badge-primary"><?= icon('shield') ?> TinyCat Core</span>
                    <h1 class="text-2xl m-0"><?= e(config('app.name', 'TinyCat')) ?></h1>
                    <p class="text-muted mb-0"><?= et('message.welcome', ['app' => config('app.name', 'TinyCat')]) ?></p>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" type="button" data-modal-open="welcome-modal"><?= icon('external-link') ?> <span>Otevřít modal</span></button>
                    <button class="btn btn-secondary" type="button" data-toast="TinyCat UI je připravené." data-toast-type="success"><?= icon('bell') ?> <span>Toast</span></button>
                </div>
            </div>

            <div class="grid md:grid-3">
                <article class="card">
                    <div class="card-body stack">
                        <h2 class="text-lg m-0 cluster gap-2"><?= icon('check-circle', 'icon text-success') ?> Stav</h2>
                        <p class="text-muted mb-0">Aplikace běží přes Laragon na lokální doméně.</p>
                    </div>
                </article>
                <article class="card">
                    <div class="card-body stack">
                        <h2 class="text-lg m-0 cluster gap-2"><?= icon('database', 'icon text-primary') ?> Databáze</h2>
                        <p class="text-muted mb-0">Konfigurace míří na MySQL databázi <strong><?= e(config('database.name')) ?></strong>.</p>
                    </div>
                </article>
                <article class="card">
                    <div class="card-body stack">
                        <h2 class="text-lg m-0 cluster gap-2"><?= icon('settings', 'icon text-primary') ?> Rozhraní</h2>
                        <p class="text-muted mb-0">Základní komponenty jsou připravené pro malé projekty.</p>
                    </div>
                </article>
            </div>

            <section class="card" data-tabs>
                <div class="card-header split">
                    <div class="stack" style="--stack-gap: 4px;">
                        <h2 class="text-lg m-0">Administrace</h2>
                        <p class="text-muted mb-0">Kompaktní pracovní plocha pro malé projekty.</p>
                    </div>
                    <button class="btn btn-sm btn-primary" type="button"><?= icon('plus') ?> <span>Nový záznam</span></button>
                </div>

                <div class="tabs px-4" role="tablist" aria-label="Administrace">
                    <button class="tab" type="button" id="tab-records" role="tab" aria-controls="panel-records" aria-selected="true" data-tab="records"><?= icon('database') ?> Záznamy</button>
                    <button class="tab" type="button" id="tab-users" role="tab" aria-controls="panel-users" aria-selected="false" data-tab="users"><?= icon('users') ?> Uživatelé</button>
                    <button class="tab" type="button" id="tab-activity" role="tab" aria-controls="panel-activity" aria-selected="false" data-tab="activity"><?= icon('clock') ?> Aktivita</button>
                </div>

                <div class="card-body">
                    <div class="tab-panel stack" id="panel-records" role="tabpanel" aria-labelledby="tab-records" data-tab-panel="records">
                        <div class="split">
                            <div class="input-group max-w-sm">
                                <input class="input" type="search" placeholder="Hledat záznam">
                                <button class="btn btn-secondary" type="button" aria-label="Hledat"><?= icon('search') ?></button>
                            </div>
                            <button class="btn btn-secondary" type="button"><?= icon('filter') ?> <span>Filtr</span></button>
                        </div>

                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Název</th>
                                        <th>Typ</th>
                                        <th>Stav</th>
                                        <th>Upraveno</th>
                                        <th>Akce</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <strong>Úvodní stránka</strong>
                                            <div class="table-meta">/home</div>
                                        </td>
                                        <td>Stránka</td>
                                        <td><span class="badge badge-primary"><?= icon('check') ?> Publikováno</span></td>
                                        <td>Dnes</td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Upravit"><?= icon('edit') ?></button>
                                                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Zobrazit"><?= icon('eye') ?></button>
                                                <button class="btn btn-sm btn-ghost btn-icon text-danger" type="button" aria-label="Smazat"><?= icon('delete') ?></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>Galerie</strong>
                                            <div class="table-meta">/galerie</div>
                                        </td>
                                        <td>Média</td>
                                        <td><span class="badge">Koncept</span></td>
                                        <td>Včera</td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Upravit"><?= icon('edit') ?></button>
                                                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Nahrát"><?= icon('upload') ?></button>
                                                <button class="btn btn-sm btn-ghost btn-icon text-danger" type="button" aria-label="Smazat"><?= icon('delete') ?></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>Kontakt</strong>
                                            <div class="table-meta">/kontakt</div>
                                        </td>
                                        <td>Formulář</td>
                                        <td><span class="badge badge-primary"><?= icon('check') ?> Aktivní</span></td>
                                        <td>12. 6.</td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Upravit"><?= icon('edit') ?></button>
                                                <button class="btn btn-sm btn-ghost btn-icon" type="button" aria-label="Kopírovat"><?= icon('copy') ?></button>
                                                <button class="btn btn-sm btn-ghost btn-icon text-danger" type="button" aria-label="Smazat"><?= icon('delete') ?></button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <?= pagination($demoPagination, '#panel-records') ?>
                    </div>

                    <div class="tab-panel stack" id="panel-users" role="tabpanel" aria-labelledby="tab-users" data-tab-panel="users" hidden>
                        <details class="expand" open>
                            <summary><?= icon('user') ?> Nikola</summary>
                            <div class="expand-body grid sm:grid-3">
                                <div>
                                    <div class="table-meta">Role</div>
                                    <strong>Admin</strong>
                                </div>
                                <div>
                                    <div class="table-meta">E-mail</div>
                                    <strong>nikola@example.test</strong>
                                </div>
                                <div>
                                    <div class="table-meta">Stav</div>
                                    <span class="badge badge-primary"><?= icon('check') ?> Aktivní</span>
                                </div>
                            </div>
                        </details>
                        <details class="expand">
                            <summary><?= icon('user') ?> Editor</summary>
                            <div class="expand-body grid sm:grid-3">
                                <div>
                                    <div class="table-meta">Role</div>
                                    <strong>Editor</strong>
                                </div>
                                <div>
                                    <div class="table-meta">E-mail</div>
                                    <strong>editor@example.test</strong>
                                </div>
                                <div>
                                    <div class="table-meta">Stav</div>
                                    <span class="badge">Pozvánka</span>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div class="tab-panel stack" id="panel-activity" role="tabpanel" aria-labelledby="tab-activity" data-tab-panel="activity" hidden>
                        <div class="alert alert-success"><?= icon('check-circle') ?> Stránka byla publikovaná.</div>
                        <div class="alert alert-info"><?= icon('upload') ?> Do galerie byly nahrané nové soubory.</div>
                        <div class="alert alert-warning"><?= icon('alert') ?> Formulář čeká na kontrolu polí.</div>
                    </div>
                </div>
            </section>

            <section class="grid md:grid-2">
                <article class="card">
                    <div class="card-header">
                        <h2 class="text-lg m-0 cluster gap-2"><?= icon('filter') ?> Štítky</h2>
                    </div>
                    <div class="card-body stack">
                        <div class="field">
                            <label class="label" for="demo-tags">Tagifier</label>
                            <div class="tagifier" data-tagifier data-value="tinycat,ui" data-suggestions="php,mysql,admin,upload,crud,ajax,seo,media,laragon,tinycat,ui">
                                <div class="tag-box">
                                    <div class="tag-list" data-tag-list></div>
                                    <input class="tag-input" id="demo-tags" type="text" data-tag-input placeholder="Přidat štítek">
                                </div>
                                <input type="hidden" name="tags" data-tag-value>
                                <div class="tag-suggestions" data-tag-suggestions hidden></div>
                            </div>
                            <div class="help">Vybrané štítky projektu.</div>
                        </div>
                    </div>
                </article>

                <article class="card">
                    <div class="card-header">
                        <h2 class="text-lg m-0 cluster gap-2"><?= icon('upload') ?> Drag & drop</h2>
                    </div>
                    <div class="card-body stack">
                        <div class="sortable-list" data-sortable data-sortable-input="#layout-order">
                            <div class="sortable-item" data-sortable-item data-id="hero">
                                <span class="drag-handle"><?= icon('sort') ?></span>
                                <div class="w-full">
                                    <strong>Hero blok</strong>
                                    <div class="table-meta">Pořadí <span data-sortable-index>1</span></div>
                                </div>
                                <span class="badge">Hlavní</span>
                            </div>
                            <div class="sortable-item" data-sortable-item data-id="gallery">
                                <span class="drag-handle"><?= icon('sort') ?></span>
                                <div class="w-full">
                                    <strong>Galerie</strong>
                                    <div class="table-meta">Pořadí <span data-sortable-index>2</span></div>
                                </div>
                                <span class="badge">Média</span>
                            </div>
                            <div class="sortable-item" data-sortable-item data-id="contact">
                                <span class="drag-handle"><?= icon('sort') ?></span>
                                <div class="w-full">
                                    <strong>Kontakt</strong>
                                    <div class="table-meta">Pořadí <span data-sortable-index>3</span></div>
                                </div>
                                <span class="badge">Formulář</span>
                            </div>
                        </div>
                        <input type="hidden" id="layout-order" name="layout_order">

                        <label class="dropzone" data-dropzone>
                            <input class="sr-only" type="file" name="files[]" multiple>
                            <span class="dropzone-content">
                                <span class="dropzone-icon"><?= icon('upload') ?></span>
                                <strong>Přetáhnout soubory sem</strong>
                                <span class="text-muted text-sm">nebo kliknout pro výběr</span>
                            </span>
                            <span class="dropzone-files w-full" data-dropzone-files></span>
                        </label>
                    </div>
                </article>
            </section>

            <section class="card">
                <div class="card-header split">
                    <div class="stack" style="--stack-gap: 4px;">
                        <h2 class="text-lg m-0 cluster gap-2"><?= icon('edit') ?> Editor obsahu</h2>
                        <p class="text-muted mb-0">Čisté psaní pro články, stránky a poznámky.</p>
                    </div>
                    <button class="btn btn-sm btn-primary" type="button"><?= icon('save') ?> <span>Uložit</span></button>
                </div>
                <div class="card-body">
                    <textarea class="textarea" name="content" data-editor data-editor-min-height="260px"><h2>TinyCat editor</h2>
<p>Jednoduchý obsahový blok připravený pro malou administraci.</p>
<ul>
  <li>Krátké články a stránky</li>
  <li>Formátování textu</li>
  <li>Odkazy a seznamy</li>
</ul></textarea>
                </div>
            </section>
        </div>
    </main>

    <div class="modal" id="welcome-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="welcome-title" data-open="false">
        <div class="modal-backdrop"></div>
        <div class="modal-panel">
            <div class="modal-header">
                <h2 class="text-lg m-0" id="welcome-title">TinyCat modal</h2>
                <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-close aria-label="Zavřít"><?= icon('close') ?></button>
            </div>
            <div class="modal-body stack">
                <p>Všechno je připravené pro rychlý start.</p>
                <div class="alert alert-info">TinyCat má základní UI vrstvu, překlady i databázové helpery.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-modal-close><?= icon('close') ?> <span>Zavřít</span></button>
                <button class="btn btn-primary" type="button" data-toast="Akce proběhla." data-toast-type="success" data-modal-close><?= icon('confirm') ?> <span>Hotovo</span></button>
            </div>
        </div>
    </div>
</body>
</html>
