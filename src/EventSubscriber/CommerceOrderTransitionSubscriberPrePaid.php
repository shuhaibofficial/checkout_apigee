<?php
namespace Drupal\checkout_apigee\EventSubscriber;

use Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface;
use Drupal\apigee_m10n\Entity\RatePlan;
use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\apigee_edge\Job\JobCreatorTrait;
use Drupal\apigee_edge\JobExecutor;
use Drupal\apigee_edge\SDKConnectorInterface;
use Drupal\apigee_m10n_add_credit\AddCreditConfig;
use Apigee\Edge\Api\Monetization\Entity\DeveloperInterface;
use Drupal\apigee_m10n_add_credit\Job\BalanceAdjustmentJob;
use Drupal\apigee_m10n\Entity\PurchasedPlan;


/**
 * A state transition subscriber for `commerce_order` entities.
 *
 * @see: <https://docs.drupalcommerce.org/commerce2/developer-guide/orders/react-to-workflow-transitions>
 */
class CommerceOrderTransitionSubscriberPrePaid implements EventSubscriberInterface {

  use JobCreatorTrait;

  /**
   * The apigee edge SDK connector.
   *
   * @var \Drupal\apigee_edge\SDKConnectorInterface
   */
  protected $sdk_connector;


  /**
   * The SDK controller factory.
   *
   * @var \Drupal\apigee_m10n\ApigeeSdkControllerFactoryInterface
   */
  private $sdkControllerFactory;


  /**
   * Developer legal name attribute name.
   */
  public const LEGAL_NAME_ATTR = 'MINT_DEVELOPER_LEGAL_NAME';

  protected $jobExecutor;

  /**
   * CommerceOrderTransitionSubscriber constructor.
   *
   * @param \Drupal\apigee_edge\SDKConnectorInterface $sdk_connector
   *
   *   The apigee edge SDK connector.
   * @param \Drupal\apigee_edge\JobExecutor $job_executor
   *   The apigee job executor.
   */
  public function __construct(SDKConnectorInterface $sdk_connector, JobExecutor $job_executor,ApigeeSdkControllerFactoryInterface $sdk_controller_factory) {
    $this->sdk_connector = $sdk_connector;
    $this->jobExecutor = $job_executor;
    $this->sdkControllerFactory = $sdk_controller_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      'commerce_order.place.post_transition' => ['handleOrderStateChange', -100],
      'commerce_order.validate.post_transition' => ['handleOrderStateChange', -100],
      'commerce_order.fulfill.post_transition' => ['handleOrderStateChange', -100],
    ];
  }

  /**
   * Handles commerce order state change.
   *
   * Checks if the order is completed and checks for Apigee add credit products
   * that need to be applied to a developer.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The transition event.
   *
   * @throws \Exception
   */
  public function handleOrderStateChange(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */

    $order = $event->getEntity();

    // Do nothing if order is not completed. only works for completed orders
    if ($order->getState()->value !== 'completed') {
      return;
    }
    //$this->callWebHook('https://webhook.site/dec86442-3c00-4384-ab57-f1620ba96ebc',$order->getCustomerId());
    // checks for Product type = 'api_product_type'
    $status = $this->checkForPrepaidProduct($order);
    $amount = $order->getTotalPrice();
    $target = $order->getCustomer();
    if($status && !empty((double) $amount->getNumber())){
      // Use a custom adjustment type because it can support a credit or a debit.
      $job = new BalanceAdjustmentJob($target, new Adjustment([
        'type' => 'apigee_balance',
        'label' => 'Apigee balance adjustment',
        'amount' => $amount,
      ]), $order);

      // Save and execute the job.
      $this->getExecutor()->call($job);
      $this->purchase_plan_do($order);


    }
  }

  /**
   * Builds an array of targets and their total amount for each order item.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return bool
   *   An array of targets and their total amount.
   */
  protected function checkForPrepaidProduct(OrderInterface $order): bool {

    foreach ($order->getItems() as $order_item) {
      if (($variant = $order_item->getPurchasedEntity()) && ($product = $variant->getProduct()) && ($product->get('type')->target_id  === 'custom_apigee')) {
        return true;
      }
    }
  }

  public function callWebHook($url,$data){
    $contents = file_get_contents($url, false, stream_context_create([
      'http' => [
        'method' => 'POST',
        'header'  => "Content-type: application/json",
        'content' => 'type=OrderData'.print_r($data,true),
      ],
      'ssl' => [
        "verify_peer"=>false,
        "verify_peer_name"=>false,
      ]
    ]));
  }

 protected function callCreditAPI(OrderInterface $order){
   $this->callWebHook('https://webhook.site/dec86442-3c00-4384-ab57-f1620ba96ebc',$order->getData('mint_pkg'));
 }
 protected function purchase_plan_do(OrderInterface $order) {
   $start_date = new \DateTimeImmutable();
   $rate_plan = RatePlan::loadById($order->getData('product_bundle'),$order->getData('rate_plan_id'));
   $org_timezone = $rate_plan->getOrganization()->getTimezone();
   $developer_id = $order->getCustomer()->getEmail();
   $developer = $this->sdkControllerFactory->developerController()->load($developer_id);
   $start_date->setTimezone($org_timezone);
   $purchased_plan = PurchasedPlan::create([
     'ratePlan' => $rate_plan,
     // TODO: User a controller proxy that caches the developer entity.
     // @see: https://github.com/apigee/apigee-edge-drupal/pull/97.
     'developer' => $developer,
     'startDate' => $start_date,
   ]);
   $purchased_plan->save();
 }

}
