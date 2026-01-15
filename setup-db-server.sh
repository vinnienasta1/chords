#!/bin/bash

# Скрипт для установки сервера базы данных MariaDB на Ubuntu
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

# Функция проверки установленных БД пакетов
check_existing_db() {
    info "Проверка наличия установленных пакетов баз данных..."
    
    if dpkg -l | grep -qE "mariadb-server|mysql-server|postgresql"; then
        warning "Обнаружены установленные пакеты базы данных:"
        dpkg -l | grep -E "mariadb-server|mysql-server|postgresql" | awk '{print "  - " $2}'
        
        read -p "Продолжить установку? Это может вызвать конфликты. (y/N): " continue_install
        if [[ ! "$continue_install" =~ ^[Yy]$ ]]; then
            info "Установка отменена пользователем"
            exit 0
        fi
    else
        success "Установленные пакеты БД не обнаружены"
    fi
}

# Функция приветствия
greet_user() {
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║     Установщик сервера базы данных MariaDB               ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    info "Добро пожаловать! Этот скрипт поможет вам установить и настроить MariaDB сервер."
    echo ""
}

# Функция запроса переменных
get_variables() {
    echo ""
    info "Пожалуйста, предоставьте следующую информацию:"
    echo ""
    
    # Пароль root для MariaDB
    while true; do
        read -sp "Введите пароль для root пользователя MariaDB: " DB_ROOT_PASSWORD
        echo ""
        if [ -z "$DB_ROOT_PASSWORD" ]; then
            error "Пароль не может быть пустым!"
            continue
        fi
        read -sp "Подтвердите пароль: " DB_ROOT_PASSWORD_CONFIRM
        echo ""
        if [ "$DB_ROOT_PASSWORD" != "$DB_ROOT_PASSWORD_CONFIRM" ]; then
            error "Пароли не совпадают! Попробуйте снова."
            continue
        fi
        break
    done
    
    # Имя базы данных
    read -p "Введите имя базы данных (по умолчанию: chords): " DB_NAME
    DB_NAME=${DB_NAME:-chords}
    
    # Пользователь базы данных
    read -p "Введите имя пользователя БД (по умолчанию: dbuser): " DB_USER
    DB_USER=${DB_USER:-dbuser}
    
    # Пароль пользователя БД
    while true; do
        read -sp "Введите пароль для пользователя $DB_USER: " DB_USER_PASSWORD
        echo ""
        if [ -z "$DB_USER_PASSWORD" ]; then
            error "Пароль не может быть пустым!"
            continue
        fi
        read -sp "Подтвердите пароль: " DB_USER_PASSWORD_CONFIRM
        echo ""
        if [ "$DB_USER_PASSWORD" != "$DB_USER_PASSWORD_CONFIRM" ]; then
            error "Пароли не совпадают! Попробуйте снова."
            continue
        fi
        break
    done
    
    # Хост для подключения
    read -p "Разрешить удаленные подключения? (y/N): " ALLOW_REMOTE
    if [[ "$ALLOW_REMOTE" =~ ^[Yy]$ ]]; then
        BIND_ADDRESS="0.0.0.0"
        info "БД будет доступна для удаленных подключений"
    else
        BIND_ADDRESS="127.0.0.1"
        info "БД будет доступна только локально"
    fi
    
    # Порт (опционально)
    read -p "Порт для MariaDB (по умолчанию: 3306): " DB_PORT
    DB_PORT=${DB_PORT:-3306}
    
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

# Функция установки MariaDB
install_mariadb() {
    info "Установка MariaDB сервера..."
    
    # Установка без интерактивных запросов
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        mariadb-server \
        mariadb-client \
        > /dev/null 2>&1
    
    success "MariaDB установлен"
}

# Функция настройки MariaDB
configure_mariadb() {
    info "Настройка MariaDB..."
    
    # Остановка службы для настройки
    systemctl stop mariadb 2>/dev/null || true
    
    # Настройка bind-address
    if [ -f /etc/mysql/mariadb.conf.d/50-server.cnf ]; then
        sed -i "s/^bind-address.*/bind-address = $BIND_ADDRESS/" /etc/mysql/mariadb.conf.d/50-server.cnf
    fi
    
    # Запуск службы
    systemctl start mariadb
    systemctl enable mariadb
    
    # Безопасная установка root пароля
    info "Установка пароля root..."
    sleep 3  # Даем время серверу запуститься
    
    # Пробуем подключиться без пароля (для новой установки)
    if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
        # Установка пароля для новой установки
        mysql -u root <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_ROOT_PASSWORD';
FLUSH PRIVILEGES;
EOF
        success "Пароль root установлен"
    else
        warning "Не удалось подключиться к MariaDB без пароля. Возможно, пароль уже установлен."
        warning "Попытка установить новый пароль через mysql..."
        # Пробуем через mysql (скрипт запущен от root)
        mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_ROOT_PASSWORD';" 2>/dev/null && \
        mysql -e "FLUSH PRIVILEGES;" 2>/dev/null && \
        success "Пароль root обновлен" || \
        warning "Не удалось установить пароль автоматически. Возможно, потребуется ручная настройка."
    fi
    
    # Удаление анонимных пользователей и тестовой БД
    mysql -u root -p"$DB_ROOT_PASSWORD" <<EOF
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF
    
    success "MariaDB настроен"
}

# Функция создания структуры базы данных
create_database_structure() {
    info "Создание структуры базы данных (таблицы, индексы, внешние ключи)..."
    
    mysql -u root -p"$DB_ROOT_PASSWORD" "$DB_NAME" <<'DBSTRUCT'
-- Таблица песен
CREATE TABLE IF NOT EXISTS `songs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `artist` varchar(255) DEFAULT NULL,
  `cap` text DEFAULT NULL,
  `first_note` varchar(50) DEFAULT NULL,
  `skill_stars` int(11) DEFAULT 0,
  `popularity_stars` int(11) DEFAULT 0,
  `locale` varchar(10) DEFAULT NULL,
  `lyrics` longtext DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_songs_artist` (`artist`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Таблица аккордов
CREATE TABLE IF NOT EXISTS `chords` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `song_id` int(11) NOT NULL,
  `chord_text` text NOT NULL,
  `position` int(11) DEFAULT 0,
  `char_position` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chords_song_id` (`song_id`),
  CONSTRAINT `fk_chords_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Таблица сет-листов
CREATE TABLE IF NOT EXISTS `setlists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Таблица элементов сет-листа
CREATE TABLE IF NOT EXISTS `setlist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setlist_id` int(11) NOT NULL,
  `song_id` int(11) DEFAULT NULL,
  `block_index` int(11) DEFAULT 1,
  `position` int(11) DEFAULT 0,
  `checked` tinyint(1) DEFAULT 0,
  `comment` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_setlist_items_setlist` (`setlist_id`),
  KEY `fk_setlist_items_song` (`song_id`),
  CONSTRAINT `fk_setlist_items_setlist` FOREIGN KEY (`setlist_id`) REFERENCES `setlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_setlist_items_song` FOREIGN KEY (`song_id`) REFERENCES `songs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Таблица комментариев к сет-листам
CREATE TABLE IF NOT EXISTS `setlist_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setlist_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_setlist_comments_setlist` (`setlist_id`),
  CONSTRAINT `fk_setlist_comments_setlist` FOREIGN KEY (`setlist_id`) REFERENCES `setlists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Таблица пользователей
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password_hash` text NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `full_name` varchar(255) DEFAULT '',
  `avatar_path` varchar(255) DEFAULT NULL,
  `avatar_data` longblob DEFAULT NULL,
  `avatar_mime` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
DBSTRUCT
    
    success "Структура базы данных создана"
}

# Функция создания базы данных и пользователя
create_database() {
    info "Создание базы данных и пользователя..."
    
    mysql -u root -p"$DB_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_USER_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
EOF

    if [[ "$ALLOW_REMOTE" =~ ^[Yy]$ ]]; then
        mysql -u root -p"$DB_ROOT_PASSWORD" <<EOF
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_USER_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
EOF
    fi
    
    success "База данных и пользователь созданы"
    
    # Создание структуры базы данных
    create_database_structure
}

# Функция установки phpMyAdmin
install_phpmyadmin() {
    info "Установка phpMyAdmin..."
    
    # Установка необходимых пакетов
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        php \
        php-mysql \
        php-mbstring \
        php-zip \
        php-gd \
        php-json \
        php-curl \
        apache2 \
        > /dev/null 2>&1
    
    # Установка phpMyAdmin
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq phpmyadmin > /dev/null 2>&1
    
    # Настройка Apache для phpMyAdmin
    if [ ! -f /etc/apache2/conf-available/phpmyadmin.conf ]; then
        ln -s /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf 2>/dev/null || true
    fi
    a2enconf phpmyadmin > /dev/null 2>&1 || true
    a2enmod rewrite > /dev/null 2>&1 || true
    
    # Настройка phpMyAdmin для работы с нашим пользователем
    phpmyadmin_user="$DB_USER"
    phpmyadmin_password="$DB_USER_PASSWORD"
    
    mysql -u root -p"$DB_ROOT_PASSWORD" <<EOF
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$phpmyadmin_user'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    systemctl restart apache2
    systemctl enable apache2
    
    # Получение IP адреса
    SERVER_IP=$(hostname -I | awk '{print $1}')
    
    success "phpMyAdmin установлен"
}

# Функция вывода отчета
print_report() {
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              УСТАНОВКА ЗАВЕРШЕНА УСПЕШНО!                  ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "ДАННЫЕ ДЛЯ ПОДКЛЮЧЕНИЯ К БАЗЕ ДАННЫХ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}Хост:${NC} localhost (или IP сервера для удаленных подключений)"
    echo -e "  ${YELLOW}Порт:${NC} $DB_PORT"
    echo -e "  ${YELLOW}База данных:${NC} $DB_NAME"
    echo ""
    echo -e "  ${YELLOW}Root пользователь:${NC}"
    echo -e "    Логин: root"
    echo -e "    Пароль: $DB_ROOT_PASSWORD"
    echo ""
    echo -e "  ${YELLOW}Пользователь БД:${NC}"
    echo -e "    Логин: $DB_USER"
    echo -e "    Пароль: $DB_USER_PASSWORD"
    echo ""
    
    if [ "$INSTALL_PHPMYADMIN" = "yes" ]; then
        SERVER_IP=$(hostname -I | awk '{print $1}')
        info "════════════════════════════════════════════════════════════"
        info "ДОСТУП К PHPMYADMIN:"
        info "════════════════════════════════════════════════════════════"
        echo ""
        echo -e "  ${YELLOW}URL:${NC} http://$SERVER_IP/phpmyadmin"
        echo -e "  ${YELLOW}Локальный доступ:${NC} http://localhost/phpmyadmin"
        echo ""
        echo -e "  ${YELLOW}Данные для входа:${NC}"
        echo -e "    Логин: $DB_USER"
        echo -e "    Пароль: $DB_USER_PASSWORD"
        echo ""
    fi
    
    info "════════════════════════════════════════════════════════════"
    info "ПОЛЕЗНЫЕ КОМАНДЫ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}Подключение к БД:${NC}"
    echo -e "    mysql -u $DB_USER -p$DB_USER_PASSWORD $DB_NAME"
    echo ""
    echo -e "  ${YELLOW}Статус MariaDB:${NC}"
    echo -e "    systemctl status mariadb"
    echo ""
    echo -e "  ${YELLOW}Перезапуск MariaDB:${NC}"
    echo -e "    systemctl restart mariadb"
    echo ""
    
    if [ "$INSTALL_PHPMYADMIN" = "yes" ]; then
        echo -e "  ${YELLOW}Статус Apache:${NC}"
        echo -e "    systemctl status apache2"
        echo ""
        echo -e "  ${YELLOW}Перезапуск Apache:${NC}"
        echo -e "    systemctl restart apache2"
        echo ""
    fi
    
    info "════════════════════════════════════════════════════════════"
    echo ""
    success "Все готово! Хорошего дня! 🎉"
    echo ""
}

# Основная функция
main() {
    check_root
    check_ubuntu
    check_existing_db
    greet_user
    get_variables
    
    echo ""
    info "Начинаем установку..."
    echo ""
    
    update_system
    install_mariadb
    configure_mariadb
    create_database
    
    echo ""
    read -p "Установить phpMyAdmin? (y/N): " INSTALL_PHPMYADMIN
    if [[ "$INSTALL_PHPMYADMIN" =~ ^[Yy]$ ]]; then
        install_phpmyadmin
    else
        INSTALL_PHPMYADMIN="no"
        echo ""
        success "Хорошего дня! 👋"
    fi
    
    print_report
}

# Запуск основной функции
main
