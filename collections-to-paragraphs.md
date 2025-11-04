# Конвертація Field Collections (Drupal 7) → Paragraphs (Drupal 11)

## Огляд

Цей документ описує процес конвертації Field Collections з Drupal 7 в Paragraphs для Drupal 11 під час міграції.

## Відмінності між Field Collections та Paragraphs

### Field Collections (Drupal 7)
- Окремі entities типу `field_collection_item`
- Зберігаються в окремих таблицях
- Мають `item_id`, `field_name`, `revision_id`
- Прив'язані до host entity (node) через field name
- Можуть бути вкладеними (nested)

### Paragraphs (Drupal 11)
- Окремі entities типу `paragraph`
- Мають `id`, `type`, `parent_id`, `parent_type`
- Прив'язуються через Entity Reference Revisions поле
- Підтримують revisions та вкладеність
- Більш гнучкі та продуктивні

---

## Рекомендована структура JSON (Варіант 1)

### Для ноди з field collections включеними в дані ноди:

```json
{
  "nid": 123,
  "title": "Товар: Сукня літня",
  "type": "product",
  "body": "Опис товару...",
  "created": 1234567890,
  "changed": 1234567890,

  "field_specifications": {
    "field_type": "field_collection",
    "target_bundle": "field_specifications",
    "cardinality": -1,
    "items": [
      {
        "item_id": 45,
        "delta": 0,
        "archived": 0,
        "field_spec_title": "Розмір",
        "field_spec_value": "M, L, XL",
        "field_spec_icon": {
          "fid": 789,
          "filename": "size-icon.png",
          "uri": "public://icons/size-icon.png",
          "url": "https://old-site.com/sites/default/files/icons/size-icon.png",
          "filesize": 2048,
          "mime": "image/png"
        }
      },
      {
        "item_id": 46,
        "delta": 1,
        "archived": 0,
        "field_spec_title": "Матеріал",
        "field_spec_value": "Бавовна 95%, Еластан 5%",
        "field_spec_icon": null
      },
      {
        "item_id": 47,
        "delta": 2,
        "archived": 0,
        "field_spec_title": "Догляд",
        "field_spec_value": "Прати при 30°C",
        "field_spec_icon": {
          "fid": 790,
          "filename": "wash-icon.png",
          "uri": "public://icons/wash-icon.png",
          "url": "https://old-site.com/sites/default/files/icons/wash-icon.png",
          "filesize": 1856,
          "mime": "image/png"
        }
      }
    ]
  },

  "field_product_gallery": {
    "field_type": "field_collection",
    "target_bundle": "field_product_gallery",
    "cardinality": -1,
    "items": [
      {
        "item_id": 101,
        "delta": 0,
        "archived": 0,
        "field_gallery_image": {
          "fid": 5001,
          "filename": "dress-front.jpg",
          "uri": "public://products/dress-front.jpg",
          "url": "https://old-site.com/sites/default/files/products/dress-front.jpg",
          "filesize": 245678,
          "mime": "image/jpeg",
          "width": 1200,
          "height": 1600,
          "alt": "Сукня спереду",
          "title": "Вигляд спереду"
        },
        "field_gallery_caption": "Передній вигляд",
        "field_gallery_featured": 1
      },
      {
        "item_id": 102,
        "delta": 1,
        "archived": 0,
        "field_gallery_image": {
          "fid": 5002,
          "filename": "dress-back.jpg",
          "uri": "public://products/dress-back.jpg",
          "url": "https://old-site.com/sites/default/files/products/dress-back.jpg",
          "filesize": 238901,
          "mime": "image/jpeg",
          "width": 1200,
          "height": 1600,
          "alt": "Сукня ззаду",
          "title": "Вигляд ззаду"
        },
        "field_gallery_caption": "Задній вигляд",
        "field_gallery_featured": 0
      }
    ]
  }
}
```

**Переваги цього варіанту:**
- ✅ Всі дані в одному запиті (менше HTTP запитів)
- ✅ Зрозуміла структура items масиву
- ✅ Легко обробляти в циклі
- ✅ Поля плоскі (не обгорнуті в "fields")
- ✅ Підтримує вкладеність
- ✅ Зберігає delta для порядку
- ✅ Містить item_id для маппінгу

