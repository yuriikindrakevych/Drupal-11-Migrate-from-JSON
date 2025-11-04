# Migrate from Drupal 7

Модуль для міграції даних з Drupal 7 в Drupal 11 через JSON API.

## Опис

Цей модуль надає зручний інтерфейс для міграції даних з Drupal 7 в Drupal 11 через JSON API. Він підтримує міграцію:
- ✅ Словників таксономії та їх полів
- ✅ Термінів таксономії з ієрархією
- ✅ Типів контенту та їх полів
- ✅ Матеріалів (nodes) з підтримкою перекладів
- ✅ Файлів та зображень (автоматичне завантаження)
- ✅ Полів користувачів
- ✅ Користувачів з аватарами
- ✅ Автоматичний маппінг старих ID в нові ID
- ✅ Автоматичний імпорт через cron з підтримкою offset

Модуль призначений для роботи з багатомовними сайтами Drupal 7 та підтримує інкрементальні оновлення (перевірка поля `changed`).

## Вимоги

- Drupal 10 або 11
- На Drupal 7 сайті має бути встановлений та налаштований JSON API endpoint
- **Для багатомовності (опціонально):**
  - Language модуль (ввімкнено за замовчуванням)
  - Content Translation модуль

## Встановлення

1. Розмістіть модуль в директорії `modules/custom/migrate_from_drupal7`
2. Увімкніть модуль через адміністративний інтерфейс або drush:
   ```
   drush en migrate_from_drupal7
   ```

## Налаштування

1. Перейдіть до розділу **Управління** → **Конфігурація** → **Створення матеріалів** → **Міграція з Drupal 7**
2. Відкрийте вкладку **Налаштування**
3. Введіть базову URL адресу вашого Drupal 7 сайту
4. Перевірте та за потреби відредагуйте endpoint для різних типів даних
5. Збережіть налаштування

## Використання

### Імпорт словників таксономії

1. Перейдіть до вкладки **Імпорт словників таксономії**
2. Виберіть словники, які потрібно імпортувати
3. Для кожного словника виберіть поля, які потрібно імпортувати
4. Натисніть кнопку **Імпортувати**
5. Процес імпорту буде виконаний через Batch API

**Примітка:** На цьому етапі імпортуються тільки структури словників та їх поля. Терміни таксономії будуть імпортовані пізніше.

### Підтримка багатомовності

Модуль автоматично налаштовує багатомовність для словників, якщо в даних з Drupal 7 встановлено `translatable: true`:

1. **Автоматична конфігурація:** При імпорті словника з увімкненою багатомовністю, модуль:
   - Увімкне вибір мови на сторінках створення та редагування термінів
   - Увімкне переклад для термінів таксономії
   - Налаштує мовні параметри відповідно до даних з Drupal 7

2. **Інформація про багатомовність:** Форма імпорту показує детальну інформацію:
   - Чи є словник багатомовним
   - Режим перекладу (переклад/локалізація/фіксована мова)
   - i18n режим (0-4)
   - Статус Entity Translation

3. **Вимоги:** Для роботи багатомовності на Drupal 11 сайті має бути увімкнений модуль **Content Translation**. Якщо модуль не увімкнено, словники будуть створені без налаштувань багатомовності, про що буде записано попередження в логи.

## Робота з маппінгом ID (Old ID → New ID)

Модуль автоматично зберігає відповідність між старими ID з Drupal 7 та новими ID в Drupal 11 у таблиці `migrate_from_drupal7_mapping`. Це дозволяє легко знаходити нові ID за старими, що критично важливо для міграції зв'язків між сутностями (наприклад, прив'язка замовлень до користувачів).

### Структура таблиці маппінгу

```sql
migrate_from_drupal7_mapping:
  - id (serial) - Primary key
  - entity_type (varchar) - Тип сутності: 'node', 'user', 'term', 'vocabulary'
  - old_id (varchar) - Старий ID з Drupal 7
  - new_id (int) - Новий ID в Drupal 11
  - vocabulary_id (varchar) - ID словника (для термінів)
  - created (int) - Timestamp створення
  - updated (int) - Timestamp оновлення
```

### Використання MappingService

#### 1. Отримання сервісу

```php
// Через dependency injection (рекомендовано)
$mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
```

#### 2. Отримання нового ID за старим (основне використання)

