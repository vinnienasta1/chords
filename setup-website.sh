#!/bin/bash

# Скрипт для установки веб-сайта на Ubuntu
# Автор: Auto-generated script
# Версия: 1.0

set -e  # Остановка при ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функция для вывода сообщений
info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Функция для проверки, запущен ли скрипт от root
check_root() {
    if [ "$EUID" -ne 0 ]; then 
        error "Пожалуйста, запустите скрипт с правами root (sudo)"
        exit 1
    fi
}

# Функция проверки Ubuntu
check_ubuntu() {
    info "Проверка операционной системы..."
    
    if [ ! -f /etc/os-release ]; then
        error "Не удалось определить операционную систему"
        exit 1
    fi
    
    . /etc/os-release
    
    if [ "$ID" != "ubuntu" ]; then
        error "Этот скрипт предназначен только для Ubuntu. Обнаружена: $ID"
        exit 1
    fi
    
    success "Обнаружена Ubuntu $VERSION"
}

# Функция проверки установленного веб-сервера
check_existing_webserver() {
    info "Проверка наличия установленных веб-серверов..."
    
    if systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet nginx 2>/dev/null; then
        if systemctl is-active --quiet apache2 2>/dev/null; then
            warning "Обнаружен запущенный Apache2"
        fi
        if systemctl is-active --quiet nginx 2>/dev/null; then
            warning "Обнаружен запущенный Nginx"
        fi
        
        if [ -d "/var/www/html" ] && [ "$(ls -A /var/www/html 2>/dev/null)" ]; then
            warning "Директория /var/www/html не пуста"
        fi
        
        read -p "Продолжить установку? (y/N): " continue_install
        if [[ ! "$continue_install" =~ ^[Yy]$ ]]; then
            info "Установка отменена пользователем"
            exit 0
        fi
    else
        success "Установленные веб-серверы не обнаружены"
    fi
}

# Функция приветствия
greet_user() {
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              Установщик веб-сайта                         ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    info "Добро пожаловать! Этот скрипт поможет вам установить и настроить веб-сайт."
    echo ""
}

# Функция запроса переменных
get_variables() {
    echo ""
    info "Пожалуйста, предоставьте следующую информацию:"
    echo ""
    
    # Выбор веб-сервера
    read -p "Выберите веб-сервер (nginx/apache, по умолчанию: nginx): " WEBSERVER
    WEBSERVER=${WEBSERVER:-nginx}
    WEBSERVER=$(echo "$WEBSERVER" | tr '[:upper:]' '[:lower:]')
    
    if [ "$WEBSERVER" != "nginx" ] && [ "$WEBSERVER" != "apache" ] && [ "$WEBSERVER" != "apache2" ]; then
        warning "Неверный выбор. Используется nginx"
        WEBSERVER="nginx"
    fi
    
    # Настройки базы данных
    echo ""
    info "Настройки подключения к базе данных:"
    read -p "Хост БД (по умолчанию: localhost): " DB_HOST
    DB_HOST=${DB_HOST:-localhost}
    
    read -p "Порт БД (по умолчанию: 3306): " DB_PORT
    DB_PORT=${DB_PORT:-3306}
    
    read -p "Пользователь БД: " DB_USER
    if [ -z "$DB_USER" ]; then
        error "Пользователь БД не может быть пустым!"
        exit 1
    fi
    
    while true; do
        read -sp "Пароль пользователя БД: " DB_PASSWORD
        echo ""
        if [ -z "$DB_PASSWORD" ]; then
            error "Пароль не может быть пустым!"
            continue
        fi
        break
    done
    
    read -p "Имя базы данных (по умолчанию: chords): " DB_NAME
    DB_NAME=${DB_NAME:-chords}
    
    # Broadcast токен
    echo ""
    while true; do
        read -sp "Введите Broadcast Token (BROADCAST_TOKEN): " BROADCAST_TOKEN
        echo ""
        if [ -z "$BROADCAST_TOKEN" ]; then
            error "Broadcast Token не может быть пустым!"
            continue
        fi
        read -sp "Подтвердите Broadcast Token: " BROADCAST_TOKEN_CONFIRM
        echo ""
        if [ "$BROADCAST_TOKEN" != "$BROADCAST_TOKEN_CONFIRM" ]; then
            error "Токены не совпадают! Попробуйте снова."
            continue
        fi
        break
    done
    
    # URL бота
    read -p "URL Telegram бота API (например: http://192.168.1.100:8080): " BOT_API_URL
    if [ -z "$BOT_API_URL" ]; then
        warning "URL бота не указан. Можно будет изменить позже."
    fi
    
    # Путь к коду сайта
    echo ""
    info "Источник кода сайта:"
    echo "  1) URL репозитория GitHub (например: https://github.com/user/repo.git)"
    echo "  2) Локальный путь к директории с кодом"
    echo "  3) Пропустить (код нужно будет добавить вручную)"
    read -p "Выберите вариант (1-3, по умолчанию: 1): " SITE_SOURCE_TYPE
    SITE_SOURCE_TYPE=${SITE_SOURCE_TYPE:-1}
    
    case $SITE_SOURCE_TYPE in
        1)
            read -p "Введите URL репозитория GitHub (по умолчанию: https://github.com/vinnienasta1/chords.git): " SITE_SOURCE
            SITE_SOURCE=${SITE_SOURCE:-"https://github.com/vinnienasta1/chords.git"}
            ;;
        2)
            read -p "Введите путь к директории с кодом: " SITE_SOURCE
            ;;
        3)
            SITE_SOURCE=""
            warning "Код сайта нужно будет добавить вручную после установки"
            ;;
        *)
            SITE_SOURCE="https://github.com/vinnienasta1/chords.git"
            warning "Неверный выбор. Используется репозиторий по умолчанию"
            ;;
    esac
    
    echo ""
    success "Все переменные получены!"
}

