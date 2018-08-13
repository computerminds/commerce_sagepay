<?php

namespace Drupal\commerce_sagepay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use SagepayApiFactory;
use SagepaySettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the Sagepay Form Integration payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "sagepay_form",
 *   label = @Translation("Sagepay (Form Integration)"),
 *   display_label = @Translation("Sagepay"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_sagepay\PluginForm\FormIntegrationForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 *   modes = {
 *    SAGEPAY_ENV_TEST = "Test", SAGEPAY_ENV_LIVE = "Live",
 *   },
 * )
 */
class FormIntegration extends OffsitePaymentGatewayBase implements FormIntegrationInterface {

  use SagepayCommon;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private $loggerChannelFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private $moduleHandler;

  /**
   * Constructs a new PaymentGatewayBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, RequestStack $requestStack, LoggerChannelFactoryInterface $loggerChannelFactory, TimeInterface $time, ModuleHandlerInterface $moduleHandler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->requestStack = $requestStack;
    $this->loggerChannelFactory = $loggerChannelFactory;
    $this->time = $time;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['vendor'] = [
      '#type' => 'textfield',
      '#title' => t('SagePay Vendor Name'),
      '#description' => t('This is the vendor name that SagePay sent you when
     you set up your account.'),
      '#required' => TRUE,
      '#default_value' => isset($this->configuration['vendor']) ? $this->configuration['vendor'] : '',
    ];

    $form['enc_key'] = [
      '#type' => 'textfield',
      '#title' => t('Encryption Key'),
      '#description' => t('If you have requested form based integration, you will have received an encryption key from SagePay in a separate email.'),
      '#default_value' => (isset($this->configuration['enc_key'])) ? $this->configuration['enc_key'] : '',
      '#required' => TRUE,
    ];

    $form['test_enc_key'] = [
      '#type' => 'textfield',
      '#title' => t('Test Mode Encryption Key'),
      '#description' => t('If you have requested form based integration, you will have received an encryption key from SagePay in a separate email. The encryption key for the test server is different to the one used in your production environment.'),
      '#default_value' => (isset($this->configuration['test_enc_key'])) ? $this->configuration['test_enc_key'] : '',
      '#required' => TRUE,
    ];

    $form['transaction'] = [
      '#type' => 'fieldset',
      '#title' => 'Transaction Settings',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['transaction']['sagepay_order_description'] = [
      '#type' => 'textfield',
      '#title' => t('Order Description'),
      '#description' => $this->t('The description of the order that will appear in the SagePay transaction. (For example, Your order from sitename.com)'),
      '#default_value' => (isset($this->configuration['sagepay_order_description'])) ? $this->configuration['sagepay_order_description'] : $this->t('Your order from sitename.com'),
    ];

    $form['security'] = [
      '#type' => 'fieldset',
      '#title' => 'Security Checks',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['security']['sagepay_apply_avs_cv2'] = [
      '#type' => 'radios',
      '#title' => t('AVS / CV2 Mode'),
      '#description' => t('CV2 validation mode used by default on all transactions.'),
      '#options' => [
        '0' => t('If AVS/CV2 enabled then check them. If rules apply, use rules. (default)'),
        '1' => t('Force AVS/CV2 checks even if not enabled for the account. If rules apply, use rules.'),
        '2' => t('Force NO AVS/CV2 checks even if enabled on account.'),
        '3' => t('Force AVS/CV2 checks even if not enabled for the account but DO NOT apply any rules.'),
      ],
      '#default_value' => (isset($this->configuration['sagepay_apply_avs_cv2'])) ? $this->configuration['sagepay_apply_avs_cv2'] : 0,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['vendor'] = $values['vendor'];
      $this->configuration['enc_key'] = $values['enc_key'];
      $this->configuration['test_enc_key'] = $values['test_enc_key'];
      $this->configuration['sagepay_order_description'] = $values['transaction']['sagepay_order_description'];
      $this->configuration['sagepay_apply_avs_cv2'] = $values['security']['sagepay_apply_avs_cv2'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    $url = SAGEPAY_FORM_SERVER_TEST;
    if ($this->getMode() == SAGEPAY_ENV_LIVE) {
      $url = SAGEPAY_FORM_SERVER_LIVE;
    }
    return $url;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $decryptedSagepayResponse = $this->decryptSagepayResponse($this->configuration['test_enc_key'], $this->requestStack->getCurrentRequest()->query->get('crypt'));

    if (!$decryptedSagepayResponse) {
      throw new PaymentGatewayException();
    }

    // Get and check the VendorTxCode.
    $vendorTxCode = isset($decryptedSagepayResponse['VendorTxCode']) ? $decryptedSagepayResponse['VendorTxCode'] : FALSE;
    if (empty($vendorTxCode)) {
      $this->loggerChannelFactory->get('commerce_sagepay')
        ->error('No VendorTxCode returned.');
      throw new PaymentGatewayException('No VendorTxCode returned.');
    }

    if (in_array($decryptedSagepayResponse['Status'], [
      SAGEPAY_REMOTE_STATUS_OK,
      SAGEPAY_REMOTE_STATUS_REGISTERED,
    ])) {
      $payment = $this->createPayment($decryptedSagepayResponse, $order);
      $payment->remote_state = $decryptedSagepayResponse['Status'];
      $payment->state = 'capture_completed';
      $payment->save();
      $logLevel = 'info';
      $logMessage = 'OK Payment callback received from SagePay for order %order_id with status code %status';
      $logContext = [
        '%order_id' => $order->id(),
        '%status' => $decryptedSagepayResponse['Status'],
      ];

      $this->loggerChannelFactory->get('commerce_sagepay')
        ->log($logLevel, $logMessage, $logContext);
    }
    else {
      $sagepayError = $this->decipherSagepayError($order, $decryptedSagepayResponse);
      $logLevel = $sagepayError['logLevel'];
      $logMessage = $sagepayError['logMessage'];
      $logContext = $sagepayError['logContext'];
      $this->loggerChannelFactory->get('commerce_sagepay')
        ->log($logLevel, $logMessage, $logContext);
      drupal_set_message($sagepayError['drupalMessage'], $sagepayError['drupalMessageType']);
      throw new PaymentGatewayException('ERROR result from Sagepay for order ' . $decryptedSagepayResponse['VendorTxCode']);
    }
  }

  /**
   * Create a Commerce Payment from a Sagepay form request successful result.
   *
   * @return PaymentInterface $payment
   *    The commerce payment record.
   */
  public function createPayment(array $decryptedSagepayResponse, OrderInterface $order) {

    /** @var \Drupal\commerce_payment\PaymentStorageInterface $paymentStorage */
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

    /** @var PaymentInterface $payment */
    $payment = $paymentStorage->create([
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $decryptedSagepayResponse['VendorTxCode'],
      'remote_state' => SAGEPAY_REMOTE_STATUS_OK,
      'authorized' => $this->time->getRequestTime(),
    ]);

    $payment->save();

    return $payment;
  }

