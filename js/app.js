/**
 * Общий JavaScript для всех страниц
 * Инициализация sidebar, user menu и других общих компонентов
 */

// Инициализация sidebar toggle
function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    const backdrop = document.getElementById('backdrop');
    const btn = document.createElement('button');
    btn.className = 'toggle';
    btn.setAttribute('aria-label', 'Открыть меню');
    // Загружаем иконку меню из SVG файла
    fetch('/icons/menu1.svg')
        .then(response => response.text())
        .then(svgText => {
            // Очищаем XML декларацию и добавляем атрибуты для правильного отображения
            const parser = new DOMParser();
            const svgDoc = parser.parseFromString(svgText, 'image/svg+xml');
            const svgElement = svgDoc.querySelector('svg');
            if (svgElement) {
                svgElement.setAttribute('width', '20');
                svgElement.setAttribute('height', '20');
                svgElement.setAttribute('fill', 'currentColor');
                svgElement.style.display = 'block';
                // Заменяем все fill и stroke на currentColor для поддержки тем
                svgElement.querySelectorAll('[fill]').forEach(el => {
                    if (el.getAttribute('fill') !== 'none') {
                        el.setAttribute('fill', 'currentColor');
                    }
                });
                svgElement.querySelectorAll('[stroke]').forEach(el => {
                    if (el.getAttribute('stroke') !== 'none') {
                        el.setAttribute('stroke', 'currentColor');
                    }
                });
                btn.appendChild(svgElement);
            }
        })
        .catch(() => {
            // Fallback на текстовый символ если SVG не загрузился
            btn.textContent = '≡';
        });
    
    const close = () => { 
        sidebar.classList.remove('open'); 
        backdrop?.classList.remove('show'); 
    };
    
    btn.addEventListener('click', () => { 
        sidebar.classList.toggle('open'); 
        backdrop?.classList.toggle('show'); 
    });
    
    backdrop?.addEventListener('click', close);
    document.body.appendChild(btn);
    
    // Закрытие при клике вне sidebar
    document.addEventListener("click", (e) => {
        if (e.target.closest("#sidebar")) return;
        if (!sidebar.contains(e.target) && !btn.contains(e.target) && e.target !== backdrop) {
            close();
        }
    });
    
    // Закрытие при клике на ссылку в sidebar
    sidebar.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            // Не закрываем бар при клике на переключатель дерева "Все песни"
            if (link.dataset.navToggle === 'songs') return;
            setTimeout(close, 100);
        });
    });
    
    // Открытие свайпом вправо на мобильных устройствах
    initSwipeToOpen(sidebar, close);
}

/**
 * Инициализация свайпа для открытия sidebar на мобильных
 */
function initSwipeToOpen(sidebar, close) {
    // Проверяем, что это мобильное устройство (ширина экрана <= 960px)
    const isMobile = () => window.innerWidth <= 960;
    
    let touchStartX = 0;
    let touchStartY = 0;
    let touchStartTime = 0;
    let isSwiping = false;
    const SWIPE_THRESHOLD = 50; // Минимальное расстояние для свайпа
    const MAX_VERTICAL_DISTANCE = 100; // Максимальное вертикальное отклонение для горизонтального свайпа
    const SWIPE_MAX_TIME = 500; // Максимальное время свайпа в миллисекундах
    
    const open = () => {
        if (!sidebar.classList.contains('open')) {
            sidebar.classList.add('open');
            const backdrop = document.getElementById('backdrop');
            if (backdrop) backdrop.classList.add('show');
        }
    };
    
    // Обработка начала касания
    document.addEventListener('touchstart', (e) => {
        if (!isMobile()) return;
        
        const touch = e.touches[0];
        touchStartX = touch.clientX;
        touchStartY = touch.clientY;
        touchStartTime = Date.now();
        isSwiping = false;
    }, { passive: true });
    
    // Обработка движения касания
    document.addEventListener('touchmove', (e) => {
        if (!isMobile()) return;
        
        const touch = e.touches[0];
        const deltaX = touch.clientX - touchStartX;
        const deltaY = Math.abs(touch.clientY - touchStartY);
        const absDeltaX = Math.abs(deltaX);
        
        // Определяем, является ли это горизонтальным свайпом
        if (absDeltaX > 10 && absDeltaX > deltaY) {
            isSwiping = true;
            
            // Если меню закрыто и свайп вправо - предотвращаем скролл
            if (!sidebar.classList.contains('open') && deltaX > 0) {
                e.preventDefault();
            }
            
            // Если меню открыто и свайп влево - предотвращаем скролл
            if (sidebar.classList.contains('open') && deltaX < 0) {
                e.preventDefault();
            }
        }
    }, { passive: false });
    
    // Обработка окончания касания
    document.addEventListener('touchend', (e) => {
        if (!isMobile() || !isSwiping) return;
        
        const touch = e.changedTouches[0];
        const deltaX = touch.clientX - touchStartX;
        const deltaY = Math.abs(touch.clientY - touchStartY);
        const deltaTime = Date.now() - touchStartTime;
        const absDeltaX = Math.abs(deltaX);
        
        // Проверяем, что это горизонтальный свайп
        const isHorizontalSwipe = absDeltaX > deltaY && absDeltaX > SWIPE_THRESHOLD && deltaTime < SWIPE_MAX_TIME;
        
        if (isHorizontalSwipe) {
            // Свайп вправо для открытия (когда меню закрыто)
            if (deltaX > 0 && !sidebar.classList.contains('open')) {
                open();
            }
            // Свайп влево для закрытия (когда меню открыто)
            else if (deltaX < 0 && sidebar.classList.contains('open')) {
                close();
            }
        }
        
        isSwiping = false;
    }, { passive: true });
    
    // Обработка отмены касания
    document.addEventListener('touchcancel', () => {
        isSwiping = false;
    }, { passive: true });
}