---

## Варіант 2: Окремий endpoint для field collections

### GET /json-api/node/123

```json
{
  "nid": 123,
  "title": "Товар: Сукня літня",
  "type": "product",
  "body": "Опис товару...",
  "field_price": 1500,
  "field_category": [25, 36],

  "_field_collections": {
    "field_specifications": "field_collection",
    "field_product_gallery": "field_collection"
  }
}
```

### GET /json-api/field-collections?entity_type=node&entity_id=123

```json
{
  "entity_type": "node",
  "entity_id": 123,
  "collections": {
    "field_specifications": [
      {
        "item_id": 45,
        "delta": 0,
        "bundle": "field_specifications",
        "archived": 0,
        "fields": {
          "field_spec_title": "Розмір",
          "field_spec_value": "M, L, XL",
          "field_spec_icon": {
            "fid": 789,
            "filename": "size-icon.png",
            "url": "https://old-site.com/sites/default/files/icons/size-icon.png"
          }
        }
      },
      {
        "item_id": 46,
        "delta": 1,
        "bundle": "field_specifications",
        "archived": 0,
        "fields": {
          "field_spec_title": "Матеріал",
          "field_spec_value": "Бавовна 95%, Еластан 5%",
          "field_spec_icon": null
        }
      }
    ],
    "field_product_gallery": [
      {
        "item_id": 101,
        "delta": 0,
        "bundle": "field_product_gallery",
        "archived": 0,
        "fields": {
          "field_gallery_image": {
            "fid": 5001,
            "filename": "dress-front.jpg",
            "url": "https://old-site.com/sites/default/files/products/dress-front.jpg",
            "alt": "Сукня спереду",
            "width": 1200,
            "height": 1600
          },
          "field_gallery_caption": "Передній вигляд",
          "field_gallery_featured": 1
        }
      }
    ]
  }
}
```

---

## Варіант 3: З вкладеними Field Collections

```json
{
  "nid": 456,
  "title": "Рецепт: Борщ український",
  "type": "recipe",

  "field_recipe_steps": {
    "field_type": "field_collection",
    "target_bundle": "field_recipe_steps",
    "cardinality": -1,
    "items": [
      {
        "item_id": 201,
        "delta": 0,
        "archived": 0,
        "field_step_number": 1,
        "field_step_title": "Підготовка інгредієнтів",
        "field_step_description": "Почистити овочі, нарізати...",
        "field_step_image": {
          "fid": 3001,
          "filename": "step1.jpg",
          "url": "https://old-site.com/files/recipe/step1.jpg"
        },

        "field_step_ingredients": {
          "field_type": "field_collection",
          "target_bundle": "field_step_ingredients",
          "cardinality": -1,
          "items": [
            {
              "item_id": 301,
              "delta": 0,
              "archived": 0,
              "field_ingredient_name": "Буряк",
              "field_ingredient_amount": "500",
              "field_ingredient_unit": "г"
            },
            {
              "item_id": 302,
              "delta": 1,
              "archived": 0,
              "field_ingredient_name": "Картопля",
              "field_ingredient_amount": "400",
              "field_ingredient_unit": "г"
            }
          ]
        }
      },
      {
        "item_id": 202,
        "delta": 1,
        "archived": 0,
        "field_step_number": 2,
        "field_step_title": "Варіння бульйону",
        "field_step_description": "Поставити м'ясо варитись...",
        "field_step_image": {
          "fid": 3002,
          "filename": "step2.jpg",
          "url": "https://old-site.com/files/recipe/step2.jpg"
        },

        "field_step_ingredients": {
          "field_type": "field_collection",
          "target_bundle": "field_step_ingredients",
          "cardinality": -1,
          "items": [
            {
              "item_id": 303,
              "delta": 0,
              "archived": 0,
              "field_ingredient_name": "М'ясо",
              "field_ingredient_amount": "600",
              "field_ingredient_unit": "г"
            },
            {
              "item_id": 304,
              "delta": 1,
              "archived": 0,
              "field_ingredient_name": "Вода",
              "field_ingredient_amount": "2",
              "field_ingredient_unit": "л"
            }
          ]
        }
      }
    ]
  }
}
```

