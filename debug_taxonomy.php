<?php

/**
 * Скрипт для діагностики термінів таксономії.
 *
 * Використання:
 * drush php:script debug_taxonomy.php category
 */

use Drupal\taxonomy\Entity\Term;

// Отримуємо vocabulary_id з аргументів.
$vocabulary_id = $argv[1] ?? 'category';

echo "=== Діагностика словника: {$vocabulary_id} ===\n\n";

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
echo "\n=== Список всіх термінів ===\n";
foreach ($terms_by_id as $tid => $info) {
  $parent_str = implode(', ', $info['parent_ids']);
  echo "tid={$tid} | name={$info['name']} | parent=[{$parent_str}] | lang={$info['langcode']}\n";
}

echo "\n=== Діагностика завершена ===\n";
