<?php

/**
 * 2007-2017 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2016 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use FacebookAds\Api;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\ActionSource;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\EventRequestAsync;
use FacebookAds\Object\ServerSide\UserData;
use FacebookAds\Http\Exception\RequestException;

class Pspixel extends Module
{
    protected $js_path = null;
    protected $front_controller = null;

    protected $isPS16 = false;

    public function __construct()
    {
        $this->name = 'pspixel';
        $this->author = 'Prestashop';
        $this->tab = 'analytics_stats';
        $this->module_key = '73fdb778d4cf7afd3bbfe96dc57dc8c5';
        $this->version = '1.1.2';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $this->ps_versions_compliancy = array(
            'min' => '1.6.0.0',
            'max' => _PS_VERSION_,
        );

        parent::__construct();

        $this->displayName = $this->l('Official Facebook Pixel');
        $this->description = $this->l('This module allows you to implement an analysis tool into your website pages and track events');

        $this->js_path = 'modules/' . $this->name . '/views/js/';
        $this->front_controller = Context::getContext()->link->getModuleLink(
            $this->name,
            'FrontAjaxPixel',
            array(),
            true
        );
        $this->isPS16 = version_compare(_PS_VERSION_, '1.7.0.0', '<');
    }

    public function install()
    {
        $this->_clearCache('*');
        Configuration::updateValue('PS_PIXEL_ID', '');
        Configuration::updateValue('PS_PIXEL_ACCESS_TOKEN', '');
        Configuration::updateValue('PS_PIXEL_TEST_ENABLE', false);
        Configuration::updateValue('PS_PIXEL_TEST_CODE', '');

        return parent::install()
            && $this->registerHook('header')
            && $this->registerHook('displayPaymentTop')
            && $this->registerHook('displayOrderConfirmation')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionAjaxDieProductControllerdisplayAjaxQuickviewBefore')
            && $this->registerHook('actionObjectOrderAddAfter');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    private function postProcess()
    {
        if (((bool)Tools::isSubmit('submitPixel')) === true) {
            $id_pixel = pSQL(trim(Tools::getValue('PS_PIXEL_ID')));
            if (empty($id_pixel)) {
                return  $this->displayError(
                    $this->l('Your ID Pixel can not be empty')
                );
            } elseif (Tools::strlen($id_pixel) < 15 || Tools::strlen($id_pixel) > 16) {
                return  $this->displayError(
                    $this->l('Your ID Pixel must be 16 characters long')
                );
            } else {
                Configuration::updateValue('PS_PIXEL_ID', $id_pixel);
                $this->displayConfirmation(
                    $this->l('Your ID Pixel have been updated.')
                );
            }

            $accessToken = pSQL(trim(Tools::getValue('PS_PIXEL_ACCESS_TOKEN')));
            if (empty($accessToken)) {
                $this->displayError(
                    $this->l('Your access token can not be empty')
                );
            } else {
                Configuration::updateValue('PS_PIXEL_ACCESS_TOKEN', $accessToken);
                $this->displayConfirmation(
                    $this->l('Your access token have been updated.')
                );
            }

            $testEnable = (int)Tools::getValue('PS_PIXEL_TEST_ENABLE');
            Configuration::updateValue('PS_PIXEL_TEST_ENABLE', $testEnable);
            $this->displayConfirmation(
                $this->l('Your test enable have been updated.')
            );

            $testCode = pSQL(trim(Tools::getValue('PS_PIXEL_TEST_CODE')));
            if (empty($testCode)) {
                $this->displayError(
                    $this->l('Your test code can not be empty')
                );
            } else {
                Configuration::updateValue('PS_PIXEL_TEST_CODE', $testCode);
                $this->displayConfirmation(
                    $this->l('Your test code have been updated.')
                );
            }
        }
    }

    public function getContent()
    {
        // Set JS
        $this->context->controller->addJs(array(
            $this->_path . 'views/js/conf.js',
            $this->_path . 'views/js/faq.js'
        ));

        // Set CSS
        $this->context->controller->addCss(
            $this->_path . 'views/css/faq.css'
        );

        $is_submit = $this->postProcess();

        include_once('classes/APIFAQClass.php');
        $api = new APIFAQ();
        $api_json = Tools::jsonDecode($api->getData($this));
        $apifaq_json_categories = '';
        if (!empty($api_json)) {
            $apifaq_json_categories = $api_json->categories;
        }

        $this->context->smarty->assign(array(
            'is_submit'          => $is_submit,
            'module_name'        => $this->name,
            'module_version'     => $this->version,
            'debug_mode'         => (int) _PS_MODE_DEV_,
            'module_display'     => $this->displayName,
            'multishop'          => (int) Shop::isFeatureActive(),
            'apifaq'             => $apifaq_json_categories,
            'version'            => _PS_VERSION_,
            'id_pixel'           => pSQL(Configuration::get('PS_PIXEL_ID')),
            'access_token'       => pSQL(Configuration::get('PS_PIXEL_ACCESS_TOKEN')),
            'test_enable'        => Configuration::get('PS_PIXEL_TEST_ENABLE'),
            'test_code'          => pSQL(Configuration::get('PS_PIXEL_TEST_CODE')),
        ));

        return $is_submit . $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
    }

    /*
    ** Hook's Managment
    */
    public function hookActionFrontControllerSetMedia()
    {
        $pixel_id = Configuration::get('PS_PIXEL_ID');
        if (empty($pixel_id)) {
            return;
        }

        // Asset Manager
        $this->context->controller->addJS($this->js_path . 'printpixel.js');
    }

    // Handle Payment module (AddPaymentInfo)
    public function hookDisplayPaymentTop($params)
    {
        $pixel_id = Configuration::get('PS_PIXEL_ID');
        if (empty($pixel_id)) {
            return;
        }

        $items_id = array();
        $items = $this->context->cart->getProducts();
        foreach ($items as &$item) {
            $items_id[] = (int)$item['id_product'];
        }
        unset($items, $item);

        $iso_code = pSQL($this->context->currency->iso_code);
        $content = array(
            'value' => Tools::ps_round($this->context->cart->getOrderTotal(), 2),
            'currency' => $iso_code,
            'content_type' => 'product',
            'content_ids' => $items_id,
            'num_items' => $this->context->cart->nbProducts(),
        );

        $content = $this->formatPixel($content);

        $this->context->smarty->assign(array(
            'type' => 'AddPaymentInfo',
            'content' => $content,
        ));

        return $this->display(__FILE__, 'views/templates/hook/displaypixel.tpl');
    }

    // Set Pixel (ViewContent / ViewCategory / ViewCMS / Search / InitiateCheckout)
    public function hookHeader($params)
    {
        $pixel_id = Configuration::get('PS_PIXEL_ID');
        if (empty($pixel_id)) {
            return;
        }

        // Asset Manager to be sure the JS is loaded
        $this->context->controller->addJS($this->js_path . 'printpixel.js');

        $type = '';
        $content = array();

        $page = $this->context->controller->php_self;
        if (empty($page)) {
            $page = Tools::getValue('controller');
        }
        $page = pSQL($page);

        // front || modulefront 
        $controller_type = pSQL($this->context->controller->controller_type);

        $id_lang = (int)$this->context->language->id;
        $locale = pSQL(Tools::strtoupper($this->context->language->iso_code));
        $iso_code = pSQL($this->context->currency->iso_code);
        $content_type = 'product';

        $track = 'track';
        /**
         * Triggers ViewContent product pages
         */
        if ($page === 'product') {
            $type = 'ViewContent';
            $product = $this->context->controller->getProduct();

            /*if ($product->hasAttributes() > 0) {
                $content_type = 'product_group';
            }*/

            $content = array(
                'content_name' => Tools::replaceAccentedChars($product->name) . ' (' . $locale . ')',
                'content_ids' => array($product->id),
                'content_type' => $content_type,
                'value' => (float)$product->price,
                'currency' => $iso_code,
            );
        }
        /**
         * Triggers ViewContent for category pages
         */
        elseif ($page === 'category' && $controller_type === 'front') {
            $type = 'ViewCategory';
            $category = $this->context->controller->getCategory();

            //$breadcrumbs = $this->context->controller->getBreadcrumbLinks();
            //$breadcrumb = implode(' > ', array_column($breadcrumbs['links'], 'title'));
            $breadcrumb = '';

            $prods = $category->getProducts($id_lang, 1, 10);
            $track = 'trackCustom';

            $content = array(
                'content_name' => Tools::replaceAccentedChars($category->name) . ' (' . $locale . ')',
                'content_category' => Tools::replaceAccentedChars($breadcrumb),
                'content_ids' => array_column($prods, 'id_product'),
                'content_type' => $content_type,
            );
        }
        /**
         * Triggers ViewContent for custom module
         */
        elseif ($controller_type === 'modulefront') {
            $front_module = $this->context->controller->module;
            $name = Tools::ucfirst($front_module->name);
            $type = 'View' . $name . Tools::ucfirst($page);

            $track = 'trackCustom';
            $content = array();
        }
        /**
         * Triggers ViewContent for cms pages
         */
        elseif ($page === 'cms') {
            $type = 'ViewCMS';
            $cms = new Cms((int)Tools::getValue('id_cms'), $id_lang);

            //$breadcrumbs = $this->context->controller->getBreadcrumbLinks();
            //$breadcrumb = implode(' > ', array_column($breadcrumbs['links'], 'title'));
            $breadcrumb = '';
            $track = 'trackCustom';

            $content = array(
                'content_category' => Tools::replaceAccentedChars($breadcrumb),
                'content_name' => Tools::replaceAccentedChars($cms->meta_title) . ' (' . $locale . ')',
            );
        }
        /**
         * Triggers Search for result pages
         */
        elseif ($page === 'search') {
            $type = Tools::ucfirst($page);
            $content = array(
                'search_string' => pSQL(Tools::getValue('s')),
            );
        }
        /**
         * Triggers InitiateCheckout for checkout page
         */
        elseif ($page === 'cart' || ($page === 'order' && $this->context->controller->step === 0)) {
            $type = 'InitiateCheckout';

            $content = array(
                'num_items' => $this->context->cart->nbProducts(),
                'content_ids' => array_column($this->context->cart->getProducts(), 'id_product'),
                'content_type' => $content_type,
                'value' => (float)$this->context->cart->getOrderTotal(),
                'currency' => $iso_code,
            );
        }
        /**
         * Triggers InitiateCheckout for checkout page
         */
        elseif ($page === 'order' && $this->context->controller->step === 1 && Validate::isLoadedObject($this->context->customer)
        && $this->context->customer->logged == 1) {
            $type = 'CheckoutCustomerLogued';
            $track = 'trackCustom';

            $idCustomer = $this->context->customer->id;

            $content = array(
                'content_category' => '',
                'content_name' => $idCustomer,
                'external_id' => $idCustomer
            );

            Configuration::updateValue('PSPIXEL_CUSTOMER_' . $idCustomer . '_REMOTE_ADDR',  $_SERVER['REMOTE_ADDR']);
            Configuration::updateValue('PSPIXEL_CUSTOMER_' . $idCustomer . '_HTTP_USER_AGENT', $_SERVER['HTTP_USER_AGENT']);
        }

        // Format Pixel to display
        $content = $this->formatPixel($content);

        Media::addJsDef(array(
            'pixel_fc' => $this->front_controller
        ));

        $this->context->smarty->assign(array(
            'id_pixel' => pSQL(Configuration::get('PS_PIXEL_ID')),
            'type' => $type,
            'content' => $content,
            'track' => $track,
        ));

        return $this->display(__FILE__, 'views/templates/hook/header.tpl');
    }

    // Handle QuickView (ViewContent)
    public function hookActionAjaxDieProductControllerdisplayAjaxQuickviewBefore($params)
    {
        $pixel_id = Configuration::get('PS_PIXEL_ID');
        if (empty($pixel_id)) {
            return;
        }

        // Decode Product Object
        $value = Tools::jsonDecode($params['value']);
        $locale = pSQL(Tools::strtoupper($this->context->language->iso_code));
        $iso_code = pSQL($this->context->currency->iso_code);

        $content = array(
            'content_name' => Tools::replaceAccentedChars($value->product->name) . ' (' . $locale . ')',
            'content_ids' => array($value->product->id_product),
            'content_type' => 'product',
            'value' => (float)$value->product->price_amount,
            'currency' => $iso_code,
        );
        $content = $this->formatPixel($content);

        $this->context->smarty->assign(array(
            'type' => 'ViewContent',
            'content' => $content,
        ));

        $value->quickview_html .= $this->context->smarty->fetch(
            $this->local_path . 'views/templates/hook/displaypixel.tpl'
        );

        // Recode Product Object
        $params['value'] = Tools::jsonEncode($value);

        die($params['value']);
    }

    // Handle Display confirmation (Purchase)
    public function hookDisplayOrderConfirmation($params)
    {
        $pixel_id = Configuration::get('PS_PIXEL_ID');
        if (empty($pixel_id)) {
            return;
        }

        $order = isset($params['objOrder']) ? $params['objOrder'] : $params['order'];

        $num_items = 0;
        $items_id = array();
        $items = $order->getProductsDetail();
        foreach ($items as $item) {
            $num_items += (int)$item['product_quantity'];
            $items_id[] = (int)$item['product_id'];
        }
        unset($items, $item);

        $iso_code = pSQL($this->context->currency->iso_code);

        $content = array(
            'value' => Tools::ps_round($order->total_paid, 2),
            'currency' => $iso_code,
            'content_type' => 'product',
            'content_ids' => $items_id,
            'order_id' => $order->id,
            'num_items' => $num_items,
        );

        $content = $this->formatPixel($content);

        $this->context->smarty->assign(array(
            'type' => 'Purchase',
            'content' => $content,
        ));

        return $this->display(__FILE__, 'views/templates/hook/displaypixel.tpl');
    }

    public function hookActionObjectOrderAddAfter($params)
    {
        $now = date('Y-m-d H:i:s');
        $pixel_id = self::getConfig('PS_PIXEL_ID', '');
        $accessToken = self::getConfig('PS_PIXEL_ACCESS_TOKEN', '');
        $order = $params['object'];

        file_put_contents(__DIR__ . '/logs/pspixel.log', PHP_EOL . $now . ' - hookActionObjectOrderAddAfter - pixel_id: ' . $pixel_id . ' - access_token: ' . $accessToken . ' - params: ' . json_encode($params) . PHP_EOL, FILE_APPEND);

        if (empty($pixel_id) || empty($accessToken) || !Validate::isLoadedObject($order)) {
            return false;
        }

        try {
            $customer = $order->getCustomer();
            $num_items = 0;
            $items_id = array();
            $items = $order->getProductsDetail();
            foreach ($items as $item) {
                $num_items += (int)$item['product_quantity'];
                $items_id[] = (int)$item['product_id'];
            }
            unset($items, $item);

            $iso_code = pSQL($this->context->currency->iso_code);

            Api::init(null, null, $accessToken, false);            

            $ip = self::getConfig('PSPIXEL_CUSTOMER_' . $customer->id . '_REMOTE_ADDR', false);
            $userAgent = self::getConfig('PSPIXEL_CUSTOMER_' . $customer->id . '_HTTP_USER_AGENT', false);

            $user_data = (new UserData())
                ->setEmail($customer->email)
                ->setFirstName($customer->firstname)
                ->setLastName($customer->lastname)
                ->setExternalId($customer->id);

            if (is_string($ip) && !empty($ip)) {
                $user_data->setClientIpAddress($ip);
            }

            if (is_string($userAgent) && !empty($userAgent)) {
                $user_data->setClientUserAgent($userAgent);
            }

            $custom_data = (new CustomData())
                ->setCurrency($iso_code)
                ->setValue(Tools::ps_round($order->total_paid, 2))
                ->setContentType('product')
                ->setContentIds($items_id)
                ->setOrderId($order->id)
                ->setNumItems($num_items);

            $event = (new Event())
                ->setEventName('Purchase')
                ->setEventTime(time())
                //->setEventSourceUrl('http://jaspers-market.com/product/123')
                ->setUserData($user_data)
                ->setCustomData($custom_data)
                ->setActionSource(ActionSource::WEBSITE);

            $async_request = (new EventRequestAsync($pixel_id))->setEvents([$event]);

            $isTestEnable = (int)self::getConfig('PS_PIXEL_TEST_ENABLE', 0);
            if ($isTestEnable > 0) {
                $testCode = self::getConfig('PS_PIXEL_TEST_CODE', '');
                $async_request->setTestEventCode($testCode);
            }

            file_put_contents(__DIR__ . '/logs/pspixel.log', $now . ' - hookActionObjectOrderAddAfter(' . $order->id . ', ' . $customer->email . ', '. $ip .', ' . $userAgent .') -> SEND... ' . PHP_EOL, FILE_APPEND);
            $async_request->execute()->then(function () use ($order, $customer, $now) {
                file_put_contents(__DIR__ . '/logs/pspixel.log', $now . ' - hookActionObjectOrderAddAfter(' . $order->id . ', ' . $customer->email . ') -> SUCCESS ' . PHP_EOL, FILE_APPEND);
                
                Configuration::deleteByName('PSPIXEL_CUSTOMER_' . $customer->id . '_REMOTE_ADDR');
                Configuration::deleteByName('PSPIXEL_CUSTOMER_' . $customer->id . '_HTTP_USER_AGENT');
                //PrestaShopLogger::addLog('pspixel::hookActionObjectOrderAddAfter(' . $order->id . ', ' . $customer->email . ') SUCCESS', 1, null, 'pspixel', 1, false);
            }, function (RequestException $e) use ($now, $order, $customer) {
                file_put_contents(__DIR__ . '/logs/pspixel.log', $now . ' - hookActionObjectOrderAddAfter(' . $order->id . ', ' . $customer->email . ') -> ERROR - RequestException: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                /*print("Error!!!\n" .
                    $e->getMessage() . "\n" .
                    $e->getRequest()->getMethod() . "\n"
                );*/
            });
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/logs/pspixel.log', $now . ' - hookActionObjectOrderAddAfter(' . $order->id . ', ' . $customer->email . ') -> EXCEPTION' . PHP_EOL, FILE_APPEND);
            //PrestaShopLogger::addLog('pspixel::hookActionObjectOrderAddAfter(' . $order->id . ', ' . $customer->email . ') EXCEPTION = ' . $e->getMessage(), 1, null, 'pspixel', 1, false);
        }
    }

    // Format you pixel
    private function formatPixel($params)
    {
        if (!empty($params)) {
            $format = '{';
            foreach ($params as $key => &$val) {
                if (gettype($val) === 'string') {
                    $format .= $key . ': \'' . addslashes($val) . '\', ';
                } elseif (gettype($val) === 'array') {
                    $format .= $key . ': [\'';
                    foreach ($val as &$id) {
                        $format .= (int)$id . "', '";
                    }
                    unset($id);
                    $format = Tools::substr($format, 0, -4);
                    $format .= '\'], ';
                } else {
                    $format .= $key . ': ' . addslashes($val) . ', ';
                }
            }
            unset($params, $key, $val);

            $format = Tools::substr($format, 0, -2);
            $format .= '}';

            return $format;
        }
        return false;
    }

    public static function getConfig($key, $defValue = null)
    {

        if (version_compare(_PS_VERSION_, '1.7.0.0', '<')) {
            $value = Configuration::get($key);
            return $value === false ? $defValue : $value;
        }

        return Configuration::get($key, null, null, null, $defValue);
    }
}
