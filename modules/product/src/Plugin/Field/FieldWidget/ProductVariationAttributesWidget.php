<?php

namespace Drupal\commerce_product\Plugin\Field\FieldWidget;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_product\ProductAttributeFieldManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'commerce_product_variation_attributes' widget.
 *
 * @FieldWidget(
 *   id = "commerce_product_variation_attributes",
 *   label = @Translation("Product variation attributes"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class ProductVariationAttributesWidget extends ProductVariationWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The attribute field manager.
   *
   * @var \Drupal\commerce_product\ProductAttributeFieldManagerInterface
   */
  protected $attributeFieldManager;

  /**
   * The product attribute storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $attributeStorage;

  /**
   * Constructs a new ProductVariationAttributesWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_product\ProductAttributeFieldManagerInterface $attribute_field_manager
   *   The attribute field manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, ProductAttributeFieldManagerInterface $attribute_field_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings, $entity_type_manager);

    $this->attributeFieldManager = $attribute_field_manager;
    $this->attributeStorage = $entity_type_manager->getStorage('commerce_product_attribute');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('commerce_product.attribute_field_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->get('product');
    $variations = $this->variationStorage->loadEnabled($product);
    if (count($variations) === 0) {
      // Nothing to purchase, tell the parent form to hide itself.
      $form_state->set('hide_form', TRUE);
      $element['variation'] = [
        '#type' => 'value',
        '#value' => 0,
      ];
      return $element;
    }
    elseif (count($variations) === 1) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $selected_variation */
      $selected_variation = reset($variations);
      // If there is 1 variation but there are attribute fields, then the
      // customer should still see the attribute widgets, to know what they're
      // buying (e.g a product only available in the Small size).
      if (empty($this->attributeFieldManager->getFieldDefinitions($selected_variation->bundle()))) {
        $element['variation'] = [
          '#type' => 'value',
          '#value' => $selected_variation->id(),
        ];
        return $element;
      }
    }

    // Build the full attribute form.
    $ids = $form_state->getFormObject()->getFormInstanceIds();
    $id = end($ids);
    $wrapper_id = Html::getUniqueId('commerce-product-add-to-cart-form-' . $id);
    $form += [
      '#wrapper_id' => $wrapper_id,
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];
    $creator = \Drupal::service('commerce_product.variation_bulk_creator');
    $all = $creator->getUsedAttributesCombinations($variations)['combinations'];
    $variation = reset($variations);
    if ($trigger = $form_state->getTriggeringElement()) {
      $parents = array_merge($element['#field_parents'], [$items->getName(), $delta]);
      $user_input = (array) NestedArray::getValue($form_state->getUserInput(), $parents);
      $user_input['trigger_value'] = $trigger['#value'];
      $user_input['all'] = $all;
      $selected_variation = $this->selectVariationFromUserInput($variations, $user_input);
    }
    else {
      $selected_variation = $this->variationStorage->loadFromContext($product);
      // The returned variation must also be enabled.
      if (!in_array($selected_variation, $variations)) {
        $selected_variation = $variation;
      }
    }

    $element['variation'] = [
      '#type' => 'value',
      '#value' => $selected_variation->id(),
    ];
    // Set the selected variation in the form state for our AJAX callback.
    $form_state->set('selected_variation', $selected_variation->id());

    $element['attributes'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['attribute-widgets'],
      ],
    ];

    if ($all) {
      $variations = ['all' => $all, 'options' => $creator->getAttributeFieldOptionIds($variation)['options']];
    }
    $attribute_info = $this->getAttributeInfo($selected_variation, $variations);

    foreach ($attribute_info as $field_name => $attribute) {
      $element['attributes'][$field_name] = [
        '#id' => Html::getUniqueId('edit-purchased-entity-0-attributes-' . $field_name . '-' . $id),
        '#type' => $attribute['element_type'],
        '#title' => $attribute['title'],
        '#options' => $attribute['values'],
        '#required' => $attribute['required'],
        '#default_value' => $attribute['default_value'],
        '#ajax' => [
          'callback' => [get_class($this), 'ajaxRefresh'],
          'wrapper' => $form['#wrapper_id'],
        ],
      ];
      // Convert the _none option into #empty_value.
      if (isset($element['attributes'][$field_name]['#options']['_none'])) {
        if (!$element['attributes'][$field_name]['#required']) {
          $element['attributes'][$field_name]['#empty_value'] = '';
        }
        unset($element['attributes'][$field_name]['#options']['_none']);
      }
      // 1 required value -> Disable the element to skip unneeded ajax calls.
      if ($attribute['required'] && count($attribute['values']) === 1) {
        $element['attributes'][$field_name]['#disabled'] = TRUE;
      }
      // Optimize the UX of optional attributes:
      // - Hide attributes that have no values.
      // - Require attributes that have a value on each variation.
      if (empty($element['attributes'][$field_name]['#options'])) {
        $element['attributes'][$field_name]['#access'] = FALSE;
      }
      if (!isset($element['attributes'][$field_name]['#empty_value'])) {
        $element['attributes'][$field_name]['#required'] = TRUE;
      }

    }

    return $element;
  }

  /**
   * Selects a product variation from user input.
   *
   * If there's no user input (form viewed for the first time), the default
   * variation is returned.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   An array of product variations.
   * @param array $user_input
   *   The user input and all the variation type attributes combinations.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The selected variation.
   */
  protected function selectVariationFromUserInput(array $variations, array $user_input) {
    if (!empty($user_input)) {
      $user_input['valid'] = $user_input['attributes'];
      if ($user_input['all']) {
        $user_input['valid'] = [];
        // The $creator returns '_none' for combinations with optional fields,
        // but $user_input contains '0' for those fields, so change '0' to
        // '_none' for filtering out unrelevant combinations properly.
        $none_id = '0';
        $trigger_name = array_search($user_input['trigger_value'], $user_input['attributes']);
        $trigger_value = $user_input['trigger_value'] == $none_id ? '_none' : $user_input['trigger_value'];
        foreach ($user_input['all'] as $index => $combination) {
          if ($combination[$trigger_name] != $trigger_value) {
            unset($user_input['all'][$index]);
          }
          else {
            foreach ($user_input['attributes'] as $key => $value) {
              $value = $value == $none_id ? '_none' : $value;
              if ($combination[$key] == $value) {
                $user_input['valid'][$key] = $value;
              }
            }
          }
        }
        foreach ($user_input['all'] as $index => $combination) {
          $merged = array_merge($combination, $user_input['valid']);
          // The exact attributes combination selected by a user is found.
          if ($combination == $merged) {
            $user_input['attributes'] = $combination;
          }
        }
      }
      unset($user_input['all']);
      $attributes = $user_input['attributes'];
      $selected_variation = NULL;
      foreach ($variations as $variation) {
        $values = [];
        foreach ($attributes as $field_name => $value) {
          $id = $variation->getAttributeValueId($field_name);
          $values[$field_name] = is_null($id) ? '_none' : $id;
          $merged = array_merge($user_input['valid'], $values);
          // Select variation having at least some valid attribute ids.
          if ($user_input['valid'] == $merged) {
            $selected_variation = $variation;
          }
        }
        $merged = array_merge($attributes, $values);
        // The exact selected variation is found.
        if ($attributes == $merged) {
          $selected_variation = $variation;
          break;
        }
      }
    }

    return $selected_variation ?: reset($variations);
  }

  /**
   * Gets the attribute information for the selected product variation.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $selected_variation
   *   The selected product variation.
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[]|array $variations
   *   The available product variations or an array of the current variation
   *   type attributes combinations and options.
   *
   * @return array[]
   *   The attribute information, keyed by field name.
   */
  protected function getAttributeInfo(ProductVariationInterface $selected_variation, array $variations) {
    $bundle = $selected_variation->bundle();
    $field_definitions = $this->attributeFieldManager->getFieldDefinitions($bundle);
    $field_map = $this->attributeFieldManager->getFieldMap($bundle);
    $field_names = array_unique(array_column($field_map, 'field_name'));
    $form_display = entity_get_form_display($selected_variation->getEntityTypeId(), $bundle, 'default');
    if (($last = end($variations)) && $last instanceof ProductVariationInterface) {
      $creator = \Drupal::service('commerce_product.variation_bulk_creator');
      $all = $creator->getUsedAttributesCombinations($variations)['combinations'];
      $options = $creator->getAttributeFieldOptionIds($last)['options'];
    }
    else {
      $all = $variations['all'];
      $options = $variations['options'];
    }
    $attributes = $values = $default_settings = [];
    // As fields with _none value are not returned so we need to restore them.
    $selected_ids = array_merge(array_fill_keys($field_names, '_none'), $selected_variation->getAttributeValueIds());
    $previous_field_name = array_keys($selected_ids)[0];
    $previous_field_id = reset($selected_ids);
    $default_settings['skip_option_label'] = $this->t('No, thanks!');
    $default_settings['no_options_label'] = $this->t('No options available ...');
    $default_settings['hide_no_options'] = FALSE;
    // The id of an empty option. If changing the value then change the
    // condition with the same value in self->selectVariationFromUserInput().
    $none_id = '0';
    // Prevent memory exhaustion as $variations array can be quite heavy.
    unset($variations, $selected_variation, $creator);

    foreach ($field_names as $index => $field_name) {
      $field_id = $selected_ids[$field_name];
      $values[$field_name] = [];
      /** @var \Drupal\commerce_product\Entity\ProductAttributeInterface $attribute_type */
      $attribute_type = $this->attributeStorage->load($field_map[$index]['attribute_id']);
      //$field = $field_definitions[$field_name];
      $attributes[$field_name] = [
        'field_name' => $field_name,
        'title' => $field_definitions[$field_name]->getLabel(),
        'required' => $field_definitions[$field_name]->isRequired(),
        'element_type' => $attribute_type->getElementType(),
        'default_value' => $field_id,
      ];
      unset($field_definitions[$field_name]);
      // The first attribute gets all values. Every next attribute gets only
      // the values from variations matching the previous attribute value.
      // For 'Color' and 'Size' attributes that means getting the colors of all
      // variations, but only the sizes of variations with the selected color.
      foreach ($all as $indeks => $combination) {
        if ($index && $combination[$previous_field_name] != $previous_field_id) {
          // Improve perfomance unsetting unrelevant combinations.
          unset($all[$indeks]);
          continue;
        }
        else {
          $option = [];
          // Add dummy empty option to choose nothing on an optional field.
          if ($combination[$field_name] == '_none') {
            $settings = $form_display->getRenderer($field_name)->getSettings() + $default_settings;
            $option_id = $none_id;
            $label = $settings['skip_option_label'];
          }
          else {
            $option_id = $combination[$field_name];
            $label = $options[$field_name][$combination[$field_name]];
          }
          $option[$option_id] = $label;

          // In order to avoid weird results after reordering attribute fields
          // ensure that selected option is at the top of the list.
          // @see https://www.drupal.org/node/2707721
          // @see https://www.drupal.org/files/issues/_add%20to%20cart.png
          if ($combination[$field_name] == $field_id) {
            $values[$field_name] = $option + $values[$field_name];
          }
          else {
            $values[$field_name] += $option;
          }
        }
      }
      $single = count($values[$field_name]) == 1;
      $no_options = $single && array_keys($values[$field_name])[0] == $none_id;
      if (empty($values[$field_name]) || ($no_options && $settings['hide_no_options'])) {
        unset($attributes[$field_name]);
        continue;
      }
      if ($no_options) {
        $values[$field_name][$none_id] = $settings['no_options_label'];
      }
      $attributes[$field_name]['required'] = $single ?: $attributes[$field_name]['required'];
      $attributes[$field_name]['values'] = $values[$field_name];
      $previous_field_id = $field_id;
      $previous_field_name = $field_name;
    }

    return $attributes;
  }

  /**
   * Gets the attribute values of a given set of variations.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   The variations.
   * @param string $field_name
   *   The field name of the attribute.
   * @param callable|null $callback
   *   An optional callback to use for filtering the list.
   *
   * @return array[]
   *   The attribute values, keyed by attribute ID.
   */
  protected function getAttributeValues(array $variations, $field_name, callable $callback = NULL) {
    $values = [];
    foreach ($variations as $variation) {
      if (is_null($callback) || call_user_func($callback, $variation)) {
        $attribute_value = $variation->getAttributeValue($field_name);
        if ($attribute_value) {
          $values[$attribute_value->id()] = $attribute_value->label();
        }
        else {
          $values['_none'] = '';
        }
      }
    }

    return $values;
  }

}
