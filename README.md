# 📦 Rulon — учёт рулонов на производстве

Мини-склад рулонов с автоматическим изменением статуса и защитой от повторного использования.

---

## 🎯 Что делает

- **Склад** вносит рулоны в БД (MySQL).
- **Оператор** станка вводит номер рулона в UI.
- **Оператор** нажимает кнопку:
  - ✅ **Set** — рулон закончен.
  - ⏳ **Not finished** — рулон не закончен.
- **Система** автоматически меняет статус в БД.
- **Блокировка** повторного использования `complete` рулона.
- **Логирование** всех действий в `coil_logs`.

## 🚀 Как это работает

```
[Склад]                    [Сервер]                 [Оператор]
   │                          │                          │
   │ INSERT в MySQL           │                          │
   ├─────────────────────────►│                          │
   │                          │      POST coil=21/0587   │
   │                          │◄─────────────────────────┤
   │                          │      action=complete     │
   │                          │                          │
   │                          │  UPDATE shift_coils      │
   │                          │  SET finish_status=...   │
   │                          │                          │
   │  SELECT из MySQL         │                          │
   │◄─────────────────────────┤                          │
   │                          │                          │
   │  Видит новый статус      │                          │
```

## 📋 Логика статусов

| Статус | Что значит | Время | Можно повторно? |
| :--- | :--- | :--- | :--- |
| `partial` | Рулон не закончен | Не фиксируется | ✅ Да |
| `complete` | Рулон закончен | Фиксируется | ❌ Нет (блокировка) |

---

## 🛠️ Стек

- **Backend:** PHP 8.5
- **База данных:** MySQL 8.4 (в Docker)
- **Frontend:** HTML, CSS, JavaScript
- **Docker:** контейнер `mysqldb`

## 📁 Структура

```
Rulon/
├── index.html          — UI для оператора
├── CSS.css             — стили
├── coil_checker.js     — клиентская логика (POST)
├── check_coil.php      — серверная логика
├── .env                — пароль MySQL (не в GitHub)
├── .gitignore          — защита от мусора
└── README.md           — этот файл
```

---

## 🚀 Запуск проекта

### 1. База данных

```bash
docker start mysqldb
```

### 2. Проверка

```bash
docker ps
```

### 3. Вход в MySQL (опционально)

```bash
docker exec -it mysqldb mysql -uroot -p
```

Пароль: см. `.env` → `DB_PASSWORD`.

```sql
USE spiral_production;
SELECT COUNT(*) FROM shift_coils;
```

### 4. PHP-сервер

**В новом окне терминала:**

```bash
cd ~/Desktop/Rulon
php -S localhost:8000
```

### 5. UI в браузере

```
http://localhost:8000/index.html
```

## 🛑 Как выключить

1. **PHP:** `Ctrl+C`
2. **MySQL:** `exit`
3. **Docker:** `docker stop mysqldb`

---

## 🗄️ Команды для склада (MySQL)

### Вход в MySQL

```bash
docker exec -it mysqldb mysql -uroot -p
```

Пароль: `.env` → `DB_PASSWORD`.

```sql
USE spiral_production;
```

### 📌 Проверки (перед внесением)

**Проверить, что рулона ещё нет:**
```sql
SELECT * FROM shift_coils WHERE coil_number = '21/0587';
```
**Если пусто** — можно вносить.
**Если есть** — уже в базе.

**Проверить существующие заказы:**
```sql
SELECT id, order_code, diameter, thickness FROM orders;
```

**Проверить, какие рулоны уже есть по заказу:**
```sql
SELECT coil_number, finish_status FROM shift_coils WHERE order_id = 5;
```

### 📥 Внести один рулон

```sql
INSERT INTO shift_coils 
(coil_number, order_id, finish_status, finished_at, width, thickness, weight)
VALUES 
('21/0587', 5, 'partial', NULL, 1780.00, 12.7, 28.800);
```

**Что значит:**
- `coil_number` — номер рулона.
- `order_id` — ID заказа (**из** `orders`).
- `finish_status` — всегда `'partial'` **при** **внесении**.
- `finished_at` — `NULL` (**ещё** **не** **закончен**).
- `width` — ширина (**мм**).
- `thickness` — толщина (**мм**).
- `weight` — вес (**кг**).

### 📥 Внести несколько рулонов сразу

```sql
INSERT INTO shift_coils 
(coil_number, order_id, finish_status, finished_at, width, thickness, weight)
VALUES 
('21/0572', 5, 'partial', NULL, 1780.00, 12.7, 28.800),
('21/0573', 5, 'partial', NULL, 1800.00, 13.0, 30.200),
('21/0574', 5, 'partial', NULL, 1750.00, 12.5, 27.500),
('21/0575', 6, 'partial', NULL, 1820.00, 13.2, 31.100),
('21/0576', 6, 'partial', NULL, 1775.00, 12.8, 29.000);
```

### ✏️ Обновить данные рулона

