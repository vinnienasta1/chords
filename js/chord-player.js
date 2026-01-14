/**
 * JavaScript для транспонирования аккордов и управления просмотром песен
 * Используется на странице songs.php при просмотре песни
 */

// Транспонирование аккордов
let currentTranspose = 0;

const sharpToFlat = {
    'C#': 'Db', 'D#': 'Eb', 'F#': 'Gb', 'G#': 'Ab', 'A#': 'Bb'
};

const flatToSharp = {
    'Db': 'C#', 'Eb': 'D#', 'Gb': 'F#', 'Ab': 'G#', 'Bb': 'A#'
};

function mapSharp(root) {
    return sharpToFlat[root] || root;
}

function normalizeRoot(root) {
    const map = { 'H': 'B', 'B': 'Bb' };
    return map[root] || root;
}

function transposeChord(chordText, semitones) {
    const notes = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    const match = chordText.match(/^([A-H][b#]?)(.*)/);
    if (!match) return chordText;
    
    let root = normalizeRoot(match[1]);
    const suffix = match[2];
    
    if (flatToSharp[root]) root = flatToSharp[root];
    
    let idx = notes.indexOf(root);
    if (idx === -1) return chordText;
    
    idx = (idx + semitones + 12) % 12;
    let newRoot = notes[idx];
    newRoot = mapSharp(newRoot);
    
    return newRoot + suffix;
}

function applyTranspose() {
    const chordSpans = document.querySelectorAll('.chord');
    chordSpans.forEach(span => {
        const original = span.getAttribute('data-chord');
        if (original) {
            span.textContent = transposeChord(original, currentTranspose);
        }
    });
    
    const tpValue = document.getElementById('tp-value');
    if (tpValue) {
        tpValue.textContent = currentTranspose > 0 ? `+${currentTranspose}` : currentTranspose;
    }
}

function initTranspose() {
    const tpUp = document.getElementById('tp-up');
    const tpDown = document.getElementById('tp-down');
    const tpReset = document.getElementById('tp-reset');
    
    if (tpUp) {
        tpUp.addEventListener('click', () => {
            currentTranspose++;
            applyTranspose();
        });
    }
    
    if (tpDown) {
        tpDown.addEventListener('click', () => {
            currentTranspose--;
            applyTranspose();
        });
    }
    
    if (tpReset) {
        tpReset.addEventListener('click', () => {
            currentTranspose = 0;
            applyTranspose();
        });
    }
}

// Автопрокрутка
let scrollInterval = null;
let scrollSpeed = 1;

function initAutoscroll() {
    const playBtn = document.getElementById('play-btn');
    const scrollRange = document.getElementById('scroll-range');
    
    if (playBtn) {
        playBtn.addEventListener('click', () => {
            if (scrollInterval) {
                clearInterval(scrollInterval);
                scrollInterval = null;
                playBtn.textContent = '▶';
            } else {
                scrollInterval = setInterval(() => {
                    window.scrollBy(0, scrollSpeed);
                }, 50);
                playBtn.textContent = '⏸';
            }
        });
    }
    
    if (scrollRange) {
        scrollRange.addEventListener('input', (e) => {
            scrollSpeed = parseFloat(e.target.value);
        });
    }
}

// View settings (автомасштаб, скрытие аккордов)
function initViewSettings() {
    const viewToggle = document.getElementById('view-toggle');
    const viewPanel = document.getElementById('view-panel');
    const viewMenu = document.getElementById('view-menu');
    
    if (viewToggle && viewPanel && viewMenu) {
        viewToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isHidden = viewPanel.style.display === 'none' || !viewPanel.style.display;
            viewPanel.style.display = isHidden ? 'block' : 'none';
        });
        
        document.addEventListener('click', (e) => {
            if (!viewMenu.contains(e.target)) {
                viewPanel.style.display = 'none';
            }
        });
    }
    
    // Функция для обновления визуального состояния кнопок
    function updateToggleButtons() {
        const autoColsCheckbox = document.getElementById('auto-cols');
        const hideChordsCheckbox = document.getElementById('hide-chords');
        const autoColsBtn = document.getElementById('auto-cols-btn');
        const hideChordsBtn = document.getElementById('hide-chords-btn');
        
        if (autoColsBtn && autoColsCheckbox) {
            autoColsBtn.classList.toggle('active', autoColsCheckbox.checked);
        }
        if (hideChordsBtn && hideChordsCheckbox) {
            hideChordsBtn.classList.toggle('active', hideChordsCheckbox.checked);
        }
    }
    
    // Автомасштаб (колонки)
    const autoColsCheckbox = document.getElementById('auto-cols');
    if (autoColsCheckbox) {
        const savedAutoCols = localStorage.getItem('autoCols') === 'true';
        autoColsCheckbox.checked = savedAutoCols;
        if (savedAutoCols) applyAutoCols();
        updateToggleButtons();
        
        autoColsCheckbox.addEventListener('change', (e) => {
            localStorage.setItem('autoCols', e.target.checked);
            updateToggleButtons();
            if (e.target.checked) {
                applyAutoCols();
            } else {
                removeAutoCols();
            }
        });
    }
    
    // Скрыть аккорды
    const hideChordsCheckbox = document.getElementById('hide-chords');
    if (hideChordsCheckbox) {
        const savedHideChords = localStorage.getItem('hideChords') === 'true';
        hideChordsCheckbox.checked = savedHideChords;
        if (savedHideChords) applyHideChords();
        updateToggleButtons();
        
        hideChordsCheckbox.addEventListener('change', (e) => {
            localStorage.setItem('hideChords', e.target.checked);
            updateToggleButtons();
            if (e.target.checked) {
                applyHideChords();
            } else {
                removeHideChords();
            }
        });
    }
    
    // Сброс настроек
    const viewReset = document.getElementById('view-reset');
    if (viewReset) {
        viewReset.addEventListener('click', () => {
            localStorage.removeItem('autoCols');
            localStorage.removeItem('hideChords');
            if (autoColsCheckbox) autoColsCheckbox.checked = false;
            if (hideChordsCheckbox) hideChordsCheckbox.checked = false;
            updateToggleButtons();
            removeAutoCols();
            removeHideChords();
        });
    }
}

function applyAutoCols() {
    const songContent = document.querySelector('.song-content');
    if (!songContent) return;
    
    const lines = Array.from(songContent.children);
    if (lines.length === 0) return;
    
    // Вспомогательные функции для определения типа строки
    function isHeading(line) {
        const text = line.textContent.trim().toLowerCase();
        return text.startsWith('verse') || text.startsWith('chorus') || 
               text.startsWith('куплет') || text.startsWith('припев') ||
               text.startsWith('intro') || text.startsWith('outro') ||
               text.startsWith('bridge') || text.startsWith('мост');
    }
    
    function isEmptyTextLine(line) {
        return line.classList.contains('text-line') && line.textContent.trim() === '';
    }
    
    function isChordLine(line) {
        return line.classList.contains('chord-line') && !line.classList.contains('empty');
    }
    
    // Определяем допустимые точки разрыва
    const allowedBreak = new Array(lines.length).fill(false);
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const prevLine = i > 0 ? lines[i - 1] : null;
        const nextLine = i < lines.length - 1 ? lines[i + 1] : null;
        
        // Правило 1: Новая колонка может начинаться с заголовка
        if (isHeading(line)) {
            allowedBreak[i] = true;
        }
        
        // Правило 2: Если предыдущая строка пустая, а текущая - аккорды или пустая
        if (prevLine && isEmptyTextLine(prevLine) && (isChordLine(line) || isEmptyTextLine(line))) {
            allowedBreak[i] = true;
        }
        
        // Правило 3: Если предыдущая строка пустая, а текущая - текст (не аккорды)
        if (prevLine && isEmptyTextLine(prevLine) && line.classList.contains('text-line') && !isChordLine(line)) {
            allowedBreak[i] = true;
        }
    }
    
    // Применяем колонки
    songContent.style.columnCount = '2';
    songContent.style.columnGap = '2rem';
    songContent.style.columnRule = '1px solid rgba(255,255,255,0.1)';
    
    lines.forEach((line, idx) => {
        if (allowedBreak[idx]) {
            line.style.breakBefore = 'column';
        } else {
            line.style.breakBefore = 'avoid';
        }
        line.style.breakInside = 'avoid';
    });
}

