/**
 * Управление цветовыми темами
 */
(function() {
    'use strict';

    const THEME_STORAGE_KEY = 'vinnie_chords_theme';
    const DEFAULT_THEME = 'dark';
    
    const themes = {
        dark: {
            name: 'Темная',
            icon: 'moon1',
            description: 'Темная тема с фиолетовым акцентом'
        },
        dark2: {
            name: 'Темная 2',
            icon: 'stars1',
            description: 'Темная тема с графитовым акцентом'
        },
        light1: {
            name: 'Светлая',
            icon: 'sun',
            description: 'Светлая тема с синим акцентом'
        },
        light2: {
            name: 'Светлая 2',
            icon: 'sun1',
            description: 'Светлая тема с фиолетовым акцентом'
        }
    };
    
    /**
     * Загрузить SVG иконку и вставить в элемент
     */
    function loadIcon(iconName, element) {
        fetch(`/icons/${iconName}.svg`)
            .then(response => response.text())
            .then(svgText => {
                // Очищаем XML декларацию
                svgText = svgText.replace(/<\?xml[^>]*\?>/g, '').replace(/<!--.*?-->/gs, '').trim();
                const parser = new DOMParser();
                const svgDoc = parser.parseFromString(svgText, 'image/svg+xml');
                const svgElement = svgDoc.querySelector('svg');
                if (svgElement) {
                    svgElement.setAttribute('width', '20');
                    svgElement.setAttribute('height', '20');
                    svgElement.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
                    svgElement.style.display = 'block';
                    svgElement.style.fill = 'currentColor';
                    // Заменяем все fill и stroke на currentColor для поддержки тем
                    svgElement.querySelectorAll('[fill]').forEach(el => {
                        const fillValue = el.getAttribute('fill');
                        if (fillValue && fillValue !== 'none' && fillValue !== 'currentColor') {
                            el.setAttribute('fill', 'currentColor');
                        }
                    });
                    svgElement.querySelectorAll('[stroke]').forEach(el => {
                        const strokeValue = el.getAttribute('stroke');
                        if (strokeValue && strokeValue !== 'none' && strokeValue !== 'currentColor') {
                            el.setAttribute('stroke', 'currentColor');
                        }
                    });
                    // Обрабатываем inline стили
                    svgElement.querySelectorAll('[style]').forEach(el => {
                        let style = el.getAttribute('style');
                        style = style.replace(/stroke:#000000|stroke:#000|stroke:black/gi, 'stroke:currentColor');
                        style = style.replace(/fill:#000000|fill:#000|fill:black/gi, 'fill:currentColor');
                        el.setAttribute('style', style);
                    });
                    // Очищаем элемент и добавляем SVG
                    element.innerHTML = '';
                    const clonedSvg = svgElement.cloneNode(true);
                    element.appendChild(clonedSvg);
                }
            })
            .catch(() => {
                // Fallback на текстовый символ если SVG не загрузился
                element.textContent = '●';
            });
    }

    /**
     * Получить текущую тему из localStorage или вернуть тему по умолчанию
     */
    function getCurrentTheme() {
        try {
            const saved = localStorage.getItem(THEME_STORAGE_KEY);
            return saved && themes[saved] ? saved : DEFAULT_THEME;
        } catch (e) {
            return DEFAULT_THEME;
        }
    }

    /**
     * Установить тему
     */
    function setTheme(themeId) {
        if (!themes[themeId]) {
            console.warn('Неизвестная тема:', themeId);
            return;
        }

        try {
            document.documentElement.setAttribute('data-theme', themeId);
            localStorage.setItem(THEME_STORAGE_KEY, themeId);
            
            // Обновить активную кнопку в переключателе
            updateThemeSwitcher(themeId);
            
            // Вызвать событие для других модулей
            window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: themeId } }));
        } catch (e) {
            console.error('Ошибка при установке темы:', e);
        }
    }

    /**
     * Инициализация темы при загрузке страницы
     */
    function initTheme() {
        const theme = getCurrentTheme();
        setTheme(theme);
    }

    /**
     * Получить следующую тему в цикле
     */
    function getNextTheme(currentThemeId) {
        const themeOrder = ['dark', 'dark2', 'light1', 'light2'];
        const currentIndex = themeOrder.indexOf(currentThemeId);
        const nextIndex = (currentIndex + 1) % themeOrder.length;
        return themeOrder[nextIndex];
    }

    /**
     * Создать HTML переключателя тем
     */
    function createThemeSwitcherHTML() {
        const currentTheme = getCurrentTheme();
        const currentThemeData = themes[currentTheme];
        
        let html = '<div class="theme-switcher">';
        html += `<button class="theme-toggle-btn" id="theme-toggle-btn" title="${currentThemeData.description}">`;
        html += `<span class="theme-icon"></span>`;
        html += '</button>';
        html += '</div>';
        return html;
    }

    /**
     * Обновить активное состояние переключателя
     */
    function updateThemeSwitcher(activeThemeId) {
        const btn = document.getElementById('theme-toggle-btn');
        if (!btn) return;

        const currentThemeData = themes[activeThemeId];
        if (currentThemeData) {
            const icon = btn.querySelector('.theme-icon');
            if (icon) {
                loadIcon(currentThemeData.icon, icon);
            }
            btn.title = currentThemeData.description;
        }
    }

    /**
     * Инициализация переключателя тем
     */
    function initThemeSwitcher() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) {
            // Если сайдбара еще нет, попробуем позже
            setTimeout(initThemeSwitcher, 100);
            return;
        }

        // Проверяем, не добавлен ли уже переключатель
        if (document.getElementById('theme-toggle-btn')) {
            return;
        }

        // Создаем контейнер для переключателя тем
        const switcherHTML = createThemeSwitcherHTML();
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = switcherHTML;
        const switcher = tempDiv.firstElementChild;

        // Добавляем переключатель в конец сайдбара
        sidebar.appendChild(switcher);

        // Добавляем обработчик события для переключения
        const toggleBtn = document.getElementById('theme-toggle-btn');
        if (toggleBtn) {
            // Загружаем иконку текущей темы
            const iconElement = toggleBtn.querySelector('.theme-icon');
            if (iconElement) {
                loadIcon(themes[getCurrentTheme()].icon, iconElement);
            }
            
            toggleBtn.addEventListener('click', () => {
                const currentTheme = getCurrentTheme();
                const nextTheme = getNextTheme(currentTheme);
                setTheme(nextTheme);
            });
        }

        // Добавляем стили для переключателя
        addThemeSwitcherStyles();
    }

    /**
     * Добавить стили для переключателя тем
     */
    function addThemeSwitcherStyles() {
        if (document.getElementById('theme-switcher-styles')) return;

        const style = document.createElement('style');
        style.id = 'theme-switcher-styles';
        style.textContent = `
            .theme-switcher {
                margin-top: auto;
                padding-top: 1.5rem;
                border-top: 1px solid var(--border);
                display: flex;
                justify-content: center;
            }
            
            .sidebar {
                display: flex;
                flex-direction: column;
            }
            
            .theme-toggle-btn {
                width: 44px;
                height: 44px;
                border-radius: 10px;
                border: 1px solid var(--border);
                background: var(--card-bg);
                color: var(--text);
                cursor: pointer;
                transition: all 0.2s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0;
                position: relative;
                overflow: hidden;
            }
            
            .theme-toggle-btn:hover {
                border-color: var(--accent);
                background: color-mix(in srgb, var(--accent) 10%, transparent);
                transform: translateY(-1px);
            }
            
            .theme-toggle-btn:active {
                transform: translateY(0);
            }
            
            .theme-icon {
                font-size: 1.5rem;
                line-height: 1;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        `;
        document.head.appendChild(style);
    }

    // Инициализация при загрузке DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            initThemeSwitcher();
        });
    } else {
        initTheme();
        initThemeSwitcher();
    }

    // Экспорт для глобального использования
    window.ThemeSwitcher = {
        setTheme: setTheme,
        getCurrentTheme: getCurrentTheme,
        getThemes: () => themes
    };
})();