# Функция установки обновлений
update_system() {
    info "Обновление списка пакетов..."
    apt-get update -qq
    
    info "Установка обновлений системы..."
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -y -qq
    
    success "Система обновлена"
}

# Функция установки веб-сервера и PHP
install_webserver_php() {
    info "Установка веб-сервера и PHP..."
    
    if [ "$WEBSERVER" = "nginx" ]; then
        # Установка Nginx
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            nginx \
            > /dev/null 2>&1
        
        # Определение версии PHP
        PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' | head -1 || echo "8.3")
        
        # Установка PHP и необходимых модулей
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            php${PHP_VERSION} \
            php${PHP_VERSION}-fpm \
            php${PHP_VERSION}-mysql \
            php${PHP_VERSION}-mbstring \
            php${PHP_VERSION}-xml \
            php${PHP_VERSION}-curl \
            php${PHP_VERSION}-gd \
            php${PHP_VERSION}-zip \
            php${PHP_VERSION}-bcmath \
            > /dev/null 2>&1 || \
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            php \
            php-fpm \
            php-mysql \
            php-mbstring \
            php-xml \
            php-curl \
            php-gd \
            php-zip \
            php-bcmath \
            > /dev/null 2>&1
        
        success "Nginx и PHP установлены"
    else
        # Установка Apache
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            apache2 \
            libapache2-mod-php \
            > /dev/null 2>&1
        
        # Определение версии PHP
        PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' | head -1 || echo "8.3")
        
        # Установка PHP модулей
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            php${PHP_VERSION} \
            php${PHP_VERSION}-mysql \
            php${PHP_VERSION}-mbstring \
            php${PHP_VERSION}-xml \
            php${PHP_VERSION}-curl \
            php${PHP_VERSION}-gd \
            php${PHP_VERSION}-zip \
            php${PHP_VERSION}-bcmath \
            > /dev/null 2>&1 || \
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            php \
            php-mysql \
            php-mbstring \
            php-xml \
            php-curl \
            php-gd \
            php-zip \
            php-bcmath \
            > /dev/null 2>&1
        
        success "Apache и PHP установлены"
    fi
}

# Функция настройки Nginx
configure_nginx() {
    info "Настройка Nginx..."
    
    # Определение версии PHP
    PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' | head -1 || echo "8.3")
    PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
    
    # Проверка существования сокета
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        # Попытка найти правильный сокет
        PHP_FPM_SOCK=$(find /run/php -name "*.sock" 2>/dev/null | head -1)
        if [ -z "$PHP_FPM_SOCK" ]; then
            PHP_FPM_SOCK="/run/php/php8.3-fpm.sock"
        fi
    fi
    
    # Создание конфигурации сайта
    cat > /etc/nginx/sites-available/chords <<NGINX_CONF
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    root /var/www/html;
    index index.php index.html index.htm;

    server_name _;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ /\.ht {
        deny all;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }
}
NGINX_CONF
    
    # Активация сайта
    ln -sf /etc/nginx/sites-available/chords /etc/nginx/sites-enabled/
    
    # Удаление дефолтного сайта, если есть
    rm -f /etc/nginx/sites-enabled/default
    
    # Проверка конфигурации
    nginx -t
    
    success "Nginx настроен"
}

