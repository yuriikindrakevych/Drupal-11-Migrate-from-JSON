/**
 * @file
 * JavaScript для форми імпорту таксономій.
 */

(function (Drupal) {
  'use strict';

  /**
   * Behavior для обробки "Вибрати всі поля".
   */
  Drupal.behaviors.migrateFromDrupal7SelectAll = {
    attach: function (context, settings) {
      // Знаходимо всі чекбокси "Вибрати всі поля"
      var selectAllCheckboxes = context.querySelectorAll('input[type="checkbox"][id$="select-all"]');

      selectAllCheckboxes.forEach(function (selectAllCheckbox) {
        // Перевіряємо чи вже обробили цей елемент
        if (selectAllCheckbox.dataset.selectAllProcessed) {
          return;
        }
        selectAllCheckbox.dataset.selectAllProcessed = 'true';

        // Знаходимо батьківський контейнер полів
        var formItem = selectAllCheckbox.closest('.form-item');
        if (!formItem) {
          return;
        }
        var fieldsContainer = formItem.parentElement;
        if (!fieldsContainer) {
          return;
        }

        // Знаходимо всі чекбокси полів (окрім самого select_all)
        var allCheckboxes = fieldsContainer.querySelectorAll('input[type="checkbox"]');
        var fieldCheckboxes = [];
        allCheckboxes.forEach(function (checkbox) {
          if (checkbox !== selectAllCheckbox) {
            fieldCheckboxes.push(checkbox);
          }
        });

        // Обробник для "Вибрати всі"
        selectAllCheckbox.addEventListener('change', function () {
          var isChecked = this.checked;
          fieldCheckboxes.forEach(function (checkbox) {
            checkbox.checked = isChecked;
          });
        });

        // Обробник для окремих чекбоксів (для синхронізації стану "Вибрати всі")
        fieldCheckboxes.forEach(function (checkbox) {
          checkbox.addEventListener('change', function () {
            var allChecked = fieldCheckboxes.every(function (cb) {
              return cb.checked;
            });
            selectAllCheckbox.checked = allChecked;
          });
        });

        // Ініціалізація стану "Вибрати всі" при завантаженні
        if (fieldCheckboxes.length > 0) {
          var allChecked = fieldCheckboxes.every(function (cb) {
            return cb.checked;
          });
          selectAllCheckbox.checked = allChecked;
        }
      });
    }
  };

})(Drupal);
