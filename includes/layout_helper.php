<?php
/**
 * Хелпер для рендеринга общих элементов layout (sidebar, header)
 */

/**
 * Вставляет SVG иконку из файла, сохраняя возможность использовать currentColor
 * @param string $iconName Имя файла иконки (без расширения .svg)
 * @param int $width Ширина иконки (по умолчанию 16)
 * @param int $height Высота иконки (по умолчанию 16)
 * @return string HTML с inline SVG
 */
function renderIcon(string $iconName, int $width = 16, int $height = 16): string {
    static $rawCache = [];
    $iconPath = __DIR__ . '/../icons/' . $iconName . '.svg';
    if (!file_exists($iconPath)) {
        return '';
    }
    if (!isset($rawCache[$iconName])) {
        $rawCache[$iconName] = file_get_contents($iconPath);
    }
    $svgContent = $rawCache[$iconName];
    // Удаляем XML декларацию и комментарии
    $svgContent = preg_replace('/<\?xml[^>]*\?>/', '', $svgContent);
    $svgContent = preg_replace('/<!--.*?-->/s', '', $svgContent);
    $svgContent = trim($svgContent);
    
    // Извлекаем viewBox если есть
    preg_match('/viewBox=["\']([^"\']+)["\']/', $svgContent, $viewBoxMatch);
    $viewBox = $viewBoxMatch[1] ?? '';
    
    // Извлекаем содержимое между <svg> тегами
    preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $svgContent, $contentMatch);
    $innerContent = $contentMatch[1] ?? '';
    
    if (empty($innerContent)) {
        // Если не удалось извлечь содержимое, пытаемся обработать весь SVG
        // Удаляем атрибуты width и height из корневого SVG
        $svgContent = preg_replace('/\s+width=["\'][^"\']*["\']/', '', $svgContent);
        $svgContent = preg_replace('/\s+height=["\'][^"\']*["\']/', '', $svgContent);
        // Заменяем fill и stroke на currentColor где это возможно
        $svgContent = preg_replace('/fill="(?!none|currentColor)[^"]*"/', 'fill="currentColor"', $svgContent);
        $svgContent = preg_replace('/stroke="(?!none|currentColor)[^"]*"/', 'stroke="currentColor"', $svgContent);
        // Добавляем/обновляем width и height
        $svgContent = preg_replace('/<svg/', '<svg width="' . $width . '" height="' . $height . '"', $svgContent);
        // Добавляем необходимые атрибуты если их нет
        if (strpos($svgContent, 'aria-hidden') === false) {
            $svgContent = str_replace('<svg', '<svg aria-hidden="true" focusable="false"', $svgContent);
        }
        if (strpos($svgContent, 'xmlns=') === false) {
            $svgContent = str_replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg"', $svgContent);
        }
        return $svgContent;
    }
    
    // Заменяем fill на currentColor, но только если fill не none и не currentColor
    $innerContent = preg_replace('/fill="(?!none|currentColor)[^"]*"/', 'fill="currentColor"', $innerContent);
    $innerContent = preg_replace('/stroke="(?!none|currentColor)[^"]*"/', 'stroke="currentColor"', $innerContent);
    // Заменяем inline стили со stroke и fill на currentColor (полная обработка style атрибутов)
    $innerContent = preg_replace_callback('/style="([^"]*)"/', function($matches) {
        $style = $matches[1];
        // Заменяем stroke цвета на currentColor
        $style = preg_replace('/stroke:#000000|stroke:#000|stroke:black/gi', 'stroke:currentColor', $style);
        $style = preg_replace('/stroke-miterlimit:[^;]*;/', '', $style); // Удаляем stroke-miterlimit если есть
        // Заменяем fill цвета на currentColor
        $style = preg_replace('/fill:#000000|fill:#000|fill:black/gi', 'fill:currentColor', $style);
        // Заменяем stroke-linecap и stroke-linejoin, оставляем их
        return 'style="' . $style . '"';
    }, $innerContent);
    // Убираем атрибуты width и height из внутренних элементов
    $innerContent = preg_replace('/\s+width="[^"]*"/', '', $innerContent);
    $innerContent = preg_replace('/\s+height="[^"]*"/', '', $innerContent);
    
    // Создаем новый SVG с нужными атрибутами
    $viewBoxAttr = $viewBox ? ' viewBox="' . htmlspecialchars($viewBox) . '"' : '';
    $result = '<svg width="' . $width . '" height="' . $height . '"' . $viewBoxAttr . ' xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">';
    $result .= $innerContent;
    $result .= '</svg>';
    
    return $result;
}

/**
 * Рендерит HTML head секцию
 * @param string $title Заголовок страницы
 * @param array $additionalStyles Массив дополнительных CSS файлов
 */
