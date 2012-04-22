<?php

/**
 * Author: Allan Randall
 * Web: http://nutrinio.com.au
 * Email: allan@nutrino.com.au
 * Created: 2009-11-18
 * Version: 0.1
 *
 * Provides:
 *  Australia Post Shipping
 *
 * Requires:
 *  Hooks for custom shipping modules to be intergrated into Prestashop
 *      (ShippingModule.php)
 *
 */


class AustPostShippingAPI {
    const TYPE_STANDARD = 'STANDARD';
    const TYPE_EXPRESS  = 'EXPRESS';
    const TYPE_AIR      = 'AIR';
    const TYPE_SEA      = 'SEA';
    const TYPE_ECONOMY  = 'ECONOMY';

    const MAX_PACKAGE_SIZE = 20000; // Australia post max package size is 20kg

    private $server_url = 'http://drc.edeliver.com.au/ratecalc.asp';
    private $cache      = Array();

    public function Calculate_Shipping(
        $pickup_postcode,
        $dest_postcode,
        $dest_country,
        $weight,
        $type = self::TYPE_STANDARD,
        $quantity = 1,
        $length = 100,
        $width = 100,
        $height = 100
    ) {

        // TODO: Validate params, trust Prestashop for now...
        $get_string = sprintf(
            'Pickup_Postcode=%s&Destination_Postcode=%s&Country=%s'
                . '&Service_Type=%s&Quantity=%u'
                . '&Height=%u&Length=%u&Width=%u'
                . '&Weight=%u',
            urlencode($pickup_postcode),
            urlencode($dest_postcode),
            urlencode($dest_country),
            urlencode($type),
            urlencode($quantity),
            urlencode($length),
            urlencode($width),
            urlencode($height),
            urlencode($weight)
        );

        /*
         * as this can be called several times with the same params
         * we cache the last request in memory otherwise we query
         * Australia Post lots of times, which I dont think they would 
         * be too hapy about.
         */
        if (in_array($get_string, array_keys($this->cache))) {
            return $this->cache[$get_string];
        }

        // Call eDeliver API (Doc: http://drc.edeliver.com.au/)
        $url = sprintf('%s?%s', $this->server_url, $get_string);
        $api_result = @file_get_contents($url);
        if (!$api_result) {
            $curl_obj = curl_init($url);
            curl_setopt($curl_obj, CURLOPT_RETURNTRANSFER, true);
            $api_result = curl_exec($curl_obj);
            curl_close($curl_obj);
        }

        $result = array();
        foreach(explode("\n", $api_result) as $param) {
            if(!trim($param)) {
                continue;
            }
            $key_value = explode("=", $param);
            $result[$key_value[0]] = trim($key_value[1]);
        }

        $result['err'] = ($result['err_msg'] == 'OK') ? 0 : 1;
        $this->cache[$get_string] = $result;

        return $result;
    }
}
//store austpost api objet as a global so that caching will work.
global $austpost_api;
$austpost_api = new AustPostShippingAPI();

class ShippingAustPost extends ShippingModule {
    public $name    = 'shippingaustpost';
    public $tab     = 'Shipping';
    public $version = '0.1';

    private $template_settings = 'shippingaustpost.tpl';
    private $weight_adjustment = 1;

