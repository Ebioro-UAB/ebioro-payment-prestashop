<?php
/**
 * Ebioro Payments for PrestaShop 8.
 *
 * Hosted-checkout payment module: at checkout the customer is redirected to the
 * Ebioro-hosted payment page; settlement is confirmed asynchronously via webhook.
 * Mirrors the WooCommerce plugin's behaviour.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

require_once dirname(__FILE__) . '/classes/EbioroApiHandler.php';

class EbioroPayment extends PaymentModule
{
    /** Configuration keys. */
    const CFG_API_KEY = 'EBIORO_API_KEY';
    const CFG_API_SECRET = 'EBIORO_API_SECRET';
    const CFG_TEST_MODE = 'EBIORO_TEST_MODE';
    const CFG_LOCALE = 'EBIORO_LOCALE';
    /** Custom order state created on install. */
    const CFG_OS_AWAITING = 'EBIORO_OS_AWAITING';

    public function __construct()
    {
        $this->name = 'ebioropayment';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Ebioro';
        $this->need_instance = 0;
        $this->controllers = array('redirect', 'webhook');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Ebioro Payments');
        $this->description = $this->l('Accept USDC payments through the Ebioro hosted checkout.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Ebioro Payments?');

        if (!Configuration::get(self::CFG_API_KEY) || !Configuration::get(self::CFG_API_SECRET)) {
            $this->warning = $this->l('Your Ebioro API key and secret must be configured.');
        }
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->installAwaitingOrderState();
    }

    public function uninstall()
    {
        foreach (array(self::CFG_API_KEY, self::CFG_API_SECRET, self::CFG_TEST_MODE, self::CFG_LOCALE) as $key) {
            Configuration::deleteByName($key);
        }
        // Leave the custom order state in place — historical orders may reference it.
        return parent::uninstall();
    }

    /** Create the "Awaiting Ebioro payment" order state once. */
    private function installAwaitingOrderState()
    {
        if (Configuration::get(self::CFG_OS_AWAITING)) {
            $existing = new OrderState((int) Configuration::get(self::CFG_OS_AWAITING));
            if (Validate::isLoadedObject($existing)) {
                return true;
            }
        }

        $state = new OrderState();
        $state->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $state->name[$lang['id_lang']] = 'Awaiting Ebioro payment';
        }
        $state->send_email = false;
        $state->color = '#0092DF';
        $state->hidden = false;
        $state->delivery = false;
        $state->logable = false;
        $state->invoice = false;
        $state->paid = false;
        $state->module_name = $this->name;

        if ($state->add()) {
            Configuration::updateValue(self::CFG_OS_AWAITING, (int) $state->id);
            return true;
        }

        return false;
    }

    /** Admin configuration form (API key, secret, test mode, locale). */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $apiKey = trim((string) Tools::getValue(self::CFG_API_KEY));
            $apiSecret = trim((string) Tools::getValue(self::CFG_API_SECRET));
            $testMode = (bool) Tools::getValue(self::CFG_TEST_MODE);
            $locale = trim((string) Tools::getValue(self::CFG_LOCALE)) ?: 'en';

            if ('' === $apiKey || '' === $apiSecret) {
                $output .= $this->displayError($this->l('API key and secret are required.'));
            } else {
                Configuration::updateValue(self::CFG_API_KEY, $apiKey);
                Configuration::updateValue(self::CFG_API_SECRET, $apiSecret);
                Configuration::updateValue(self::CFG_TEST_MODE, $testMode ? 1 : 0);
                Configuration::updateValue(self::CFG_LOCALE, $locale);
                $output .= $this->displayConfirmation($this->l('Settings saved.'));
            }
        }

        return $output . $this->renderConfigForm();
    }

    private function renderConfigForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Ebioro API credentials'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Key'),
                        'name' => self::CFG_API_KEY,
                        'desc' => $this->l('Your Ebioro public API key (pk_...).'),
                        'required' => true,
                    ),
                    array(
                        'type' => 'password',
                        'label' => $this->l('API Secret'),
                        'name' => self::CFG_API_SECRET,
                        'desc' => $this->l('Your Ebioro secret API key (sk_...). Stored server-side only.'),
                        'required' => true,
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Test mode'),
                        'name' => self::CFG_TEST_MODE,
                        'desc' => $this->l('Use the Ebioro test environment (test-merchant.ebioro.com).'),
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Checkout locale'),
                        'name' => self::CFG_LOCALE,
                        'desc' => $this->l('Language preset for the hosted payment page (e.g. en, es).'),
                    ),
                ),
                'submit' => array('title' => $this->l('Save')),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submit' . $this->name;
        $helper->fields_value = array(
            self::CFG_API_KEY => Configuration::get(self::CFG_API_KEY),
            self::CFG_API_SECRET => Configuration::get(self::CFG_API_SECRET),
            self::CFG_TEST_MODE => (int) Configuration::get(self::CFG_TEST_MODE),
            self::CFG_LOCALE => Configuration::get(self::CFG_LOCALE) ?: 'en',
        );

        return $helper->generateForm(array($fields_form));
    }

    /** Offer Ebioro as a payment option at checkout. */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->checkCurrency($params['cart'])) {
            return array();
        }
        if (!Configuration::get(self::CFG_API_KEY) || !Configuration::get(self::CFG_API_SECRET)) {
            return array();
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with Ebioro (USDC)'))
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', array(), true))
            ->setLogo(Media::getMediaPath(dirname(__FILE__) . '/logo.png'));

        return array($option);
    }

    /** Confirmation message shown on the order-confirmation page. */
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }
        $this->context->smarty->assign(array(
            'shop_name' => $this->context->shop->name,
        ));
        return $this->fetch('module:ebioropayment/views/templates/hook/payment_return.tpl');
    }

    /** Whether the cart currency is enabled for this module. */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency((int) $cart->id_currency);
        $currencies_module = $this->getCurrency((int) $cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Shared API handler built from stored config. */
    public function getApiHandler()
    {
        return new EbioroApiHandler(
            Configuration::get(self::CFG_API_KEY),
            Configuration::get(self::CFG_API_SECRET),
            (bool) Configuration::get(self::CFG_TEST_MODE)
        );
    }
}
