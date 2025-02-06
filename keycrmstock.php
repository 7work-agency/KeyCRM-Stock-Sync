<?php
if (!defined('_PS_VERSION_')) exit;

/**
 * KeyCRM Stock Synchronization Module for PrestaShop
 * 
 * This module provides integration between PrestaShop and KeyCRM
 * for synchronizing product stock levels. It allows automatic
 * stock updates through a secure API connection.
 * 
 * Features:
 * - Automatic stock synchronization via cron
 * - Secure API integration
 * - Configuration through PrestaShop admin panel
 * - Support for both simple and combination products
 * 
 * @package KeyCrmStock
 * @author 7work.agency
 * @version 1.0.0
 */
class KeyCrmStock extends Module {
    /**
     * Class constructor
     * 
     * Initializes module basic information and localizable properties.
     * Sets up module name, version, and other essential parameters.
     */
    public function __construct() {
        $this->name = 'keycrmstock';
        $this->tab = 'export';
        $this->version = '1.0.0';
        $this->author = '7work.agency';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.6.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('KeyCRM Stock Sync');
        $this->description = $this->l('Stock synchronization with KeyCRM');
    }

    /**
     * Module installation method
     * 
     * Performs the following tasks during installation:
     * - Checks PrestaShop version compatibility
     * - Creates necessary configuration values
     * - Generates a secure key for cron jobs
     * - Registers necessary hooks
     * 
     * @return bool Success status of installation
     */
    public function install() {
        if (version_compare(_PS_VERSION_, '1.6.0', '<')) {
            $this->_errors[] = $this->l('This module requires Prestashop version 1.6 or newer');
            return false;
        }
        
        $secure_key = Tools::encrypt($this->name.microtime());
        
        // Install default configuration
        if (!parent::install() 
            || !Configuration::updateValue('KEYCRM_API_KEY', '')
            || !Configuration::updateValue('KEYCRM_CRON_KEY', $secure_key)
            || !$this->registerHook('actionAdminControllerSetMedia')
            || !$this->registerHook('displayBackOfficeHeader')) {
            return false;
        }

        return true;
    }

    /**
     * Module uninstallation method
     * 
     * Removes all module-related configuration values from the database
     * ensuring clean uninstallation of the module.
     * 
     * @return bool Success status of uninstallation
     */
    public function uninstall() {
        return parent::uninstall() 
            && Configuration::deleteByName('KEYCRM_API_KEY')
            && Configuration::deleteByName('KEYCRM_CRON_KEY');
    }

    /**
     * Hook for adding CSS/JS to admin panel
     */
    public function hookActionAdminControllerSetMedia() {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addCSS($this->_path.'views/css/admin.css');
        }
    }

    /**
     * Hook for back office header
     */
    public function hookDisplayBackOfficeHeader() {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/admin.js');
        }
    }

    /**
     * Generates and handles the module's configuration page
     * 
     * Creates the admin interface for module configuration including:
     * - API credentials input form
     * - Cron job setup instructions
     * - Configuration save handling
     * 
     * @return string HTML content of the configuration page
     */
    public function getContent() {
        $output = '';
        
        // Add CSRF token validation
        if (Tools::isSubmit('submit'.$this->name)) {
            if (!Tools::isSubmit('token') || !Tools::getAdminToken(Tools::getValue('token'))) {
                $output .= $this->displayError($this->l('Invalid security token'));
                return $output.$this->renderForm();
            }
            
            $api_key = Tools::getValue('KEYCRM_API_KEY');
            
            // Validate inputs
            if (empty($api_key)) {
                $output .= $this->displayError($this->l('API Key is required'));
            } else {
                Configuration::updateValue('KEYCRM_API_KEY', $api_key);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        
        // Display cron information using template
        $cron_url = Tools::getHttpHost(true).__PS_BASE_URI__.'modules/'.$this->name.'/sync.php?secure_key='.Configuration::get('KEYCRM_CRON_KEY');
        $this->context->smarty->assign(array(
            'cron_url' => $cron_url,
            'cron_settings' => $this->l('Cron settings', 'keycrmstock'),
            'cron_instructions' => $this->l('For automatic stock synchronization, add to cron:', 'keycrmstock')
        ));
        
        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/cron_info.tpl');
        
        return $output.$this->renderForm();
    }

    /**
     * Generates the configuration form
     * 
     * Creates a form with fields for KeyCRM API settings using PrestaShop's
     * HelperForm class. Includes fields for:
     * - API Key
     * 
     * @return string HTML markup of the configuration form
     */
    public function renderForm() {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('KeyCRM Settings'),
                    'icon' => 'icon-cogs'
                ),
                'description' => $this->l('Instructions for getting API key: ') .
                    '<a href="https://help.keycrm.app/uk/process-automation-api-and-more/where-to-get-an-api-key" target="_blank">' .
                    '<b>'.$this->l('Click here to learn how to get your API key') .'</b>'.
                    '</a>',
                'input' => array(
                    array(
                        'type' => 'password',
                        'label' => $this->l('API Key'),
                        'name' => 'KEYCRM_API_KEY',
                        'required' => true,
                        'class' => 'fixed-width-xxl',
                        'desc' => Configuration::get('KEYCRM_API_KEY') 
                            ? '<p class="help-block">'.$this->l('API Key saved').'</p>' 
                            : $this->l('Enter your KeyCRM API Key'),
                        'hint' => $this->l('You can find your API key in KeyCRM settings')
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit'.$this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        // Add CSRF token to form
        $helper->tpl_vars = array(
            'fields_value' => array(
                'KEYCRM_API_KEY' => Configuration::get('KEYCRM_API_KEY'),
                'token' => Tools::getAdminTokenLite('AdminModules')
            ),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }
}
