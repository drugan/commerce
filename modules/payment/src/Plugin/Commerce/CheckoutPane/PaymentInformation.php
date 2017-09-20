<?php

namespace Drupal\commerce_payment\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface as EntityPaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\profile\Entity\Profile;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the payment information pane.
 *
 * @CommerceCheckoutPane(
 *   id = "payment_information",
 *   label = @Translation("Payment information"),
 *   default_step = "order_information",
 *   wrapper_element = "fieldset",
 * )
 */
class PaymentInformation extends CheckoutPaneBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new BillingInformation object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $checkout_flow
   *   The parent checkout flow.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow);

    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    $summary = '';
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $this->order->payment_gateway->entity;
    if (!$payment_gateway) {
      return $summary;
    }

    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $payment_method = $this->order->payment_method->entity;
    if ($payment_gateway_plugin instanceof SupportsStoredPaymentMethodsInterface && $payment_method) {
      $view_builder = $this->entityTypeManager->getViewBuilder('commerce_payment_method');
      $payment_method_view = $view_builder->view($payment_method, 'default');
      $summary = $this->renderer->render($payment_method_view);
    }
    else {
      $billing_profile = $this->order->getBillingProfile();
      if ($billing_profile) {
        $profile_view_builder = $this->entityTypeManager->getViewBuilder('profile');
        $profile_view = $profile_view_builder->view($billing_profile, 'default');
        $summary = $payment_gateway->getPlugin()->getDisplayLabel();
        $summary .= $this->renderer->render($profile_view);
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    /** @var \Drupal\commerce_payment\PaymentGatewayStorageInterface $payment_gateway_storage */
    $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface[] $payment_gateways */
    $payment_gateways = $payment_gateway_storage->loadMultipleForOrder($this->order);
    // When no payment gateways are defined, throw an error and fail reliably.
    if (empty($payment_gateways)) {
      drupal_set_message($this->noPaymentGatewayErrorMessage(), 'error');
      return [];
    }
    // @todo Support multiple gateways.
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = reset($payment_gateways);

    $pane_form['payment_gateway'] = [
      '#type' => 'value',
      '#value' => $payment_gateway->id(),
    ];

    $payment_gateway_plugin = $payment_gateway->getPlugin();

    if ($payment_gateway_plugin instanceof SupportsStoredPaymentMethodsInterface) {
      $this->attachPaymentMethodForm($payment_gateway, $pane_form, $form_state);
    }
    else {
      /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
      $billing_profile = $this->order->getBillingProfile();
      $billing_profiles = [0 => $this->t('- New Information -')];
      $profile_id = $billing_profile ? $billing_profile->id() : 0;
      if ($customer_id = $this->order->getCustomerId()) {
        $storage = $this->entityTypeManager->getHandler('profile', 'storage');
        $customer = $this->order->getCustomer();
        // Pick up profile based on priority: 1 - order, 2 - default, 3 - first.
        $billing_profile = $billing_profile ?: ($storage->loadDefaultByUser($customer, 'customer') ?: $storage->loadByUser($customer, 'customer'));
        $profile_id = $billing_profile ? $billing_profile->id() : 0;
        if (($trigger = $form_state->getTriggeringElement()) && array_pop($trigger['#parents']) == 'billing_profile_id') {
          $trigger_value = explode('.', $trigger['#value']);
          $trigger_id = $trigger_value[0] != $profile_id ? $trigger_value[0] : 0;
          $profile_id = $trigger_value[0];
        }
        $info = $this->t('info id:');
        $countries = \Drupal::service('address.country_repository');
        $locale = $countries->getDefaultLocale();
        $fallbackLocale = $countries->getFallbackLocale();
        foreach ($storage->loadMultipleByUser($customer, 'customer') as $profile) {
          $id = $profile->id();
          $address = $address_0 = $profile->get('address')->getValue()[0];
          $lines = [];
          $address[1] = $countries->get($address['country_code'], $locale, $fallbackLocale)->getName();
          $address[2] = implode(' ', [$address['postal_code'], $address['locality'], $address['dependent_locality'], $address['administrative_area']]);
          $address['zzz'] = implode(' ', [$address['given_name'], $address['additional_name'], $address['family_name']]);
          unset($address['country_code'], $address['given_name'], $address['additional_name'], $address['family_name'], $address['postal_code'], $address['locality'], $address['dependent_locality'], $address['administrative_area']);
          foreach ($address as $key => $value) {
            if (empty($value)) {
              unset($address[$key]);
            }
            else {
              $lines["{$id}.{$key}"] = $value;
            }
          }
          krsort($lines, SORT_NATURAL);
          // Set the profile option for select element as a multiline optgroup.
          $billing_profiles[$address_0['address_line1'] . " ({$info} {$id})"] = $lines;
          if (!empty($trigger_id) && $trigger_id == $id) {
            $billing_profile = $profile;
            $this->order->setBillingProfile($billing_profile);
            $input = $form_state->getUserInput();
            $input['payment_information']['address'][0] = $address_0;
            $form_state->setUserInput($input);
          }
        }
      }
      if (empty($profile_id)) {
        $profile = $this->entityTypeManager->getStorage('profile')->create([
          'uid' => $customer_id,
           'type' => 'customer',
         ]);
        // Prevent overwriting existing billing profile.
        $billing_profile ? $this->order->setBillingProfile($profile) : NULL;
        $billing_profile = $profile;
      }

      $form_display = \Drupal\Core\Entity\Entity\EntityFormDisplay::collectRenderDisplay($billing_profile, 'default');
      $form_display->buildForm($billing_profile, $pane_form, $form_state);
      // Remove the details wrapper from the address field.
      if (!empty($pane_form['address']['widget'][0])) {
        // Show only for authenticated user who has at least 1 profile saved.
        if ($customer_id && count($billing_profiles) > 1) {
          $pane_form['address']['widget'][0]['billing_profile_id'] = [
            '#type' => "select",
            '#title' => $this->t('Choose from earlier saved information'),
            '#options' => $billing_profiles,
            '#default_value' =>  $billing_profile->id() . '.address_line1',
            '#limit_validation_errors' => [],
            '#ajax' => $pane_form['payment_method']['#ajax'],
            '#attributes' => $pane_form['address']['#attributes'],
            // Place it exactly above the country code select list.
            '#weight' => --$pane_form['address']['#weight'],
          ];
        }
      }
      // Store the billing profile for the validate/submit methods.
      $pane_form['#entity'] = $billing_profile;
    }

    return $pane_form;
  }

  /**
   * Creates the payment method selection form for supported gateways.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway
   *   The payment gateway.
   * @param array $pane_form
   *   The pane form, containing the following basic properties:
   *   - #parents: Identifies the position of the pane form in the overall
   *     parent form, and identifies the location where the field values are
   *     placed within $form_state->getValues().
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent form.
   */
  protected function attachPaymentMethodForm(EntityPaymentGatewayInterface $payment_gateway, array &$pane_form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
    $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
    $payment_gateway_plugin = $payment_gateway->getPlugin();
    $options = [];
    $default_option = NULL;
    $customer = $this->order->getCustomer();
    if ($customer) {
      $payment_methods = $payment_method_storage->loadReusable($customer, $payment_gateway);
      foreach ($payment_methods as $payment_method) {
        $options[$payment_method->id()] = $payment_method->label();
      }
    }
    $payment_method_types = $payment_gateway_plugin->getPaymentMethodTypes();
    foreach ($payment_method_types as $payment_method_type) {
      $id = 'new_' . $payment_method_type->getPluginId();
      $options[$id] = $payment_method_type->getCreateLabel();
    }
    $values = $form_state->getValue($pane_form['#parents']);
    if (!empty($values['payment_method'])) {
      $selected_option = $values['payment_method'];
    }
    else {
      $default_payment_method_type = $payment_gateway_plugin->getDefaultPaymentMethodType();
      $selected_option = 'new_' . $default_payment_method_type->getPluginId();
    }

    // Prepare the form for ajax.
    $pane_form['#wrapper_id'] = Html::getUniqueId('payment-information-wrapper');
    $pane_form['#prefix'] = '<div id="' . $pane_form['#wrapper_id'] . '">';
    $pane_form['#suffix'] = '</div>';

    $pane_form['payment_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment method'),
      '#options' => $options,
      '#default_value' => $selected_option,
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxRefresh'],
        'wrapper' => $pane_form['#wrapper_id'],
      ],
    ];
    if (substr($selected_option, 0, 4) == 'new_') {
      $payment_method = $payment_method_storage->create([
        'type' => substr($selected_option, 4),
        'payment_gateway' => $payment_gateway->id(),
        'uid' => $this->order->getCustomerId(),
      ]);
      $pane_form['add_payment_method'] = [
        '#type' => 'commerce_payment_gateway_form',
        '#operation' => 'add-payment-method',
        '#default_value' => $payment_method,
      ];
    }
  }

  /**
   * Ajax callback.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    $parents = $form_state->getTriggeringElement()['#parents'];
    array_pop($parents);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);

    /** @var \Drupal\commerce_payment\PaymentGatewayStorageInterface $payment_gateway_storage */
    $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $payment_gateway_storage->load($values['payment_gateway']);

    if ($payment_gateway->getPlugin() instanceof SupportsStoredPaymentMethodsInterface) {
      if (!isset($values['payment_method'])) {
        $form_state->setError($complete_form, $this->noPaymentGatewayErrorMessage());
      }
    }
    else {
      /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
      $billing_profile = $pane_form['#entity'];
      $form_display = EntityFormDisplay::collectRenderDisplay($billing_profile, 'default');
      $form_display->extractFormValues($billing_profile, $pane_form, $form_state);
      $form_display->validateFormValues($billing_profile, $pane_form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);

    /** @var \Drupal\commerce_payment\PaymentGatewayStorageInterface $payment_gateway_storage */
    $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $payment_gateway_storage->load($values['payment_gateway']);

    if ($payment_gateway->getPlugin() instanceof SupportsStoredPaymentMethodsInterface) {
      if (is_numeric($values['payment_method'])) {
        /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
        $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
        $payment_method = $payment_method_storage->load($values['payment_method']);
      }
      else {
        $payment_method = $values['add_payment_method'];
      }

      /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
      $this->order->payment_gateway = $payment_method->getPaymentGateway();
      $this->order->payment_method = $payment_method;
      $this->order->setBillingProfile($payment_method->getBillingProfile());
    }
    else {
      /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
      $this->order->payment_gateway = $payment_gateway;
      /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
      $billing_profile = $pane_form['#entity'];
      $form_display = EntityFormDisplay::collectRenderDisplay($billing_profile, 'default');
      $form_display->extractFormValues($billing_profile, $pane_form, $form_state);
      $billing_profile->save();
      $this->order->setBillingProfile($billing_profile);
    }
  }

  /**
   * Returns an error message in case there are no payment gateways.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The error message.
   */
  protected function noPaymentGatewayErrorMessage() {
    return $this->t('No payment gateways are defined, create one first.');
  }

}