function removeAutoCols() {
    const songContent = document.querySelector('.song-content');
    if (!songContent) return;
    
    songContent.style.columnCount = '';
    songContent.style.columnGap = '';
    songContent.style.columnRule = '';
    
    const lines = songContent.children;
    Array.from(lines).forEach(line => {
        line.style.breakBefore = '';
        line.style.breakInside = '';
    });
}

function applyHideChords() {
    const chordLines = document.querySelectorAll('.chord-line');
    chordLines.forEach(line => {
        line.style.display = 'none';
    });
}

function removeHideChords() {
    const chordLines = document.querySelectorAll('.chord-line');
    chordLines.forEach(line => {
        line.style.display = '';
    });
}

// Навигация по сетлисту
function initSetlistNavigation() {
    const prevBtn = document.getElementById('prev-song');
    const nextBtn = document.getElementById('next-song');
    
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            const url = new URL(window.location.href);
            const setlistId = url.searchParams.get('setlist_id');
            const currentPos = parseInt(url.searchParams.get('setlist_pos') || '-1');
            if (setlistId && currentPos > 0) {
                url.searchParams.set('setlist_pos', currentPos - 1);
                window.location.href = url.toString();
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            const url = new URL(window.location.href);
            const setlistId = url.searchParams.get('setlist_id');
            const currentPos = parseInt(url.searchParams.get('setlist_pos') || '-1');
            if (setlistId && currentPos >= 0) {
                url.searchParams.set('setlist_pos', currentPos + 1);
                window.location.href = url.toString();
            }
        });
    }
}

// Автоматическая инициализация
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initTranspose();
        initAutoscroll();
        initViewSettings();
        initSetlistNavigation();
    });
} else {
    initTranspose();
    initAutoscroll();
    initViewSettings();
    initSetlistNavigation();
}
