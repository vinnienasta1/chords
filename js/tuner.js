'use strict';

(() => {
    const A4 = 440;
    const notes = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    const needle = document.getElementById('tuner-needle');
    const noteMain = document.getElementById('tuner-note-main');
    const centsEl = document.getElementById('tuner-cents');
    const levelEl = document.getElementById('tuner-level');
    const statusEl = document.getElementById('tuner-status');
    const meterBody = document.querySelector('.meter');
    // pro tuner elements
    const proNote = document.getElementById('pro-note');
    const proDelta = document.getElementById('pro-delta');
    const proString = document.getElementById('pro-string');
    const proStrings = document.getElementById('pro-strings');
    const proOsc = document.getElementById('pro-osc');
    const proOscCtx = proOsc ? proOsc.getContext('2d') : null;
    let oscData = null;
    let gateCents = [];
    let lockStreakStart = null;
    let lastGoodFreq = null;
    let lastGoodTime = 0;

    let audioCtx = null;
    let analyser = null;
    let source = null;
    let micStream = null;
    let raf = null;
    const bufSize = 2048;
    let data = new Float32Array(bufSize);
    let smoothCents = 0;
    let lockTime = 0;
    const SMOOTH = 0.8; // ещё мягче основной индикатор
    const LOCK_HOLD = 320; // ms удержание зелёного
    const LOCK_RANGE = 2; // диапазон попадания в ноту, центов
    const LOCK_RANGE_STRING = 10; // диапазон попадания для фиксации струны
    const LOCK_STRING_TIME = 1000; // мс удержания для фиксации струны
    const HIST_LEN = 240; // длина истории для осциллограммы
    const GRAPH_RANGE = 200; // диапазон отображения графика +/- центов
    const SILENCE_RMS = 0.018; // порог тишины для обрыва кривой
    const PRO_SMOOTH = 0.9; // сглаживание высоты для графика (сильно мягко)
    const FREQ_MIN = 60; // отсечка шума по частоте
    const FREQ_MAX = 1300;
    const GATE_WINDOW = 5; // окно для медианного фильтра
    const GATE_JUMP = 80; // допустимый скачок (центов), иначе шум
    const GATE_RMS = 0.05; // если rms ниже и скачок большой — отбрасываем
    const HOLD_LAST_MS = 1000; // удержание последней частоты при кратковременных сбоях
    let lastCentsVal = 0;
    let manualTarget = null;

    const STRINGS = [
        { name: 'E4', freq: 329.63, label: '1 (E4)' },
        { name: 'B3', freq: 246.94, label: '2 (B3)' },
        { name: 'G3', freq: 196.00, label: '3 (G3)' },
        { name: 'D3', freq: 146.83, label: '4 (D3)' },
        { name: 'A2', freq: 110.00, label: '5 (A2)' },
        { name: 'E2', freq: 82.41,  label: '6 (E2)' }
    ];
    let centsHistory = [];

    function bindStringClicks() {
        if (!proStrings) return;
        proStrings.querySelectorAll('.string-row').forEach(r => {
            r.addEventListener('click', () => {
                const clicked = r.dataset.string || null;
                // если нажали ту же — снять выбор
                if (manualTarget === clicked) {
                    manualTarget = null;
                    r.classList.remove('active');
                } else {
                    manualTarget = clicked;
                }
                // сбрасываем историю к нулю, чтобы не тянуть старый след
                centsHistory = [];
                lastCentsVal = 0;
                lockStreakStart = null;
                // если сбросили выбор — не трогаем tuned; если выбрали новую — снимаем tuned только у неё
                if (manualTarget) {
                    proStrings.querySelectorAll('.string-row').forEach(x => {
                        if (x.dataset.string === manualTarget) x.classList.remove('tuned');
                    });
                }
                updatePro(null);
            });
        });
    }

    function freqToNoteData(freq) {
        const n = Math.round(12 * (Math.log(freq / A4) / Math.log(2)));
        const noteIndex = (n + 9 + 12 * 1000) % 12; // сдвиг, чтобы A=9
        const name = notes[noteIndex];
        const octave = 4 + Math.floor((n + 9) / 12);
        const ref = A4 * Math.pow(2, n / 12);
        const cents = 1200 * Math.log(freq / ref) / Math.log(2);
        return { name, octave, cents, ref };
    }

    function autoCorrelate(buf, sampleRate) {
        let SIZE = buf.length;
        let rms = 0;
        for (let i = 0; i < SIZE; i++) {
            rms += buf[i] * buf[i];
        }
        rms = Math.sqrt(rms / SIZE);
        if (rms < 0.01) return null; // слишком тихо

        let r1 = 0, r2 = SIZE - 1, thres = 0.2;
        for (let i = 0; i < SIZE / 2; i++) {
            if (Math.abs(buf[i]) < thres) { r1 = i; break; }
        }
        for (let i = 1; i < SIZE / 2; i++) {
            if (Math.abs(buf[SIZE - i]) < thres) { r2 = SIZE - i; break; }
        }

        buf = buf.slice(r1, r2);
        SIZE = buf.length;

        const c = new Array(SIZE).fill(0);
        for (let i = 0; i < SIZE; i++) {
            for (let j = 0; j < SIZE - i; j++) {
                c[i] = c[i] + buf[j] * buf[j + i];
            }
        }

        let d = 0; while (c[d] > c[d + 1]) d++;
        let maxVal = -1, maxPos = -1;
        for (let i = d; i < SIZE; i++) {
            if (c[i] > maxVal) { maxVal = c[i]; maxPos = i; }
        }
        let T0 = maxPos;

        // интерполяция
        if (T0 > 0 && T0 < c.length - 1) {
            const x0 = c[T0 - 1], x1 = c[T0], x2 = c[T0 + 1];
            const a = (x0 + x2 - 2 * x1) / 2;
            const b = (x2 - x0) / 2;
            if (a) T0 = T0 - b / (2 * a);
        }
        return sampleRate / T0;
    }

    function updateStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function stop() {
        if (raf) cancelAnimationFrame(raf);
        raf = null;
        if (source) {
            source.disconnect();
            source = null;
        }
        if (micStream) {
            try { micStream.getTracks().forEach(t => t.stop()); } catch {}
            micStream = null;
        }
        // контекст и анализатор оставляем живыми для мобильных
        updateStatus('Остановлено. Нажмите «Запустить».');
    }

    function draw() {
        if (!analyser) return;
        analyser.getFloatTimeDomainData(data);
        // вычисляем RMS для определения тишины
        let rms = 0;
        for (let i = 0; i < data.length; i++) rms += data[i] * data[i];
        rms = Math.sqrt(rms / data.length);

        const rawFreq = autoCorrelate(data, audioCtx.sampleRate);
        const sanitized = sanitizeFreq(rawFreq, rms);
        const now = performance.now();

        let freqToUse = sanitized;
        if (!sanitized || rms < SILENCE_RMS) {
            // fallback к последнему хорошему в течение HOLD_LAST_MS
            if (lastGoodFreq && (now - lastGoodTime) < HOLD_LAST_MS) {
                freqToUse = lastGoodFreq;
            } else {
                freqToUse = null;
            }
        } else {
            lastGoodFreq = sanitized;
            lastGoodTime = now;
        }

        updateUI(freqToUse);
        raf = requestAnimationFrame(draw);
    }

    function median(arr) {
        const a = arr.filter(Number.isFinite).slice().sort((x, y) => x - y);
        if (!a.length) return null;
        const mid = Math.floor(a.length / 2);
        return a.length % 2 ? a[mid] : (a[mid - 1] + a[mid]) / 2;
    }

    function sanitizeFreq(freq, rms) {
        if (!freq) return null;
        if (freq < FREQ_MIN || freq > FREQ_MAX) return null;
        const { cents } = freqToNoteData(freq);
        gateCents.push(cents);
        if (gateCents.length > GATE_WINDOW) gateCents.shift();
        const m = median(gateCents);
        if (m !== null && Math.abs(cents - m) > GATE_JUMP && rms < GATE_RMS) {
            return null; // считаем шумом
        }
        return freq;
    }

    function updateUI(freq) {
        if (!noteMain || !centsEl || !needle || !levelEl || !meterBody) return;
        const now = performance.now();
        if (!freq) {
            noteMain.textContent = '—';
            centsEl.textContent = '— ¢';
            needle.style.transform = 'rotate(0deg)';
            levelEl.textContent = 'Тихо';
            meterBody.classList.remove('tuner-locked');
            updatePro(null);
            return;
        }
        const { name, octave, cents } = freqToNoteData(freq);
        noteMain.textContent = `${name}${octave}`;

        // сглаживание центов
        const target = Math.max(-50, Math.min(50, cents));
        smoothCents = smoothCents * (1 - SMOOTH) + target * SMOOTH;
        centsEl.textContent = `${smoothCents.toFixed(1)} ¢`;
        needle.style.transform = `rotate(${(smoothCents / 50) * 45}deg)`;
        levelEl.textContent = 'ОК';

        // подсветка зелёным при попадании в ноту (целый тон) с удержанием
        if (Math.abs(target) <= LOCK_RANGE) {
            lockTime = now;
        }
        if (now - lockTime < LOCK_HOLD) {
            meterBody.classList.add('tuner-locked');
        } else {
            meterBody.classList.remove('tuner-locked');
        }

        updatePro(freq);
    }

    function updatePro(freq) {
        if (!proNote || !proDelta || !proString) return;
        let target = null;
        if (manualTarget) {
            target = STRINGS.find(s => s.name === manualTarget) || null;
        }

        if (!freq) {
            proNote.textContent = target ? target.name : '—';
            proDelta.textContent = '—';
            proString.textContent = target ? target.label : '—';
            if (proStrings) proStrings.querySelectorAll('.string-row').forEach(r => r.classList.toggle('active', target && r.dataset.string === target.name));
            // даже без сигнала двигаем историю вокруг последнего значения
            drawOsc(lastCentsVal);
            lockStreakStart = null;
            return;
        }
        // выбираем ближайшую или указанную струну
        let best = null;
        let bestCents = null;
        const arr = target ? [target] : STRINGS;
        arr.forEach(s => {
            const cents = 1200 * Math.log(freq / s.freq) / Math.log(2);
            if (best === null || Math.abs(cents) < Math.abs(bestCents)) {
                best = s;
                bestCents = cents;
            }
        });
        if (!best) return;
        // чуть сгладим график, но не основной циферблат
        lastCentsVal = lastCentsVal * (1 - PRO_SMOOTH) + bestCents * PRO_SMOOTH;

        proNote.textContent = best.name;
        proDelta.textContent = `${bestCents.toFixed(1)} ¢`;
        proString.textContent = best.label;

        if (proStrings) {
            proStrings.querySelectorAll('.string-row').forEach(r => {
                r.classList.toggle('active', r.dataset.string === best.name);
            });
            // для устойчивости используем сглаженное значение
            handleStringLock(best.name, lastCentsVal);
        }

        drawOsc(lastCentsVal);
    }

    function handleStringLock(name, cents) {
        if (!manualTarget || manualTarget !== name) {
            lockStreakStart = null;
            return;
        }
        const now = performance.now();
        if (Math.abs(cents) <= LOCK_RANGE_STRING) {
            if (lockStreakStart === null) lockStreakStart = now;
            if (now - lockStreakStart >= LOCK_STRING_TIME) {
                proStrings.querySelectorAll('.string-row').forEach(r => {
                    if (r.dataset.string === name) r.classList.add('tuned');
                });
            }
        } else {
            lockStreakStart = null;
        }
    }

    function drawOsc(cents) {
        if (!proOscCtx || !analyser) return;
        const w = proOsc.width;
        const h = proOsc.height;

        // обновляем историю: null — пауза (обрыв), число — точка
        if (typeof cents === 'number') {
            const clamped = Math.max(-GRAPH_RANGE, Math.min(GRAPH_RANGE, cents));
            centsHistory.push(clamped);
            lastCentsVal = clamped;
        } else {
            centsHistory.push(null);
        }
        if (centsHistory.length > HIST_LEN) centsHistory.shift();

        // работаем с фиксированной длиной истории, чтобы шаг был стабильным
        const history = centsHistory.length < HIST_LEN
            ? Array(HIST_LEN - centsHistory.length).fill(null).concat(centsHistory)
            : centsHistory.slice(-HIST_LEN);

        proOscCtx.clearRect(0, 0, w, h);

        // сетка: 20 клеток вверх/вниз (1 цент на клетку при диапазоне 20)
        proOscCtx.strokeStyle = 'rgba(255,255,255,0.08)';
        proOscCtx.lineWidth = 1;
        for (let i = 1; i <= 20; i++) {
            const norm = i / GRAPH_RANGE; // 1 цент = 1/20
            const yTop = h / 2 - norm * (h / 2) * 0.9;
            const yBottom = h / 2 + norm * (h / 2) * 0.9;
            proOscCtx.beginPath(); proOscCtx.moveTo(0, yTop); proOscCtx.lineTo(w, yTop); proOscCtx.stroke();
            proOscCtx.beginPath(); proOscCtx.moveTo(0, yBottom); proOscCtx.lineTo(w, yBottom); proOscCtx.stroke();
        }

        // горизонтальная линия 0
        proOscCtx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--border') || '#4b5563';
        proOscCtx.lineWidth = 1;
        proOscCtx.beginPath();
        proOscCtx.moveTo(0, h / 2);
        proOscCtx.lineTo(w, h / 2);
        proOscCtx.stroke();

        // вертикальный маркер центра
        proOscCtx.strokeStyle = 'rgba(255,255,255,0.25)';
        proOscCtx.lineWidth = 1;
        proOscCtx.beginPath();
        proOscCtx.moveTo(w / 2, 0);
        proOscCtx.lineTo(w / 2, h);
        proOscCtx.stroke();

        // осциллограмма высоты
        const colorAccent = getComputedStyle(document.documentElement).getPropertyValue('--accent') || '#6c63ff';
        const colorGood = '#21c45c';
        const lastVal = [...history].reverse().find(v => typeof v === 'number');
        const isLock = typeof lastVal === 'number' && Math.abs(lastVal) <= LOCK_RANGE;
        proOscCtx.strokeStyle = isLock ? colorGood : colorAccent;
        proOscCtx.lineWidth = 2;

        const step = w / Math.max(1, history.length - 1);
        let started = false;
        history.forEach((c, i) => {
            const x = i * step;
            if (typeof c !== 'number') { started = false; return; }
            const norm = c / GRAPH_RANGE; // -1..1
            const y = h / 2 - norm * (h / 2) * 0.9;
            if (!started) {
                proOscCtx.beginPath();
                proOscCtx.moveTo(x, y);
                started = true;
            } else {
                proOscCtx.lineTo(x, y);
            }
        });
        if (started) proOscCtx.stroke();
    }

    async function start() {
        try {
            // остановим предыдущий поток, но оставим контекст
            stop();

            // простой запрос — обычно стабильнее на мобильных
            micStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });

            // создаём / резюмируем аудиоконтекст
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') {
                await audioCtx.resume().catch(() => {});
            }

            // создаём анализатор один раз
            if (!analyser) {
                analyser = audioCtx.createAnalyser();
                analyser.fftSize = bufSize * 2;
                data = new Float32Array(analyser.fftSize);
            }

            source = audioCtx.createMediaStreamSource(micStream);
            source.connect(analyser);

            updateStatus('Слушаю... Играйте ноту.');
            draw();
        } catch (e) {
            console.error(e);
            updateStatus('Не удалось получить доступ к микрофону.');
        }
    }
    // автостарт при загрузке
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { bindStringClicks(); start(); });
    } else {
        bindStringClicks();
        start();
    }
})();

