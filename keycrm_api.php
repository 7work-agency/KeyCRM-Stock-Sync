<?php
/**
 * KeyCRM API Integration Class
 * 
 * This class handles all API communication with the KeyCRM service
 * for retrieving stock data. It provides a secure and reliable way
 * to fetch product stock information from KeyCRM.
 * 
 * Features:
 * - Secure API authentication
 * - Error handling and logging
 * - HTTP request management
 * - JSON response parsing
 * 
 * @package KeyCrmStock
 * @version 1.0.0
 */

class KeyCrmApi {
    private $apiBaseUrl = 'https://openapi.keycrm.app/v1';
    private $lastRequestTime = 0;
    private $requestsInMinute = 0;
    private $perPage = 50;

    /**
     * Retrieves stock data from KeyCRM API
     * 
     * Makes an HTTP request to the KeyCRM API to fetch stock information
     * for active offers. Handles authentication, request parameters,
     * and error scenarios. Supports pagination.
     * 
     * @return array|false Returns array of stock data on success, false on failure
     */
    public function getStockData() {
        $formatted_data = ['offers' => []];
        $page = 1;

        try {
            $api_key = Configuration::get('KEYCRM_API_KEY');
            if (empty($api_key)) {
                Logger::addLog('[KeyCrmStock] KeyCRM API key is not configured', 3);
                return false;
            }

            $next_page_url = $this->apiBaseUrl.'/offers/stocks?'.http_build_query([
                'filter[status]' => 'active',
                'page' => $page,
                'perPage' => $this->perPage
            ]);

            while ($next_page_url) {
                if (!$this->canMakeRequest()) {
                    Logger::addLog('[KeyCrmStock] KeyCRM API rate limit reached (60 requests per minute)', 3);
                    return false;
                }
                
                $ch = curl_init($next_page_url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Authorization: Bearer '.$api_key,
                    'Accept: application/json'
                ));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                
                $result = curl_exec($ch);
                
                if (curl_errno($ch)) {
                    Logger::addLog('[KeyCrmStock] KeyCRM CURL error: '.curl_error($ch), 3);
                    curl_close($ch);
                    return false;
                }
                
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_code !== 200) {
                    Logger::addLog("[KeyCrmStock] KeyCRM: HTTP error $http_code", 3);
                    return false;
                }

                $data = json_decode($result, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Logger::addLog('[KeyCrmStock] KeyCRM: Invalid JSON response. Error: '.json_last_error_msg(), 3);
                    return false;
                }

                if (!isset($data['data']) || !is_array($data['data'])) {
                    Logger::addLog('[KeyCrmStock] KeyCRM: Unexpected response format - missing data array.', 3);
                    return false;
                }

                foreach ($data['data'] as $item) {
                    if (!isset($item['sku']) || !isset($item['quantity'])) {
                        Logger::addLog('[KeyCrmStock] KeyCRM: Invalid item format in response - missing required fields.', 3);
                        continue;
                    }
                    
                    $formatted_data['offers'][] = [
                        'sku' => $item['sku'],
                        'price' => $item['price'],
                        'quantity' => (int)$item['quantity'],
                        'reserve'  => (int)$item['reserve'],
                    ];
                }

                $next_page_url = $data['next_page_url'];
                $this->requestsInMinute++;
            }

            if (empty($formatted_data['offers'])) {
                Logger::addLog('[KeyCrmStock] KeyCRM: No valid stock data found in response', 3);
                return false;
            }

            return $formatted_data;
        } catch (Exception $e) {
            Logger::addLog('[KeyCrmStock] KeyCRM Error: '.$e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Check if we can make a new request according to rate limits
     * 
     * @return bool True if we can make a request, false if we need to wait
     */
    private function canMakeRequest() {
        $current_time = time();
        if ($current_time - $this->lastRequestTime >= 60) {
            $this->requestsInMinute = 0;
            $this->lastRequestTime = $current_time;
            return true;
        }
        
        if ($this->requestsInMinute >= 60) {
            return false;
        }
        
        return true;
    }
}