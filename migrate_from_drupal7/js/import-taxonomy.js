/**
 * @file
 * JavaScript для форми імпорту таксономій.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Behavior для обробки "Вибрати всі поля".
   */
  Drupal.behaviors.migrateFromDrupal7SelectAll = {
    attach: function (context, settings) {
      // Знаходимо всі чекбокси "Вибрати всі поля"
      var selectAllCheckboxes = once('select-all', 'input[type="checkbox"][id$="select-all"]', context);

      selectAllCheckboxes.forEach(function (element) {
        var $selectAll = $(element);

        // Знаходимо батьківський контейнер полів
        var $fieldsContainer = $selectAll.closest('.form-item').parent();

        // Знаходимо всі чекбокси полів (окрім самого select_all)
        var $fieldCheckboxes = $fieldsContainer.find('input[type="checkbox"]').not($selectAll);

        // Обробник для "Вибрати всі"
        $selectAll.on('change', function () {
          var isChecked = $(this).is(':checked');
          $fieldCheckboxes.prop('checked', isChecked);
        });

        // Обробник для окремих чекбоксів (для синхронізації стану "Вибрати всі")
        $fieldCheckboxes.on('change', function () {
          var allChecked = $fieldCheckboxes.length === $fieldCheckboxes.filter(':checked').length;
          $selectAll.prop('checked', allChecked);
        });

        // Ініціалізація стану "Вибрати всі" при завантаженні
        var allChecked = $fieldCheckboxes.length === $fieldCheckboxes.filter(':checked').length;
        if (allChecked && $fieldCheckboxes.length > 0) {
          $selectAll.prop('checked', true);
        }
      });
    }
  };

})(jQuery, Drupal, once);