```php
// Отримати новий UID користувача за старим
$old_uid = 123; // UID з Drupal 7
$new_uid = $mapping_service->getNewId('user', $old_uid);
// $new_uid тепер містить новий UID в Drupal 11 або NULL якщо маппінг не знайдено

// Отримати новий NID матеріалу за старим
$old_nid = 456; // NID з Drupal 7
$new_nid = $mapping_service->getNewId('node', $old_nid);
// Для nodes можна також вказати тип контенту як 3-й параметр:
$new_nid = $mapping_service->getNewId('node', $old_nid, 'article');

// Отримати новий TID терміна за старим
$old_tid = 789;
$new_tid = $mapping_service->getNewId('term', $old_tid, 'category');
// Для термінів 3-й параметр (vocabulary_id) обов'язковий!
```

#### 3. Отримання старого ID за новим (зворотній маппінг)

```php
// Отримати старий UID за новим
$new_uid = 42;
$old_uid = $mapping_service->getOldId('user', $new_uid);

// Отримати старий NID за новим
$new_nid = 123;
$old_nid = $mapping_service->getOldId('node', $new_nid, 'article');
```

#### 4. Отримання всіх маппінгів для типу сутності

```php
// Отримати всі маппінги користувачів
$user_mappings = $mapping_service->getAllMappings('user');
// Повертає масив: [old_uid => new_uid, old_uid => new_uid, ...]

// Приклад: ['123' => 42, '456' => 43, '789' => 44]

// Отримати всі маппінги термінів конкретного словника
$term_mappings = $mapping_service->getAllMappings('term', 'category');
```

#### 5. Отримання повного маппінгу з деталями

```php
// Отримати повний запис маппінгу
$mapping = $mapping_service->getMapping('user', '123');
// Повертає масив:
// [
//   'id' => 1,
//   'entity_type' => 'user',
//   'old_id' => '123',
//   'new_id' => 42,
//   'vocabulary_id' => '',
//   'created' => 1234567890,
//   'updated' => 1234567890
// ]
```

### Практичні приклади використання

#### Приклад 1: Імпорт замовлень інтернет-магазину

```php
// Припустимо, ви отримали дані замовлення з Drupal 7
$order_data = [
  'nid' => 9876,              // Старий NID замовлення
  'uid' => 123,               // Старий UID покупця
  'product_nid' => 5432,      // Старий NID товару
  'quantity' => 2,
  'price' => 1500,
];

$mapping_service = \Drupal::service('migrate_from_drupal7.mapping');

// Конвертуємо старі ID в нові
$new_user_id = $mapping_service->getNewId('user', $order_data['uid']);
$new_product_id = $mapping_service->getNewId('node', $order_data['product_nid'], 'product');

// Перевіряємо чи знайдено маппінги
if (!$new_user_id) {
  \Drupal::logger('mymodule')->error('User with old UID @uid not found', ['@uid' => $order_data['uid']]);
  return;
}

if (!$new_product_id) {
  \Drupal::logger('mymodule')->error('Product with old NID @nid not found', ['@nid' => $order_data['product_nid']]);
  return;
}

// Створюємо замовлення з новими ID
$order = Node::create([
  'type' => 'order',
  'title' => 'Order #' . $order_data['nid'],
  'field_customer' => $new_user_id,
  'field_product' => $new_product_id,
  'field_quantity' => $order_data['quantity'],
  'field_price' => $order_data['price'],
]);
$order->save();

// Зберігаємо маппінг самого замовлення для майбутнього використання
$mapping_service->saveMapping('node', $order_data['nid'], $order->id(), 'order');
```

#### Приклад 2: Оновлення посилань на користувачів у коментарях

```php
// Припустимо, у вас є коментарі з полем author_uid з Drupal 7
$comment_data = [
  'cid' => 111,
  'author_uid' => 456,  // Старий UID автора
  'body' => 'Текст коментаря',
];

$mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
$new_author_uid = $mapping_service->getNewId('user', $comment_data['author_uid']);

$comment = Comment::create([
  'entity_type' => 'node',
  'field_name' => 'comment',
  'uid' => $new_author_uid ?: 0, // Якщо не знайдено - анонім
  'comment_body' => $comment_data['body'],
]);
$comment->save();
```

#### Приклад 3: Міграція зв'язків entity reference полів

