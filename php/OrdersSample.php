<?php
/**
 * Copyright 2016 Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once 'BaseSample.php';

/**
 * Class for running through an example workflow with the
 * Orders service. For this sample, we use the sandbox API endpoint
 * so that we can create/manage test orders and so that we don't
 * accidentally mutate real orders in the system.
 */
class OrdersSample extends BaseSample {
  private $nonce = 0; // used by newOperationId()

  public function run() {
    // First, we create a new test order using the template1 template.
    // Normally, orders would be automatically populated by Google in the
    // non-sandbox version, and we'd skip ahead to find out what orders are
    // currently waiting for our acknowledgment.
    print 'Creating new test order...';
    $request =
        new Google_Service_ShoppingContent_OrdersCreateTestOrderRequest();
    $request->setTemplateName('template1');
    $resp = $this->session->sandboxService->orders->createtestorder(
        $this->session->merchantId, $request);
    $orderId = $resp->getOrderId();
    printf("done (%s).\n", $orderId);
    print "\n";

    // List all unacknowledged orders.  In normal usage, this is where we'd
    // get new order IDs to operate on. We should see the new test order
    // show up here.
    $this->listUnacknowledgedOrders();

    // Acknowledge the newly created order.  The assumption in doing so is that
    // we've collected the information for this order into our own internal
    // system, and acknowledging it allows us to skip it when searching for new
    // orders in the future.
    $this->acknowledge($orderId);

    // We'll also set the merchant order ID to simulate having entered it into
    // our own internal database.  For this, and the tracking and shipping IDs
    // we'll see later, we'll use random numbers.
    $merchantOrderId = mt_rand();
    $this->updateMerchantOrderId($orderId, $merchantOrderId);

    $currentOrder = $this->getByMerchantOrderId($merchantOrderId);
    $this->printOrder($currentOrder);
    print "\n";

    // Oops, we don't have enough stock for all the Chromecasts ordered! Let's
    // cancel one of them from the order.
    $this->cancelLineItem(
        $orderId,
        $currentOrder->getLineItems()[0]->getId(),
        1,
        'noInventory',
        'Ran out of inventory while fulfilling request.'
    );

    // Notice the change in quantities in this updated view of the order.
    $currentOrder = $this->getOrder($orderId);
    $this->printOrder($currentOrder);
    print "\n";

    // At this point, we'll advance the test order.  This simulates the point
    // at which an order is no longer cancellable by the customer and the order
    // information reflects that we're ready for the merchant to ship.
    printf('Advancing test order %s...', $orderId);
    $this->session->sandboxService->orders->advancetestorder(
        $this->session->merchantId, $orderId);
    print "done.\n";
    print "\n";

    // Notice the change in status for the order.
    $currentOrder = $this->getOrder($orderId);
    $this->printOrder($currentOrder);
    print "\n";

    // To simulate partial fulfillment, we'll pick the first line item and
    // ship the still-pending amount. (Remember, we cancelled one already.)
    // We store the request to later access the random strings generated
    // for the shipment and tracking IDs.
    $shipItem1Req = $this->shipLineItemAll(
        $orderId, $currentOrder->getLineItems()[0]);

    $currentOrder = $this->getOrder($orderId);
    $this->printOrder($currentOrder);
    print "\n";

    // Now we ship the rest.
    $shipItem2Req = $this->shipLineItemAll(
        $orderId, $currentOrder->getLineItems()[1]);

    $currentOrder = $this->getOrder($orderId);
    $this->printOrder($currentOrder);
    print "\n";

    // Customer receives the first item, so we notify Google that it was
    // delivered.
    $this->lineItemDelivered($orderId, $shipItem1Req);

    $currentOrder = $this->getOrder($orderId);
    $this->printOrder($currentOrder);
    print "\n";

    // Customer receives the second item, so we notify Google that it was
    // delivered.
    $this->lineItemDelivered($orderId, $shipItem2Req);

    $currentOrder = $this->getOrder($orderId);
    $this->printOrder($currentOrder);
    print "\n";

    // Turns out one of the first line item was damaged when it arrived,
    // so we let Google know the customer returned one.
    $this->lineItemReturned(
        $orderId,
        $currentOrder->getLineItems()[0]->getId(),
        1,
        'productArrivedDamaged',
        'Item was non-functional on receipt.'
    );

    $currentOrder = $this->getOrder($orderId);
    $this->printOrder($currentOrder);
    print "\n";
  }

  /**
   * Operation IDs (even across operations) must be unique over the lifetime
   * of an order, so that Google can detect and reject duplicate requests.
   * Since we're sending requests sequentially and not retrying, we just use
   * a simple nonce that's incremented each time.
   */
  private function newOperationId() {
    return strval($this->nonce++);
  }

