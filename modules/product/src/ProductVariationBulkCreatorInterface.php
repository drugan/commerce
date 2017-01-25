<?php

namespace Drupal\commerce_product;

use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_product\Entity\Product;

/**
 * Manages variation combinations creation.
 *
 * Variation combination is a unique array of the variation attributes IDs.
 */
interface ProductVariationBulkCreatorInterface {

  /**
   * Helper method to get variation sku field form display settings.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The commerce product variation.
   *
   * @return array
   *   The last three elements are only present on ProductVariationSkuWidget:
   *   - "size": HTML size attribute value.
   *   - "placeholder": HTML placeholder attribute value.
   *   - "prefix": An optional prefix for the field value.
   *   - "suffix": An optional suffix for the field value.
   *   - "more_entropy": The length and therefore uniqueness of the field value.
   *
   * @see \Drupal\commerce\Plugin\Field\FieldWidget\ProductVariationSkuWidget
   * @see \Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget
   */
  public static function getSkuSettings(ProductVariation $variation);

  /**
   * Default value callback for the 'sku' base field definition.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The commerce product variation.
   *
   * @return string
   *   An optionally prefixed/suffixed unique identifier based on settings
   *   of the widget of the field and current time in microseconds.
   *
   * @see \Drupal\commerce_product\Entity\ProductVariation::baseFieldDefinitions()
   * @see http://php.net/manual/en/function.uniqid.php
   */
  public static function getAutoSku(ProductVariation $variation);

  /**
   * Creates sample variation for commerce_product.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   A commerce product, whether new or having some variations saved on it.
   * @param array $variation_custom_values
   *   (optional) An associative array of a variation property values which
   *   will be used to auto create sample variation.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariation
   *   A commerce_product variation.
   *
   * @see \Drupal\commerce_product\Entity\ProductVariation->create()
   * @see self->createAllProductVariations()
   */
  public function createSampleProductVariation(Product $product, array $variation_custom_values = []);

  /**
   * Creates all possible variations for commerce_product.
   *
   * @param \Drupal\commerce_product\Entity\Product $product
   *   A commerce product, whether new or having some variations saved on it.
   * @param array $variation_custom_values
   *   (optional) An associative array of a variation property values which
   *   will be used to auto create all variations.
   *
   * @return array|null
   *   An array of all commerce product variations that were missed before.
   *
   * @see \Drupal\commerce_product\Entity\Product->getVariations()
   * @see self->getAllAttributesCombinations()
   */
  public function createAllProductVariations(Product $product, array $variation_custom_values = []);

  /**
   * An AJAX callback to create all possible variations on the commerce product
   * add or edit form.
   *
   * @param array $form
   *   An array form for commerce_product with ief widget.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the commerce_product form with at least one variation
   *   created.
   *
   * @see self->getIefFormAllAttributesCombinations()
   */
  public function createAllIefFormVariations(array $form, FormStateInterface $form_state);

  /**
   * Gets all variations combinations and labels.
   *
   * @param array $variations
   *   The commerce product variations.
   *
   * @return array|null
   *   An array of used combinations, possible combinations and their quantity,
   *   last variation, inline_entity_form entities, duplicated combinations and
   *   an HTML list of duplicated variations labels if they are found:
   *   - "last_variation": The variation on the last inline entity form array.
   *   - "possible": An array with all combinations and their quantity:
   *     - "combinations": All possible cobinations.
   *     - "count": The quantity of the combinations.
   *   - "combinations": The already used combinations.
   *   - "duplicated": The duplicated combinations.
   *   - "used": The copy of the already used "combinations" element.
   *   - "duplications_list": HTML list of duplicated combinations if present.
   */
  public function getAllAttributesCombinations(array $variations);

  /**
   * Gets all variations combinations and labels on a product IEF form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the commerce_product form with at least one variation
   *   created.
   * @param string $ief_id
   *   A product form IEF widget id.
   *
   * @return array|null
   *   The array sructure is the same as self->getAllAttributesCombinations()
   *   except one additional element:
   *   - "ief_entities": Inline entity form arrays.
   */
  public function getIefFormAllAttributesCombinations(FormStateInterface $form_state, $ief_id = '');

  /**
   * A callback which might be set to #pre_render or #after_build form element.
   *
   * This helper function alters data on the form element passed along with the
   * element in the following manner:
   * @code
   * $i = 0;
   * $element['alter_data_' . $i] = [
   *   '#parents' => ['form', 'deep', 'nested', 'array_element'],
   *   '#default_value' => $my_value,
   *   // ...
   * ];
   * $i++;
   * $element['alter_data_' . $i] = [
   *   '#parents' => ['form', 'another', 'nested', 'array_element'],
   *   '#disabled' => TRUE,
   *   // ...
   * ];
   * // @var \Drupal\commerce_product\ProductVariationBulkCreator $creator
   * $element['#after_build'][] = [$creator, 'afterBuildPreRenderArrayAlter'];
   * @endcode
   * It is primarily used for form structures and renderable arrays. Any number
   * of data arrays with different paths (#parents) may be attached to an
   * element. If #parents is omitted the altering will apply on the root of the
   * element. The $creator may be passed to a callbacks array as an object or
   * a fully qualified class name. After the target array elements being altered
   * the 'alter_data_NNN' containers are unset.
   *
   * @param array $element
   *   The render array element normally passed by the system call.
   *
   * @return array
   *   The altered render array element.
   *
   * @see commerce_product_field_widget_form_alter()
   */
  public static function afterBuildPreRenderArrayAlter(array $element);

  /**
   * Gets all used variations attributes combinations on a commerce_product.
   *
   * @param array $variations
   *   The commerce product variations.
   *
   * @return array|null
   *   An array of used combinations, possible combinations and their quantity,
   *   last variation and inline_entity_form entities:
   *   - "ief_entities": Inline entity form arrays.
   *   - "last_variation": The variation on the last inline entity form array.
   *   - "possible": An array with all combinations and their quantity:
   *     - "combinations": All possible cobinations.
   *     - "count": The quantity of the combinations.
   *   - "combinations": The already used combinations.
   */
  public function getUsedAttributesCombinations(array $variations);

  /**
   * Gets the IDs of the variation's attribute fields.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The commerce product variation.
   *
   * @return array
   *   An array of IDs arrays keyed by field name.
   */
  public function getAttributeFieldOptionIds(ProductVariation $variation);

  /**
   * Gets the names of the entity's attribute fields.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The commerce product variation.
   *
   * @return string[]
   *   The attribute field names.
   */
  public function getAttributeFieldNames(ProductVariation $variation);

  /**
   * Gets all ids combinations of the commerce_product's attribute fields.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariation $variation
   *   The commerce product variation.
   *
   * @return array
   *   An array of ids combinations and combinations quantity:
   *   - "combinations": All possible combinations of attributes IDs.
   *   - "count": The quantity of the combinations.
   */
  public function getAttributesCombinations(ProductVariation $variation);

  /**
   * Gets combinations of an Array values.
   *
   * See the function
   * @link https://gist.github.com/fabiocicerchia/4556892 source origin @endlink
   * .
   *
   * @param array $data
   *   An array with mixed data.
   *
   * @return array
   *   An array of all possible array values combinations.
   */
  public function getArrayValueCombinations(array $data = [], &$all = [], $group = [], $value = NULL, $i = 0);

}