function renderHead(string $title = 'Vinnie chords', array $additionalStyles = []): void {
    ?>
<!DOCTYPE html>
<html lang='ru'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title><?php echo htmlspecialchars($title); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <script>
        // Ранняя инициализация темы до загрузки CSS
        (function() {
            try {
                const saved = localStorage.getItem('vinnie_chords_theme');
                const theme = saved && ['dark', 'dark2', 'light1', 'light2'].includes(saved) ? saved : 'dark';
                document.documentElement.setAttribute('data-theme', theme);
            } catch(e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <link rel="stylesheet" href="/common.css">
    <link rel="stylesheet" href="/components.css">
    <?php foreach ($additionalStyles as $style): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($style); ?>">
    <?php endforeach; ?>
    <style>
        /* Поддержка дерева навигации для "Песни" */
        .sidebar .nav-group { display:flex; flex-direction:column; gap:0.35rem; }
        .sidebar .nav-group > a { margin-bottom:-0.05rem; }
        .sidebar .nav-sub { display:none; flex-direction:column; gap:0.2rem; padding-left:0.4rem; }
        .sidebar .nav-group.open .nav-sub { display:flex; }
        .sidebar .nav-sub a { padding:0.5rem 0.75rem; border-radius:8px; border:1px dashed var(--border); background:color-mix(in srgb, var(--panel) 80%, transparent); font-size:0.92rem; text-align:left; }
    </style>
</head>
    <?php
}

/**
 * Рендерит sidebar с навигацией и user-block
 * @param array $userData Данные пользователя из getCurrentUser()
 * @param string $activePage Активная страница для подсветки в навигации (index, songs, setlists, admin)
 */
function renderSidebar(array $userData, string $activePage = ''): void {
    extract($userData);
    ?>
<div class="backdrop" id="backdrop"></div>
<aside class="sidebar" id="sidebar">
    <div class="user-block">
        <div class="user-pill" id="user-pill">
            <?php if ($hasAvatar && $avatarUrl): ?>
                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="Avatar" class="user-avatar" loading="lazy">
            <?php else: ?>
                <?php echo htmlspecialchars($initial); ?>
            <?php endif; ?>
        </div>
        <div class="user-name"><?php echo htmlspecialchars($displayName); ?></div>
        <div class="user-menu" id="user-menu">
            <a href="/profile.php">Мой профиль</a>
            <?php 
                $canAccessAdmin = isset($isAdminOrModerator) ? $isAdminOrModerator : (($isAdmin ?? false) || ($isModerator ?? false));
                if ($canAccessAdmin): 
            ?>
                <a href="/admin.php">Админ-панель</a>
            <?php endif; ?>
            <a href="/logout.php">Выйти</a>
        </div>
    </div>
    <nav class="nav">
        <a href="/" class="<?php echo $activePage === 'index' ? 'active' : ''; ?>">Главная</a>
        <div class="nav-group <?php echo $activePage === 'songs' ? 'open' : ''; ?>">
            <a href="/songs.php" class="<?php echo $activePage === 'songs' ? 'active' : ''; ?>" data-nav-toggle="songs">Песни</a>
            <div class="nav-sub">
                <a href="/songs.php">Все песни</a>
                <a href="/songs.php?locale=ru">Русские</a>
                <a href="/songs.php?locale=foreign">Иностранные</a>
                <a href="/songs.php?sort=pop">Популярные</a>
            </div>
        </div>
        <a href="/setlists.php" class="<?php echo $activePage === 'setlists' ? 'active' : ''; ?>">Сет листы</a>
        <a href="/tuner.php" class="<?php echo $activePage === 'tuner' ? 'active' : ''; ?>">Тюнер</a>
    </nav>
    <!-- Переключатель тем будет добавлен через JavaScript -->
</aside>
    <?php
}

/**
 * Рендерит общий JavaScript для sidebar и user menu
 */
function renderLayoutScripts(): void {
    ?>
<script src="/js/theme-switcher.js"></script>
<script src="/js/app.js"></script>
<script>
(function() {
    function initNavTree() {
        document.querySelectorAll('.nav-group').forEach(group => {
            const link = group.querySelector('[data-nav-toggle="songs"]');
            const sub = group.querySelector('.nav-sub');
            if (!link || !sub) return;

            // На старте закрыто всегда
            group.classList.remove('open');
            sub.style.display = 'none';

            link.addEventListener('click', (e) => {
                e.preventDefault();
                const open = group.classList.contains('open');
                group.classList.toggle('open', !open);
                sub.style.display = !open ? 'flex' : 'none';
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavTree);
    } else {
        initNavTree();
    }
})();
</script>
    <?php
}
