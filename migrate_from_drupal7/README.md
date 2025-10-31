# Migrate from Drupal 7

Модуль для міграції даних з Drupal 7 в Drupal 11 через JSON API.

## Опис

Цей модуль надає зручний інтерфейс для міграції даних з Drupal 7 в Drupal 11. Він підтримує міграцію:
- Словників таксономії та їх полів
- Термінів таксономії (в розробці)
- Типів контенту (в розробці)
- Нод та їх полів (в розробці)
- Файлів (в розробці)
- Користувачів (в розробці)

Модуль призначений для роботи з багатомовними сайтами Drupal 7.

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

## Майбутні можливості

- Імпорт термінів таксономії
- Імпорт типів контенту
- Імпорт нод
- Імпорт файлів
- Імпорт користувачів
- Підтримка метатегів
- Підтримка зв'язків між сутностями

## Автор

Модуль створено для міграції з Drupal 7 на Drupal 11.

## Ліцензія

GPL-2.0+
