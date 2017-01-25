<?php

namespace Drupal\commerce_product;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\commerce_price\Price;
use Drupal\Component\Utility\NestedArray;

/**
 * Default implementation of the ProductVariationBulkCreatorInterface.
 */
class ProductVariationBulkCreator implements ProductVariationBulkCreatorInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ProductVariationBulkCreator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSkuSettings(ProductVariation $variation) {
    $form_display = entity_get_form_display($variation->getEntityTypeId(), $variation->bundle(), 'default');
    /** @var Drupal\commerce_product\Plugin\Field\FieldWidget\ProductVariationSkuWidget $widget */
    /** @var Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget $widget */
    $widget = $form_display->getRenderer('sku');
    // If no one widget is enabled, then we need to asign uniqid() SKUs at the
    // background to avoid having variations without SKU at all.
    $default_sku_settings = [
      'uniqid_enabled' => TRUE,
      'more_entropy' => FALSE,
      'prefix' => 'default_sku-',
      'suffix' => '',
    ];
    return $widget ? $widget->getSettings() : $default_sku_settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function getAutoSku(ProductVariation $variation) {
    extract(static::getSkuSettings($variation));

    // Do return empty string in case of StringTextfieldWidget.
    return isset($uniqid_enabled) ? ($uniqid_enabled ? \uniqid($prefix, $more_entropy) . $suffix : "{$prefix}{$suffix}") : '';
  }