```php
// Припустимо, у вас є стаття з посиланням на категорії
$article_data = [
  'nid' => 777,
  'title' => 'My Article',
  'field_category' => [123, 456, 789], // Старі TID категорій
];

$mapping_service = \Drupal::service('migrate_from_drupal7.mapping');

// Конвертуємо всі TID в нові
$new_category_ids = [];
foreach ($article_data['field_category'] as $old_tid) {
  $new_tid = $mapping_service->getNewId('term', $old_tid, 'category');
  if ($new_tid) {
    $new_category_ids[] = ['target_id' => $new_tid];
  }
}

$article = Node::create([
  'type' => 'article',
  'title' => $article_data['title'],
  'field_category' => $new_category_ids,
]);
$article->save();

// Зберігаємо маппінг статті
$mapping_service->saveMapping('node', $article_data['nid'], $article->id(), 'article');
```

#### Приклад 4: Масове оновлення існуючих сутностей

```php
// Отримати всі маппінги користувачів для масової обробки
$mapping_service = \Drupal::service('migrate_from_drupal7.mapping');
$user_mappings = $mapping_service->getAllMappings('user');

foreach ($user_mappings as $old_uid => $new_uid) {
  $user = User::load($new_uid);
  if ($user) {
    // Виконуємо додаткові операції з користувачем
    // Наприклад, оновлюємо кастомне поле з старим UID для backwards compatibility
    $user->set('field_old_drupal7_uid', $old_uid);
    $user->save();
  }
}
```

### Збереження власних маппінгів

Якщо ви створюєте власний модуль імпорту, можете також використовувати MappingService:

```php
$mapping_service = \Drupal::service('migrate_from_drupal7.mapping');

// Зберегти новий маппінг
$mapping_service->saveMapping(
  'node',           // entity_type
  '999',            // old_id з Drupal 7
  123,              // new_id в Drupal 11
  'custom_type'     // vocabulary_id або тип контенту (опціонально)
);

// Оновити існуючий маппінг (автоматично, якщо існує)
$mapping_service->saveMapping('user', '888', 456);
```

### Видалення маппінгів

```php
$mapping_service = \Drupal::service('migrate_from_drupal7.mapping');

// Видалити конкретний маппінг
$mapping_service->deleteMapping('user', '123');

// Видалити всі маппінги для типу сутності
$mapping_service->deleteAllMappings('node', 'article');
```

### Важливі примітки

1. **Перевірка наявності:** Завжди перевіряйте результат `getNewId()` на NULL перед використанням
2. **Типи сутностей:** Використовуйте стандартні типи: 'node', 'user', 'term', 'vocabulary'
3. **Vocabulary ID:** Для термінів таксономії завжди вказуйте vocabulary_id (machine name словника)
4. **Збереження:** Маппінги зберігаються автоматично при імпорті через модуль
5. **Унікальність:** Комбінація entity_type + old_id + vocabulary_id є унікальною
6. **Постійність:** Маппінги зберігаються постійно і не видаляються автоматично

## Структура модуля

```
migrate_from_drupal7/
├── config/
│   └── install/
│       └── migrate_from_drupal7.settings.yml
├── src/
│   ├── Form/
│   │   ├── ImportTaxonomyForm.php
│   │   └── SettingsForm.php
│   └── Service/
│       └── Drupal7ApiClient.php
├── migrate_from_drupal7.info.yml
├── migrate_from_drupal7.links.menu.yml
├── migrate_from_drupal7.links.task.yml
├── migrate_from_drupal7.routing.yml
├── migrate_from_drupal7.services.yml
└── README.md
```

## Формат JSON для таксономій

Модуль очікує отримати дані таксономій в наступному форматі:

```json
{
  "category": {
    "vid": "1",
    "name": "Категорії",
    "machine_name": "category",
    "description": "Категорії викрійок",
    "hierarchy": "1",
    "module": "taxonomy",
    "weight": "-6",
    "translatable": true,
    "translation_mode": "translate",
    "i18n_mode": "4",
    "entity_translation_enabled": false,
    "fields": {
      "field_h1_title": {
        "label": "Заголовок Н1",
        "field_name": "field_h1_title",
        "type": "text",
        "required": 0,
        "description": "",
        "widget": "text_textfield",
        "cardinality": "1"
      }
    }
  }
}
```