---

## Варіант 4: З Entity Reference в Field Collection

```json
{
  "nid": 789,
  "title": "Статья про туризм",
  "type": "article",

  "field_related_content": {
    "field_type": "field_collection",
    "target_bundle": "field_related_content",
    "cardinality": -1,
    "items": [
      {
        "item_id": 501,
        "delta": 0,
        "archived": 0,
        "field_related_title": "Рекомендовані місця",
        "field_related_nodes": [
          {
            "nid": 111,
            "title": "Карпати влітку",
            "type": "place"
          },
          {
            "nid": 112,
            "title": "Озеро Синевир",
            "type": "place"
          }
        ],
        "field_related_terms": [
          {
            "tid": 25,
            "name": "Гори",
            "vocabulary": "tags"
          },
          {
            "tid": 36,
            "name": "Природа",
            "vocabulary": "tags"
          }
        ]
      }
    ]
  }
}
```

---

## Варіант 5: Багатомовність (з перекладами)

```json
{
  "nid": 999,
  "title": "Multilingual Product",
  "type": "product",
  "language": "uk",

  "field_features": {
    "field_type": "field_collection",
    "target_bundle": "field_features",
    "cardinality": -1,
    "translatable": true,
    "items": {
      "uk": [
        {
          "item_id": 601,
          "delta": 0,
          "langcode": "uk",
          "archived": 0,
          "field_feature_name": "Водонепроникність",
          "field_feature_value": "IPX7",
          "field_feature_icon": {
            "fid": 4001,
            "filename": "water-icon.png",
            "url": "https://old-site.com/files/icons/water-icon.png"
          }
        }
      ],
      "en": [
        {
          "item_id": 602,
          "delta": 0,
          "langcode": "en",
          "archived": 0,
          "field_feature_name": "Water Resistance",
          "field_feature_value": "IPX7",
          "field_feature_icon": {
            "fid": 4001,
            "filename": "water-icon.png",
            "url": "https://old-site.com/files/icons/water-icon.png"
          }
        }
      ]
    }
  }
}
```

---

## Ключові елементи структури

### 1. Обов'язкові поля для кожного field collection item

```json
{
  "item_id": 45,        // Для маппінгу field_collection_item → paragraph
  "delta": 0,           // Порядок сортування
  "archived": 0,        // Чи архівований (0/1)
  "bundle": "field_specifications"  // Тип field collection (для варіанту 2)
}
```

### 2. Метадані для поля (опціонально, але корисно)

```json
{
  "field_type": "field_collection",
  "target_bundle": "field_specifications",
  "cardinality": -1,    // -1 = unlimited, 1,2,3... = конкретна кількість
  "translatable": true  // Чи є переклади
}
```

### 3. Для файлів/зображень (мінімум)

```json
{
  "fid": 789,
  "filename": "image.jpg",
  "url": "https://old-site.com/sites/default/files/image.jpg",
  "filesize": 2048,      // Опціонально
  "mime": "image/jpeg",  // Опціонально
  "alt": "Alt text",     // Для зображень
  "title": "Title"       // Для зображень
}
```

### 4. Для entity reference

```json
{
  "nid": 111,           // Або tid, uid
  "title": "Node title",
  "type": "article"     // Або vocabulary для термінів
}
```

---

## Алгоритм конвертації

### Крок 1: Підготовка (один раз)

1. **Створити Paragraph Types в Drupal 11**
   - Для кожного field collection type створити відповідний paragraph type
   - Додати ті ж поля що були в field collection

2. **Оновити Content Type**
   - Замінити field collection поле на Entity Reference Revisions поле
   - Тип поля: paragraph
   - Дозволені типи: вибрати створені paragraph types

### Крок 2: JSON API Endpoint для Drupal 7

Потрібен endpoint який поверне field collections (один з варіантів вище).

### Крок 3: Процес імпорту ноди з конвертацією

