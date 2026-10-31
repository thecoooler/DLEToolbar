<?php

/*
 * Run from the plugin root:
 * php -S 127.0.0.1:8000 tests/preview.php
 * Then open http://127.0.0.1:8000/?n=8
 */

define('ENGINE_DIR', sys_get_temp_dir() . '/dletoolbar-preview-' . getmypid());

spl_autoload_register(static function (string $class): void {
    $prefix = 'iCooLER\\DLEToolbar\\';
    if (strpos($class, $prefix) === 0) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function get_folder_list(string $path): array
{
    return [
        'Default' => ['name' => 'Стандартный'],
        'Studio' => ['name' => 'Studio'],
        'Minimal' => ['name' => 'Minimal'],
    ];
}

$config = [
    'http_home_url' => 'http://localhost/',
    'admin_path' => 'admin.php',
    'skin' => 'Default',
    'version_id' => '18.0',
];
$member_id = ['user_group' => 1];
$user_group = [
    1 => ['group_name' => 'Администраторы', 'allow_admin' => 1],
    2 => ['group_name' => 'Главные редакторы', 'allow_admin' => 1],
    3 => ['group_name' => 'Журналисты', 'allow_admin' => 1],
    4 => ['group_name' => 'Посетители', 'allow_admin' => 0],
    5 => ['group_name' => 'Гости', 'allow_admin' => 0],
];
$dle_module = 'main';
$dle_login_hash = 'preview';
$news_found = false;
$Timer = new class {
    public float $elapsed = 0;

    public function get(): float
    {
        return $this->elapsed;
    }
};
define('PREFIX', 'dle');
$db = new class {
    public int $query_num = 0;
    public array $query_list = [];
    public float $MySQL_time_taken = 0;
    public function query($sql, $showError = true)
    {
        return true;
    }
    public function get_row($result = null)
    {
        return null;
    }
    public function free($result = null) {}
};

$scenarios = [
    ['response' => 200, 'time' => 0.008, 'queries' => 0],
    ['response' => 201, 'time' => 0.024, 'queries' => 3],
    ['response' => 302, 'time' => 0.087, 'queries' => 7],
    ['response' => 304, 'time' => 0.163, 'queries' => 14],
    ['response' => 400, 'time' => 0.420, 'queries' => 23],
    ['response' => 401, 'time' => 0.972, 'queries' => 36],
    ['response' => 404, 'time' => 1.840, 'queries' => 52],
    ['response' => 422, 'time' => 3.270, 'queries' => 73],
    ['response' => 429, 'time' => 5.600, 'queries' => 95],
    ['response' => 500, 'time' => 8.250, 'queries' => 120],
    ['response' => 503, 'time' => 12.400, 'queries' => 145],
    ['response' => 418, 'time' => 0.001, 'queries' => 184],
];

$compare = ($_GET['compare'] ?? '') === 'metrics';
$designs = require __DIR__ . '/previews/metric-designs.php';
$count = max(1, min(count($scenarios), (int) ($_GET['n'] ?? 8)));
$items = [];
if ($compare) {
    // Every shape the formatted time can take from 0 ms to 100 s, plus the rounding edges between units.
    $design = reset($designs);
    $times = [0, 0.4, 42, 199.2, 199.6, 420, 999.2, 999.6, 1001, 1240, 9940, 9960, 10001, 42000, 99600, 120000];
    foreach ($times as $index => $ms) {
        $items[] = [
            'scenario' => ['response' => 200, 'time' => $ms / 1000, 'queries' => 7],
            'skin' => 0,
            'design' => $design,
            'badge' => rtrim(rtrim(number_format($ms, 1, '.', ' '), '0'), '.') . ' мс',
            'column' => intdiv($index, 4),
            'group' => $index,
        ];
    }
} else {
    for ($index = 0; $index < $count; $index++) {
        $items[] = ['scenario' => $scenarios[$index], 'skin' => $index, 'design' => null, 'badge' => 'Вариант ' . ($index + 1), 'column' => 0, 'group' => 0];
    }
}

$toolbar = new \iCooLER\DLEToolbar\Toolbar\DLEToolbar();
$toolbarsHtml = '';
$cardsHtml = '';
$positionToolbar = static function (string $html, int $index, int $column, int $group, string $badge): string {
    $bottom = 20 + $index * 88;
    $positionStyles = sprintf(
        "\n            .dock { position: relative; }\n"
        . "            .preview-badge { position: absolute; bottom: calc(100%% + 6px); left: 4px; padding: 3px 7px; border: 1px solid var(--border); border-radius: 5px; background: var(--surface); color: var(--muted); font-size: 10px; font-variant-numeric: tabular-nums; pointer-events: none; }\n"
        . "            :host { inset: auto !important; left: var(--preview-left, 12px) !important; right: auto !important; bottom: var(--preview-bottom, %dpx) !important; width: max-content !important; max-width: calc(100vw - 24px) !important; }\n",
        $bottom,
    );

    $html = str_replace('</style>', $positionStyles . '        </style>', $html);
    $html = str_replace(
        '<div data-dle-toolbar lang="ru" hidden>',
        '<div data-dle-toolbar data-preview-index="' . $index . '" data-preview-column="' . $column . '" data-preview-group="' . $group . '" lang="ru" hidden>',
        $html,
    );
    $dock = '<div class="dock" role="toolbar" aria-label="Управление сайтом" aria-orientation="horizontal">';

    return str_replace(
        $dock,
        $dock . '<span class="preview-badge" aria-hidden="true">' . htmlspecialchars($badge) . '</span>',
        $html,
    );
};
foreach ($items as $index => $item) {
    $scenario = $item['scenario'];
    $Timer->elapsed = $scenario['time'];
    $db->query_num = $scenario['queries'];
    $db->query_list = [];
    $db->MySQL_time_taken = 0.0;

    for ($queryNumber = 1; $queryNumber <= $scenario['queries']; $queryNumber++) {
        $queryTime = 0.0002 + ($queryNumber % 7) * 0.00013;
        $db->query_list[] = [
            'query' => 'SELECT id, title FROM dle_post WHERE id = ' . $queryNumber,
            'time' => $queryTime,
            'num' => $queryNumber,
        ];
        $db->MySQL_time_taken += $queryTime;
    }

    $config['skin'] = $item['skin'] % 2 === 0 ? 'Default' : 'Studio';
    if ($item['skin'] % 3 === 1) {
        $_COOKIE['dle_skin'] = 'Studio';
    } else {
        unset($_COOKIE['dle_skin']);
    }

    http_response_code($scenario['response']);
    $html = $toolbar->render();
    $toolbarsHtml .= $positionToolbar($html, $index, $item['column'], $item['group'], $item['badge']);
    http_response_code(200);

    if ($compare) {
        continue;
    }
    $cardsHtml .= '<article class="scenario"><span class="scenario-number">'
        . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '</span><div><h2>'
        . htmlspecialchars($item['badge']) . '</h2><p>HTTP ' . $scenario['response'] . ' · '
        . number_format($scenario['time'] * 1000, 1, '.', '') . ' мс · ' . $scenario['queries'] . ' SQL-запросов</p></div></article>';
}

if ($compare) {
    foreach ($designs as $design) {
        $cardsHtml .= '<article class="scenario"><div><h2>' . htmlspecialchars($design['title']) . '</h2><p>'
            . htmlspecialchars($design['description']) . '</p></div></article>';
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DLEToolbar · визуальная проверка</title>
    <style>
        :root { color-scheme: light; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #27272a; background: #f4f4f5; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; }
        main { position: fixed; top: 0; right: 0; width: 40vw; height: 100vh; height: 100dvh; overflow-y: auto; margin: 0; padding: 36px 24px 40px; border-left: 1px solid #e4e4e7; background: #f4f4f5; }
        .eyebrow { margin: 0 0 10px; color: #71717a; font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(30px, 6vw, 48px); font-weight: 650; letter-spacing: -.045em; }
        .intro { max-width: 660px; margin: 12px 0 28px; color: #52525b; font-size: 15px; line-height: 1.65; }
        .toolbar { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; margin-bottom: 34px; padding: 16px; border: 1px solid #e4e4e7; border-radius: 12px; background: #fff; }
        label { display: grid; gap: 6px; color: #52525b; font-size: 12px; }
        input, button { min-height: 38px; border: 1px solid #d4d4d8; border-radius: 7px; font: inherit; }
        select { min-height: 38px; padding: 0 10px; border: 1px solid #d4d4d8; border-radius: 7px; background: #fff; color: #27272a; font: inherit; font-size: 13px; }
        .mode-link { align-self: center; margin-left: auto; color: #18181b; font-size: 13px; }
        input { width: 100px; padding: 0 10px; color: #27272a; background: #fff; }
        button { padding: 0 14px; border-color: #18181b; background: #18181b; color: #fafafa; font-size: 13px; cursor: pointer; }
        .hint { margin: 0 0 22px; color: #71717a; font-size: 12px; }
        .scenarios { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 10px; }
        .scenario { display: flex; align-items: center; gap: 14px; min-height: 72px; padding: 14px; border: 1px solid #e4e4e7; border-radius: 10px; background: #fff; }
        .scenario-number { display: grid; width: 36px; height: 36px; flex: 0 0 auto; place-items: center; border-radius: 9px; background: #f4f4f5; color: #52525b; font-size: 12px; font-variant-numeric: tabular-nums; }
        .scenario h2 { margin: 0 0 4px; font-size: 13px; font-weight: 600; }
        .scenario p { margin: 0; color: #71717a; font-size: 12px; font-variant-numeric: tabular-nums; }
        .compare main { width: 210px; padding: 24px 12px; }
        .compare h1 { font-size: 26px; }
        .intro-compare { margin: 12px 0 16px; color: #52525b; font-size: 12px; line-height: 1.5; }
        .compare .preview-column-title { position: fixed; margin: 0; color: #52525b; font-size: 12px; font-weight: 650; }
        .compare .intro { display: none; }
        .compare .scenarios { grid-template-columns: 1fr; }
        .compare .scenario { min-height: 0; padding: 10px; }
        .compare .scenario-number { display: none; }
        @media (max-width: 600px) {
            main { padding: 20px 12px 28px; }
            h1 { font-size: clamp(18px, 5vw, 28px); }
            .intro { font-size: 12px; line-height: 1.5; }
            .toolbar { padding: 10px; }
            .toolbar label { width: 100%; }
            input { width: 100%; max-width: 110px; }
            .scenario { gap: 8px; padding: 9px; }
            .scenario-number { display: none; }
            .scenario p { font-size: 10px; }
        }
    </style>
</head>
<body<?= $compare ? ' class="compare"' : '' ?>>
<main>
    <p class="eyebrow">DLEToolbar · preview</p>
    <h1><?= $compare ? 'Варианты метрик' : 'Визуальная проверка панели' ?></h1>
    <p class="intro">Каждый плавающий toolbar показывает отдельный набор метрик. Попробуйте светлую и тёмную тему системы, разные размеры окна, переключатель шаблона, сброс кэша и панель SQL-запросов над тулбаром.</p>
    <?php if ($compare): ?>
    <p class="intro-compare">Время округляется до ближайшего значения: до секунды целые миллисекунды, до 10 сек один знак после точки, дальше целые секунды, не больше 100 сек. Цвет метки считается по показанному значению. Над тулбаром исходное время, результат и ширина текста.</p>
    <p><a class="mode-link" href="?n=8">← Наборы значений</a></p>
    <?php else: ?>
    <form class="toolbar" method="get">
        <label>Количество панелей (1–12)<input name="n" type="number" min="1" max="12" value="<?= $count ?>"></label>
        <button type="submit">Обновить предпросмотр</button>
        <a class="mode-link" href="?compare=metrics">Варианты метрик →</a>
    </form>
    <?php endif ?>
    <p class="hint">Запуск из корня проекта: <code>php -S 127.0.0.1:8000 tests/preview.php</code></p>
    <section class="scenarios" aria-label="Наборы значений">
        <?= $cardsHtml ?>
    </section>
</main>
<?php if ($compare): foreach ($items as $item): ?>
<p class="preview-column-title"><?= htmlspecialchars($item['badge']) ?></p>
<?php endforeach; endif ?>
<?= $toolbarsHtml ?>
<script>
(() => {
    const hosts = [...document.querySelectorAll('[data-dle-toolbar]')]
        .sort((a, b) => Number(a.dataset.previewIndex) - Number(b.dataset.previewIndex));

    const layoutToolbars = () => {
        const dock = (host) => host.shadowRoot.querySelector('.dock').getBoundingClientRect();
        const badge = (host) => host.shadowRoot.querySelector('.preview-badge').getBoundingClientRect();
        const compare = <?= $compare ? 'true' : 'false' ?>;
        const titles = [...document.querySelectorAll('.preview-column-title')];
        if (compare) hosts.forEach((host) => { host.shadowRoot.querySelector('.preview-badge').style.display = 'none'; });
        const columns = [];
        hosts.forEach((host) => (columns[Number(host.dataset.previewColumn)] ??= []).push(host));

        let left = 12;
        columns.forEach((column) => {
            // The first variant of each column sits at the top, so stack from the last one upwards.
            let bottom = 20;
            [...column].reverse().forEach((host, index, reversed) => {
                host.style.setProperty('--preview-bottom', `${bottom}px`);
                host.style.setProperty('--preview-left', `${left}px`);
                bottom += host.getBoundingClientRect().height + (compare ? 12 : badge(host).height + 14);

                const next = reversed[index + 1];
                if (compare && next?.dataset.previewGroup !== host.dataset.previewGroup) {
                    const title = titles[Number(host.dataset.previewGroup)];
                    const value = host.shadowRoot.querySelector('.time .metric-value');
                    title.dataset.width ??= `${value.firstChild.textContent} ${value.querySelector('.metric-unit').textContent} · текст ${value.getBoundingClientRect().width.toFixed(1)} px`;
                    title.textContent = `${title.textContent.split(' →')[0]} → ${title.dataset.width}`;
                    title.style.left = `${left + 4}px`;
                    title.style.bottom = `${bottom + 4}px`;
                    bottom += 30;
                }
            });
            left += Math.max(...column.map((host) => host.getBoundingClientRect().width)) + 16;
        });
    };

    let resizeFrame;
    window.addEventListener('resize', () => {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(layoutToolbars);
    });
    layoutToolbars();
})();
</script>
</body>
</html>