// Инициализация user menu
function initUserMenu() {
    const userPill = document.getElementById('user-pill');
    const userMenu = document.getElementById('user-menu');
    if (!userPill || !userMenu) return;
    
    userPill.addEventListener('click', (e) => {
        e.stopPropagation();
        userMenu.style.display = userMenu.style.display === 'block' ? 'none' : 'block';
    });
    
    document.addEventListener('click', (e) => {
        if (!userMenu.contains(e.target) && e.target !== userPill) {
            userMenu.style.display = 'none';
        }
    });
}

// Инициализация дерева "Песни" в сайдбаре
function initNavTree() {
    // Инжектируем стили дерева, если их нет (нужно для кешированной вставки)
    if (!document.getElementById('sidebar-nav-tree-styles')) {
        const s = document.createElement('style');
        s.id = 'sidebar-nav-tree-styles';
        s.textContent = `
            .sidebar .nav-group { display:flex; flex-direction:column; gap:0.35rem; }
            .sidebar .nav-group > a { margin-bottom:-0.05rem; }
            .sidebar .nav-sub { display:none; flex-direction:column; gap:0.2rem; padding-left:0.4rem; }
            .sidebar .nav-group.open .nav-sub { display:flex; }
            .sidebar .nav-sub a { padding:0.5rem 0.75rem; border-radius:8px; border:1px dashed var(--border); background:color-mix(in srgb, var(--panel) 80%, transparent); font-size:0.92rem; text-align:left; }
        `;
        document.head.appendChild(s);
    }

    document.querySelectorAll('.nav-group').forEach(group => {
        // сброс состояния
        group.classList.remove('open');
        const link = group.querySelector('[data-nav-toggle="songs"]');
        const sub = group.querySelector('.nav-sub');
        if (!link || !sub) return;

        // очищаем инлайны, управление — только через класс
        sub.style.display = '';

        // убираем старые слушатели через клонирование
        const newLink = link.cloneNode(true);
        link.parentNode.replaceChild(newLink, link);

        const close = () => {
            group.classList.remove('open');
            newLink.setAttribute('aria-expanded', 'false');
        };
        const open = () => {
            group.classList.add('open');
            newLink.setAttribute('aria-expanded', 'true');
        };

        // По умолчанию закрыто
        close();

        newLink.addEventListener('click', (e) => {
            e.preventDefault();
            if (group.classList.contains('open')) {
                close();
            } else {
                open();
            }
        });
    });
}

// Единый confirm для форм с data-confirm
function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach(form => {
        if (form.dataset.confirmBound === '1') return;
        form.dataset.confirmBound = '1';
        const msg = form.getAttribute('data-confirm') || 'Подтвердите действие';
        form.addEventListener('submit', (e) => {
            if (!window.confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });
}

// Инициализация будет вызвана из sidebar-cache.js после загрузки сайдбара
// Это позволяет избежать попыток инициализации до того, как сайдбар загружен
document.addEventListener('DOMContentLoaded', () => {
    initConfirmForms();
    initNavTree();
});

// На случай, если DOM уже готов (например, если скрипт загружен после), гарантируем вызов
if (document.readyState !== 'loading') {
    initConfirmForms();
    initNavTree();
}