```php
// Псевдокод
function importNodeWithFieldCollections($node_data) {

  // 1. Отримати field collections для цієї ноди (якщо варіант 2)
  // Для варіанту 1 - дані вже в $node_data

  // 2. Створити node
  $node = Node::create([
    'type' => $node_data['type'],
    'title' => $node_data['title'],
  ]);

  // 3. Для кожного field collection поля
  foreach ($node_data as $field_name => $field_data) {

    // Перевіряємо чи це field collection
    if (!isset($field_data['field_type']) || $field_data['field_type'] !== 'field_collection') {
      continue;
    }

    $paragraph_references = [];

    // 4. Створити paragraph для кожного item
    foreach ($field_data['items'] as $item) {

      // 5. Створити paragraph
      $paragraph = createParagraphFromItem($item, $field_data['target_bundle']);

      // 6. Зберегти маппінг
      $mapping_service->saveMapping(
        'field_collection_item',
        $item['item_id'],
        $paragraph->id(),
        $field_data['target_bundle']
      );

      // 7. Додати reference
      $paragraph_references[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    // 8. Прив'язати paragraphs до ноди
    $node->set($field_name, $paragraph_references);
  }

  $node->save();
}

function createParagraphFromItem($item, $bundle) {
  $paragraph = Paragraph::create([
    'type' => $bundle,
  ]);

  // Встановити всі поля
  foreach ($item as $field_name => $value) {
    // Пропускаємо service поля
    if (in_array($field_name, ['item_id', 'delta', 'archived', 'bundle'])) {
      continue;
    }

    // Перевіряємо чи це вкладена field collection
    if (is_array($value) && isset($value['field_type']) && $value['field_type'] === 'field_collection') {
      // Рекурсивна обробка вкладених field collections
      $nested_paragraphs = [];
      foreach ($value['items'] as $nested_item) {
        $nested = createParagraphFromItem($nested_item, $value['target_bundle']);
        $nested_paragraphs[] = [
          'target_id' => $nested->id(),
          'target_revision_id' => $nested->getRevisionId(),
        ];
      }
      $paragraph->set($field_name, $nested_paragraphs);
    }
    // Обробка файлів/зображень
    elseif (is_array($value) && isset($value['fid'])) {
      $file = downloadFile($value['url'], $value['filename']);
      if ($file) {
        $paragraph->set($field_name, [
          'target_id' => $file->id(),
          'alt' => $value['alt'] ?? '',
          'title' => $value['title'] ?? '',
        ]);
      }
    }
    // Обробка entity reference
    elseif (is_array($value) && isset($value[0]['nid'])) {
      $references = [];
      foreach ($value as $ref) {
        $new_nid = $mapping_service->getNewId('node', $ref['nid'], $ref['type']);
        if ($new_nid) {
          $references[] = ['target_id' => $new_nid];
        }
      }
      $paragraph->set($field_name, $references);
    }
    // Обробка taxonomy term reference
    elseif (is_array($value) && isset($value[0]['tid'])) {
      $references = [];
      foreach ($value as $ref) {
        $new_tid = $mapping_service->getNewId('term', $ref['tid'], $ref['vocabulary']);
        if ($new_tid) {
          $references[] = ['target_id' => $new_tid];
        }
      }
      $paragraph->set($field_name, $references);
    }
    // Прості значення
    else {
      $paragraph->set($field_name, $value);
    }
  }

  $paragraph->save();
  return $paragraph;
}
```

---

## Обробка перекладів

```php
// Для багатомовних field collections (варіант 5)
foreach ($field_data['items'] as $langcode => $items) {

  if ($langcode === 'uk') {
    // Основна мова - обробляємо як зазвичай
    // ...
  } else {
    // Переклад
    if (!$node->hasTranslation($langcode)) {
      $translation = $node->addTranslation($langcode);
    } else {
      $translation = $node->getTranslation($langcode);
    }

    // Створити paragraphs для перекладу
    $translated_paragraphs = [];
    foreach ($items as $item) {
      $paragraph = createParagraphFromItem($item, $field_data['target_bundle']);
      $translated_paragraphs[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    $translation->set($field_name, $translated_paragraphs);
  }
}
```

---

## Що потрібно додати в модуль

### 1. Новий endpoint в SettingsForm.php

```php
'field_collections' => [
  'title' => 'Field Collections для entity',
  'default' => '/json-api/field-collections',
],
```