  /**
   * Lists the unacknowledged orders for {@code $this->session->merchantId},
   * printing out each in turn.
   */
  public function listUnacknowledgedOrders() {
    printf("Printing unacknowledged orders for %s.\n",
        $this->session->merchantId);
    $parameters = ['acknowledged' => false];
    do {
      $resp = $this->session->sandboxService->orders->listOrders(
          $this->session->merchantId, $parameters);
      if (empty($resp->getResources())) {
        print ("No orders.\n");
      }
      foreach ($resp->getResources() as $order) {
        $this->printOrder($order);
      }
      $parameters['pageToken'] = $resp->getNextPageToken();
    } while (!empty($parameters['pageToken']));
    print "\n";
  }

  /**
   * Acknowledges order {@code $orderId}, which allows us to filter it out
   * when looking for new orders.
   *
   * @param string $orderId the order ID of the order to acknowledge
   */
  public function acknowledge($orderId) {
    printf('Acknowledging order %s... ', $orderId);
    $req = new Google_Service_ShoppingContent_OrdersAcknowledgeRequest();
    $req->setOperationId($this->newOperationId());
    $resp = $this->session->sandboxService->orders->acknowledge(
        $this->session->merchantId, $orderId, $req);
    printf("done (%s).\n", $resp->getExecutionStatus());
    print "\n";
  }

  /**
   * Retrieves the order information for the (Google-supplied) {@code $orderId}.
   *
   * @param string $orderId the order ID of the order to retrieve
   * @return Google_Service_ShoppingContent_Order
   */
  public function getOrder($orderId) {
    printf('Retrieving order %s... ', $orderId);
    $order = $this->session->sandboxService->orders->get(
        $this->session->merchantId, $orderId);
    print "done.\n\n";
    return $order;
  }

  /**
   * Retrieves the order information for the (merchant-supplied)
   * {@code $merchantOrderId}.
   *
   * @param string $merchantOrderId the merchant order ID of the order to
   *     retrieve
   * @return Google_Service_ShoppingContent_Order
   */
  public function getByMerchantOrderId($merchantOrderId) {
    printf('Retrieving merchant order %s... ', $merchantOrderId);
    $resp = $this->session->sandboxService->orders->getbymerchantorderid(
        $this->session->merchantId, $merchantOrderId);
    print "done.\n\n";
    return $resp->getOrder();
  }

  /**
   * Updates the merchant order ID for {@code $orderId}.
   *
   * @param string $orderId the order ID of the order to update
   * @param string $merchantOrderId the new merchant order ID of the order
   */
  public function updateMerchantOrderId($orderId, $merchantOrderId) {
    printf('Updating merchant order ID to %s... ', $merchantOrderId);
    $req =
        new Google_Service_ShoppingContent_OrdersUpdateMerchantOrderIdRequest();
    $req->setOperationId($this->newOperationId());
    $req->setMerchantOrderId($merchantOrderId);

    $resp = $this->session->sandboxService->orders->updatemerchantorderid(
        $this->session->merchantId, $orderId, $req);
    printf("done (%s).\n", $resp->getExecutionStatus());
    print "\n";
  }

  /**
   * Cancels a line item from the order {@code $orderId}.
   *
   * @param string $orderId the order ID of the order to update
   * @param string $lineItemId the ID of the line item to cancel
   * @param int $quantity amount of the line item to cancel
   * @param string $reason a value from a Google-defined enum (see docs)
   * @param string $reasonText free-form text explaining the cancellation
   *
   * @see https://developers.google.com/shopping-content/v2/reference/v2.1/orders/cancellineitem
   */
  public function cancelLineItem(
      $orderId, $lineItemId, $quantity, $reason, $reasonText) {
    printf('Cancelling %d of item %s... ', $quantity, $lineItemId);
    $req = new Google_Service_ShoppingContent_OrdersCancelLineItemRequest();
    $req->setLineItemId($lineItemId);
    $req->setQuantity($quantity);
    $req->setReason($reason);
    $req->setReasonText($reasonText);
    $req->operationId = $this->newOperationId();

    $resp = $this->session->sandboxService->orders->cancellineitem(
        $this->session->merchantId, $orderId, $req);
    printf("done (%s).\n", $resp->getExecutionStatus());
    print "\n";
  }

