#!/usr/bin/env bash
#
# Первичная настройка узла рендерера (docs/plan/storefront-renderer.md, этап 1).
#
# Запускается один раз от root на чистой Ubuntu 24.04:
#
#   ssh root@<ip> 'bash -s' < services/storefront-renderer/deploy/provision.sh
#
# Повторный запуск безопасен: каждый шаг проверяет, сделан ли он.
#
# Что получается на выходе:
#   - пользователь `renderer` с теми же SSH-ключами, что у root, в группах
#     docker и sudo;
#   - вход только по ключу, root по SSH закрыт;
#   - входящий трафик закрыт, кроме 22/tcp;
#   - автоматические обновления безопасности;
#   - Docker Engine и compose plugin из официального репозитория;
#   - ротация журналов контейнеров (узел пишет в stdout круглосуточно);
#   - swap 2 GB: Chromium даёт пики памяти, и без swap OOM-killer убивает
#     браузер посреди карточки.
#
# Root закрывается последним шагом и только если у `renderer` есть ключ:
# иначе машина останется без входа вовсе.

set -euo pipefail

RENDERER_USER=renderer

if [[ "$(id -u)" -ne 0 ]]; then
    echo "Запускать от root" >&2
    exit 1
fi

. /etc/os-release
if [[ "${ID}" != "ubuntu" || "${VERSION_ID}" != "24.04" ]]; then
    echo "Ожидается Ubuntu 24.04, получено ${ID} ${VERSION_ID}" >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> Пакеты"
apt-get update -q
apt-get upgrade -yq
apt-get install -yq ca-certificates curl gnupg ufw unattended-upgrades

echo "==> Автоматические обновления безопасности"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

echo "==> Пользователь ${RENDERER_USER}"
if ! id "${RENDERER_USER}" >/dev/null 2>&1; then
    adduser --disabled-password --gecos '' "${RENDERER_USER}"
fi
# sudo без пароля: пароля у пользователя нет, вход только по ключу,
# а группа docker и так даёт права root — отдельный пароль ничего не закрыл бы.
usermod -aG sudo "${RENDERER_USER}"
echo "${RENDERER_USER} ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-${RENDERER_USER}"
chmod 440 "/etc/sudoers.d/90-${RENDERER_USER}"
visudo -cq
install -d -m 700 -o "${RENDERER_USER}" -g "${RENDERER_USER}" "/home/${RENDERER_USER}/.ssh"
if [[ -s /root/.ssh/authorized_keys ]]; then
    install -m 600 -o "${RENDERER_USER}" -g "${RENDERER_USER}" \
        /root/.ssh/authorized_keys "/home/${RENDERER_USER}/.ssh/authorized_keys"
fi

echo "==> Docker"
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -q
    apt-get install -yq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "5" }
}
EOF
systemctl enable --now docker
systemctl restart docker
usermod -aG docker "${RENDERER_USER}"

echo "==> Swap"
if ! swapon --show | grep -q '/swapfile'; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
echo 'vm.swappiness=10' > /etc/sysctl.d/90-renderer.conf
sysctl -q --system

echo "==> Файрвол"
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw --force enable

echo "==> SSH"
if [[ ! -s "/home/${RENDERER_USER}/.ssh/authorized_keys" ]]; then
    echo "У ${RENDERER_USER} нет SSH-ключа — вход root НЕ закрыт, иначе машина останется без входа" >&2
    exit 1
fi
cat > /etc/ssh/sshd_config.d/10-conwix.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
EOF
sshd -t
systemctl reload ssh

echo "==> Готово"
docker --version
docker compose version
ufw status verbose | head -n 5
