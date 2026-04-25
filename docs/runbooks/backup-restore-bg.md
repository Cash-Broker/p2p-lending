# Backup / Restore — практическо ръководство

Този документ описва ежедневния backup процес на платформата P2P Invest и стъпките за теглене и възстановяване.

---

## 📋 Бърз преглед

| Какво | Кога | Къде |
|---|---|---|
| Backup се създава автоматично | Всяка нощ в 02:30 UTC (~04:30 Sofia) | `/var/backups/mysql/` на server-а |
| Telegram съобщение „Backup готов" | След създаване | В групата „Vamasset" |
| Запазват се последните | 30 дни | По-старите се трият автоматично |
| Шифровани с | AES-256 + парола от `BACKUP_ENCRYPTION_PASS` в `.env` | Без паролата НЕ могат да се отворят |

---

## 🔄 Какво прави автоматичният backup

Всяка вечер в 02:30 UTC сървърът:

1. Прави transaction-consistent SQL dump на цялата база (`mysqldump --single-transaction`).
2. Компресира с gzip.
3. Шифрова с AES-256-CBC + PBKDF2 (100,000 итерации) и парола от `.env`.
4. Запазва във файл `/var/backups/mysql/YYYY-MM-DD.sql.gz.enc`.
5. Обновява символен линк `/var/backups/mysql/latest.sql.gz.enc` → най-новия.
6. Изтрива файлове по-стари от 30 дни.
7. Праща Telegram съобщение с името, размера и команда за SCP.

---

## 📥 Как да си тегля backup на лаптопа

### Опция 1 — SCP (бърз, terminal)

Отваряш Git Bash на лаптопа и пускаш:

```sh
mkdir -p ~/p2p-backups   # само първия път

scp -i ~/.ssh/id_ed25519_p2p \
    yordan@178.104.78.0:/var/backups/mysql/latest.sql.gz.enc \
    ~/p2p-backups/
```

Файлът пристига в `C:\Users\yorda\p2p-backups\latest.sql.gz.enc`.

⚠️ Веднага го **преименувай** с дата (защото следващия път `latest` ще се презапише):

```sh
mv ~/p2p-backups/latest.sql.gz.enc ~/p2p-backups/2026-04-25.sql.gz.enc
```

Или директно тегли с конкретна дата:

```sh
scp -i ~/.ssh/id_ed25519_p2p \
    yordan@178.104.78.0:/var/backups/mysql/2026-04-25.sql.gz.enc \
    ~/p2p-backups/
```

### Опция 2 — WinSCP (графично, drag & drop)

1. Изтегли WinSCP (winscp.net, безплатно).
2. New Site:
   - Host: `178.104.78.0`
   - Username: `yordan`
   - Advanced → Authentication → Private key file: `C:\Users\yorda\.ssh\id_ed25519_p2p`
   - (WinSCP ще предложи да го конвертира в .ppk формат — кажи ОК.)
3. Login.
4. На server-а навигирай до `/var/backups/mysql/`.
5. Drag-vай файла на лявата страна (твоят лаптоп).

---

## 💾 Препоръка за съхранение

Когато си тегли backup, **веднага го копирай на втори носител**:

```
~/p2p-backups/2026-04-25.sql.gz.enc       ← на лаптопа
└── копирай на USB stick                  ← в чекмеджето вкъщи
└── копирай на external HDD               ← на бюрото
```

**Седмичен ритуал (петък вечер, ~5 минути):**
1. SCP последния backup от server.
2. Copy на USB stick.
3. Сложи USB stick в чекмеджето.

---

## 🔑 Encryption паролата — КРИТИЧНО

Паролата за дешифриране е в `.env` на сървъра като `BACKUP_ENCRYPTION_PASS`. Без нея файловете са **неотваряеми**.

**Запази паролата на 3 места ВЕДНАГА след първоначално конфигуриране:**

1. **Password manager** (1Password, Bitwarden, Apple Keychain, Google Password Manager).
2. **Принтирана на хартия** в чекмеджето вкъщи / трезор.
3. **На USB stick** в плик с надпис „P2P Invest backup decryption pass".

Ако загубиш паролата → backup-ите стават безполезни.
Ако някой я открадне → backup файловете могат да се прочетат → не я споделяй никъде.

---

## 🚨 Възстановяване (Restore) — как се прави

### Сценарий A — Restore на server-а от server-side backup

```sh
ssh -i ~/.ssh/id_ed25519_p2p yordan@178.104.78.0

# Виж наличните backup-и:
sudo /usr/local/bin/p2p-restore

# Направи restore от конкретен файл:
sudo /usr/local/bin/p2p-restore /var/backups/mysql/2026-04-25.sql.gz.enc
```

Скриптът ще:
1. Покаже информация за файла + цел.
2. Покаже сериозно предупреждение (DESTRUCTIVE).
3. Изиска да напишеш `YES` с главни букви.
4. Декриптира → разархивира → импортира в MySQL.
5. Покаже инструкции за следващи стъпки (restart workers, clear cache, health check).

### Сценарий B — Restore от твой лаптоп

Ако цялата `/var/backups/mysql/` е изгубена (например VM е rebuilt):

```sh
# 1. Качи backup от лаптопа обратно на server:
scp -i ~/.ssh/id_ed25519_p2p \
    ~/p2p-backups/2026-04-25.sql.gz.enc \
    yordan@178.104.78.0:/tmp/

# 2. SSH на server-а:
ssh -i ~/.ssh/id_ed25519_p2p yordan@178.104.78.0

# 3. Restore:
sudo /usr/local/bin/p2p-restore /tmp/2026-04-25.sql.gz.enc

# 4. Изтрий копието от /tmp:
rm /tmp/2026-04-25.sql.gz.enc
```

---

## 🛠 Поддръжка / Troubleshooting

### Не пристига Telegram съобщение „Backup готов"

Възможни причини:
1. Cron не пуска скрипта → `sudo crontab -l | grep p2p-local-backup`
2. Скриптът пада → `sudo tail -50 /var/log/p2p-local-backup.log`
3. Telegram credentials грешни в `.env` → `php artisan telegram:test`
4. Backup script-ът няма execute права → `sudo chmod +x /usr/local/bin/p2p-local-backup`

### Telegram съобщава „BACKUP FAILED"

В Telegram съобщението има команда `tail -50`. Влез на server-а и я пусни:

```sh
ssh -i ~/.ssh/id_ed25519_p2p yordan@178.104.78.0
sudo tail -50 /var/log/p2p-local-backup.log
```

Често причини:
- MySQL не работи: `sudo systemctl status mysql`
- Свободно място < 100 MB на диска: `df -h /`

---

## 📊 Размер на backup-а — колко да очаквам

| Phase | Очакван размер на 1 backup |
|---|---|
| Pre-launch (текущ test data) | < 1 MB |
| Beta launch (50 инвеститори) | 2-5 MB |
| 6 месеца launch (500 инвеститори) | 20-50 MB |

При надхвърляне на ~500 MB на backup → старата стратегия „SCP веднъж седмично" може да отнема дълго. Тогава съобрази migration to Hetzner Storage Box.

---

## 📝 Конвенции

- Backup имена: `YYYY-MM-DD.sql.gz.enc` (UTC дата).
- Symlink: `latest.sql.gz.enc` → винаги към най-новия.
- Лог: `/var/log/p2p-local-backup.log`.
- Cron: 02:30 UTC всеки ден.
- Retention: 30 дни (server-side).