  /**
   * Marks a line item from the order {@code $orderId} as having shipped.
   * This method uses the pending quantity of the item.  It returns the
   * shipping request so we can access the randomly-generated tracking and
   * shipping IDs.
   *
   * @param string $orderId the order ID of the order to update
   * @param Google_Service_ShoppingContent_OrderLineItem $lineItem
   *        the line item to cancel
   * @return Google_Service_ShoppingContent_OrdersShipLineItemsRequest
   */
  public function shipLineItemAll($orderId, $lineItem) {
    printf('Shipping %d of item %s... ', $lineItem->getQuantityPending(),
        $lineItem->getId());
    $item = new Google_Service_ShoppingContent_OrderShipmentLineItemShipment();
    $item->setLineItemId($lineItem->getId());
    $item->setQuantity($lineItem->getQuantityPending());

    $shp = new Google_Service_ShoppingContent_OrdersCustomBatchRequestEntryShipLineItemsShipmentInfo();
    $shp->setCarrier(
        $lineItem->getShippingDetails()->getMethod()->getCarrier());
    $shp->setShipmentId(mt_rand());
    $shp->setTrackingId(mt_rand());

    $req = new Google_Service_ShoppingContent_OrdersShipLineItemsRequest();
    $req->setLineItems([$item]);
    $req->operationId = $this->newOperationId();
    $req->setShipmentInfos([$shp]);
    $resp = $this->session->sandboxService->orders->shiplineitems(
        $this->session->merchantId, $orderId, $req);

    printf("done (%s).\n", $resp->getExecutionStatus());
    print "\n";

    return $req;
  }

  /**
   * Marks all of the line item from the order {@code $orderId} that was
   * shipped as being delivered.
   *
   * @param string $orderId the order ID of the order to update
   * @param Google_Service_ShoppingContent_OrdersShipLineItemsRequest $shipReq
   *        the shipping information for the line item to update
   */
  public function lineItemDelivered($orderId, $shipReq) {
    $nbLineItem = count($shipReq->getShipmentInfos());
    for ($i = 0; $i < $nbLineItem; $i++) {
      printf('Marking shipment %s as delivered... ',
             $shipReq->getShipmentInfos()[$i]->getShipmentId());
      $req = new Google_Service_ShoppingContent_OrdersUpdateShipmentRequest();
      $req->setCarrier($shipReq->getShipmentInfos()[$i]->getCarrier());
      $req->setShipmentId($shipReq->getShipmentInfos()[$i]->getShipmentId());
      $req->setTrackingId($shipReq->getShipmentInfos()[$i]->getTrackingId());
      $req->setStatus('delivered');
      $req->operationId = $this->newOperationId();

      $resp = $this->session->sandboxService->orders->updateshipment(
          $this->session->merchantId, $orderId, $req);
      printf("done (%s).\n", $resp->getExecutionStatus());
      print "\n";
    }

  }

  /**
   * Returns a line item from the order {@code $orderId}.
   *
   * @param string $orderId the order ID of the order to update
   * @param string $lineItemId the ID of the line item to return
   * @param int $quantity amount of the line item to return
   * @param string $reason a value from a Google-defined enum (see docs)
   * @param string $reasonText free-form text explaining the return
   *
   * @see https://developers.google.com/shopping-content/v2/reference/v2.1/orders/returnrefundlineitem
   */
  public function lineItemReturned(
      $orderId, $lineItemId, $quantity, $reason, $reasonText) {
    printf('Marking %d of item %s as returned... ', $quantity, $lineItemId);
    $req = new Google_Service_ShoppingContent_OrdersReturnRefundLineItemRequest();
    $req->setLineItemId($lineItemId);
    $req->setQuantity($quantity);
    $req->setReason($reason);
    $req->setReasonText($reasonText);
    $req->operationId = $this->newOperationId();

    $resp = $this->session->sandboxService->orders->returnrefundlineitem(
        $this->session->merchantId, $orderId, $req);
    printf("done (%s).\n", $resp->getExecutionStatus());
    print "\n";
  }

  // Helper function to just make code more streamlined below.  Default for
  // $indent fits most uses.
  private function printIfTrue($value, $text, $indent = '  ') {
    if($value) {
      printf($indent . "- %s: %s\n", $text, $value);
    }
  }

