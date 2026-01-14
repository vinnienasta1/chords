/**
 * Модуль для кэширования и загрузки сайдбара
 * Загружает сайдбар один раз и кэширует в localStorage
 */

(function() {
    'use strict';

    const CACHE_KEY = 'sidebar_cache';
    const CACHE_VERSION = 14;
    const CACHE_TTL = 5 * 60 * 1000; // 5 минут

    /**
     * Определяет активную страницу по URL
     */
    function getActivePage() {
        const path = window.location.pathname;
        if (path === '/' || path === '/index.php') return 'index';
        if (path === '/songs.php') return 'songs';
        if (path === '/setlists.php' || path.startsWith('/setlist_view.php')) return 'setlists';
        if (path === '/tuner.php') return 'tuner';
        if (path === '/admin.php') return 'admin';
        if (path === '/profile.php') return '';
        if (path === '/edit_song.php') return '';
        return '';
    }

    /**
     * Получает данные из кэша
     */
    function getCachedSidebar() {
        try {
            const cached = localStorage.getItem(CACHE_KEY);
            if (!cached) return null;

            const data = JSON.parse(cached);
            if (data.version !== CACHE_VERSION) return null;

            const now = Date.now();
            if (now - data.timestamp > CACHE_TTL) return null;

            return data;
        } catch (e) {
            console.warn('Ошибка чтения кэша сайдбара:', e);
            return null;
        }
    }

    /**
     * Сохраняет данные в кэш
     */
    function setCachedSidebar(html, userId) {
        try {
            const data = {
                version: CACHE_VERSION,
                html: html,
                userId: userId,
                timestamp: Date.now()
            };
            localStorage.setItem(CACHE_KEY, JSON.stringify(data));
        } catch (e) {
            console.warn('Ошибка сохранения кэша сайдбара:', e);
        }
    }

    /**
     * Очищает кэш
     */
    function clearCache() {
        try {
            localStorage.removeItem(CACHE_KEY);
        } catch (e) {
            console.warn('Ошибка очистки кэша сайдбара:', e);
        }
    }

    /**
     * Загружает сайдбар с сервера
     */
    async function loadSidebarFromServer(activePage) {
        try {
            const response = await fetch(`/api/sidebar.php?activePage=${encodeURIComponent(activePage)}`);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json();
            if (!data.success) {
                throw new Error('Server returned error');
            }
            return data;
        } catch (e) {
            console.error('Ошибка загрузки сайдбара:', e);
            throw e;
        }
    }

    /**
     * Обновляет активную страницу в навигации
     */
    function updateActivePage(sidebarElement, activePage) {
        if (!sidebarElement || !activePage) return;

        const navLinks = sidebarElement.querySelectorAll('.nav a');
        navLinks.forEach(link => {
            link.classList.remove('active');
            const href = link.getAttribute('href');
            if (activePage === 'index' && (href === '/' || href === '/index.php')) {
                link.classList.add('active');
            } else if (activePage === 'songs' && href === '/songs.php') {
                link.classList.add('active');
            } else if (activePage === 'setlists' && (href === '/setlists.php' || href.startsWith('/setlist_view.php'))) {
                link.classList.add('active');
            } else if (activePage === 'tuner' && href === '/tuner.php') {
                link.classList.add('active');
            } else if (activePage === 'admin' && href === '/admin.php') {
                link.classList.add('active');
            }
        });
    }

    /**
     * Вставляет сайдбар в DOM
     */
    function insertSidebar(html, activePage) {
        const layout = document.querySelector('.layout');
        if (!layout) {
            console.error('Элемент .layout не найден');
            return false;
        }

        // Удаляем существующий сайдбар и backdrop, если есть
        const existingSidebar = document.getElementById('sidebar');
        const existingBackdrop = document.getElementById('backdrop');
        if (existingSidebar) existingSidebar.remove();
        if (existingBackdrop) existingBackdrop.remove();

        // Создаем временный контейнер для парсинга HTML
        const temp = document.createElement('div');
        temp.innerHTML = html;

        // Вставляем backdrop и sidebar перед main
        const backdrop = temp.querySelector('#backdrop');
        const sidebar = temp.querySelector('#sidebar');

        if (backdrop && sidebar) {
            layout.insertBefore(backdrop, layout.firstChild);
            layout.insertBefore(sidebar, layout.querySelector('main') || layout.firstChild);

            // Обновляем активную страницу
            updateActivePage(sidebar, activePage);

            return true;
        }

        return false;
    }

    /**
     * Загружает и отображает сайдбар
     */
    async function loadSidebar(forceRefresh = false) {
        const activePage = getActivePage();
        let sidebarHtml = null;
        let userId = null;

        // Пытаемся загрузить из кэша, если не требуется принудительное обновление
        if (!forceRefresh) {
            const cached = getCachedSidebar();
            if (cached) {
                sidebarHtml = cached.html;
                userId = cached.userId;
            }
        }

        // Если нет в кэше, загружаем с сервера
        if (!sidebarHtml) {
            try {
                const data = await loadSidebarFromServer(activePage);
                sidebarHtml = data.html;
                userId = data.userId;
                setCachedSidebar(sidebarHtml, userId);
            } catch (e) {
                console.error('Не удалось загрузить сайдбар:', e);
                return false;
            }
        }

        // Вставляем в DOM
        const success = insertSidebar(sidebarHtml, activePage);
        if (success) {
            // Инициализируем sidebar и user menu после вставки
            // Используем небольшую задержку, чтобы убедиться, что app.js загружен
            setTimeout(() => {
                if (typeof initSidebar === 'function') {
                    initSidebar();
                }
                if (typeof initUserMenu === 'function') {
                    initUserMenu();
                }
                if (typeof initNavTree === 'function') {
                    initNavTree();
                }
            }, 0);
        }

        return success;
    }

    // Экспортируем функции
    window.SidebarCache = {
        load: loadSidebar,
        clear: clearCache,
        refresh: () => loadSidebar(true)
    };

    // Автоматическая загрузка при готовности DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            loadSidebar();
        });
    } else {
        loadSidebar();
    }
})();
