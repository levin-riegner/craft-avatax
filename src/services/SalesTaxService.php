<?php
/**
 * Avatax plugin for Craft CMS 3.x
 *
 * Calculate and add sales tax to an order's base tax using Avalara's AvaTax service.
 *
 * @link      http://surprisehighway.com
 * @copyright Copyright (c) 2019 Surprise Highway
 */

namespace surprisehighway\avatax\services;

use surprisehighway\avatax\Avatax;
use Avalara\AvaTaxClient;

use Craft;
use craft\base\Component;

use craft\commerce\models\Address;
use craft\commerce\models\Transaction;
use craft\commerce\elements\Order;

use yii\base\Exception;
use yii\log\Logger;

/**
 * @author    Surprise Highway
 * @package   Avatax
 * @since     2.0.0
 */
class SalesTaxService extends Component
{

    // Public Properties
    // =========================================================================

    /**
     * @var settings
     */
    public $settings;

    /**
     * @var boolean
     */
    public $debug = false;

    /**
     * @var string The type of commit (order or invoice)
     */
    public $type;


    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $settings = Avatax::$plugin->getSettings();

        $this->settings = $settings;
        $this->debug = $settings->debug;
    }

    /**
     * @param array $settings
     * @return object or boolean
     *
     *  From any other plugin file, call it like this:
     *  Avatax::getInstance()->SalesTaxService->connectionTest()
     *
     * Creates a new client with the given settings and tests the connection.
     * See https://developer.avalara.com/api-reference/avatax/rest/v2/methods/Utilities/Ping/
     *
     */
    public function connectionTest($settings)
    {
        $client = $this->createClient($settings);

        return $client->ping();
    }

    /**
     * @param object Order $order
     * @return object
     *
     *  From any other plugin file, call it like this:
     *  Avatax::getInstance()->SalesTaxService->createSalesOrder()
     *
     * Creates a new sales order - a temporary transaction to determine the tax rate.
     * See "Sales Orders vs Sales Invoices" https://developer.avatax.com/blog/2016/11/04/estimating-tax-with-rest-v2/
     * See also: https://developer.avatax.com/avatax/use-cases/
     *
     */
    public function createSalesOrder(Order $order)
    {
        if(!$this->settings->enableTaxCalculation)
        {
            Avatax::info(__FUNCTION__.'(): Tax Calculation is disabled.');

            return false;
        }

        $this->type = 'order';

        $client = $this->createClient();

        $tb = new \Avalara\TransactionBuilder(
            $client, $this->getCompanyCode(), 
            \Avalara\DocumentType::C_SALESORDER, 
            $this->getCustomerCode($order)
        );

        $totalTax = $this->getTotalTax($order, $tb);

        return $totalTax;
    }

    /**
     * @param object Order $order
     * @return object
     *
     *  From any other plugin file, call it like this:
     *  Avatax::getInstance()->SalesTaxService->createSalesInvoice()
     *
     * Creates and commits a new sales invoice
     * See "Sales Orders vs Sales Invoices" https://developer.avatax.com/blog/2016/11/04/estimating-tax-with-rest-v2/
     * See also: https://developer.avatax.com/avatax/use-cases/
     *
     */
    public function createSalesInvoice(Order $order)
    {
        if(!$this->settings->enableCommitting)
        {

            Avatax::info(__FUNCTION__.'(): Document Committing is disabled.');

            return false;
        }

        $this->type = 'invoice';

        $client = $this->createClient();

        $tb = new \Avalara\TransactionBuilder(
            $client, $this->getCompanyCode(), 
            \Avalara\DocumentType::C_SALESINVOICE, 
            $this->getCustomerCode($order)
        );

        $tb->withCommit();

        $totalTax = $this->getTotalTax($order, $tb);

        return $totalTax;
    }

    /**
     * @param float $amount amount of the refund
     * @param object Transaction $transaction
     * @return object
     *
     * Refund a committed sales invoice
     * See "Refund Transaction" https://developer.avalara.com/api-reference/avatax/rest/v2/methods/Transactions/RefundTransaction
     *
     */
    public function refundTransaction($amount, Transaction $transaction)
    {
        $client = $this->createClient();

        $request = array(
            'companyCode' => $this->getCompanyCode(),
            'transactionCode' => $this->getTransactionCode($transaction->order)
        );

        $model = array(
            'refundTransactionCode' => $request['transactionCode'].'.1',
            'refundType' => \Avalara\RefundType::C_FULL,
            'refundDate' => date('Y-m-d'),
            'referenceCode' => 'Refund from Craft Commerce'
        );

        extract($request);

        if($amount !== $transaction->paymentAmount || $transaction->refundableAmount !== $transaction->paymentAmount) {
            Avatax::error('Transaction Code '.$transactionCode.' could not be refunded. Only one full refund per transaction is currently supported.');
            return;
        }

        $response = $client->refundTransaction(
            $companyCode, 
            $transactionCode, 
            null, 
            null, 
            'true', 
            $model
        );

        if($this->debug)
        {
            $request = array_merge($request, $model);

            Avatax::info('\Avalara\Client->refundTransaction(): ', ['request' =>json_encode($request), 'response' => json_encode($response)]);
        }

        if(isset($response->status) && $response->status === 'Committed')
        {
            Avatax::info('Transaction Code '.$transactionCode.' was successfully refunded.');

            return true;
        }

        Avatax::error('Transaction Code '.$transactionCode.' could not be refunded.');

        return false;
    }

    /**
     * @param object $address Address model craft\commerce\models\Address
     * @return object
     *
     *  From any other plugin file, call it like this:
     *  Avatax::getInstance()->SalesTaxService->validateAddress()
     *
     * Validates and address
     * See: https://developer.avalara.com/api-reference/avatax/rest/v2/methods/Addresses/ResolveAddressPost/
     *
     */
    public function validateAddress($address)
    {
        if(!$this->settings['enableAddressValidation'])
        {
            Avatax::info(__FUNCTION__.'(): Address validation is disabled.');

            return false;
        }

        $signature = $this->getAddressSignature($address);
        $cacheKey = 'avatax-address-'.$signature;
        $cache = Craft::$app->getCache();

        // Check if validated address has been cached, if not make api call.
        $response = $cache->get($cacheKey);
        //if($response) Avatax::info('Cached address found: '.$cacheKey);

        if(!$response) 
        {
            $request = array(
                'line1' => $address->address1,
                'line2' => $address->address2,
                'line3' => '', 
                'city' => $address->city,
                'region' => $address->getState() ? $address->getState() ? $address->getState()->abbreviation : $address->getStateText() : '', 
                'postalCode' => $address->zipCode,
                'country' => $address->country->iso, 
                'textCase' => 'Mixed',
                'latitude' => '',
                'longitude' => ''
            );

            extract($request);

            $client = $this->createClient();

            $response = $client->resolveAddress($line1, $line2, $line3, $city, $region, $postalCode, $country, $textCase, $latitude, $longitude);

            $cache->set($cacheKey, $response);

            Avatax::info('\Avalara\AvaTaxClient->resolveAddress():', ['request' => json_encode($request), 'response' => json_encode($response)]);
        }

        if(isset($response->validatedAddresses) || isset($response->coordinates))
        {
            return true;
        }

        Avatax::error('Address validation failed.');

        // Request failed
        throw new Exception('Invalid address.');

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * @return string $companyCode
     */
    private function getCompanyCode()
    {
        if($this->settings['environment'] === 'production')
        {
            $companyCode = $this->settings['companyCode'];
        }

        if($this->settings['environment'] === 'sandbox')
        {
            $companyCode = $this->settings['sandboxCompanyCode'];
        }

        return $companyCode;
    }

    /**
     * @return string $customerCode
     */
    private function getCustomerCode($order)
    {
        return (!empty($order->email)) ? $order->email : 'GUEST';
    }

    /**
     * @return string $transactionCode
     *
     * Use the prefixed order number as the document code so that
     * we can reference it again for subsequent calls if needed.
     */
    private function getTransactionCode($order)
    {
        $prefix = 'cr_';

        return $prefix.$order->number;
    }

    /**
     * @return object $client
     */
    private function createClient($settings = null)
    {
        $settings = ($settings) ? $settings : $this->settings;

        $pluginName = 'Craft Commerce '.Avatax::$plugin->name;
        $pluginVersion = Avatax::$plugin->version;
        $machineName = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : 'localhost';

        if($settings['environment'] === 'production')
        {
            if(!empty($settings['accountId']) && !empty($settings['licenseKey']))
            {
                // Create a new client
                $client = new AvaTaxClient($pluginName, $pluginVersion, $machineName, 'production');

                $client->withLicenseKey( $settings['accountId'], $settings['licenseKey'] );

                return $client;
            }
        }

        if($settings['environment'] === 'sandbox')
        {
            if(!empty($settings['sandboxAccountId']) && !empty($settings['sandboxLicenseKey']))
            {
                // Create a new client
                $client = new AvaTaxClient($pluginName, $pluginVersion, $machineName, 'sandbox');

                $client->withLicenseKey( $settings['sandboxAccountId'], $settings['sandboxLicenseKey'] );

                return $client;
            }
        }

        // Don't have credentials
        Avatax::error('Avatax Account Credentials not found');

        // throw a craft exception which returns the error
        throw new Exception('Avatax Account Credentials not found');
    }

    /**
     * @param object Order $order
     * @param object Avatax\TransactionBuilder $transaction
     * @return object
     *
     */
    private function getTotalTax($order, $transaction)
    {
        if($this->settings['enableAddressValidation'])
        {
            // Make sure we have a valid address before continuing.
            $this->validateAddress($order->shippingAddress);
        }

        $defaultTaxCode = $this->settings['defaultTaxCode'];
        $defaultShippingCode = $this->settings['defaultShippingCode'];
        $defaultDiscountCode = $this->settings['defaultDiscountCode'];

        $t = $transaction->withTransactionCode(
                $this->getTransactionCode($order)
            )
            ->withAddress(
                'shipFrom',
                $this->settings['shipFromStreet1'],
                $this->settings['shipFromStreet2'],
                $this->settings['shipFromStreet3'],
                $this->settings['shipFromCity'],
                $this->settings['shipFromState'],
                $this->settings['shipFromZipCode'],
                $this->settings['shipFromCountry']
            )
            ->withAddress(
                'shipTo',
                $order->shippingAddress->address1,
                NULL,
                NULL,
                $order->shippingAddress->city,
                $order->shippingAddress->getState() ? $order->shippingAddress->getState() ? $order->shippingAddress->getState()->abbreviation : $order->shippingAddress->getStateText() : '',
                $order->shippingAddress->zipCode,
                $order->shippingAddress->getCountry()->iso
            );

        // Add each line item to the transaction
        foreach ($order->lineItems as $lineItem) {
            
            // Our product has the avatax tax category specified
            if($lineItem->taxCategory->handle === 'avatax'){

                $taxCode = $defaultTaxCode ?: 'P0000000';

                if(isset($lineItem->purchasable->product->avataxTaxCode)) {
                    $taxCode = $lineItem->purchasable->product->avataxTaxCode ?: $defaultTaxCode;
                }

                $itemCode = $lineItem->id;

                if(!empty($lineItem->sku)) {
                    $itemCode = $lineItem->sku;
                }

               // amount, $quantity, $itemCode, $taxCode)
               $t = $t->withLine(
                    $lineItem->subtotal,    // Total amount for the line item
                    $lineItem->qty,         // Quantity
                    $itemCode,              // Item Code
                    $taxCode                // Tax Code - Default or Custom Tax Code.
                );

               // add human-readable description to line item
               $t = $t->withLineDescription($lineItem->purchasable->product->title);
           }
        }

        // Add each discount line item
        $discountCode = $defaultDiscountCode ?: 'OD010000'; // Discounts/retailer coupons associated w/taxable items only

        foreach ($order->adjustments as $adjustment) {
            
            if($adjustment->type === 'discount') {
                $t = $t->withLine(
                    $adjustment->amount, // Total amount for the line item
                    1,                   // quantity
                    $adjustment->name,   // Item Code
                    $discountCode        // Tax Code
                );

                // add description to discount line item
                $t = $t->withLineDescription($adjustment->description);
            }
        }

        // Add shipping cost as line-item
        $shippingTaxCode = $defaultShippingCode ?: 'FR';

        $t = $t->withLine(
            $order->totalShippingCost,  // total amount for the line item
            1,                          // quantity
            "FREIGHT",                  // Item Code
            $shippingTaxCode            // Tax code for freight (Shipping)
        );

        // add description to shipping line item
        $t = $t->withLineDescription('Total Shipping Cost');

        // add entity/use code if set for a logged-in User
        if(!is_null($order->customer->user))
        {
            if(isset($order->customer->user->avataxCustomerUsageType) 
            && !empty($order->customer->user->avataxCustomerUsageType->value))
            {
                $t = $t->withEntityUseCode($order->customer->user->avataxCustomerUsageType->value);
            }
        }

        if($this->debug)
        {
            // workaround to save the model as array for debug logging
            $m = $t; $model = $m->createAdjustmentRequest(null, null)['newTransaction'];
        }

        $signature = $this->getOrderSignature($order);
        $cacheKey = 'avatax-'.$this->type.'-'.$signature;
        $cache = Craft::$app->getCache();

        // Check if tax request has been cached when not committing, if not make api call.
        $response = $cache->get($cacheKey);
        //if($response) Avatax::info('Cached order found: '.$cacheKey); 

        if(!$response || $this->type === 'invoice') 
        {
            $response = $t->create();

            $cache->set($cacheKey, $response);

            if($this->debug)
            {
                Avatax::info('\Avalara\TransactionBuilder->create() '.$this->type.':', ['request' => json_encode($model), 'response' => json_encode($response)]);
            }
        }

        if(isset($response->totalTax))
        {
            return $response->totalTax;
        }

        Avatax::error('Request to avatax.com failed');

        // Request failed
        throw new Exception('Request could not be completed');

        return false;
    }

    /**
     * Returns a hash derived from the order's properties.
     */
    private function getOrderSignature(Order $order)
    {
        $orderNumber = $order->number;
        $shipping = $order->totalShippingCost;
        $discount = $order->totalDiscount;
        $tax = $order->totalTax;
        $total = $order->totalPrice;

        $address1 = $order->shippingAddress->address1;
        $address2 = $order->shippingAddress->address2;
        $city = $order->shippingAddress->city;
        $zipCode = $order->shippingAddress->zipCode;
        $country = $order->shippingAddress->country->iso;
        $address = $address1.$address2.$city.$zipCode.$country;

        $lineItems = '';
        foreach ($order->lineItems as $lineItem)
        {
            $itemCode = $lineItem->id;
            $subtotal = $lineItem->subtotal;
            $qty = $lineItem->qty;
            $lineItems .= $itemCode.$subtotal.$qty;
        }

        return md5($orderNumber.$shipping.$discount.$tax.$total.$lineItems.$address); 
    }

    /**
     * Returns a hash derived from the address.
     */
    private function getAddressSignature(Address $address)
    {
        $address1 = $address->address1;
        $address2 = $address->address2;
        $city = $address->city;
        $zipCode = $address->zipCode;
        $country = $address->country->iso;

        return md5($address1.$address2.$city.$zipCode.$country); 
    }
}