  /**
   * {@inheritdoc}
   */
  public static function afterBuildPreRenderArrayAlter(array $element) {
    $i = 0;
    while (isset($element['alter_data_' . $i]) && $data = $element['alter_data_' . $i]) {
      $parents = [];
      if (isset($data['#parents'])) {
        $parents = $data['#parents'];
        unset($data['#parents']);
      }
      unset($element['alter_data_' . $i]);
      $key_exists = NULL;
      $old_data = NestedArray::getValue($element, $parents, $key_exists);
      if (is_array($old_data)) {
        $data = array_replace($old_data, $data);
      }
      elseif ($key_exists && !in_array($old_data, $data)) {
        $data[] = $old_data;
      }
      NestedArray::setValue($element, $parents, $data);
      $i++;
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function createSampleProductVariation(Product $product, array $variation_custom_values = []) {
    $variations = $product->getVariations();
    $variation = end($variations);
    $timestamp = time();
    if (!$variation instanceof ProductVariation) {
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->create([
        'type' => $product->getFieldDefinition('variations')->getSettings()['handler_settings']['target_bundles'][0],
        'created' => $timestamp,
        'changed' => $timestamp,
      ]);
    }
    $field = $this->getAttributeFieldOptionIds($variation);
    if ($all = $this->getUsedAttributesCombinations($variations)) {
      foreach ($all['possible']['combinations'] as $combination) {
        if (!in_array($combination, $all['combinations'])) {
          foreach ($combination as $field_name => $id) {
            $field['ids'][$field_name] = [$id];
          }
          break;
        }
      }
    }
    foreach ($field['ids'] as $field_name => $ids) {
      $variation->get($field_name)->setValue(['target_id' => $ids[0] == '_none' ? NULL : $ids[0]]);
    }

    $sku = static::getAutoSku($variation);
    $variation->setSku(empty($sku) ? \uniqid() : $sku);

    foreach ($variation_custom_values as $name => $value) {
      $variation->set($name, $value);
    }
    if (!$variation->getPrice() instanceof Price) {
      $currency_storage = $this->entityTypeManager->getStorage('commerce_currency');
      $currencies = array_keys($currency_storage->loadMultiple());
      $currency = empty($currencies) ? 'USD' : $currencies[0];
      // Decimals are omitted intentionally as $currency format is unknown here.
      // The prices still have valid format after saving.
      $variation->setPrice(new Price('1', $currency));
    }
    $variation->updateOriginalValues();

    return $variation;
  }

  /**
   * {@inheritdoc}
   */
  public function createAllProductVariations(Product $product, array $variation_custom_values = []) {
    $variations = $product->getVariations();
    $timestamp = time();
    if (empty($variations) || !empty($variation_custom_values)) {
      $variations[] = $this->createSampleProductVariation($product, $variation_custom_values);
      $timestamp++;
    }

    if (!$all = $this->getAllAttributesCombinations($variations)) {
      return;
    }

    // Improve perfomance by getting sku settings just once instead of
    // calling static::getAutoSku() in the loop.
    extract(static::getSkuSettings($all['last_variation']));
    $prefix = isset($prefix) ? $prefix : '';
    $suffix = isset($suffix) ? $suffix : '';
    $more_entropy = isset($more_entropy) ? $more_entropy : FALSE;
    foreach ($all['possible']['combinations'] as $combination) {
      if (!in_array($combination, $all['combinations'])) {
        $variation = $all['last_variation']->createDuplicate()
          ->setSku(\uniqid($prefix, $more_entropy) . $suffix)
          ->setChangedTime($timestamp)
          ->setCreatedTime($timestamp);
        foreach ($combination as $field_name => $id) {
          $variation->get($field_name)->setValue(['target_id' => $id == '_none' ? NULL : $id]);
        }
        $variation->updateOriginalValues();
        $variations[] = $variation;
        // To avoid the same CreatedTime on multiple variations increase the
        // $timestamp by one second instead of calling time() in the loop.
        $timestamp++;
      }
    }

    return $variations;
  }

  /**
   * {@inheritdoc}
   */
  public function createAllIefFormVariations(array $form, FormStateInterface $form_state) {
    // Rid of entity type manager here as that prevents to use instance of
    // ProductVariationBulkCreator as an AJAX callback therefore forcing to use
    // just the class name instead of object and define all functions as static.
    $this->entityTypeManager = NULL;
    $ief_id = $form['variations']['widget']['#ief_id'];
    if (!$all = $this->getIefFormAllAttributesCombinations($form_state, $ief_id)) {
      return;
    }
    $timestamp = time();
    $ief_entity = end($all['ief_entities']);
    extract(static::getSkuSettings($all['last_variation']));
    $prefix = isset($prefix) ? $prefix : '';
    $suffix = isset($suffix) ? $suffix : '';
    $more_entropy = isset($more_entropy) ? $more_entropy : FALSE;
    foreach ($all['possible']['combinations'] as $combination) {
      if (!in_array($combination, $all['combinations'])) {
        $variation = $all['last_variation']->createDuplicate()
          ->setSku(\uniqid($prefix, $more_entropy) . $suffix)
          ->setChangedTime($timestamp)
          ->setCreatedTime($timestamp);
        foreach ($combination as $field_name => $id) {
          $variation->get($field_name)->setValue(['target_id' => $id == '_none' ? NULL : $id]);
        }
        $variation->updateOriginalValues();
        $ief_entity['entity'] = $variation;
        $ief_entity['weight'] += 1;
        $ief_entity['needs_save'] = TRUE;
        array_push($all['ief_entities'], $ief_entity);
        $timestamp++;
      }
    }
    $form_state->set(['inline_entity_form', $ief_id, 'entities'], $all['ief_entities']);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function getIefFormAllAttributesCombinations(FormStateInterface $form_state, $ief_id = '') {
    $this->entityTypeManager = NULL;
    $ief_entities = $form_state->get(['inline_entity_form', $ief_id, 'entities']) ?: [];
    $variations = array_column($ief_entities, 'entity');
    if ($all = $this->getAllAttributesCombinations($variations)) {
      $all['ief_entities'] = $ief_entities;
    }

    return $all;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllAttributesCombinations(array $variations) {
    if (!$all = $this->getUsedAttributesCombinations($variations)) {
      return;
    }
    $all['used'] = $all['duplicated'] = [];
    foreach ($all['combinations'] as $combination) {
      if (in_array($combination, $all['used'])) {
        $all['duplicated'][] = $combination;
      }
      else {
        $all['used'][] = $combination;
      }
    }
    if (!empty($all['duplicated'])) {
      $field_options = $this->getAttributeFieldOptionIds($all['last_variation']);
      $all['duplications_list'] = '<ul>';
      foreach ($all['duplicated'] as $fields) {
        $label = [];
        foreach ($fields as $field_name => $id) {
          if (isset($field_options['options'][$field_name][$id])) {
            $label[] = $field_options['options'][$field_name][$id];
          }
        }
        $label = Html::escape(implode(', ', $label));
        $all['duplications_list'] .= '<li>' . $label . '</li>';
      }
      $all['duplications_list'] .= '</ul>';
      $all['duplications_list'] = Markup::create($all['duplications_list']);
    }
    else {
      $all['duplicated'] = $all['duplications_list'] = FALSE;
    }

    return $all;
  }

  /**
   * {@inheritdoc}
   */
  public function getUsedAttributesCombinations(array $variations) {
    $all = [];
    $all['last_variation'] = end($variations);
    if (!$all['last_variation'] instanceof ProductVariation) {
      return;
    }
    $all['possible'] = $this->getAttributesCombinations($all['last_variation']);
    $nones = array_fill_keys(array_keys($this->getAttributeFieldOptionIds($all['last_variation'])['ids']), '_none');
    foreach ($variations as $variation) {
      // ProductVariation->getAttributeValueIds() does not return empty optional
      // fields. Merge 'field_name' => '_none' as a choice in the combination.
      // @todo Render '_none' option on an Add to Cart form.
      // @see ProductVariationAttributesWidget->formElement()
      // @see CommerceProductRenderedAttribute::processRadios()
      $all['combinations'][] = array_merge($nones, $variation->getAttributeValueIds());
    }

    return $all;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttributeFieldOptionIds(ProductVariation $variation) {
    $field_options = $fields = $ids = $options = [];
    foreach ($this->getAttributeFieldNames($variation) as $field_name) {
      $definition = $variation->get($field_name)->getFieldDefinition();
      $fields[$field_name] = $definition->getFieldStorageDefinition()
        ->getOptionsProvider('target_id', $variation)
        ->getSettableOptions(\Drupal::currentUser());
      $ids[$field_name] = $options[$field_name] = [];
      foreach ($fields[$field_name] as $key => $value) {
        if (is_array($value) && $keys = array_keys($value)) {
          $ids[$field_name] = array_unique(array_merge($ids[$field_name], $keys));
          $options[$field_name] += $value;
        }
        elseif ($keys = array_keys($fields[$field_name])) {
          $ids[$field_name] = array_unique(array_merge($ids[$field_name], $keys));
          $options[$field_name] += $fields[$field_name];
        }
        // Optional fields need '_none' id as a possible choice.
        !$definition->isRequired() && array_unshift($ids[$field_name], '_none');
      }
    }
    $field_options['ids'] = $ids;
    $field_options['options'] = $options;

    return $field_options;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttributeFieldNames(ProductVariation $variation) {
    $attribute_field_manager = \Drupal::service('commerce_product.attribute_field_manager');
    $field_map = $attribute_field_manager->getFieldMap($variation->bundle());

    return array_column($field_map, 'field_name');
  }

  /**
   * {@inheritdoc}
   */
  public function getAttributesCombinations(ProductVariation $variation) {
    $ids = $this->getAttributeFieldOptionIds($variation)['ids'];
    $combinations = $this->getArrayValueCombinations($ids);
    $field_names = array_unique(array_values($this->getAttributeFieldNames($variation)));
    $all = [];
    foreach ($combinations as $combination) {
      array_walk($combination, function (&$id) {
        $id = (string) $id;
      });
      $all['combinations'][] = array_combine($field_names, $combination);
    }
    $all['count'] = count($combinations);

    return $all;
  }

  /**
   * {@inheritdoc}
   */
  public function getArrayValueCombinations(array $data = [], &$all = [], $group = [], $value = NULL, $i = 0) {
    $keys = array_keys($data);
    if (isset($value) === TRUE) {
      array_push($group, $value);
    }
    if ($i >= count($data)) {
      array_push($all, $group);
    }
    elseif (isset($keys[$i])) {
      $currentKey = $keys[$i];
      $currentElement = $data[$currentKey];
      foreach ($currentElement as $key => $val) {
        $this->getArrayValueCombinations($data, $all, $group, $val, $i + 1);
      }
    }

    return $all;
  }

}