```sql
UPDATE shift_coils
SET width = 1800.00, thickness = 13.0, weight = 30.200
WHERE coil_number = '21/0582';
```

### 🗑️ Удалить рулон

```sql
DELETE FROM shift_coils WHERE coil_number = '21/0456';
```

**Внимание:** удаление — **только** **если** **ошиблись** **при** **внесении**.
**Если** **рулон** **уже** **был** **в** **работе** — **не** **удаляй** (**логи** **в** `coil_logs` **останутся**).

### 📊 Проверка незавершённых рулонов

```sql
SELECT coil_number, order_id, width, thickness
FROM shift_coils 
WHERE finish_status = 'partial';
```

### 📊 Все рулоны по заказу

```sql
SELECT coil_number, finish_status, finished_at
FROM shift_coils 
WHERE order_id = 5
ORDER BY coil_number;
```

### 📊 Статистика за сегодня

```sql
SELECT 
  SUM(CASE WHEN action = 'complete' THEN 1 ELSE 0 END) AS completed_today,
  SUM(CASE WHEN action = 'partial' THEN 1 ELSE 0 END) AS partial_today
FROM coil_logs
WHERE DATE(timestamp) = CURDATE();
```

### 📊 Логи за сегодня (последние 20)

```sql
SELECT cl.id, sc.coil_number, cl.action, cl.timestamp, cl.user_ip
FROM coil_logs cl
LEFT JOIN shift_coils sc ON sc.id = cl.coil_id
WHERE DATE(cl.timestamp) = CURDATE()
ORDER BY cl.id DESC
LIMIT 20;
```

### 📊 Кто больше работал за сегодня

```sql
SELECT user_ip, COUNT(*) AS actions
FROM coil_logs
WHERE DATE(timestamp) = CURDATE()
GROUP BY user_ip
ORDER BY actions DESC;
```

### 📊 Полный отчёт за период

```sql
SELECT 
  sc.coil_number,
  sc.finish_status,
  sc.finished_at,
  o.order_code
FROM shift_coils sc
LEFT JOIN orders o ON o.id = sc.order_id
WHERE sc.finish_status = 'complete'
  AND DATE(sc.finished_at) BETWEEN '2026-09-01' AND '2026-09-30'
ORDER BY sc.finished_at DESC;
```

---

## ⚠️ Правила и проверки

### 🔒 Правила безопасности

1. **Пароль MySQL** — только в `.env`, не в коде.
2. **Только POST** — UI отправляет POST-запросы.
3. **Prepared statements** — защита от SQL-инъекций.
4. **Валидация `coil_number`** — только `A-Z`, `a-z`, `0-9`, `/`, `-`, `_`.
5. **Блокировка `complete`** — завершённый рулон нельзя изменить.
6. **Логирование** — все действия пишутся в `coil_logs`.

### 📏 Правила формата `coil_number`

**Разрешено:**
- Буквы: `A-Z`, `a-z`.
- Цифры: `0-9`.
- Слэш: `/`.
- Дефис: `-`.
- Подчёркивание: `_`.
- Длина: **1–50** **символов**.

**Примеры:**
- ✅ `21/0587`
- ✅ `A-26/32`
- ✅ `test_01`
- ❌ `21 0587` (**пробел**)
- ❌ `21.0587` (**точка**)

### ✅ Чек-лист перед внесением рулона

- [ ] Проверить, что `coil_number` **уникален** (`SELECT`).
- [ ] Проверить, что `order_id` **существует** в `orders`.
- [ ] Заполнить `width`, `thickness`, `weight`.
- [ ] Статус — `'partial'`.
- [ ] `finished_at` — `NULL`.

### ✅ Чек-лист после смены

- [ ] Проверить незавершённые: `SELECT * FROM shift_coils WHERE finish_status='partial';`
- [ ] Посмотреть логи: `SELECT * FROM coil_logs ORDER BY id DESC LIMIT 20;`
- [ ] Статистика за смену (**complete**).
- [ ] **Не удалять** **рулоны** **из** `shift_coils` (**только** **ошибочные**).

### ⛔ Чего НЕ делать

- ❌ **Не** **менять** `finish_status` **напрямую** **в** **MySQL** (**только** **через** **UI**).
- ❌ **Не** **удалять** **записи** **из** `coil_logs` (**история**).
- ❌ **Не** **коммитить** `.env` **в** **GitHub**.
- ❌ **Не** **запускать** **PHP** **без** `.env` (**упадёт**).

---

## 🔒 Безопасность

- ✅ Пароль MySQL — в `.env`.
- ✅ Только POST-запросы.
- ✅ Prepared statements.
- ✅ Валидация `coil_number`.
- ✅ Блокировка повтора.

---

# 📦 Rulon — ניהול גלילים בייצור

מחסן מיני של גלילים עם שינוי סטטוס אוטומטי והגנה מפני שימוש חוזר.

---

## 🎯 מה זה עושה

