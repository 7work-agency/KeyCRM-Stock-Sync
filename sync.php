<?php
/**
 * KeyCRM Stock Synchronization Script
 *
 * This script handles the synchronization of product stock levels between
 * PrestaShop and KeyCRM. It is designed to be run via cron job.
 *
 * Process flow:
 * 1. Validates module status and security key
 * 2. Fetches stock data from KeyCRM API
 * 3. Updates PrestaShop product quantities
 *
 * @author 7work.agency
 * @version 1.0.0
 */

// Include required PrestaShop core files and module dependencies
require_once dirname(__FILE__).'/../../config/config.inc.php';
require_once dirname(__FILE__).'/../../init.php';
require_once _PS_MODULE_DIR_.'keycrmstock/keycrm_api.php';

// Verify that the module is active in PrestaShop
if (!Module::isEnabled('keycrmstock')) {
    die('Module is disabled');
}

// Validate the security key to prevent unauthorized access
$secure_key = Tools::getValue('secure_key');
if ($secure_key != Configuration::get('KEYCRM_CRON_KEY')) {
    die('Invalid security key');
}

try {
    // Instantiate KeyCrmApi class
    $keyCrmApi = new KeyCrmApi();
    // Fetch current stock levels from KeyCRM API
    $stockData = $keyCrmApi->getStockData();
    if (!$stockData || !isset($stockData['offers'])) {
        die('Error: invalid data format from KeyCRM');
    }

    // Counter for tracking number of updated products
    $updated = 0;

    // Process each offer and update corresponding PrestaShop product stock
    foreach ($stockData['offers'] as $offer) {
        // Skip offers without required data
        if (!isset($offer['sku'], $offer['quantity'])) {
            continue;
        }

        // Search for product by SKU in both product and product_attribute tables
        $product_id = Db::getInstance()->getValue(
            'SELECT id_product FROM '._DB_PREFIX_.'product_attribute 
            WHERE reference = "'.pSQL($offer['sku']).'"
            UNION
            SELECT id_product FROM '._DB_PREFIX_.'product 
            WHERE reference = "'.pSQL($offer['sku']).'"'
        );

        // Calculate real available quantity
        $quantity = (int)$offer['quantity'] - (int)$offer['reserved'];

        // Update stock quantity if product is found
        if ($product_id) {
            StockAvailable::setQuantity((int)$product_id, 0, (int)$quantity);
            $updated++;
        }
    }
    
    // Output success message with timestamp and update count
    echo sprintf('Synchronization completed successfully: %s. Updated products: %d', date('Y-m-d H:i:s'), $updated);
} catch (Exception $e) {
    // Handle any unexpected errors during synchronization
    die('Synchronization error: '.$e->getMessage());
}
