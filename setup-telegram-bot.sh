#!/bin/bash

# Скрипт для установки Telegram бота на Ubuntu
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

# Функция проверки Python
check_python() {
    info "Проверка Python..."
    
    if ! command -v python3 &> /dev/null; then
        error "Python3 не установлен. Установите его перед запуском скрипта."
        exit 1
    fi
    
    PYTHON_VERSION=$(python3 --version | awk '{print $2}')
    success "Обнаружен Python $PYTHON_VERSION"
    
    # Проверка версии Python (минимум 3.8)
    PYTHON_MAJOR=$(echo $PYTHON_VERSION | cut -d. -f1)
    PYTHON_MINOR=$(echo $PYTHON_VERSION | cut -d. -f2)
    
    if [ "$PYTHON_MAJOR" -lt 3 ] || ([ "$PYTHON_MAJOR" -eq 3 ] && [ "$PYTHON_MINOR" -lt 8 ]); then
        error "Требуется Python 3.8 или выше. Обнаружена версия: $PYTHON_VERSION"
        exit 1
    fi
}

# Функция проверки установленного бота
check_existing_bot() {
    info "Проверка наличия установленного бота..."
    
    if [ -d "/opt/telegram-bot" ]; then
        warning "Обнаружена директория /opt/telegram-bot"
        
        if systemctl is-active --quiet telegram-bot 2>/dev/null; then
            warning "Сервис telegram-bot уже запущен"
        fi
        
        read -p "Продолжить установку? Существующая установка может быть перезаписана. (y/N): " continue_install
        if [[ ! "$continue_install" =~ ^[Yy]$ ]]; then
            info "Установка отменена пользователем"
            exit 0
        fi
    else
        success "Установленный бот не обнаружен"
    fi
}

# Функция приветствия
greet_user() {
    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║          Установщик Telegram бота                         ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    info "Добро пожаловать! Этот скрипт поможет вам установить и настроить Telegram бота."
    echo ""
}

# Функция запроса переменных
get_variables() {
    echo ""
    info "Пожалуйста, предоставьте следующую информацию:"
    echo ""
    
    # Токен Telegram бота
    while true; do
        read -p "Введите токен Telegram бота (BOT_TOKEN): " BOT_TOKEN
        if [ -z "$BOT_TOKEN" ]; then
            error "Токен не может быть пустым!"
            continue
        fi
        break
    done
    
    # ID администратора
    read -p "Введите Telegram ID администратора (ADMIN_USER_ID, по умолчанию: пусто): " ADMIN_USER_ID
    ADMIN_USER_ID=${ADMIN_USER_ID:-""}
    
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
    
    # Настройки API
    echo ""
    info "Настройки API сервера:"
    read -p "API хост (по умолчанию: 0.0.0.0): " API_HOST
    API_HOST=${API_HOST:-0.0.0.0}
    
    read -p "API порт (по умолчанию: 8080): " API_PORT
    API_PORT=${API_PORT:-8080}
    
    # URL сайта
    read -p "URL сайта (SITE_URL, например: http://192.168.1.100): " SITE_URL
    if [ -z "$SITE_URL" ]; then
        warning "URL сайта не указан. Можно будет изменить позже в config.py"
    fi
    
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
    
    # Путь к коду бота
    echo ""
    info "Источник кода бота:"
    echo "  1) URL репозитория GitHub (например: https://github.com/user/repo.git)"
    echo "  2) Локальный путь к директории с кодом"
    echo "  3) Локальный путь к файлу bot.py"
    echo "  4) Пропустить (код нужно будет добавить вручную)"
    read -p "Выберите вариант (1-4, по умолчанию: 4): " BOT_SOURCE_TYPE
    BOT_SOURCE_TYPE=${BOT_SOURCE_TYPE:-4}
    
    case $BOT_SOURCE_TYPE in
        1)
            read -p "Введите URL репозитория GitHub: " BOT_SOURCE
            ;;
        2)
            read -p "Введите путь к директории с кодом: " BOT_SOURCE
            ;;
        3)
            read -p "Введите путь к файлу bot.py: " BOT_SOURCE
            ;;
        4)
            BOT_SOURCE=""
            warning "Код бота нужно будет добавить вручную после установки"
            ;;
        *)
            BOT_SOURCE=""
            warning "Неверный выбор. Код бота нужно будет добавить вручную"
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

# Функция установки системных зависимостей
install_system_dependencies() {
    info "Установка системных зависимостей..."
    
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        python3 \
        python3-pip \
        python3-venv \
        git \
        > /dev/null 2>&1
    
    success "Системные зависимости установлены"
}