    public function __construct() {

        parent::__construct();

        $this->name_type_array = array(
            AustPostShippingAPI::TYPE_STANDARD => array(
                'name'        => 'Australia Post (Standard)',
                'delay'       => 'Approx 2-5 days',
                'tax'         => 10,
                'iso_include' => 'AU',
            ),
            AustPostShippingAPI::TYPE_EXPRESS => array(
                'name'        => 'Australia Post (Express)',
                'delay'       => 'Next Business Day',
                'tax'         => 10,
                'iso_include' => 'AU',
            ),
            AustPostShippingAPI::TYPE_AIR => array(
                'name'        => 'Australia Post (Air)',
                'delay'       => 'Overseas Shipping',
                'tax'         => 0,
                'iso_exclude' => 'AU',
            ),
            AustPostShippingAPI::TYPE_SEA => array(
                'name'        => 'Australia Post (Sea)',
                'delay'       => 'Overseas Shipping',
                'tax'         => 0,
                'iso_exclude' => 'AU',
            ),

            /* Economy Air Parcel Post service is no longer available, please use other service type.
            AustPostShippingAPI::TYPE_ECONOMY => array(
                'name'   => 'Australia Post (Economy)',
                'delay'  => 'Overseas Shipping.',
                'tax'    => 0,
            ),
            */
        );

        $weight_units = strtolower(Configuration::get('PS_WEIGHT_UNIT'));
        switch($weight_units){
            case "kg":
            case "k.g.":
            case "kilo":
            case "kilos":
            case "kilograms":
                $this->weight_adjustment = 1000;
                break;
            case "grams":
            case "grms":
            case "g":
            default:
                $this->weight_adjustment = 1;
                break;
        }

        foreach (array_keys($this->name_type_array) as $type) {
            $this->name_type_array[$type]['type'] = $type;
            $this->name_type_array[$type]['id_carrier'] =
                Configuration::get('PS_AUST_POST_CARRIER_' . $type);
        }

        $this->default_weight = Configuration::get('PS_AUST_POST_WEIGHT_DEFAULT');
        $this->packing_weight = Configuration::get('PS_AUST_POST_PACK_WEIGHT');
        $this->packing_weight_percent = Configuration::get('PS_AUST_POST_PACK_PERCENT');
        $this->packing_multi = Configuration::get('PS_AUST_POST_PACK_MULTIPLE');

        $this->pickup_postcode = Configuration::get('PS_SHOP_CODE');

        $this->page = basename(__FILE__, '.php');
        $this->displayName = $this->l('Australia Post Shipping');
        $this->description = $this->l('Calculates the shipping price of an item through Australia Post based on weight and destination.');
        $this->confirmUninstall = $this->l('Are you sure you want to delete Australia Post Shipping? This will also delete carriers defined by this module.');
    }

    public function install() {
        Configuration::updateValue('PS_AUST_POST_WEIGHT_DEFAULT',  500);
        Configuration::updateValue('PS_AUST_POST_PACK_WEIGHT',       0);
        Configuration::updateValue('PS_AUST_POST_PACK_PERCENT',     .1);
        Configuration::updateValue('PS_AUST_POST_PACK_MULTIPLE', FALSE);

        parent::install(FALSE);

        // Default to all enabled
        foreach (array_keys($this->name_type_array) as $type) {
            $this->installService($type);
        }

        return $this->registerHook('calculateShipping');
    }

    public function installService($type) {
        if ($this->name_type_array[$type]['id_carrier']) {
            // already installed
            return true;
        }

        $carrier_id = $this->addCarrier(
            $this->name_type_array[$type]['name'],
            Array(
                'delay'       => $this->name_type_array[$type]['delay'],
                'tax_rate'    => $this->name_type_array[$type]['tax'],
                'iso_include' => @$this->name_type_array[$type]['iso_include'],
                'iso_exclude' => @$this->name_type_array[$type]['iso_exclude'],
            )
        );

        Configuration::updateValue('PS_AUST_POST_CARRIER_' . $type, $carrier_id);
        $this->name_type_array[$type]['id_carrier'] = $carrier_id;
    }

    public function hookUpdateCarrier($params) {
        foreach (array_keys($this->name_type_array) as $type) {
            if (Configuration::get('PS_AUST_POST_CARRIER_' . $type) == $params['id_carrier']) {
                $this->name_type_array[$type]['id_carrier'] = $params['carrier']->id;
                Configuration::updateValue('PS_AUST_POST_CARRIER_' . $type, $params['carrier']->id);
            }
        }
        return parent::hookUpdateCarrier($params);
    }

    public function deleteService($type) {
        $id_carrier = $this->name_type_array[$type]['id_carrier'];
        if ($id_carrier) {
            $this->deleteCarrier($id_carrier);

            Configuration::updateValue('PS_AUST_POST_CARRIER_' . $type, 0);
            $this->name_type_array[$type]['id_carrier'] = 0;
        }
    }

    public function getContent() {
        global $smarty, $cookie;

        if(isset($_POST['btnUpdate'])) {
            Configuration::updateValue('PS_AUST_POST_WEIGHT_DEFAULT', $_POST['default_weight']);
            $this->default_weight = $_POST['default_weight'];
            Configuration::updateValue('PS_AUST_POST_PACK_WEIGHT',    $_POST['packing_weight']);
            $this->packing_weight = $_POST['packing_weight'];
            Configuration::updateValue('PS_AUST_POST_PACK_PERCENT',   $_POST['packing_weight_percent']/100);
            $this->packing_weight_percent = $_POST['packing_weight_percent']/100;
            Configuration::updateValue('PS_AUST_POST_PACK_MULTIPLE',   isset($_POST['multi']));
            $this->packing_multi = isset($_POST['multi']);

            foreach (array_keys($this->name_type_array) as $type) {
                if (in_array($type, $_POST['services'])) {
                    $this->installService($type);
                } else {
                    $this->deleteService($type);
                }
            }
        }

        $smarty->assign('form_post', $_SERVER['REQUEST_URI']);

        $smarty->assign('default_weight', $this->default_weight);
        $smarty->assign('packing_weight', $this->packing_weight);
        $smarty->assign('packing_multi', $this->packing_multi);
        $smarty->assign('max_kgs', AustPostShippingAPI::MAX_PACKAGE_SIZE / 1000);
        $smarty->assign('packing_weight_percent', $this->packing_weight_percent * 100);
        $smarty->assign('service_types', $this->name_type_array);
        return $this->display(__FILE__, $this->template_settings);
    }

