# Структура includes/

Этот каталог содержит переиспользуемые компоненты для всех страниц проекта.

## Файлы

### `auth_required.php`
Проверяет аутентификацию пользователя и редиректит на страницу входа при необходимости.

**Использование:**
```php
$username = require __DIR__ . '/includes/auth_required.php';
```

**Возвращает:** `string` - имя пользователя из сессии

---

### `user_helper.php`
Содержит функцию для загрузки данных текущего пользователя из базы данных.

**Функция:** `getCurrentUser(string $username): array`

**Использование:**
```php
require_once __DIR__ . '/includes/user_helper.php';
$userData = getCurrentUser($username);
extract($userData);
```

**Возвращает массив:**
- `user` - полные данные пользователя из БД
- `isAdmin` - `bool` - является ли пользователь админом
- `hasAvatar` - `bool` - есть ли у пользователя аватар
- `avatarUrl` - `string|null` - URL аватара с cache-busting
- `displayName` - `string` - отображаемое имя (full_name или username)
- `initial` - `string` - первая буква имени для placeholder

---

### `layout_helper.php`
Содержит функции для рендеринга общих элементов layout (head, sidebar, scripts).

**Функции:**

#### `renderHead(string $title, array $additionalStyles = []): void`
Рендерит HTML `<head>` секцию с мета-тегами, favicon, и CSS.

**Пример:**
```php
renderHead('Название страницы', ['/custom.css']);
```

#### `renderSidebar(array $userData, string $activePage = ''): void`
Рендерит боковое меню с навигацией и user-block.

**Параметры:**
- `$userData` - массив из `getCurrentUser()`
- `$activePage` - активная страница: 'index', 'songs', 'setlists', 'admin'

**Пример:**
```php
renderSidebar($userData, 'songs');
```

#### `renderLayoutScripts(): void`
Рендерит общие JavaScript скрипты для sidebar toggle и user menu.

**Пример:**
```php
renderLayoutScripts();
```

---

### `auth_helper.php`
Содержит дополнительные функции для работы с правами доступа.

**Функции:**

#### `isCurrentUserAdmin(): bool`
Проверяет, является ли текущий пользователь администратором.

#### `requireAdmin(string $redirectTo = '/'): void`
Требует права администратора, иначе редирект.

**Пример:**
```php
require_once __DIR__ . '/includes/auth_helper.php';
requireAdmin(); // Редирект на / если не админ
```

#### `getCurrentUserId(): ?int`
Получает ID текущего пользователя.

#### `isCurrentUser(int $userId): bool`
Проверяет, является ли указанный пользователь текущим.

---

## JavaScript модули

### `js/app.js`
Общий JavaScript для всех страниц (sidebar toggle, user menu).
Подключается автоматически через `renderLayoutScripts()`.

### `js/filters.js`
JavaScript для фильтров и поиска на странице songs.php.
Подключается на странице songs.php.

### `js/chord-player.js`
JavaScript для транспонирования аккордов, автопрокрутки и настроек просмотра.
Подключается на странице songs.php при просмотре песни.

---

## Типичная структура страницы

```php
<?php
$username = require __DIR__ . '/includes/auth_required.php';
require_once __DIR__ . '/includes/user_helper.php';
require_once __DIR__ . '/includes/layout_helper.php';

$userData = getCurrentUser($username);
extract($userData);

// Ваша логика страницы...

renderHead('Заголовок страницы');
?>
<body>
    <style>
        /* Специфичные стили страницы */
    </style>
    
    <div class="layout">
        <?php renderSidebar($userData, 'index'); ?>
        
        <main class="content">
            <!-- Контент страницы -->
        </main>
    </div>
    
    <?php renderLayoutScripts(); ?>
    <script>
        // Специфичный JS страницы
    </script>
</body>
</html>
```

---

## Преимущества новой структуры

1. **DRY**: Код не дублируется на каждой странице
2. **Поддержка**: Изменения в одном месте применяются ко всем страницам
3. **Читаемость**: Страницы стали короче и понятнее
4. **Безопасность**: Централизованная аутентификация
5. **Производительность**: Общие стили кешируются браузером