### 2. Метод в Drupal7ApiClient.php

```php
/**
 * Отримати field collections для entity.
 *
 * @param string $entity_type
 *   Тип сутності: node, user, taxonomy_term.
 * @param int $entity_id
 *   ID сутності.
 *
 * @return array|null
 *   Field collections або NULL.
 */
public function getFieldCollections($entity_type, $entity_id) {
  $params = [
    'entity_type' => $entity_type,
    'entity_id' => $entity_id,
  ];
  return $this->get('field_collections', $params);
}
```

### 3. Новий Form: ImportParagraphTypesForm.php

Аналогічно до ImportContentTypesForm - створює paragraph types з field collection definitions.

### 4. Розширити ImportNodesForm.php

Додати метод `convertFieldCollectionsToParagraphs()` і викликати його при імпорті ноди.

---

## Порядок виконання міграції

```
1. Створити Paragraph Types
   → Форма: ImportParagraphTypesForm
   → Вхідні дані: JSON з описом field collection types
   → Результат: Створені paragraph types з полями

2. Оновити Content Types
   → Замінити field collection поля на paragraph поля
   → Вручну або через form

3. Імпортувати ноди
   → ImportNodesForm з підтримкою конвертації
   → Field collections → Paragraphs автоматично
   → Збереження маппінгу field_collection_item_id → paragraph_id
```

---

## Приклад PHP коду для обробки (спрощений)

```php
// В ImportNodesForm::importSingleNode()

// Перевіряємо чи є field collections в даних ноди
foreach ($node_data as $field_name => $field_value) {

  if (!is_array($field_value) || !isset($field_value['field_type'])) {
    continue;
  }

  if ($field_value['field_type'] === 'field_collection') {
    // Конвертуємо field collection в paragraphs
    $paragraphs = $this->convertFieldCollectionToParagraphs(
      $field_value['items'],
      $field_value['target_bundle']
    );

    // Встановлюємо paragraphs в ноду
    $node->set($field_name, $paragraphs);

    // Видаляємо з node_data щоб не обробляти як звичайне поле
    unset($node_data[$field_name]);
  }
}
```

---

## Важливі примітки

1. **Порядок створення (критично!):**
   - Спочатку створити Paragraph Types
   - Потім оновити Content Types
   - Потім імпортувати ноди

2. **Delta зберігається:**
   - Сортування items за delta
   - Зберігається порядок при створенні paragraphs

3. **Маппінг:**
   - Зберігати `field_collection_item.item_id` → `paragraph.id`
   - Тип маппінгу: `field_collection_item`
   - vocabulary_id: bundle name field collection

4. **Вкладеність:**
   - Рекурсивна обробка вкладених field collections
   - Спочатку створюються найглибші paragraphs

5. **Файли:**
   - Завантажувати файли аналогічно як для звичайних полів
   - Зберігати alt/title для зображень

6. **Entity References:**
   - Використовувати MappingService для конвертації старих ID в нові
   - Перевіряти наявність маппінгу перед створенням reference

---

## Тестування

### Контрольний список:

- [ ] Створено всі необхідні Paragraph Types
- [ ] Оновлено Content Types (замінено field collection на paragraph поля)
- [ ] Field collections без вкладеності імпортуються правильно
- [ ] Field collections з вкладеністю імпортуються правильно
- [ ] Зберігається порядок items (delta)
- [ ] Файли/зображення завантажуються
- [ ] Entity references конвертуються через маппінг
- [ ] Зберігається маппінг field_collection_item_id → paragraph_id
- [ ] Багатомовні field collections імпортуються з перекладами
- [ ] Archived items не імпортуються (якщо archived = 1)

---

## Висновок

Конвертація Field Collections → Paragraphs повністю можлива і є стандартною практикою міграції. Ключ до успіху:

1. ✅ Правильна структура JSON
2. ✅ Створення Paragraph Types перед імпортом
3. ✅ Рекурсивна обробка вкладеності
4. ✅ Збереження маппінгу для зв'язків
5. ✅ Обробка файлів та entity references

**Рекомендований варіант JSON:** Варіант 1 (всі дані в одному запиті, плоска структура items)