    public function uninstall() {
        Configuration::deleteByName('PS_AUST_POST_WEIGHT_DEFAULT');
        Configuration::deleteByName('PS_AUST_POST_PACK_WEIGHT');
        Configuration::deleteByName('PS_AUST_POST_PACK_PERCENT');

        Configuration::deleteByName('PS_AUST_POST_CARRIER_STANDARD');
        Configuration::deleteByName('PS_AUST_POST_CARRIER_EXPRESS');
        Configuration::deleteByName('PS_AUST_POST_CARRIER_AIR');
        Configuration::deleteByName('PS_AUST_POST_CARRIER_SEA');
        return parent::uninstall();
    }

    private function calculateProductWeight() {
        global $cart;
        $total_weight = 0;
        // get all products weight
        foreach ($cart->getProducts() as $product) {
            $product_weight = $product['weight_attribute'] * $this->weight_adjustment;

            $total_weight +=
                $product['cart_quantity']
                * (empty($product_weight) ? $this->default_weight : $product_weight);
            // Maximum package size is 20kg if a single product is over this then
            // this module can not be used.
            if (
                ($product_weight)
                * ( 1 + $this->packing_weight_percent )
                    + $this->packing_weight
                > AustPostShippingAPI::MAX_PACKAGE_SIZE
            ) {
                return false;
            }
        }
        return $total_weight;
    }

    public function calculatePackageWeight($product_weight) {
        // calculate package weight
        $package_weight = $product_weight * $this->packing_weight_percent;

        $packages = 1;
        if ($this->packing_multi) {
            //estimate number of packages...
            $packages = ceil(
                ($product_weight + $package_weight)
                / (AustPostShippingAPI::MAX_PACKAGE_SIZE)
            );
        }

        return $package_weight + ($packages * $this->packing_weight);
    }

    public function hookCalculateShipping($params) {
        global $cart, $austpost_api;

        if (empty($cart)) {
            return false;
        }

        $type = NULL;

        // This module has many carriers so we need to find out which one to use...
        foreach($this->name_type_array as $service => $vars) {
            if ($vars['id_carrier'] == $params['id_carrier']) {
                $type = $service;
            }
        }

        // calculate weights of products and packaging
        if (!($products_weight = $this->calculateProductWeight())) {
            return false;
        }

        $package_weight = $this->calculatePackageWeight($products_weight);

        // check we haven't exceded Australia Posts maximum weight limit.
        if (
            !$this->packing_multi
            AND ($package_weight + $products_weight) > AustPostShippingAPI::MAX_PACKAGE_SIZE
        ) {
            return false;
        }

        //populate delivery address vars
        $delivery_addr = new Address(intval($cart->id_address_delivery));
        $delivery_country = new Country($delivery_addr->id_country);

        $remaining_weight = $package_weight + $products_weight;

        $cost = 0;
        while ($remaining_weight > 0) {
            if ($remaining_weight > AustPostShippingAPI::MAX_PACKAGE_SIZE) {
                $result = $austpost_api->Calculate_Shipping(
                    $this->pickup_postcode,
                    $delivery_addr->postcode,
                    $delivery_country->iso_code,
                    AustPostShippingAPI::MAX_PACKAGE_SIZE,
                    $type
                );
                $remaining_weight -= AustPostShippingAPI::MAX_PACKAGE_SIZE;
            } else {
                $result = $austpost_api->Calculate_Shipping(
                    $this->pickup_postcode,
                    $delivery_addr->postcode,
                    $delivery_country->iso_code,
                    $remaining_weight,
                    $type
                );
                $remaining_weight = 0;
            }

            if ($result['err']) {
                return false;
            }
            $cost += $result['charge'];
        }

        //remove GST if applied as prestashop adds it
        $cost = $cost / (1 + ($this->name_type_array[$type]['tax'] / 100));

        return $cost;
    }
}

?>
