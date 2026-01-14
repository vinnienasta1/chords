/**
 * JavaScript для фильтров и поиска на странице songs.php
 */

// Инициализация поиска (как в setlist_view: только клиентский фильтр, без перезагрузки страницы)
function initSearch() {
    const searchInput = document.getElementById('search-input');
    const searchForm = document.getElementById('search-form');
    if (!searchInput || !searchForm) return;

    const list = document.querySelector('.songs-list');
    if (!list) return;

    const cards = Array.from(list.querySelectorAll('.song-card'));
    if (!cards.length) return;

    let timeout;

    function applyFilter() {
        const q = (searchInput.value || '').toLowerCase();
        let visibleCount = 0;

        cards.forEach(card => {
            const titleEl = card.querySelector('h3');
            const metaEl = card.querySelector('.meta');
            const text = [
                titleEl ? titleEl.textContent : '',
                metaEl ? metaEl.textContent : ''
            ].join(' ').toLowerCase();

            const lyrics = (card.dataset.lyrics || '').toLowerCase();

            const match = q === '' || text.includes(q) || lyrics.includes(q);
            card.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });

        // Сообщение "Песен не найдено"
        let noSongs = document.querySelector('.no-songs');
        if (!noSongs) {
            // пытаемся найти контейнер, куда его можно добавить
            const cardContainer = list.parentElement;
            if (cardContainer) {
                noSongs = document.createElement('div');
                noSongs.className = 'no-songs';
                noSongs.textContent = 'Песен не найдено.';
                cardContainer.appendChild(noSongs);
            }
        }
        if (noSongs) {
            noSongs.style.display = visibleCount === 0 ? '' : 'none';
        }
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(timeout);
        timeout = setTimeout(applyFilter, 200);
    });

    // Если при загрузке уже есть значение в поиске (по URL-параметру) — сразу применяем фильтр
    if ((searchInput.value || '').trim() !== '') {
        applyFilter();
    }
}

// Инициализация toggle фильтров
function initFiltersToggle() {
    const filtersToggle = document.getElementById('filters-toggle');
    const filtersPanel = document.getElementById('filters-panel');
    if (!filtersToggle || !filtersPanel) return;
    
    filtersToggle.addEventListener('click', () => {
        const isHidden = filtersPanel.hasAttribute('hidden');
        if (isHidden) {
            filtersPanel.removeAttribute('hidden');
        } else {
            filtersPanel.setAttribute('hidden', '');
        }
    });
}

// Инициализация кнопки сброса фильтров
function initFiltersReset() {
    const filtersReset = document.getElementById('filters-reset');
    if (!filtersReset) return;
    
    filtersReset.addEventListener('click', () => {
        window.location.href = '/songs.php';
    });
}

// Автоматическая инициализация
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initSearch();
        initFiltersToggle();
        initFiltersReset();
    });
} else {
    initSearch();
    initFiltersToggle();
    initFiltersReset();
}