# Функция настройки Apache
configure_apache() {
    info "Настройка Apache..."
    
    # Включение необходимых модулей
    a2enmod rewrite
    a2enmod php
    
    # Создание конфигурации сайта
    cat > /etc/apache2/sites-available/chords.conf <<APACHE_CONF
<VirtualHost *:80>
    ServerName _
    DocumentRoot /var/www/html
    
    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/chords_error.log
    CustomLog \${APACHE_LOG_DIR}/chords_access.log combined
</VirtualHost>
APACHE_CONF
    
    # Активация сайта
    a2ensite chords.conf
    a2dissite 000-default.conf 2>/dev/null || true
    
    success "Apache настроен"
}

# Функция создания структуры сайта
create_site_structure() {
    info "Создание структуры сайта..."
    
    # Создание директории
    mkdir -p /var/www/html
    cd /var/www/html
    
    # Получение кода сайта
    if [ -n "$SITE_SOURCE" ]; then
        if [[ "$SITE_SOURCE" =~ ^https?:// ]] || [[ "$SITE_SOURCE" =~ ^git@ ]]; then
            # Это URL репозитория
            info "Клонирование репозитория..."
            if [ -d ".git" ]; then
                git pull || true
            else
                rm -rf /tmp/chords-site-repo 2>/dev/null || true
                git clone "$SITE_SOURCE" /tmp/chords-site-repo 2>/dev/null
                if [ -d "/tmp/chords-site-repo" ] && [ "$(ls -A /tmp/chords-site-repo)" ]; then
                    # Сохранение .git, если нужно
                    if [ -d ".git" ]; then
                        mv .git .git.backup 2>/dev/null || true
                    fi
                    cp -r /tmp/chords-site-repo/* /var/www/html/ 2>/dev/null || true
                    cp -r /tmp/chords-site-repo/.[!.]* /var/www/html/ 2>/dev/null || true
                    rm -rf /tmp/chords-site-repo
                    success "Код сайта склонирован из репозитория"
                else
                    error "Не удалось клонировать репозиторий. Проверьте URL и доступ."
                    exit 1
                fi
            fi
        elif [ -d "$SITE_SOURCE" ]; then
            # Это директория
            info "Копирование файлов из директории..."
            cp -r "$SITE_SOURCE"/* /var/www/html/ 2>/dev/null || true
            cp -r "$SITE_SOURCE"/.[!.]* /var/www/html/ 2>/dev/null || true
            success "Файлы скопированы из директории"
        fi
    else
        warning "Код сайта не предоставлен. Создана базовая структура."
        warning "Пожалуйста, добавьте код сайта в /var/www/html/ после установки."
    fi
    
    # Установка прав
    chown -R www-data:www-data /var/www/html
    find /var/www/html -type d -exec chmod 755 {} \;
    find /var/www/html -type f -exec chmod 644 {} \;
    
    success "Структура сайта создана"
}

# Функция создания db.php
create_db_config() {
    info "Создание файла конфигурации базы данных..."
    
    # Проверка наличия db.example.php
    if [ -f "/var/www/html/db.example.php" ]; then
        # Копирование примера
        cp /var/www/html/db.example.php /var/www/html/db.php
        
        # Если db.example.php использует переменные окружения, они будут установлены в PHP-FPM
        success "Файл db.php создан из db.example.php"
    else
        # Создание базового db.php
        cat > /var/www/html/db.php <<DB_PHP
<?php
class DB {
    private static \$db = null;

    public static function getConnection() {
        if (self::\$db === null) {
            \$host = getenv('DB_HOST') ?: '$DB_HOST';
            \$dbName = getenv('DB_NAME') ?: '$DB_NAME';
            \$user = getenv('DB_USER') ?: '$DB_USER';
            \$pass = getenv('DB_PASS') ?: '$DB_PASSWORD';

            \$dsn = "mysql:host={\$host};dbname={\$dbName};charset=utf8mb4";
            self::\$db = new PDO(\$dsn, \$user, \$pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::\$db;
    }
}
DB_PHP
        success "Файл db.php создан"
    fi
    
    chown www-data:www-data /var/www/html/db.php
    chmod 644 /var/www/html/db.php
}

# Функция настройки PHP-FPM
configure_php_fpm() {
    info "Настройка PHP-FPM..."
    
    # Определение версии PHP
    PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' | head -1 || echo "8.3")
    PHP_FPM_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    
    # Проверка существования конфига
    if [ ! -f "$PHP_FPM_CONF" ]; then
        # Поиск конфига
        PHP_FPM_CONF=$(find /etc/php -name "www.conf" -path "*/fpm/pool.d/*" 2>/dev/null | head -1)
        if [ -z "$PHP_FPM_CONF" ]; then
            warning "Не удалось найти конфигурацию PHP-FPM. Переменные окружения нужно будет настроить вручную."
            return
        fi
    fi
    
    # Добавление переменных окружения, если их еще нет
    if ! grep -q "env\[BROADCAST_TOKEN\]" "$PHP_FPM_CONF"; then
        # Находим секцию [www] и добавляем после неё
        sed -i "/^\[www\]/a env[BROADCAST_TOKEN] = '$BROADCAST_TOKEN'" "$PHP_FPM_CONF"
    else
        # Обновляем существующее значение
        sed -i "s|env\[BROADCAST_TOKEN\].*|env[BROADCAST_TOKEN] = '$BROADCAST_TOKEN'|" "$PHP_FPM_CONF"
    fi
    
    # Добавление переменных БД
    if ! grep -q "env\[DB_HOST\]" "$PHP_FPM_CONF"; then
        sed -i "/^env\[BROADCAST_TOKEN\]/a env[DB_HOST] = '$DB_HOST'" "$PHP_FPM_CONF"
    else
        sed -i "s|env\[DB_HOST\].*|env[DB_HOST] = '$DB_HOST'|" "$PHP_FPM_CONF"
    fi
    
    if ! grep -q "env\[DB_NAME\]" "$PHP_FPM_CONF"; then
        sed -i "/^env\[DB_HOST\]/a env[DB_NAME] = '$DB_NAME'" "$PHP_FPM_CONF"
    else
        sed -i "s|env\[DB_NAME\].*|env[DB_NAME] = '$DB_NAME'|" "$PHP_FPM_CONF"
    fi
    
    if ! grep -q "env\[DB_USER\]" "$PHP_FPM_CONF"; then
        sed -i "/^env\[DB_NAME\]/a env[DB_USER] = '$DB_USER'" "$PHP_FPM_CONF"
    else
        sed -i "s|env\[DB_USER\].*|env[DB_USER] = '$DB_USER'|" "$PHP_FPM_CONF"
    fi
    
    if ! grep -q "env\[DB_PASS\]" "$PHP_FPM_CONF"; then
        sed -i "/^env\[DB_USER\]/a env[DB_PASS] = '$DB_PASSWORD'" "$PHP_FPM_CONF"
    else
        sed -i "s|env\[DB_PASS\].*|env[DB_PASS] = '$DB_PASSWORD'|" "$PHP_FPM_CONF"
    fi
    
    # Перезапуск PHP-FPM
    systemctl restart php${PHP_VERSION}-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
    
    success "PHP-FPM настроен"
}

# Функция запуска веб-сервера
start_webserver() {
    info "Запуск веб-сервера..."
    
    if [ "$WEBSERVER" = "nginx" ]; then
        systemctl restart nginx
        systemctl enable nginx
        systemctl restart php-fpm 2>/dev/null || systemctl restart php8.3-fpm 2>/dev/null || true
    else
        systemctl restart apache2
        systemctl enable apache2
    fi
    
    # Небольшая задержка
    sleep 2
    
    if [ "$WEBSERVER" = "nginx" ]; then
        if systemctl is-active --quiet nginx; then
            success "Nginx успешно запущен"
        else
            error "Не удалось запустить Nginx. Проверьте логи: journalctl -u nginx"
        fi
    else
        if systemctl is-active --quiet apache2; then
            success "Apache успешно запущен"
        else
            error "Не удалось запустить Apache. Проверьте логи: journalctl -u apache2"
        fi
    fi
}

# Функция проверки подключения к БД
test_db_connection() {
    info "Проверка подключения к базе данных..."
    
    PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' | head -1 || echo "8.3")
    
    php <<PHP_TEST
<?php
try {
    \$host = '$DB_HOST';
    \$dbName = '$DB_NAME';
    \$user = '$DB_USER';
    \$pass = '$DB_PASSWORD';
    
    \$dsn = "mysql:host={\$host};dbname={\$dbName};charset=utf8mb4";
    \$pdo = new PDO(\$dsn, \$user, \$pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "SUCCESS\n";
} catch (PDOException \$e) {
    echo "ERROR: " . \$e->getMessage() . "\n";
    exit(1);
}
?>
PHP_TEST
    
    if [ $? -eq 0 ]; then
        success "Подключение к базе данных успешно"
    else
        warning "Не удалось подключиться к базе данных. Проверьте настройки."
    fi
}

# Функция вывода отчета
print_report() {
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              УСТАНОВКА ЗАВЕРШЕНА УСПЕШНО!                  ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "ИНФОРМАЦИЯ О САЙТЕ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}Директория:${NC} /var/www/html"
    echo -e "  ${YELLOW}Веб-сервер:${NC} $WEBSERVER"
    echo -e "  ${YELLOW}Конфигурация БД:${NC} /var/www/html/db.php"
    echo ""
    
    SERVER_IP=$(hostname -I | awk '{print $1}')
    echo -e "  ${YELLOW}URL сайта:${NC} http://$SERVER_IP"
    echo -e "  ${YELLOW}Локальный доступ:${NC} http://localhost"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "НАСТРОЙКИ БАЗЫ ДАННЫХ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}Хост:${NC} $DB_HOST"
    echo -e "  ${YELLOW}Порт:${NC} $DB_PORT"
    echo -e "  ${YELLOW}База данных:${NC} $DB_NAME"
    echo -e "  ${YELLOW}Пользователь:${NC} $DB_USER"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "НАСТРОЙКИ PHP-FPM:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' | head -1 || echo "8.3")
    echo -e "  ${YELLOW}Версия PHP:${NC} $PHP_VERSION"
    echo -e "  ${YELLOW}Конфигурация:${NC} /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    echo -e "  ${YELLOW}Broadcast Token:${NC} $BROADCAST_TOKEN"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "ПОЛЕЗНЫЕ КОМАНДЫ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    if [ "$WEBSERVER" = "nginx" ]; then
        echo -e "  ${YELLOW}Статус Nginx:${NC}"
        echo -e "    systemctl status nginx"
        echo ""
        echo -e "  ${YELLOW}Перезапуск Nginx:${NC}"
        echo -e "    systemctl restart nginx"
        echo ""
    else
        echo -e "  ${YELLOW}Статус Apache:${NC}"
        echo -e "    systemctl status apache2"
        echo ""
        echo -e "  ${YELLOW}Перезапуск Apache:${NC}"
        echo -e "    systemctl restart apache2"
        echo ""
    fi
    
    echo -e "  ${YELLOW}Статус PHP-FPM:${NC}"
    echo -e "    systemctl status php${PHP_VERSION}-fpm"
    echo ""
    echo -e "  ${YELLOW}Перезапуск PHP-FPM:${NC}"
    echo -e "    systemctl restart php${PHP_VERSION}-fpm"
    echo ""
    echo -e "  ${YELLOW}Просмотр логов Nginx:${NC}"
    echo -e "    tail -f /var/log/nginx/error.log"
    echo ""
    echo -e "  ${YELLOW}Просмотр логов PHP-FPM:${NC}"
    echo -e "    tail -f /var/log/php${PHP_VERSION}-fpm.log"
    echo ""
    echo -e "  ${YELLOW}Редактирование конфигурации БД:${NC}"
    echo -e "    nano /var/www/html/db.php"
    echo ""
    echo -e "  ${YELLOW}Редактирование PHP-FPM конфигурации:${NC}"
    echo -e "    nano /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "ВАЖНЫЕ ЗАМЕЧАНИЯ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}1.${NC} Убедитесь, что BROADCAST_TOKEN совпадает с токеном в Telegram боте"
    echo -e "  ${YELLOW}2.${NC} Проверьте, что база данных доступна с этого сервера"
    echo -e "  ${YELLOW}3.${NC} Убедитесь, что порт 80 открыт в firewall"
    echo -e "  ${YELLOW}4.${NC} После изменения PHP-FPM конфигурации выполните: systemctl restart php${PHP_VERSION}-fpm"
    echo -e "  ${YELLOW}5.${NC} Проверьте права доступа к файлам: chown -R www-data:www-data /var/www/html"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    echo ""
    success "Все готово! Хорошего дня! 🎉"
    echo ""
}

# Основная функция
main() {
    check_root
    check_ubuntu
    check_existing_webserver
    greet_user
    get_variables
    
    echo ""
    info "Начинаем установку..."
    echo ""
    
    update_system
    install_webserver_php
    
    if [ "$WEBSERVER" = "nginx" ]; then
        configure_nginx
    else
        configure_apache
    fi
    
    create_site_structure
    create_db_config
    configure_php_fpm
    test_db_connection
    start_webserver
    
    print_report
}

# Запуск основной функции
main
