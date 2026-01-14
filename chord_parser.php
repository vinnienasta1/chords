<?php
// Расширенный парсер аккордов
class ChordParser {
    // Универсальный паттерн (расширенные варианты: 7M/M7/Δ и т.п.)
    // Принимает H как валидную ноту (будет заменена на B в normalizeChordLetters)
    private static $chordPattern = '~
        (?:^|\s|\()
        ([A-GH])([#b]?)                                # корень (включая H)
        (                                            # суффикс (необяз.)
            (?:
                maj|maj7|maj9|maj11|maj13|
                ma|M7|M9|M11|M13|7M|9M|11M|13M|Δ|Δ7|Δ9|
                min|m|min7|min9|min11|min13|m7|m9|m11|m13|
                dim|dim7|°|aug|\+|sus|sus2|sus4|
                add|add9|add11|add13|
                6|69|7|9|11|13|
                7b5|7#5|7b9|7#9|7#11|7b13|
                m6|m69|m7b5|m7#5|
                b5|#5|b9|#9|b11|#11|b13|#13
            )?
            [0-9+#b]*
        )?
        (?:/([A-GH][#b]?))?
        (?=\s|$|\)|\()
    ~ix';
    private static $chordPatternWithBrackets = '~\(
        ([A-GH][#b]?
        (?:
            maj|maj7|maj9|maj11|maj13|
            ma|M7|M9|M11|M13|7M|9M|11M|13M|Δ|Δ7|Δ9|
            min|m|min7|min9|min11|min13|m7|m9|m11|m13|
            dim|dim7|°|aug|\+|sus|sus2|sus4|
            add|add9|add11|add13|
            6|69|7|9|11|13|
            7b5|7#5|7b9|7#9|7#11|7b13|
            m6|m69|m7b5|m7#5|
            b5|#5|b9|#9|b11|#11|b13|#13
        )?
        [0-9+#b]*
        (?:/[A-GH][#b]?)?
        )
    \)~ix';

    // Нормализуем визуально похожие кириллические буквы к латинице (С -> C)
    // Также заменяем H на B (немецкая/русская нотация: H = B)
    private static function normalizeChordLetters(string $text): string {
        // Заменяем H на B в аккордах (H, Hm, H7, H# и т.д.)
        // Используем более простое регулярное выражение, которое заменяет H на B
        // когда H является частью аккорда (после начала строки/пробела/скобки, перед суффиксом или концом)
        
        // Сначала заменяем H в басовых нотах (например, C/H -> C/B) - это проще
        $text = preg_replace('~/H([#b]?)(?=\s|$|\))~i', '/B$1', $text);
        
        // Затем заменяем H как начало аккорда
        // Вариант 1: H с суффиксом сразу после (Hm, H7, Hdim и т.д.)
        $text = preg_replace('~(?:^|\s|\()H([#b]?)([majM7minmdim°aug+susadd0-9#b/])~i', 'B$1$2', $text);
        // Вариант 2: H как отдельный аккорд (H, H#, Hb)
        $text = preg_replace('~(?:^|\s|\()H([#b]?)(?=\s|$|\))~i', 'B$1', $text);
        
        // Заменяем кириллические буквы
        return strtr($text, [
            'С' => 'C',
            'с' => 'C',
        ]);
    }

    private static function lineHasBracketChord(string $line): bool {
        $normalized = self::normalizeChordLetters($line);
        return (bool)preg_match(self::$chordPatternWithBrackets, $normalized);
    }
    
    // Нормализация аккорда в единый формат (RootAccidentals + суффикс в нижнем регистре)
    public static function normalizeChord($text) {
        $text = trim($text, " \t\n\r\0\x0B()");
        // Заменяем H на B (немецкая/русская нотация: H = B)
        if (preg_match('~^([Hh])([#b]?)(.*)$~', $text, $hMatch)) {
            $text = 'B' . $hMatch[2] . $hMatch[3];
        }
        // Также заменяем H в басовых нотах
        $text = preg_replace('~/([Hh])([#b]?)$~', '/B$2', $text);
        
        if (!preg_match('~^([A-Ga-g])([#b]?)(.*)$~', $text, $m)) {
            return strtoupper($text);
        }

        $root = strtoupper($m[1]);
        $acc = '';
        if ($m[2] === '#') {
            $acc = '#';
        } elseif (strtolower($m[2]) === 'b') {
            $acc = 'b';
        }

        $rest = $m[3] ?? '';
        $bass = '';
        if (preg_match('~^(.*)/([A-Ga-g][#b]?)$~', $rest, $bm)) {
            $rest = $bm[1];
            $bassRoot = strtoupper($bm[2][0]);
            $bassAcc = '';
            if (isset($bm[2][1])) {
                $bassAcc = ($bm[2][1] === '#') ? '#' : ((strtolower($bm[2][1]) === 'b') ? 'b' : '');
            }
            $bass = '/' . $bassRoot . $bassAcc;
        }

        // Приводим суффикс к нижнему регистру, но больше НЕ меняем его семантику.
        // Это важно, чтобы не искажать аккорды вроде m7 -> maj7.
        $rest = strtolower($rest);
        return $root . $acc . $rest . $bass;
    }

    // Преобразование сервисных меток куплет/припев
    public static function normalizeSections($text) {
        $replacements = [
            'chorus'     => 'Припев',
            'verse'      => 'Куплет',
            'bridge'     => 'Бридж',
            'intro'      => 'Интро',
            'outro'      => 'Аутро',
            'prechorus'  => 'Предприпев',
            'pre-chorus' => 'Предприпев',
            'coda'       => 'Кода',
            'tag'        => 'Тэг',
            'solo'       => 'Соло'
        ];

        return preg_replace_callback('~[\[\(\{<]\s*(chorus|verse)\s*[\]\)\}>]~i', function ($m) use ($replacements) {
            $key = strtolower($m[1]);
            return '[' . ($replacements[$key] ?? $m[1]) . ']';
        }, $text);
    }

    // Определение типа строки
    public static function getLineType($line) {
        $line = trim($line);

        if (empty($line)) {
            return 'empty';
        }

        // Проверяем, содержит ли строка аккорды
        $chords = self::extractChords($line);

        if (empty($chords)) {
            return 'text';
        }

        // Если строка содержит только аккорды и пробелы - это аккордовая строка.
        // Убираем аккорды регуляркой, чтобы не было ложных остаточных "sus"/"maj" при перекрытии имен (G/Gsus2/Gsus4).
        $normalized = self::normalizeChordLetters($line);
        // Сначала убираем аккорды в скобках
        $lineWithoutChords = preg_replace(self::$chordPatternWithBrackets, '', $normalized);
        // Затем убираем аккорды без скобок
        // Используем упрощенный паттерн для удаления (без начального символа, чтобы правильно удалять)
        $simplePattern = '~
        ([A-G])([#b]?)                                # корень
        (                                            # суффикс (необяз.)
            (?:
                maj|maj7|maj9|maj11|maj13|
                ma|M7|M9|M11|M13|7M|9M|11M|13M|Δ|Δ7|Δ9|
                min|m|min7|min9|min11|min13|m7|m9|m11|m13|
                dim|dim7|°|aug|\+|sus|sus2|sus4|
                add|add9|add11|add13|
                6|69|7|9|11|13|
                7b5|7#5|7b9|7#9|7#11|7b13|
                m6|m69|m7b5|m7#5|
                b5|#5|b9|#9|b11|#11|b13|#13
            )?
            [0-9+#b]*
        )?
        (?:/([A-G][#b]?))?
        ~ix';
        $lineWithoutChords = preg_replace($simplePattern, '', $lineWithoutChords);
        // Удаляем пробелы, скобки и другие символы форматирования
        $lineWithoutChords = preg_replace('~[\s\[\]()]+~', '', $lineWithoutChords);

        if (empty($lineWithoutChords)) {
            return 'chords';
        }

        // Если есть и аккорды, и текст - это смешанная строка (считаем текстовой)
        return 'text';
    }

    // Извлечение аккордов из строки

    public static function extractChords($line, $offset = 0) {
        $line = self::normalizeChordLetters($line);
        $chords = [];
        $matches = [];
        $usedPositions = [];
        
        // Сначала ищем аккорды в скобках
        if (preg_match_all(self::$chordPatternWithBrackets, $line, $bracketMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($bracketMatches[1] as $match) {
                $chordText = $match[0];
                if ($chordText !== '' && ctype_lower($chordText[0])) { continue; }
                $position = $match[1] + $offset - 1; // -1 для открывающей скобки
                
                $chords[] = [
                    'text' => $chordText,
                    'position' => $position
                ];
                // Запоминаем позиции, чтобы не дублировать аккорды без скобок
                $usedPositions[$position] = true;
            }
        }
        
        // Затем ищем аккорды без скобок (даже если найдены в скобках, но на других позициях)
        if (preg_match_all(self::$chordPattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $matchIndex => $match) {
                $fullMatch = $match[0];
                // Извлекаем только текст аккорда (без начального пробела/скобки)
                $chordText = trim($fullMatch, " \t\n\r\0\x0B(");
                if ($chordText === '' || ctype_lower($chordText[0])) { continue; }
                
                // Корректируем позицию: убираем начальный символ (пробел или скобка)
                $rawPosition = $match[1];
                // Если матч начинается с пробела или скобки, корректируем позицию
                if (preg_match('/^(?:\s|\()/', $fullMatch)) {
                    $position = $rawPosition + 1 + $offset;
                } else {
                    $position = $rawPosition + $offset;
                }
                
                // Пропускаем, если аккорд уже найден в скобках на этой позиции
                if (isset($usedPositions[$position])) { continue; }
                
                // Проверяем, не находится ли этот аккорд уже в скобках на близкой позиции
                $alreadyFound = false;
                foreach ($chords as $existing) {
                    // Если позиция близка (в пределах длины аккорда), пропускаем
                    if (abs($existing['position'] - $position) < strlen($chordText) + 2) {
                        $alreadyFound = true;
                        break;
                    }
                }
                if ($alreadyFound) { continue; }
                
                $chords[] = [
                    'text' => $chordText,
                    'position' => $position
                ];
            }
        }
        
        return $chords;
    }
    
    // Извлечение всех аккордов из текста с позициями
    public static function extractAllChords($text) {
        // Убираем артефакты вида (a)a)a) / (A)A)A) из источников
        $text = preg_replace('~\([A-Za-z]\)(?:[A-Za-z]\))+~', ' ', $text);
        $chords = [];
        $lines = explode("\n", $text);
        $charPos = 0;
        
        foreach ($lines as $line) {
            $isChordLine = self::getLineType($line) === 'chords';
            $hasBracketChord = self::lineHasBracketChord($line);
            if (!$isChordLine && !$hasBracketChord) {
                $charPos += strlen($line) + 1;
                continue;
            }
            $lineChords = self::extractChords($line, $charPos);
            foreach ($lineChords as $chord) {
                $chords[] = $chord;
            }
            $charPos += strlen($line) + 1; // +1 для символа новой строки
        }
        
        return $chords;
    }
    
    // Замена аккордов в тексте на формат (аккорд)
    public static function replaceChordsWithBrackets($text) {
        // Чистим артефакты вида (a)a)a) / (A)A)A)
        $text = preg_replace('~\([A-Za-z]\)(?:[A-Za-z]\))+~', ' ', $text);
        $text = self::normalizeSections($text);
        $lines = explode("\n", $text);
        $newLines = [];
        
        // Заменяем аккорды с конца каждой строки, чтобы позиции не сбились
        foreach ($lines as $lineIndex => $line) {
            $isChordLine = self::getLineType($line) === 'chords';
            $hasBracketChord = self::lineHasBracketChord($line);
            
            // Извлекаем аккорды из строки
            $lineChords = self::extractChords($line);
            
            // Если строка не определена как аккордовая, но содержит аккорды, все равно обрабатываем
            if (empty($lineChords) && !$isChordLine && !$hasBracketChord) {
                $newLines[] = $line;
                continue;
            }
            
            if (empty($lineChords)) {
                $newLines[] = $line;
                continue;
            }
            
            $newLine = $line;
            
            // Сортируем аккорды по позиции с конца, чтобы позиции не сбились при замене
            usort($lineChords, function($a, $b) {
                return $b['position'] <=> $a['position'];
            });
            
            foreach ($lineChords as $chord) {
                $posInLine = $chord['position'];
                $chordText = $chord['text'];
                
                // Проверяем, не в скобках ли уже аккорд
                $isWrapped = false;
                if ($posInLine >= 0 && $posInLine < strlen($newLine)) {
                    $charBefore = ($posInLine > 0) ? substr($newLine, $posInLine - 1, 1) : '';
                    $charAfter = ($posInLine + strlen($chordText) < strlen($newLine)) ? substr($newLine, $posInLine + strlen($chordText), 1) : '';
                    if ($charBefore === '(' && $charAfter === ')') {
                        $isWrapped = true;
                    }
                }
                
                if (!$isWrapped) {
                    $replacement = "(" . $chordText . ")";
                    // Проверяем, что позиция корректна
                    if ($posInLine >= 0 && $posInLine <= strlen($newLine)) {
                        $actualLength = strlen($chordText);
                        // Проверяем, что на этой позиции действительно аккорд
                        $substr = substr($newLine, $posInLine, min($actualLength, strlen($newLine) - $posInLine));
                        // Сравниваем с учетом пробелов
                        if (trim($substr) === trim($chordText) || $substr === $chordText) {
                            $newLine = substr_replace($newLine, $replacement, $posInLine, $actualLength);
                        } else {
                            // Пробуем найти аккорд в строке по тексту
                            $searchPos = strpos($newLine, $chordText, max(0, $posInLine - 5));
                            if ($searchPos !== false && abs($searchPos - $posInLine) <= 5) {
                                $newLine = substr_replace($newLine, $replacement, $searchPos, strlen($chordText));
                            }
                        }
                    }
                }
            }
            
            $newLines[] = $newLine;
        }
        
        return implode("\n", $newLines);
    }
    
    // Проверка, является ли строка аккордом
    public static function isChord($text) {
        return preg_match(self::$chordPattern, trim($text)) || preg_match(self::$chordPatternWithBrackets, trim($text));
    }
}