- **המחסן** מכניס גלילים ל-DB (MySQL).
- **המפעיל** מזין מספר גליל ב-UI.
- **המפעיל** לוחץ על כפתור:
  - ✅ **Set** — הגליל הסתיים.
  - ⏳ **Not finished** — הגליל לא הסתיים.
- **המערכת** משנה סטטוס ב-DB אוטומטית.
- **חסימה** של שימוש חוזר בגליל `complete`.
- **לוג** של כל הפעולות ב-`coil_logs`.

## 📋 לוגיקת סטטוסים

| סטטוס | משמעות | זמן | ניתן להשתמש שוב? |
| :--- | :--- | :--- | :--- |
| `partial` | גליל לא הסתיים | לא נקבע | ✅ כן |
| `complete` | גליל הסתיים | נקבע | ❌ לא (חסום) |

## 🛠️ סטאק

- **Backend:** PHP 8.5
- **DB:** MySQL 8.4 (ב-Docker)
- **Frontend:** HTML, CSS, JavaScript

## 🚀 הפעלה

```bash
docker start mysqldb
cd ~/Desktop/Rulon
php -S localhost:8000
```

פתח בדפדפן:
```
http://localhost:8000/index.html
```

## 🛑 כיבוי

1. **PHP:** `Ctrl+C`
2. **MySQL:** `exit`
3. **Docker:** `docker stop mysqldb`

---

## 🗄️ פקודות למחסן (MySQL)

### כניסה ל-MySQL

```bash
docker exec -it mysqldb mysql -uroot -p
```

```sql
USE spiral_production;
```

### 📥 הכנסת גליל בודד

```sql
INSERT INTO shift_coils 
(coil_number, order_id, finish_status, finished_at, width, thickness, weight)
VALUES 
('21/0587', 5, 'partial', NULL, 1780.00, 12.7, 28.800);
```

### 📥 הכנסת כמה גלילים בבת אחת

```sql
INSERT INTO shift_coils 
(coil_number, order_id, finish_status, finished_at, width, thickness, weight)
VALUES 
('21/0572', 5, 'partial', NULL, 1780.00, 12.7, 28.800),
('21/0573', 5, 'partial', NULL, 1800.00, 13.0, 30.200),
('21/0574', 5, 'partial', NULL, 1750.00, 12.5, 27.500);
```

### ✏️ עדכון נתוני גליל

```sql
UPDATE shift_coils
SET width = 1800.00, thickness = 13.0, weight = 30.200
WHERE coil_number = '21/0582';
```

### 🗑️ מחיקת גליל

```sql
DELETE FROM shift_coils WHERE coil_number = '21/0456';
```

### 📊 בדיקת גלילים שלא הסתיימו

```sql
SELECT coil_number, order_id, width, thickness
FROM shift_coils 
WHERE finish_status = 'partial';
```

### 📊 סטטיסטיקה להיום

```sql
SELECT 
  SUM(CASE WHEN action = 'complete' THEN 1 ELSE 0 END) AS completed_today,
  SUM(CASE WHEN action = 'partial' THEN 1 ELSE 0 END) AS partial_today
FROM coil_logs
WHERE DATE(timestamp) = CURDATE();
```

---

## ⚠️ כללים ובדיקות

### 🔒 כללי אבטחה

1. **סיסמת MySQL** — רק ב-`.env`.
2. **רק POST** — ה-UI שולח POST.
3. **Prepared statements** — הגנה מ-SQL injection.
4. **ולידציה של `coil_number`** — רק `A-Z`, `a-z`, `0-9`, `/`, `-`, `_`.
5. **חסימת `complete`** — גליל שהסתיים לא ניתן לשנות.
6. **לוג** — כל הפעולות נכתבות ל-`coil_logs`.

### 📏 כללי פורמט `coil_number`

**מותר:**
- אותיות: `A-Z`, `a-z`.
- ספרות: `0-9`.
- סלאש: `/`.
- מקף: `-`.
- קו תחתון: `_`.
- אורך: **1–50** תווים.

**דוגמאות:**
- ✅ `21/0587`
- ✅ `A-26/32`
- ❌ `21 0587` (רווח)
- ❌ `21.0587` (נקודה)

### ✅ צ'ק-ליסט לפני הכנסת גליל

- [ ] לבדוק ש-`coil_number` ייחודי.
- [ ] לבדוק ש-`order_id` קיים ב-`orders`.
- [ ] למלא `width`, `thickness`, `weight`.
- [ ] סטטוס — `'partial'`.
- [ ] `finished_at` — `NULL`.

### ⛔ מה לא לעשות

- ❌ לא לשנות `finish_status` ישירות ב-MySQL.
- ❌ לא למחוק רשומות מ-`coil_logs`.
- ❌ לא לעשות commit ל-`.env` ב-GitHub.
- ❌ לא להריץ PHP בלי `.env`.

---

## 👤 Автор / מחבר

**Рустам** / **רוסטם** — [GitHub](https://github.com/rustam77828)
