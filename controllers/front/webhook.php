<?php
/**
 * Front controller: Ebioro webhook receiver (server-to-server).
 *
 * Authentication is the X-WEBHOOK-AUTH HMAC over the raw body — there is no
 * customer session, so PrestaShop auth is disabled. The payload's
 * data.metadata.order_id maps the event back to the order.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EbioroPaymentWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    /** Skip template rendering / front assets — this endpoint returns plain status. */
    public $content_only = true;

    public function postProcess()
    {
        if ('POST' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
            $this->respond(405, 'Method not allowed');
        }

        $rawBody = Tools::file_get_contents('php://input');
        $signature = $this->getSignatureHeader();
        $apiSecret = Configuration::get(EbioroPayment::CFG_API_SECRET);

        if (empty($rawBody) || !EbioroApiHandler::verifyWebhookSignature($rawBody, $signature, $apiSecret)) {
            PrestaShopLogger::addLog('[Ebioro] Webhook failed signature validation', 2, null, 'EbioroPayment');
            $this->respond(401, 'Invalid signature');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || empty($payload['data']) || !is_array($payload['data'])) {
            $this->respond(400, 'Invalid payload');
        }

        $event = $payload['data'];
        $orderId = isset($event['metadata']['order_id']) ? (int) $event['metadata']['order_id'] : 0;
        if (!$orderId) {
            $this->respond(400, 'Missing order_id');
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            $this->respond(404, 'Order not found');
        }

        $this->updateOrderState($order, $event);
        $this->respond(200, 'OK');
    }

    /** Map the Ebioro event to a PrestaShop order state. */
    private function updateOrderState(Order $order, array $event)
    {
        $type = isset($event['type']) ? $event['type'] : '';
        $status = isset($event['status']) ? $event['status'] : '';
        $settlement = isset($event['settlement_status']) ? $event['settlement_status'] : '';

        if ('transaction_updated' !== $type && 'transaction_failed' !== $type) {
            return; // transaction_created: order is already in the awaiting state.
        }

        $currentState = (int) $order->getCurrentState();
        $awaiting = (int) Configuration::get(EbioroPayment::CFG_OS_AWAITING);
        $paidState = (int) Configuration::get('PS_OS_PAYMENT');
        $errorState = (int) Configuration::get('PS_OS_ERROR');
        $canceledState = (int) Configuration::get('PS_OS_CANCELED');

        // Treat the order as still open if it's awaiting, in error, or canceled —
        // a missed first webhook can be followed by a later delivery (or resend)
        // that already carries settlement = processing/paid.
        $isOpen = in_array($currentState, array($awaiting, $errorState, $canceledState), true);

        if ('paid' === $status) {
            if ($isOpen && $currentState !== $paidState) {
                $order->setCurrentState($paidState);
                $this->note($order, 'Ebioro payment confirmed.');
            }
            if ('paid' === $settlement) {
                $this->note($order, 'Ebioro payment settled to your merchant account.');
            } elseif ('processing' === $settlement) {
                $this->note($order, 'Ebioro settlement to your merchant account initiated.');
            }
            return;
        }

        if ('underpaid' === $status) {
            $this->note($order, 'Ebioro payment was underpaid by the customer; awaiting top-up.');
            return;
        }

        if (in_array($status, array('failed', 'expired', 'canceled'), true) || 'transaction_failed' === $type) {
            if ($isOpen) {
                $order->setCurrentState($canceledState);
                $this->note($order, 'Ebioro payment ' . pSQL($status ?: 'failed') . '.');
            }
        }
    }

    private function note(Order $order, $message)
    {
        $msg = new Message();
        $msg->message = $message;
        $msg->id_order = (int) $order->id;
        $msg->private = 1;
        $msg->add();
    }

    /** Read the X-WEBHOOK-AUTH header from $_SERVER, with a getallheaders() fallback. */
    private function getSignatureHeader()
    {
        if (isset($_SERVER['HTTP_X_WEBHOOK_AUTH'])) {
            return (string) $_SERVER['HTTP_X_WEBHOOK_AUTH'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if ('x-webhook-auth' === strtolower($name)) {
                    return (string) $value;
                }
            }
        }
        return '';
    }

    /** Emit a bare HTTP status and exit — no theme, no redirect. */
    private function respond($code, $message)
    {
        http_response_code((int) $code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
