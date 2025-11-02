<?php

/**
 * Скрипт для діагностики термінів таксономії.
 *
 * Використання:
 * drush php:script debug_taxonomy.php category
 *
 * Або з повним шляхом:
 * cd /path/to/drupal && drush php:script /path/to/debug_taxonomy.php category
 */

use Drupal\taxonomy\Entity\Term;

// Отримуємо vocabulary_id з аргументів.
$vocabulary_id = $extra[0] ?? 'category';

echo "==========================================================\n";
echo "=== Діагностика словника: {$vocabulary_id} ===\n";
echo "==========================================================\n\n";

// Завантажуємо всі терміни словника.
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$term_ids = $term_storage->getQuery()
  ->condition('vid', $vocabulary_id)
  ->accessCheck(FALSE)
  ->execute();

if (empty($term_ids)) {
  echo "Терміни не знайдені.\n";
  exit;
}

echo "Знайдено термінів: " . count($term_ids) . "\n\n";

// Завантажуємо терміни та будуємо дерево.
$terms = $term_storage->loadMultiple($term_ids);

// Групуємо за parent.
$tree = [];
$terms_by_id = [];

foreach ($terms as $term) {
  $tid = $term->id();
  $name = $term->getName();
  $langcode = $term->language()->getId();
  $parent_ids = [];

  if ($term->hasField('parent')) {
    $parent_field = $term->get('parent');
    foreach ($parent_field as $parent) {
      if (!empty($parent->target_id)) {
        $parent_ids[] = $parent->target_id;
      }
    }
  }

  // Якщо немає parent, це кореневий термін.
  if (empty($parent_ids)) {
    $parent_ids = [0];
  }

  $terms_by_id[$tid] = [
    'tid' => $tid,
    'name' => $name,
    'langcode' => $langcode,
    'parent_ids' => $parent_ids,
  ];

  foreach ($parent_ids as $parent_id) {
    if (!isset($tree[$parent_id])) {
      $tree[$parent_id] = [];
    }
    $tree[$parent_id][] = $tid;
  }
}

// Функція для виведення дерева.
function print_tree($parent_id, $tree, $terms_by_id, $level = 0) {
  if (!isset($tree[$parent_id])) {
    return;
  }

  $indent = str_repeat('  ', $level);

  foreach ($tree[$parent_id] as $tid) {
    $term_info = $terms_by_id[$tid];
    $parent_str = implode(', ', $term_info['parent_ids']);

    echo $indent . "├─ {$term_info['name']} (tid={$tid}, parent=[{$parent_str}], lang={$term_info['langcode']})\n";

    // Рекурсивно виводимо дочірні терміни.
    print_tree($tid, $tree, $terms_by_id, $level + 1);
  }
}

// Виводимо дерево.
echo "=== Дерево термінів ===\n";
echo "Кореневі терміни (parent=0):\n";
print_tree(0, $tree, $terms_by_id);

// Виводимо всі терміни у вигляді списку.
echo "\n==========================================================\n";
echo "=== Список всіх термінів ===\n";
echo "==========================================================\n";
foreach ($terms_by_id as $tid => $info) {
  $parent_str = implode(', ', $info['parent_ids']);
  echo sprintf(
    "tid=%-4s | parent=[%-10s] | lang=%s | name=%s\n",
    $tid,
    $parent_str,
    $info['langcode'],
    $info['name']
  );
}

// Перевіряємо чи є дублікати по назві.
echo "\n==========================================================\n";
echo "=== Перевірка дублікатів ===\n";
echo "==========================================================\n";
$names = [];
$duplicates_found = FALSE;
foreach ($terms_by_id as $tid => $info) {
  $key = $info['name'] . '_' . $info['langcode'];
  if (!isset($names[$key])) {
    $names[$key] = [];
  }
  $names[$key][] = $tid;
}

foreach ($names as $key => $tids) {
  if (count($tids) > 1) {
    $duplicates_found = TRUE;
    list($name, $lang) = explode('_', $key, 2);
    echo "ДУБЛІКАТ: '{$name}' (lang={$lang}) знайдено " . count($tids) . " разів - tids: " . implode(', ', $tids) . "\n";
  }
}

if (!$duplicates_found) {
  echo "Дублікатів не знайдено.\n";
}

// Виводимо статистику.
echo "\n==========================================================\n";
echo "=== Статистика ===\n";
echo "==========================================================\n";
echo "Всього термінів: " . count($terms_by_id) . "\n";
echo "Кореневих термінів (parent=0): " . (isset($tree[0]) ? count($tree[0]) : 0) . "\n";

// Підраховуємо рівні вкладеності.
$depths = [];
foreach ($terms_by_id as $tid => $info) {
  $depth = 0;
  $current_tid = $tid;
  $visited = [];

  // Йдемо вгору по дереву до кореня.
  while (isset($terms_by_id[$current_tid])) {
    if (isset($visited[$current_tid])) {
      echo "ПОПЕРЕДЖЕННЯ: Циклічне посилання виявлено для терміну tid={$tid}\n";
      break;
    }
    $visited[$current_tid] = TRUE;

    $parent_ids = $terms_by_id[$current_tid]['parent_ids'];
    if (empty($parent_ids) || $parent_ids[0] == 0) {
      break;
    }
    $current_tid = $parent_ids[0];
    $depth++;

    if ($depth > 10) {
      echo "ПОПЕРЕДЖЕННЯ: Надто глибока вкладеність для терміну tid={$tid}\n";
      break;
    }
  }

  if (!isset($depths[$depth])) {
    $depths[$depth] = 0;
  }
  $depths[$depth]++;
}

echo "\nРозподіл по рівням вкладеності:\n";
ksort($depths);
foreach ($depths as $depth => $count) {
  echo "  Рівень {$depth}: {$count} термінів\n";
}

echo "\n==========================================================\n";
echo "=== Діагностика завершена ===\n";
echo "==========================================================\n";