  /**
   * Prints the order information contained in {@code $order}.
   *
   * @param Order $order the Order resource to print
   */
  public function printOrder($order) {
    printf("Order %s:\n", $order->getId());
    printf("- Status: %s\n", $order->getStatus());
    printf("- Merchant: %d\n", $order->getMerchantId());
    $this->printIfTrue($order->getMerchantOrderId(),
        'Merchant order ID', '');
    printf("- Placed on date: %s\n", $order->getPlacedDate());
    if ($order->getNetPriceAmount()) {
      $amount = $order->getNetPriceAmount();
      printf("- Net amount: %s %s\n", $amount->getValue(),
          $amount->getCurrency());
    }
    if ($order->getPaymentStatus()) {
      printf("  - Payment status: %s\n", $order->getPaymentStatus());
    }

    printf("- Acknowledged: %s\n", $order->getAcknowledged() ? 'yes' : 'no');
    if (!empty($order->getLineItems())) {
      printf("- %d line item(s):\n", count($order->getLineItems()));
      foreach ($order->getLineItems() as $item) {
        $this->printLineItem($item);
      }
    }
    if ($order->getShippingCost()) {
      $cost = $order->getShippingCost();
      printf("- Shipping cost: %s %s\n", $cost->getValue(),
          $cost->getCurrency());
    }
    if ($order->getShippingCostTax()) {
      $tax = $order->getShippingCostTax();
      printf("- Shipping cost: %s %s\n", $tax->getValue(), $tax->getCurrency());
    }
    if (!empty($order->getShipments())) {
      printf("- %d shipment(s):\n", count($order->getShipments()));
      foreach ($order->getShipments() as $shipment) {
        printf("  Shipment %s:\n", $shipment->getId());
        printf("  - Creation date: %s\n", $shipment->getCreationDate());
        printf("  - Carrier: %s\n", $shipment->getCarrier());
        printf("  - Tracking ID: %s\n", $shipment->getTrackingId());
        if (!empty($shipment->getLineItems())) {
          printf("  - %d line item(s):\n", count($shipment->getLineItems()));
          foreach ($shipment->getLineItems() as $item) {
            printf("    %d of item %s\n", $item->getQuantity(),
                $item->getLineItemId());
          }
        }
        $this->printIfTrue($shipment->getDeliveryDate(), 'Delivery date');
      }
    }
  }

  private function printLineItem($item) {
    printf("  Line item: %s\n", $item->getId());
    printf("  - Product: %s (%s)\n", $item->getProduct()->getId(),
        $item->getProduct()->getTitle());
    printf("  - Price: %s %s\n", $item->getPrice()->getValue(),
        $item->getPrice()->getCurrency());
    printf("  - Tax: %s %s\n", $item->getTax()->getValue(),
        $item->getTax()->getCurrency());
    if ($item->getShippingDetails()) {
      $details = $item->getShippingDetails();
      printf("  - Ship by date: %s\n", $details->getShipByDate());
      printf("  - Deliver by date: %s\n", $details->getDeliverByDate());
      $method = $details->getMethod();
      printf("  - Deliver via %s %s (%s - %s days)\n",
          $method->getCarrier(), $method->getMethodName(),
          $method->getMinDaysInTransit(), $method->getMaxDaysInTransit());
    }
    if ($item->getReturnInfo() && $item->getReturnInfo()->getIsReturnable()) {
      $info = $item->getReturnInfo();
      printf("  - Item is returnable.\n");
      printf("    - Days to return: %s\n", $info->getDaysToReturn());
      printf("    - Return policy is at %s.\n", $info->getPolicyUrl());
    } else {
      printf("  - Item is not returnable.\n");
    }
    $this->printIfTrue($item->getQuantityOrdered(), 'Quantity ordered');
    $this->printIfTrue($item->getQuantityPending(), 'Quantity pending');
    $this->printIfTrue($item->getQuantityCanceled(), 'Quantity canceled');
    $this->printIfTrue($item->getQuantityShipped(), 'Quantity shipped');
    $this->printIfTrue($item->getQuantityDelivered(), 'Quantity delivered');
    $this->printIfTrue($item->getQuantityReturned(), 'Quantity returned');
    if (!empty($item->getCancellations())) {
      printf("  - %d cancellation(s):\n", count($item->getCancellations()));
      foreach ($item->getCancellations() as $cancel) {
        printf("    Cancellation:\n");
        $this->printIfTrue($cancel->getActor(), 'Actor', '    ');
        printf("    - Creation date: %s\n", $cancel->getCreationDate());
        printf("    - Quantity: %s\n", $cancel->getQuantity());
        printf("    - Reason: %s\n", $cancel->getReason());
        printf("    - Reason text: %s\n", $cancel->getReasonText());
      }
    }
    if (!empty($item->getReturns())) {
      printf("  - %d return(s):\n", count($item->getReturns()));
      foreach ($item->getReturns() as $ret) {
        printf("    Return:\n");
        $this->printIfTrue($ret->getActor(), 'Actor', '    ');
        printf("    - Creation date: %s\n", $ret->getCreationDate());
        printf("    - Quantity: %s\n", $ret->getQuantity());
        printf("    - Reason: %s\n", $ret->getReason());
        printf("    - Reason text: %s\n", $ret->getReasonText());
      }
    }
  }
}

$sample = new OrdersSample();
$sample->run();
