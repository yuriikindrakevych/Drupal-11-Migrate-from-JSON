<?php
function batch_node_translate_translate($node, $source_lang, $target_lang, $fields_to_translate, $translate_mode, $path_type)
{
    if ($source_lang === $target_lang) {
        return;
    }

    // Check if translation already exists
    if ($node->hasTranslation($target_lang)) {
        \Drupal::logger('batch_node_translate')->notice(
            'Translation already exists for node @nid (target: @target)',
            [
                '@nid' => $node->id(),
                '@target' => $target_lang
            ]
        );
        return;
    }

    $config = \Drupal::config('batch_node_translate.settings');
    $translation_service = $config->get('translation_service') ?: 'deepl';

    // Get translatable and duplicate field types
    $translatable_field_types = [
        'text_with_summary',
        'string',
        'string_long',
        'text_long',
        'text',
        'image',
    ];

    $data = [];
    foreach ($fields_to_translate as $field) {
        if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
            continue;
        }

        $field_type = $node->get($field)->getFieldDefinition()->getType();

        // Only translate specific field types, others will be duplicated
        if (!in_array($field_type, $translatable_field_types)) {
            continue;
        }

        switch ($field_type) {
            case 'text_with_summary':
                $data["{$field}_value"] = $node->get($field)->value;
                if (!empty($node->get($field)->summary)) {
                    $data["{$field}_summary"] = $node->get($field)->summary;
                }
                break;
            case 'image':
                $items = $node->get($field)->getValue();
                foreach ($items as $delta => $item) {
                    if (!empty($item['alt'])) {
                        $data["{$field}_{$delta}_alt"] = $item['alt'];
                    }
                }
                break;
            default:
                $data["{$field}_value"] = $node->get($field)->value;
                break;
        }
    }

    $translated_data = [];
    if ($translate_mode === 'translate' && !empty($data)) {
        $translated_data = batch_node_translate_request($data, $source_lang, $target_lang, $translation_service);
    }

    // Create translation
    \Drupal::logger('batch_node_translate')->notice(
        'Creating translation for node @nid (from @source to @target)',
        [
            '@nid' => $node->id(),
            '@source' => $source_lang,
            '@target' => $target_lang
        ]
    );

    $translation = $node->addTranslation($target_lang);

    foreach ($fields_to_translate as $field) {
        if (!$node->hasField($field)) {
            continue;
        }

        $field_type = $node->get($field)->getFieldDefinition()->getType();
        $field_definition = $node->get($field)->getFieldDefinition();

        switch ($field_type) {
            case 'text_with_summary':
                if (in_array($field_type, $translatable_field_types) && isset($translated_data["{$field}_value"])) {
                    $translation->get($field)->setValue([
                        'value' => $translated_data["{$field}_value"],
                        'format' => $node->get($field)->format,
                        'summary' => isset($translated_data["{$field}_summary"]) ? $translated_data["{$field}_summary"] : ''
                    ]);
                } else {
                    // Duplicate the field
                    $translation->set($field, $node->get($field)->getValue());
                }
                break;

            case 'image':
                $items = $node->get($field)->getValue();
                if (in_array($field_type, $translatable_field_types)) {
                    foreach ($items as $delta => $item) {
                        if (isset($translated_data["{$field}_{$delta}_alt"])) {
                            $items[$delta]['alt'] = $translated_data["{$field}_{$delta}_alt"];
                        }
                    }
                }
                $translation->get($field)->setValue($items);
                break;

            case 'entity_reference':
                if ($field_definition->getSetting('target_type') === 'taxonomy_term') {
                    $original_terms = $node->get($field)->referencedEntities();
                    $translated_terms = [];
                    foreach ($original_terms as $term) {
                        if ($term->hasTranslation($target_lang)) {
                            $translated_terms[] = $term->getTranslation($target_lang)->id();
                        } else {
                            $translated_terms[] = $term->id();
                        }
                    }
                    if (!empty($translated_terms)) {
                        $translation->set($field, $translated_terms);
                    }
                } else {
                    $translation->set($field, $node->get($field)->getValue());
                }
                break;

            default:
                if (in_array($field_type, $translatable_field_types) && isset($translated_data["{$field}_value"])) {
                    $translation->set($field, $translated_data["{$field}_value"]);
                } else {
                    // Duplicate the field for non-translatable types
                    $translation->set($field, $node->get($field)->getValue());
                }
                break;
        }
    }

    // Copy other fields that are not in the fields_to_translate array
    foreach ($node->getFieldDefinitions() as $field_name => $field_definition) {
        if (in_array($field_name, [
                'nid', 'uuid', 'vid', 'langcode', 'default_langcode',
                'content_translation_source', 'content_translation_outdated',
                'revision_translation_affected', 'revision_default'
            ]) || in_array($field_name, $fields_to_translate)) {
            continue;
        }

        if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
            $translation->set($field_name, $node->get($field_name)->getValue());
        }
    }

    $translation->set('status', $node->isPublished() ? 1 : 0);
    $translation->set('default_langcode', 0);
    $translation->save();

    // Handle path aliases
    if ($path_type === 'duplicate') {
        $alias_storage = \Drupal::service('path_alias.repository');
        $path = '/node/' . $node->id();
        $new_path = '/node/' . $translation->id();
        $original_alias = $alias_storage->lookupBySystemPath($path, $source_lang);

        if (!empty($original_alias)) {
            $or_alias = $original_alias['alias'];
            $path_alias = PathAlias::create([
                'path' => $new_path,
                'alias' => $or_alias,
                'langcode' => $target_lang,
            ]);
            $path_alias->save();
        }
    }

    \Drupal::logger('batch_node_translate')->notice(
        'Successfully created translation for node @nid (from @source to @target)',
        [
            '@nid' => $node->id(),
            '@source' => $source_lang,
            '@target' => $target_lang
        ]
    );
}