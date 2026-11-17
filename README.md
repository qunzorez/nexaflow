# NexaFlow

NexaFlow — SRM-система для керування клієнтами, проєктами, задачами й командою. Frontend побудований на React/Vite, backend — PHP API з MySQL.

## Локальний запуск

1. Встановіть Node.js, PHP з розширеннями PDO MySQL, cURL та Fileinfo, MySQL і Mailpit.
2. Виконайте `npm ci`.
3. Створіть локальний `api/config.local.php` на основі `api/config.example.php` і вкажіть локальні реквізити БД. Цей файл ігнорується Git.
4. Застосуйте `database/schema.sql` до локальної бази.
5. Запустіть `npm run dev` і відкрийте адресу Vite, або зберіть статичний frontend командою `npm run build`.

Деталі середовищ і деплою на cPanel описані в [ENVIRONMENTS.md](ENVIRONMENTS.md).

## Production-збірка

Виконайте `npm ci` і `npm run build:live`. Сайт очікує, що вміст `dist` буде розгорнутий у `public_html/dist`, API — у `public_html/api`, а кореневий `.htaccess` залишиться в `public_html`. Для cPanel Git ця структура налаштована в `.cpanel.yml`.

Перед публікацією потрібні серверний `api/config.web.php` (або сумісний старий конфіг), застосовані SQL-міграції та HTTPS. Платіжний модуль залишайте вимкненим (`billing_enabled: false`), доки не буде готової бойової LiqPay-конфігурації.

Бухгалтерський податковий календар також потребує `api/data/vchasno-tax-events-2026.json`; cPanel deployment копіює його разом з API, а preflight перевіряє каталог до копіювання файлів. Таблиці бухгалтерського модуля та поле вибраного простору додаються ідемпотентними серверними schema checks під час першого відповідного API-запиту.

## Важливі файли

- `appsettings.json` — перемикач середовища й публічні параметри профілю; секретів тут немає.
- `api/config.example.php` — шаблон PHP-конфігурації, без реальних ключів.
- `database/live_migration.sql` — production bootstrap-міграція.
- `database/email_notifications_default_on.sql` — одноразове ввімкнення email-сповіщень для наявних користувачів.
- `.cpanel.yml` — копіювання збірки й API без перезапису серверних секретів.
