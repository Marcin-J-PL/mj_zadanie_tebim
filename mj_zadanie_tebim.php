<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Mj_zadanie_tebim extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'mj_zadanie_tebim';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'MarcinJ';
        $this->need_instance = 1;

        parent::__construct();

        $this->displayName = $this->l('Min koszt dostawy produktu');
        $this->description = $this->l('Moduł do Prestashop, który na karcie produktu wyświetli najniższy możliwy koszt dostawy dla tego produktu');

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => '9.0');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('displayProductAdditionalInfo');
    }

    public function uninstall()
    {

        return parent::uninstall();
    }


    /* Do hooka nie są przekazywane żadne parametry
     * Pobieramy id_product z Tools::getValue
     */
    public function hookDisplayProductAdditionalInfo()
    {
        $res = $this->getProductBestCarierByPrice(Tools::getValue('id_product', 0));
        if ($res === -1) {
            $message = $this->trans('Brak dostępnych przewoźników dla tego produktu', [], 'Modules.Mj_zadanie_tebim.Shop');
        } elseif ($res === 0) {
            $message = $this->trans('Darmowa dostawa dla tego produktu', [], 'Modules.Mj_zadanie_tebim.Shop');
        } else {
            $message = $this->trans('Koszt dostawa od  %s ( %s )',[Tools::displayPrice($res['0']),$res[1]], 'Modules.Mj_zadanie_tebim.Shop');
        }
        $this->smarty->assign([
            'Message' => $message,
        ]);

        return $this->fetch('module:'.$this->name.'/views/templates/hook/display.tpl');

    }

    /** Zwracamy tablicę z dwoma elementami: koszt dostawy i nazwę przewoźnika
     *  Jeżeli nie ma dostępnych przewoźników to zwracamy -1
     *  Jeżeli produkt spełnia warunki darmowej dostawy to zwracamy 0
     * 
     * @param int $id_product
     * @return array|int
     */
    public function getProductBestCarierByPrice(int $id_product)
    {

        if (!$id_product)
            return -1;

        $productObj = new Product($id_product);

        // Jeżeli produkt sepłnia warunki darmowej dostawy to zwracamy 0
        $shippingFreeWeight = Configuration::get('PS_SHIPPING_FREE_WEIGHT');
        $shippingFreePrice = Configuration::get('PS_SHIPPING_FREE_PRICE');
        if (
            ($shippingFreeWeight && $productObj->weight >= $shippingFreePrice)
            || ($shippingFreePrice && $productObj->price >= $shippingFreePrice)
        )
            return 0;

        //Ustalamy id adresu dostawy zalogowanego klienta lub z koszyka
        $id_address_delivery = $this->getIdAddressDelivery();

        $carrier_list = Carrier::getAvailableCarrierList(
            $productObj,
            0,
            $id_address_delivery,
        );

        //Możliwe, że dla danego id_address_delivery nie ma dostępnych przewoźników
        if (empty($carrier_list)) {
            $carrier_list = Carrier::getAvailableCarrierList(
                $productObj,
                0
            );
        }

        if (empty($carrier_list)) {
            return -1;
        }

        $min_cost = null;
        $min_carrierObj = null;

        foreach ($carrier_list as $key => $carrier) {
            $carrierObj = new Carrier($key);
            $shipping_cost = $this->getCarrierShippingCost($carrierObj, $productObj, $id_address_delivery);
            if ($min_cost === null || $shipping_cost < $min_cost) {
                $min_cost = $shipping_cost;
                $min_carrierObj = $carrierObj;
            }
        }

        return array($min_cost, $min_carrierObj->name);

    }

    /** Pobieramy id adresu dostawy z koszyka lub z profilu zalogowanego klienta
     *  Jeżeli nie ma ani jednego ani drugiego to zwracamy null
     *  @return int|null
     */
    private function getIdAddressDelivery()
    {
        $id_address_delivery = null;

        if (Context::getContext()->cart->id_address_delivery) {
            $id_address_delivery = Context::getContext()->cart->id_address_delivery;
        } elseif (Context::getContext()->customer->id) {
            $id_address_delivery = Address::getFirstCustomerAddressId(Context::getContext()->customer->id);
        }

        return $id_address_delivery;
    }

    /** Pobieramy id strefy na podstawie id adresu dostawy
     *  Jeżeli nie ma id adresu dostawy to pobieramy domyślny kraj i jego strefę
     *  @param int $id_address_delivery
     *  @return int
     */
    private function getIdZone(int $id_address_delivery)
    {
        if ($id_address_delivery)
            $id_zone = Address::getZoneById($id_address_delivery);
        else {
            $default_country = new Country(
                (int) Configuration::get('PS_COUNTRY_DEFAULT'),
                (int) Configuration::get('PS_LANG_DEFAULT')
            );

            $id_zone = (int) $default_country->id_zone;
        }

        return $id_zone;
    }

    /** Obliczamy koszt wysyłki dla danego przewoźnika i produktu
     *  Jeżeli $use_tax jest true to doliczamy podatek do kosztu wysyłki
     * @param Carrier $carrier
     * @param Product $productObj
     * @param int $id_address_delivery
     * @param bool $use_tax
     * @return float
     */
    private function getCarrierShippingCost(Carrier $carrier, Product $productObj, int $id_address_delivery, $use_tax = true)
    {
        $productPriceTaxIncl = Product::getPriceStatic($productObj->id, true,Tools::getValue('id_product_attribute', null));
        $shipping_method = $carrier->getShippingMethod();
        $id_zone = $this->getIdZone($id_address_delivery);

        if ($carrier->range_behavior) {
            if ($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                $shipping_cost = $carrier->getDeliveryPriceByWeight($productObj->weight, $id_zone);
            } else {
                $shipping_cost = $carrier->getDeliveryPriceByPrice($productPriceTaxIncl, $id_zone);
            }

        } else {
            if ($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                $shipping_cost = $carrier->getDeliveryPriceByWeight($productObj->weight, $id_zone);
            } else {
                $shipping_cost = $carrier->getDeliveryPriceByPrice($productPriceTaxIncl, $id_zone);
            }
        }

        /* Dodajemy opłatę manipulacyjną jeżeli koszt wysyłki jest większy od 0
         * Jeżeli koszt wysyłki jest równy 0 to znaczy, że jest darmowa dostawa i nie dodajemy opłaty manipulacyjnej
         */
        if ($shipping_cost > 0) {
            $shipping_cost += (float) Configuration::get('PS_SHIPPING_HANDLING');
        }

        if ($use_tax) {
            $address = Address::initialize((int) $id_address_delivery);
            $carrier_tax = $carrier->getTaxesRate($address);
            $shipping_cost *= 1 + ($carrier_tax / 100);
        }

        return $shipping_cost;
    }

}
