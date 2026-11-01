"""Static UI inventory. Run from any directory; no third-party dependencies."""
import collections
import json
from pathlib import Path
import re
import posixpath

ROOT = Path(__file__).resolve().parents[1]


def rel(path):
    return path.relative_to(ROOT).as_posix()


def walk(value):
    if isinstance(value, dict):
        yield value
        for child in value.values():
            yield from walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk(child)


def generate():
    components = []
    structures = collections.defaultdict(list)
    for path in sorted((ROOT / 'assets').rglob('components.json')):
        # Preserve duplicate keys, including duplicates inside one catalog.
        pairs = json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=lambda p: p)
        definitions = json.loads(path.read_text(encoding='utf-8'))
        for name, _ in pairs:
            structures[json.dumps(definitions[name], sort_keys=True)].append(name)
            nodes = list(walk(definitions[name]))
            components.append(dict(name=name, source=rel(path),
                dependencies=sorted({n[k] for n in nodes for k in ('component', 'use') if isinstance(n.get(k), str)}),
                inputs=sorted({n['get'] for n in nodes if isinstance(n.get('get'), str)})))
    names = collections.Counter(c['name'] for c in components)
    classes = collections.defaultdict(list)
    rules = []
    imports = []
    index = ROOT / 'assets/styles/index.css'
    for match in re.finditer(r'@import\s+"([^"]+)"', index.read_text(encoding='utf-8')):
        imports.append((index.parent / match[1]).resolve())
    # Lexical block scanner for the current flat CSS + nested @media syntax.
    # This is not a browser cascade/specificity evaluator.
    for path in imports:
        original = path.read_text(encoding='utf-8')
        source = re.sub(r'/\*.*?\*/', lambda m: re.sub(r'[^\n]', ' ', m[0]), original, flags=re.S)
        def blocks(start, end, context):
            pos = start
            while pos < end:
                opening = source.find('{', pos, end)
                if opening < 0:
                    break
                depth, closing = 1, opening + 1
                while closing < end and depth:
                    depth += (source[closing] == '{') - (source[closing] == '}')
                    closing += 1
                header = source[pos:opening].strip()
                line = source.count('\n', 0, pos + len(source[pos:opening]) - len(source[pos:opening].lstrip())) + 1
                if header.startswith('@'):
                    blocks(opening + 1, closing - 1, context + [header])
                else:
                    declarations = re.findall(r'([\w-]+)\s*:\s*([^;{}]+)', source[opening + 1:closing - 1])
                    for selector in re.split(r',\s*(?![^()]*\))', header):
                        selector = re.sub(r'\s+', ' ', selector.strip())
                        row = dict(selector=selector, context=context, source=rel(path), line=line, declarations=declarations)
                        rules.append(row)
                        for raw_name in set(re.findall(r'\.((?:\\.|[a-zA-Z_])(?:\\.|[\w-])*)', selector)):
                            name = re.sub(r'\\(.)', r'\1', raw_name)
                            classes[name].append(dict(source=rel(path), line=line, context=context, selector=selector))
                pos = closing
        blocks(0, len(source), [])
    groups = collections.defaultdict(list)
    for rule in rules:
        groups[(tuple(rule['context']), rule['selector'])].append(rule)
    repeats = [dict(selector=s, context=list(c), rules=rows) for (c, s), rows in groups.items() if len(rows) > 1]
    helpers = []
    for path in sorted((ROOT / 'assets/ui').glob('*.js')):
        text = path.read_text(encoding='utf-8')
        helpers.append(dict(source=rel(path), methods=[dict(name=m[1], line=text.count('\n', 0, m.start()) + 1) for m in re.finditer(r'^\s*(\w+): function\s*\(', text, re.M)]))
    partials = [rel(p) for p in sorted((ROOT / 'App').rglob('templates/parts/**/*.php'))]
    templates = [rel(p) for p in sorted((ROOT / 'App').rglob('templates/**/*.php'))]
    renderers = []
    for path in sorted((ROOT / 'App').rglob('*Views.php')):
        source = path.read_text(encoding='utf-8')
        renderers.append(dict(source=rel(path), methods=re.findall(r'public static function\s+(\w+)', source)))
    standalone = [dict(source=rel(p), classes=sorted(set(re.findall(r'\.([a-zA-Z_][\w-]*)', p.read_text(encoding='utf-8'))))) for p in sorted((ROOT / 'assets').rglob('*.css')) if p.resolve() not in imports and p != index]
    ownership = collections.defaultdict(list)
    js_entries = collections.defaultdict(list)
    js_standalone = set()
    for path in sorted((ROOT / 'assets').rglob('module.json')):
        for feature in json.loads(path.read_text(encoding='utf-8')):
            for css in feature.get('styles', []):
                ownership[css].append(dict(manifest=rel(path), routes=feature['routes']))
            for entry in feature.get('entries', []):
                js_entries[entry].append(dict(manifest=rel(path), routes=feature['routes']))
            js_standalone.update(feature.get('standalone', []))
    javascript = []
    for path in sorted((ROOT / 'assets').rglob('*.js')):
        if 'build' in path.relative_to(ROOT / 'assets').parts:
            continue
        source = path.read_text(encoding='utf-8')
        dependencies = [posixpath.normpath(posixpath.join(posixpath.dirname(rel(path)), target)) for target in re.findall(r'^import\s+["\']([^"\']+)["\'];[ \t]*$', source, re.M)]
        javascript.append(dict(source=rel(path), imports=dependencies, entries=js_entries.get(rel(path), []), standalone=rel(path) in js_standalone))
    data = dict(scope='Current working tree; static source audit, not visual verification. CSS repeated selectors are candidates, not proven defects.',
        components=components, duplicate_component_names=sorted(n for n, count in names.items() if count > 1),
        identical_component_definitions=[v for v in structures.values() if len(v) > 1],
        unresolved_dependencies=sorted({d for c in components for d in c['dependencies'] if d not in names}),
        css_imports=[rel(p) for p in imports], css_classes=dict(sorted(classes.items())),
        repeated_css_selectors=repeats, ui_services=helpers, php_partials=partials,
        php_templates=templates, php_renderers=renderers, additional_css=standalone,
        stylesheet_ownership=dict(sorted(ownership.items())), javascript=javascript)
    (ROOT / 'docs/ui-inventory.json').write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    lines = ['# Rejstřík UI', '', 'Generováno příkazem `python tools/ui-inventory.py`. Rozsah a omezení viz [audit](ui-audit.md).', '',
        f"{len(components)} definic komponent, {len(classes)} CSS tříd, {len(imports)} CSS importů, {len(javascript)} JS souborů, {len(helpers)} UI služeb, {len(partials)} PHP partialů.", '',
        '## Deklarativní komponenty', '', '| Název | Zdroj | Závislosti |', '| --- | --- | --- |']
    for c in components:
        lines.append(f"| `{c['name']}` | [{c['source']}](../{c['source']}) | {', '.join(c['dependencies']) or '—'} |")
    lines += ['', '## UI služby a metody zapsané jako `name: function`', '', 'Jde o syntaktický seznam; nezahrnuje všechny exporty a nerozhoduje o veřejnosti metody.']
    for h in helpers:
        lines.append(f"- [{h['source']}](../{h['source']}): " + ', '.join('`' + m['name'] + '`' for m in h['methods']))
    lines += ['', '## JavaScript — soubory, importy a vstupní body', '', 'Trasy označují přímé vstupní body manifestů. Další soubory se načítají přes importní graf; `bootstrap.js` připojuje společný builder automaticky.', '', '| Soubor | Přímé importy | Vstupní trasy |', '| --- | --- | --- |']
    for script in javascript:
        routes = sorted({route for entry in script['entries'] for route in entry['routes']})
        usage = 'standalone' if script['standalone'] else ', '.join(routes) or 'přes importy / společný runtime'
        lines.append(f"| [{script['source']}](../{script['source']}) | " + ', '.join(f'[{p}](../{p})' for p in script['imports']) + f' | {usage} |')
    lines += ['', '## PHP partialy', ''] + [f'- [{p}](../{p})' for p in partials]
    lines += ['', '## PHP renderery', ''] + [f"- [{r['source']}](../{r['source']}): " + ', '.join('`' + m + '`' for m in r['methods']) for r in renderers]
    lines += ['', '## Všechny PHP šablony', ''] + [f'- [{p}](../{p})' for p in templates]
    lines += ['', '## CSS mimo hlavní importní graf', ''] + [f"- [{s['source']}](../{s['source']}): " + ', '.join('`' + c + '`' for c in s['classes']) for s in standalone]
    lines += ['', '## Vlastnictví a načítání CSS v pořadí kaskády', '', '| Soubor | Manifest | Trasy |', '| --- | --- | --- |']
    for path in imports:
        for owner in ownership.get(rel(path), []):
            lines.append(f"| [{rel(path)}](../{rel(path)}) | [{owner['manifest']}](../{owner['manifest']}) | {', '.join(owner['routes'])} |")
    lines += ['', '## CSS třídy', '', 'Všechny třídy z importního grafu `assets/styles/index.css`. Počet souborů není počet duplicit; zahrnuje stavy a breakpointy.', '', '| Třída | Soubory |', '| --- | --- |']
    for name, rows in sorted(classes.items()):
        files = sorted({r['source'] for r in rows})
        lines.append(f"| `{name}` | " + ', '.join(f'[{p}](../{p})' for p in files) + ' |')
    (ROOT / 'docs/ui-inventory.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
    print(json.dumps(dict(components=len(components), classes=len(classes), css_files=len(imports), js_files=len(javascript), services=len(helpers), partials=len(partials), duplicate_names=data['duplicate_component_names'], unresolved=data['unresolved_dependencies'], repeated_selectors=len(repeats))))


if __name__ == '__main__':
    generate()