### Поля багатомовності

- **translatable** (boolean): Чи можна перекладати терміни словника
- **translation_mode** (string): Режим перекладу
  - `none` - без перекладу
  - `localize` - локалізація
  - `fixed_language` - фіксована мова
  - `translate` - переклад
- **i18n_mode** (integer): Числовий код режиму i18n (0-4)
- **entity_translation_enabled** (boolean): Чи увімкнено Entity Translation в Drupal 7

## Підтримувані типи полів

Модуль автоматично конвертує типи полів з Drupal 7 в Drupal 11:

- `text` → `string`
- `text_long` → `string_long`
- `text_with_summary` → `text_with_summary`
- `number_integer` → `integer`
- `number_decimal` → `decimal`
- `number_float` → `float`
- `list_text` → `list_string`
- `image` → `image`
- `file` → `file`
- `taxonomy_term_reference` → `entity_reference`
- `link_field` → `link`
- `email` → `email`
- `date` → `datetime`

## API Endpoints

Модуль використовує наступні endpoints з Drupal 7:

1. `/json-api/content-type` - Типи контентів і поля
2. `/json-api/fields` - Всі поля
3. `/json-api/taxonomies` - Таксономії
4. `/json-api/terms` - Терміни таксономії
5. `/json-api/metatag/term` - Метатеги термінів
6. `/json-api/nodes` - Список нод
7. `/json-api/node` - Окрема нода
8. `/json-api/metatag/node` - Метатеги нод
9. `/json-api/file` - Файли
10. `/json-api/users` - Користувачі

## Логування

Всі помилки та важливі події логуються в канал `migrate_from_drupal7`. Переглянути логи можна в розділі **Звіти** → **Останні повідомлення журналу**.

## Автоматичний імпорт через Cron

Модуль підтримує автоматичний імпорт даних через cron. Перейдіть до вкладки **Автоматичний імпорт (Cron)** для налаштування.

### Налаштування

1. **Увімкнути автоматичний імпорт** - checkbox для активації
2. **Година запуску** - вибір години для запуску (0-23)
3. **Інтервал запуску** - Щодня / Щотижня / Щомісяця

### Що можна імпортувати автоматично

1. **Терміни таксономії** - виберіть словники для оновлення
2. **Користувачі** - імпорт/оновлення користувачів з підтримкою offset
   - Кількість користувачів за раз (1-500, рекомендовано 100-200)
   - Автоматичний обхід усіх користувачів через offset
3. **Матеріали (nodes)** - виберіть типи матеріалів для оновлення
   - Опція "Пропускати незмінені матеріали" (перевірка поля `changed`)

### Послідовність імпорту

При запуску cron дані імпортуються в наступній послідовності:
1. **Терміни таксономій** - оновлення словників
2. **Користувачі** - поступовий імпорт з offset
3. **Матеріали (nodes)** - оновлення матеріалів

### Робота offset для користувачів

Модуль використовує offset для поступового обходу всіх користувачів:
- Cron 1: offset=0, імпорт 100 users → offset=100
- Cron 2: offset=100, імпорт 100 users → offset=200
- Cron N: offset=57900, імпорт 100 users → якщо отримано <100, offset=0 (цикл завершено)

Це дозволяє імпортувати десятки тисяч користувачів без перевантаження сервера.

## Реалізовані можливості

✅ Імпорт словників таксономії та їх полів
✅ Імпорт термінів таксономії
✅ Імпорт типів контенту та їх полів
✅ Імпорт полів користувачів
✅ Імпорт користувачів з аватарами
✅ Імпорт матеріалів (nodes) з перекладами
✅ Завантаження файлів та зображень
✅ Підтримка багатомовності
✅ Автоматичний маппінг old ID → new ID
✅ Автоматичний імпорт через cron
✅ Детальне логування всіх операцій
✅ Перевірка поля `changed` для пропуску незмінених сутностей

## Майбутні можливості

- Підтримка метатегів
- Імпорт коментарів
- Підтримка редиректів (301/302)
- Імпорт меню та menu links
- Графічний інтерфейс для перегляду маппінгів
- Експорт/імпорт конфігурації міграції

## Автор

Модуль створено для міграції з Drupal 7 на Drupal 11.

## Ліцензія

GPL-2.0+
