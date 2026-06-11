<?php
/**
 * Front controller: customer chose Ebioro at checkout.
 *
 * Creates the order in the "Awaiting Ebioro payment" state, opens an Ebioro
 * hosted payment carrying the order id in metadata, and redirects the browser
 * to the hosted page. The webhook later confirms settlement and marks the order
 * paid — mirroring the WooCommerce flow.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EbioroPaymentRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        // Validate the cart belongs to a logged-in customer with a complete cart,
        // exactly as PrestaShop's core payment validation expects.
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            $this->redirectToCartWithError('Your cart could not be validated.');
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->redirectToCartWithError('This payment method is not available.');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->redirectToCartWithError('Invalid customer.');
        }

        $currency = $this->context->currency;
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // Create the order in the awaiting state before redirecting offsite.
        $awaitingStateId = (int) Configuration::get(EbioroPayment::CFG_OS_AWAITING);
        $this->module->validateOrder(
            (int) $cart->id,
            $awaitingStateId,
            $total,
            $this->module->displayName,
            null,
            array(),
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        $orderId = (int) $this->module->currentOrder;
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            $this->redirectToCartWithError('The order could not be created.');
        }

        // URLs the hosted page returns the customer to.
        $redirectUrl = $this->context->link->getPageLink('order-confirmation', true, null, array(
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => $orderId,
            'key' => $customer->secure_key,
        ));
        $cancelUrl = $this->context->link->getPageLink('order', true);
        $webhookUrl = $this->context->link->getModuleLink($this->module->name, 'webhook', array(), true);

        $handler = $this->module->getApiHandler();
        list($ok, $result) = $handler->createPayment(
            EbioroApiHandler::toMinorUnit($total),
            $currency->iso_code,
            array('order_id' => $orderId, 'cart_id' => (int) $cart->id),
            $redirectUrl,
            $cancelUrl,
            $webhookUrl,
            Configuration::get('PS_SHOP_NAME'),
            $this->module->l('Order') . ' #' . $orderId,
            Configuration::get(EbioroPayment::CFG_LOCALE) ?: 'en'
        );

        if (!$ok || empty($result['hostedUrl'])) {
            // Mark the order failed so it isn't left dangling in "awaiting".
            $order->setCurrentState((int) Configuration::get('PS_OS_ERROR'));
            $this->redirectToCartWithError(is_string($result) ? $result : 'Could not start the Ebioro payment.');
        }

        // Record the Ebioro payment reference on the order for reconciliation.
        if (!empty($result['id'])) {
            $this->addPrivateOrderMessage($order, 'Ebioro payment reference: ' . pSQL($result['id']));
        }

        Tools::redirect($result['hostedUrl']);
    }

    private function redirectToCartWithError($message)
    {
        $this->errors[] = $this->module->l($message);
        $this->redirectWithNotifications($this->context->link->getPageLink('cart', true, null, array('action' => 'show')));
        exit;
    }

    /** Attach a private (back-office only) note to the order. */
    private function addPrivateOrderMessage($order, $message)
    {
        $msg = new Message();
        $msg->message = $message;
        $msg->id_order = (int) $order->id;
        $msg->private = 1;
        $msg->add();
    }
}