# Функция создания структуры проекта
create_project_structure() {
    info "Создание структуры проекта..."
    
    # Остановка существующего сервиса, если есть
    if systemctl is-active --quiet telegram-bot 2>/dev/null; then
        info "Остановка существующего сервиса..."
        systemctl stop telegram-bot 2>/dev/null || true
    fi
    
    # Создание директории
    mkdir -p /opt/telegram-bot
    cd /opt/telegram-bot
    
    # Получение кода бота
    if [ -n "$BOT_SOURCE" ]; then
        if [[ "$BOT_SOURCE" =~ ^https?:// ]] || [[ "$BOT_SOURCE" =~ ^git@ ]]; then
            # Это URL репозитория
            info "Клонирование репозитория..."
            if [ -d ".git" ]; then
                git pull || true
            else
                rm -rf /tmp/telegram-bot-repo 2>/dev/null || true
                git clone "$BOT_SOURCE" /tmp/telegram-bot-repo 2>/dev/null
                if [ -d "/tmp/telegram-bot-repo" ] && [ "$(ls -A /tmp/telegram-bot-repo)" ]; then
                    cp -r /tmp/telegram-bot-repo/* /opt/telegram-bot/ 2>/dev/null || true
                    cp -r /tmp/telegram-bot-repo/.[!.]* /opt/telegram-bot/ 2>/dev/null || true
                    rm -rf /tmp/telegram-bot-repo
                    success "Код бота склонирован из репозитория"
                else
                    error "Не удалось клонировать репозиторий. Проверьте URL и доступ."
                    exit 1
                fi
            fi
        elif [ -f "$BOT_SOURCE" ]; then
            # Это файл bot.py
            info "Копирование bot.py..."
            cp "$BOT_SOURCE" /opt/telegram-bot/bot.py
            success "Файл bot.py скопирован"
        elif [ -d "$BOT_SOURCE" ]; then
            # Это директория
            info "Копирование файлов из директории..."
            cp -r "$BOT_SOURCE"/* /opt/telegram-bot/ 2>/dev/null || true
            cp -r "$BOT_SOURCE"/.[!.]* /opt/telegram-bot/ 2>/dev/null || true
            success "Файлы скопированы из директории"
        fi
    else
        # Создание базовой структуры, если код не предоставлен
        warning "Код бота не предоставлен. Создана базовая структура."
        warning "Пожалуйста, поместите bot.py в /opt/telegram-bot/ после установки."
    fi
    
    # Проверка наличия bot.py (только если код был предоставлен)
    if [ -n "$BOT_SOURCE" ] && [ ! -f "/opt/telegram-bot/bot.py" ]; then
        error "Файл bot.py не найден! Убедитесь, что код бота доступен."
        exit 1
    fi
    
    # Делаем bot.py исполняемым
    chmod +x /opt/telegram-bot/bot.py
    
    success "Структура проекта создана"
}

# Функция создания виртуального окружения
create_venv() {
    info "Создание виртуального окружения Python..."
    
    cd /opt/telegram-bot
    
    # Удаление старого venv, если есть
    if [ -d "venv" ]; then
        rm -rf venv
    fi
    
    python3 -m venv venv
    
    success "Виртуальное окружение создано"
}

# Функция установки Python зависимостей
install_python_dependencies() {
    info "Установка Python зависимостей..."
    
    cd /opt/telegram-bot
    
    # Обновление pip
    ./venv/bin/pip install --upgrade pip -q
    
    # Установка зависимостей
    if [ -f "requirements.txt" ]; then
        ./venv/bin/pip install -r requirements.txt -q
    else
        # Установка зависимостей по умолчанию
        info "Файл requirements.txt не найден. Установка зависимостей по умолчанию..."
        ./venv/bin/pip install -q \
            Flask==3.1.2 \
            python-telegram-bot==22.5 \
            PyMySQL==1.1.2 \
            bcrypt==5.0.0 \
            httpx==0.28.1 \
            requests==2.32.5
    fi
    
    success "Python зависимости установлены"
}

# Функция создания config.py
create_config() {
    info "Создание файла конфигурации..."
    
    cat > /opt/telegram-bot/config.py <<EOF
# Конфигурация Telegram бота
BOT_TOKEN = '$BOT_TOKEN'
ADMIN_USER_ID = $([ -n "$ADMIN_USER_ID" ] && echo "$ADMIN_USER_ID" || echo "None")

# Настройки базы данных
DB_HOST = '$DB_HOST'
DB_PORT = $DB_PORT
DB_USER = '$DB_USER'
DB_PASSWORD = '$DB_PASSWORD'
DB_NAME = '$DB_NAME'

# Настройки API
API_HOST = '$API_HOST'
API_PORT = $API_PORT
# URL сайта
SITE_URL = '$SITE_URL'

# Токен для внутреннего API рассылки (должен совпадать с BROADCAST_TOKEN на сайте)
BROADCAST_TOKEN = '$BROADCAST_TOKEN'
EOF
    
    success "Файл конфигурации создан"
}

# Функция создания systemd сервиса
create_systemd_service() {
    info "Создание systemd сервиса..."
    
    cat > /etc/systemd/system/telegram-bot.service <<EOF
[Unit]
Description=Telegram Bot for User Authentication
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/telegram-bot
Environment="PATH=/opt/telegram-bot/venv/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
ExecStart=/opt/telegram-bot/venv/bin/python3 /opt/telegram-bot/bot.py
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF
    
    # Перезагрузка systemd
    systemctl daemon-reload
    
    # Включение автозапуска
    systemctl enable telegram-bot
    
    success "Systemd сервис создан и включен"
}

# Функция запуска и проверки бота
start_bot() {
    # Проверка наличия bot.py перед запуском
    if [ ! -f "/opt/telegram-bot/bot.py" ]; then
        warning "Файл bot.py не найден. Бот не будет запущен."
        warning "Добавьте bot.py в /opt/telegram-bot/ и запустите: systemctl start telegram-bot"
        return
    fi
    
    info "Запуск бота..."
    
    systemctl start telegram-bot
    
    # Небольшая задержка для запуска
    sleep 3
    
    # Проверка статуса
    if systemctl is-active --quiet telegram-bot; then
        success "Бот успешно запущен"
    else
        error "Не удалось запустить бота. Проверьте логи: journalctl -u telegram-bot"
        warning "Попробуйте запустить вручную: /opt/telegram-bot/venv/bin/python3 /opt/telegram-bot/bot.py"
    fi
}

# Функция проверки подключения к БД
test_db_connection() {
    info "Проверка подключения к базе данных..."
    
    cd /opt/telegram-bot
    
    # Простая проверка через Python
    ./venv/bin/python3 <<PYTHON_TEST
import sys
import pymysql

try:
    conn = pymysql.connect(
        host='$DB_HOST',
        port=$DB_PORT,
        user='$DB_USER',
        password='$DB_PASSWORD',
        database='$DB_NAME'
    )
    conn.close()
    print("SUCCESS")
except Exception as e:
    print(f"ERROR: {e}")
    sys.exit(1)
PYTHON_TEST
    
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
    info "ИНФОРМАЦИЯ О БОТЕ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}Директория:${NC} /opt/telegram-bot"
    echo -e "  ${YELLOW}Конфигурация:${NC} /opt/telegram-bot/config.py"
    echo -e "  ${YELLOW}Основной файл:${NC} /opt/telegram-bot/bot.py"
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
    info "API СЕРВЕР:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    SERVER_IP=$(hostname -I | awk '{print $1}')
    echo -e "  ${YELLOW}URL:${NC} http://$SERVER_IP:$API_PORT"
    echo -e "  ${YELLOW}Локальный доступ:${NC} http://localhost:$API_PORT"
    echo -e "  ${YELLOW}Broadcast endpoint:${NC} http://$SERVER_IP:$API_PORT/broadcast"
    echo ""
    echo -e "  ${YELLOW}Broadcast Token:${NC} $BROADCAST_TOKEN"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "ПОЛЕЗНЫЕ КОМАНДЫ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}Статус бота:${NC}"
    echo -e "    systemctl status telegram-bot"
    echo ""
    echo -e "  ${YELLOW}Просмотр логов:${NC}"
    echo -e "    journalctl -u telegram-bot -f"
    echo ""
    echo -e "  ${YELLOW}Перезапуск бота:${NC}"
    echo -e "    systemctl restart telegram-bot"
    echo ""
    echo -e "  ${YELLOW}Остановка бота:${NC}"
    echo -e "    systemctl stop telegram-bot"
    echo ""
    echo -e "  ${YELLOW}Редактирование конфигурации:${NC}"
    echo -e "    nano /opt/telegram-bot/config.py"
    echo ""
    echo -e "  ${YELLOW}После изменения config.py:${NC}"
    echo -e "    systemctl restart telegram-bot"
    echo ""
    
    info "════════════════════════════════════════════════════════════"
    info "ВАЖНЫЕ ЗАМЕЧАНИЯ:"
    info "════════════════════════════════════════════════════════════"
    echo ""
    echo -e "  ${YELLOW}1.${NC} Убедитесь, что BROADCAST_TOKEN совпадает с токеном на сайте (PHP-FPM env)"
    echo -e "  ${YELLOW}2.${NC} Проверьте, что порт $API_PORT доступен для сайта"
    echo -e "  ${YELLOW}3.${NC} Убедитесь, что база данных доступна с этого сервера"
    echo -e "  ${YELLOW}4.${NC} Проверьте firewall настройки для порта $API_PORT"
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
    check_python
    check_existing_bot
    greet_user
    get_variables
    
    echo ""
    info "Начинаем установку..."
    echo ""
    
    update_system
    install_system_dependencies
    create_project_structure
    create_venv
    install_python_dependencies
    create_config
    create_systemd_service
    test_db_connection
    start_bot
    
    print_report
}

# Запуск основной функции
main
