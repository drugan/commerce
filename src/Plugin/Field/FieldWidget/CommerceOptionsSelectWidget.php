<?php

namespace Drupal\commerce\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;

/**
 * Plugin implementation of the 'commerce_options_select' widget.
 *
 * @FieldWidget(
 *   id = "commerce_options_select",
 *   label = @Translation("Commerce select list"),
 *   field_types = {
 *     "entity_reference",
 *     "list_integer",
 *     "list_float",
 *     "list_string"
 *   },
 *   multiple_values = TRUE
 * )
 */
class CommerceOptionsSelectWidget extends OptionsSelectWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'skip_option_label' => t('No, thanks!'),
      'no_options_label' => t('No options available ...'),
      'hide_no_options' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $settings = $this->getSettings();
    $warning = $this->t('Leaving this field empty is not recommended.');

    $element['skip_option_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skip option label'),
      '#default_value' => $settings['skip_option_label'],
      '#description' => $this->t('Indicates for a user that choosing this option will totally skip an optional field.'),
      '#placeholder' => $warning,
    ];
    $element['hide_no_options'] = [
      '#type' => 'checkbox',
      '#title_display' => 'before',
      '#title' => $this->t('Hide empty field'),
      '#description' => $this->t('If checked, the element having only one empty option will be hidden. Not recommended. Instead set up an explanatory No options label below.'),
      '#default_value' => $settings['hide_no_options'],
    ];
    $element['no_options_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('No options label'),
      '#default_value' => $settings['no_options_label'],
      '#description' => $this->t('Indicates for a user that there is no options to choose from on an optional field.'),
      '#placeholder' => $warning,
      '#states' => [
        'visible' => [':input[name*="hide_no_options"]' => ['checked' => FALSE]],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $none = $this->t('None');
    $settings = $this->getSettings();
    $hidden = $settings['hide_no_options'];
    $settings['hide_no_options'] = $hidden ? $this->t('Hidden') : $this->t('Not hidden');
    $settings['no_options_label'] = $hidden ? '' : $settings['no_options_label'];
    foreach ($settings as $name => $value) {
      $value = empty($settings[$name]) ? $none : $value;
      $summary[] = "{$name}: {$value}";
    }

    return $summary;
  }

}