  /**
   * Decrypt the Sagepay response.
   *
   * @return array
   *    An array of the decrypted Sagepay response.
   */
  private function decryptSagepayResponse($formPassword, $encryptedResponse) {
    $decrypt = \SagepayUtil::decryptAes($encryptedResponse, $formPassword);
    $decryptArray = \SagepayUtil::queryStringToArray($decrypt);
    if (!$decrypt || empty($decryptArray)) {
      throw new PaymentGatewayException('Crypt data missing for this Sagepay Form transaction.');
    }
    return $decryptArray;
  }

  /**
   * {@inheritdoc}
   */
  public function buildTransaction(PaymentInterface $payment) {
    /** @var OrderInterface $order */
    $order = $payment->getOrder();

    $sagepayFormApi = $this->getSagepayApi($order);

    if (!$basket = $this->getBasketFromProducts($order)) {
      throw new PaymentGatewayException('No basket found');
    }

    $basket->setDescription($this->configuration['sagepay_order_description']);
    $sagepayFormApi->setBasket($basket);

    $sagepayFormApi->addAddress($this->getBillingAddress($order));

    $taxAmount = 0;
    if ($adjustments = $order->getAdjustments()) {
      foreach ($adjustments as $adjustment) {
        if ($adjustment->getType() == 'tax') {
          $taxAmount += (float) $adjustment->getAmount()->getNumber();
        }
      }
    }

    if ($this->moduleHandler->moduleExists('commerce_shipping')) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
      $shipments = $order->get('shipments')->referencedEntities();

      $delivery = 0;
      if (!empty(($shipments)) && $shippingAddress = $this->getShippingAddress(reset($shipments))) {
        $sagepayFormApi->addAddress($shippingAddress);

        foreach ($shipments as $shipment) {
          $delivery = $delivery + (float) $shipment->getAmount()->getNumber();
        }
      }
      $basket->setDeliveryNetAmount($delivery);
      $basket->setDeliveryTaxAmount($taxAmount);
    }

    $request = $sagepayFormApi->createRequest();

    $order->setData('sagepay_form', [
      'request' => $request,
    ]);

    $order->save();

    return $request;
  }

  /**
   * Get the Sagepay API.
   *
   * @return \SagepayAbstractApi
   *   The Sagepay form api object.
   */
  protected function getSagepayApi(OrderInterface $order) {
    $gatewayConfig = $this->getConfiguration();

    $sagepayConfig = SagepaySettings::getInstance([
      'env' => ($gatewayConfig['mode']) ? $gatewayConfig['mode'] : SAGEPAY_ENV_TEST,
      'vendorName' => $gatewayConfig['vendor'],
      'website' => \Drupal::request()->getSchemeAndHttpHost(),
      'formPassword' => [
        SAGEPAY_ENV_LIVE => $gatewayConfig['enc_key'],
        SAGEPAY_ENV_TEST => $gatewayConfig['test_enc_key'],
      ],
      'siteFqdns' => ['test' => \Drupal::request()->getSchemeAndHttpHost(), 'live' => \Drupal::request()->getSchemeAndHttpHost() ],
      'formSuccessUrl' => $this->buildReturnUrl($order),
      'formFailureUrl' => $this->buildCancelUrl($order),
      'currency' => $order->getTotalPrice()->getCurrencyCode(),
      'surcharges' => [],
      'allowGiftAid' => 0,
      'logError' => FALSE,
      'ApplyAVSCV2' => $gatewayConfig['sagepay_apply_avs_cv2'],
    ]);

    return SagepayApiFactory::create('form', $sagepayConfig);
  }

  /**
   * Builds the URL to the "return" page.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The "return" page url.
   */
  protected function buildReturnUrl(OrderInterface $order) {
    return Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order->id(),
      'step' => 'payment',
    ], ['absolute' => FALSE])->toString();
  }

  /**
   * Builds the URL to the "cancel" page.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The "cancel" page url.
   */
  protected function buildCancelUrl(OrderInterface $order) {
    return Url::fromRoute('commerce_payment.checkout.cancel', [
      'commerce_order' => $order->id(),
      'step' => 'payment',
    ], ['absolute' => FALSE])->toString();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
      $container->get('datetime.time'),
      $container->get('module_handler')
    );
  }

}
