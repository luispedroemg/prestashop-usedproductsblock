<?php
if (!defined('_PS_VERSION_'))
	exit;

if (!class_exists('BlockUsedProducts')) {
    class BlockUsedProducts extends Module
    {
        protected static $cache_used_products;

        public function __construct()
        {
            $this->name = 'blockusedproducts';
            $this->tab = 'front_office_features';
            $this->version = '1.10.2';
            $this->author = 'Luís Gonçalves';
            $this->need_instance = 0;

            $this->bootstrap = true;
            parent::__construct();

            $this->displayName = $this->l('Used products block');
            $this->description = $this->l('Displays a block featuring your store\'s used products.');
            $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6.99.99');
        }

        public function install()
        {
            $success = (parent::install()
                && $this->registerHook('header')
                && $this->registerHook('leftColumn')
                && $this->registerHook('addproduct')
                && $this->registerHook('updateproduct')
                && $this->registerHook('deleteproduct')
                && $this->registerHook('actionProductAdd')
                && $this->registerHook('actionProductUpdate')
                && Configuration::updateValue('USED_PRODUCTS_NBR', 5)
                && Configuration::updateValue('PS_USED_P_THRESHOLD_DAYS', 7)
                && Configuration::updateValue('PS_USED_PRODUCT_SHOW_AS_NEW', 0)
                && Configuration::updateValue('PS_USED_PRODUCT_SHOW_USED', 1)
                && Configuration::updateValue('PS_NB_DAYS_USED_PRODUCT', 30)
                && $this->registerHook('displayHomeTab')
                && $this->registerHook('displayHomeTabContent')
            );

            $this->_clearCache('*');

            return $success;
        }

        public function uninstall()
        {
            $this->_clearCache('*');

            return parent::uninstall();
        }

        public function getContent()
        {
            $output = '';
            if (Tools::isSubmit('submitBlockUsedProducts')) {
                if (!($productNbr = Tools::getValue('USED_PRODUCTS_NBR')) || empty($productNbr)) {
                    $output .= $this->displayError($this->l('Please complete the "products to display" field.'));
                } elseif ((int)($productNbr) == 0) {
                    $output .= $this->displayError($this->l('Invalid number.'));
                } else {
                    Configuration::updateValue('PS_NB_DAYS_USED_PRODUCT', (int)(Tools::getValue('PS_NB_DAYS_USED_PRODUCT')));
                    Configuration::updateValue('PS_BLOCK_USEDPRODUCTS_DISPLAY', (int)(Tools::getValue('PS_BLOCK_USEDPRODUCTS_DISPLAY')));
                    Configuration::updateValue('USED_PRODUCTS_NBR', (int)($productNbr));
                    Configuration::updateValue('PS_USED_P_THRESHOLD_DAYS', (int)Tools::getValue('PS_USED_P_THRESHOLD_DAYS'));
                    Configuration::updateValue('PS_USED_PRODUCT_SHOW_USED', (int)Tools::getValue('PS_USED_PRODUCT_SHOW_USED'));
                    Configuration::updateValue('PS_USED_PRODUCT_SHOW_AS_NEW', (int)Tools::getValue('PS_USED_PRODUCT_SHOW_AS_NEW'));

                    if ((bool)Tools::getValue('PS_USED_PRODUCT_UPDATE')) {
                        if($this->updateUsedProducts()) {
                            $output .= $this->displayWarning($this->l('Product add date was updated'));
                        }
                        else{
                            $output .= $this->displayError($this->l('No products to update! Please select a product condition to manage'));
                        }
                    }
                    $this->_clearCache('*');
                    $output .= $this->displayConfirmation($this->l('Settings updated'));
                }
            }
            return $output . $this->renderForm();
        }

        protected function updateUsedProducts()
        {
            if(Configuration::get('PS_USED_PRODUCT_SHOW_USED') || Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW')) {
                $q = 'UPDATE `' . _DB_PREFIX_ . 'product_shop` `ps`' .
                    ' INNER JOIN `' . _DB_PREFIX_ . 'product` `p` ON `ps`.`id_product` = `p`.`id_product`' .
                    ' SET `ps`.`date_add`="' . date('Y-m-d H:i:s',
                        strtotime('-' . (Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20) . ' DAY')) . '" ' .
                    ' WHERE `ps`.`date_add` > "' . date('Y-m-d',
                        strtotime('-' . (Configuration::get('PS_USED_PRODUCT_UPDATE') ? (int)Configuration::get('PS_USED_PRODUCT_UPDATE') : 20) . ' DAY')) . '"' .
                    ' AND `p`.`condition`IN (' .$this->getProductConditionsList().")";
                error_log($q);
                Db::getInstance()->execute($q, false);
                Tools::clearSmartyCache();
                $this->_clearCache('*');
                return true;
            }
            else{
                return false;
            }
        }

        protected function getProductConditionsList(){
            $inCondition = "";
            if (Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW') && Configuration::get('PS_USED_PRODUCT_SHOW_USED')) {
                $inCondition .= '"refurbished", "used"';
            } else {
                if (Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW')) {
                    $inCondition .= '"refurbished"';
                } else {
                    $inCondition .= '"used"';
                }
            }
            return $inCondition;
        }

        protected function updateUsedProduct(Product $product)
        {
            if(Configuration::get('PS_USED_PRODUCT_SHOW_USED') || Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW')) {
                $q = 'UPDATE `' . _DB_PREFIX_ . 'product_shop` `ps`' .
                    ' INNER JOIN `' . _DB_PREFIX_ . 'product` `p` ON `p`.`id_product`=`ps`.`id_product`' .
                    ' SET `ps`.`date_add`="' . date('Y-m-d H:i:s',
                        strtotime('-' . ((int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20) . ' DAY')) . '" ' .

                    ' WHERE `p`.`condition` IN ('.
                        $this->getProductConditionsList()
                    .')' .
                    ' AND `ps`.`id_product`=' . $product->id;
                error_log($q);
                Db::getInstance()->execute($q, false);
            }
        }

        protected function getUsedProducts()
        {
            if (!Configuration::get('USED_PRODUCTS_NBR')) {
                return;
            }
            $usedProducts = false;
            if (Configuration::get('PS_NB_DAYS_USED_PRODUCT')) {
                $usedProducts = $this->queryGetUsedProducts((int)$this->context->language->id, 0,
                    (int)Configuration::get('USED_PRODUCTS_NBR'));
            }
            if (!$usedProducts && Configuration::get('PS_BLOCK_USEDPRODUCTS_DISPLAY')) {
                return;
            }
            return $usedProducts;
        }

        //------- HOOKS --------
        public function hookRightColumn($params)
        {
            if (!$this->isCached('blockusedproducts.tpl', $this->getCacheId())) {
                if (!isset(BlockUsedProducts::$cache_used_products)) {
                    BlockUsedProducts::$cache_used_products = $this->getUsedProducts();
                }

                $this->smarty->assign(array(
                    'used_products' => BlockUsedProducts::$cache_used_products,
                    'mediumSize' => Image::getSize(ImageType::getFormatedName('medium')),
                    'homeSize' => Image::getSize(ImageType::getFormatedName('home'))
                ));
            }

            if (BlockUsedProducts::$cache_used_products === false) {
                return false;
            }

            return $this->display(__FILE__, 'blockusedproducts.tpl', $this->getCacheId());
        }

        public function hookLeftColumn($params)
        {
            return $this->hookRightColumn($params);
        }

        public function hookdisplayHomeTab($params)
        {
            if (!$this->isCached('tab.tpl', $this->getCacheId('blockusedproducts-tab'))) {
                BlockUsedProducts::$cache_used_products = $this->getUsedProducts();
            }

            if (BlockUsedProducts::$cache_used_products === false) {
                return false;
            }

            return $this->display(__FILE__, 'tab.tpl', $this->getCacheId('blockusedproducts-tab'));
        }

        public function hookdisplayHomeTabContent($params)
        {
            if (!$this->isCached('blockusedproducts_home.tpl', $this->getCacheId('blockusedproducts-home'))) {
                $this->smarty->assign(array(
                    'used_products' => BlockUsedProducts::$cache_used_products,
                    'mediumSize' => Image::getSize(ImageType::getFormatedName('medium')),
                    'homeSize' => Image::getSize(ImageType::getFormatedName('home'))
                ));
            }

            if (BlockUsedProducts::$cache_used_products === false) {
                return false;
            }

            return $this->display(__FILE__, 'blockusedproducts_home.tpl', $this->getCacheId('blockusedproducts-home'));
        }

        public function hookHeader($params)
        {
            if (isset($this->context->controller->php_self) && $this->context->controller->php_self == 'index') {
                $this->context->controller->addCSS(_THEME_CSS_DIR_ . 'product_list.css');
            }

            $this->context->controller->addCSS($this->_path . 'blockusedproducts.css', 'all');
        }

        public function hookAddProduct($params)
        {
            $this->hookActionProductAdd($params);
        }

        public function hookUpdateProduct($params)
        {
            $this->hookActionProductAdd($params);
        }

        public function hookDeleteProduct($params)
        {
            $this->_clearCache('*');
        }

        public function hookActionProductAdd($params)
        {

            $conditions=array();
            if(Configuration::get('PS_USED_PRODUCT_SHOW_USED')){
                $conditions[] = 'used';
            }
            if(Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW')){
                $conditions[] = 'refurbished';
            }
            if(Configuration::get('PS_USED_PRODUCT_SHOW_USED') || Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW')) {
                $product = new Product((int)$params['id_product']);
                if(in_array($product->condition, $conditions)){
                    $this->updateUsedProduct($product);
                }
            }
            $this->_clearCache('*');
        }

        public function hookActionProductUpdate($params)
        {
            $this->hookActionProductAdd($params);
        }
        //------- /HOOKS --------

        //------- UTILS --------
        public function _clearCache($template, $cache_id = null, $compile_id = null)
        {
            parent::_clearCache('blockusedproducts.tpl');
            parent::_clearCache('blockusedproducts_home.tpl', 'blockusedproducts-home');
            parent::_clearCache('tab.tpl', 'blockusedproducts-tab');
        }

        private function renderForm()
        {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('Settings'),
                        'icon' => 'icon-cogs'
                    ),
                    'input' => array(
                        array(
                            'type' => 'text',
                            'label' => $this->l('Products to display'),
                            'name' => 'USED_PRODUCTS_NBR',
                            'class' => 'fixed-width-xs',
                            'desc' => $this->l('Define the number of products to be displayed in this block.'),
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Setback days'),
                            'name' => 'PS_NB_DAYS_USED_PRODUCT',
                            'class' => 'fixed-width-xs',
                            'desc' => $this->l('Define the number of days to set back the used products add date'),
                        ),
                        array(
                            'type' => 'text',
                            'label' => $this->l('Threshold days'),
                            'name' => 'PS_USED_P_THRESHOLD_DAYS',
                            'class' => 'fixed-width-xs',
                            'desc' => $this->l('Define the number of days ins the past to search for used produts'),
                        ),
                        array(
                            'type' => 'switch',
                            'label' => $this->l('Update used products add date'),
                            'name' => 'PS_USED_PRODUCT_UPDATE',
                            'desc' => $this->l('Update recently added used products to set back the add date'),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l('Enabled')
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l('Disabled')
                                )
                            ),
                        ),
                        array(
                            'type' => 'switch',
                            'label' => $this->l('Always display this block'),
                            'name' => 'PS_BLOCK_USEDPRODUCTS_DISPLAY',
                            'desc' => $this->l('Show the block even if no used products are available.'),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l('Enabled')
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l('Disabled')
                                )
                            ),
                        ),
                        array(
                            'type' => 'switch',
                            'label' => $this->l('Display "used" products'),
                            'name' => 'PS_USED_PRODUCT_SHOW_USED',
                            'desc' => $this->l('Define whether to display and manage "used" products or not'),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l('Enabled')
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l('Disabled')
                                )
                            ),
                        ),
                        array(
                            'type' => 'switch',
                            'label' => $this->l('Display "as new" products'),
                            'name' => 'PS_USED_PRODUCT_SHOW_AS_NEW',
                            'desc' => $this->l('Define whether to display and manage "as new" products or not'),
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => 1,
                                    'label' => $this->l('Enabled')
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => 0,
                                    'label' => $this->l('Disabled')
                                )
                            ),
                        ),
                    ),
                    'submit' => array(
                        'title' => $this->l('Save'),
                    ),
                ),
            );

            $helper = new HelperForm();
            $helper->show_toolbar = false;
            $helper->table = $this->table;
            $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
            $helper->default_form_language = $lang->id;
            $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
            $helper->identifier = $this->identifier;
            $helper->submit_action = 'submitBlockUsedProducts';
            $helper->currentIndex = $this->context->link->getAdminLink('AdminModules',
                    false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
            $helper->token = Tools::getAdminTokenLite('AdminModules');
            $helper->tpl_vars = array(
                'fields_value' => $this->getConfigFieldsValues(),
                'languages' => $this->context->controller->getLanguages(),
                'id_language' => $this->context->language->id
            );
            $helper->tpl_vars['fields_value']['PS_USED_PRODUCT_UPDATE'] = 0;

            return $helper->generateForm(array($fields_form));
        }

        private function getConfigFieldsValues()
        {
            return array(
                'PS_NB_DAYS_USED_PRODUCT' => Tools::getValue('PS_NB_DAYS_USED_PRODUCT', Configuration::get('PS_NB_DAYS_USED_PRODUCT')),
                'PS_BLOCK_USEDPRODUCTS_DISPLAY' => Tools::getValue('PS_BLOCK_USEDPRODUCTS_DISPLAY', Configuration::get('PS_BLOCK_USEDPRODUCTS_DISPLAY')),
                'USED_PRODUCTS_NBR' => Tools::getValue('USED_PRODUCTS_NBR', Configuration::get('USED_PRODUCTS_NBR')),
                'PS_USED_P_THRESHOLD_DAYS' => Tools::getValue('PS_USED_P_THRESHOLD_DAYS', Configuration::get('PS_USED_P_THRESHOLD_DAYS')),
                'PS_USED_PRODUCT_SHOW_AS_NEW'=> Tools::getValue('PS_USED_PRODUCT_SHOW_AS_NEW',Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW')),
                'PS_USED_PRODUCT_SHOW_USED'=> Tools::getValue('PS_USED_PRODUCT_SHOW_USED',Configuration::get('PS_USED_PRODUCT_SHOW_USED')),
            );
        }

        /**
         * Get used products
         *
         * @param int $id_lang Language id
         * @param int $pageNumber Start from (optional)
         * @param int $nbProducts Number of products to return (optional)
         * @return array New products
         */
        private function queryGetUsedProducts(
            $id_lang,
            $page_number = 0,
            $nb_products = 10,
            $count = false,
            $order_by = null,
            $order_way = null,
            Context $context = null,
            $only_new = false
        ) {
            if (!$context) {
                $context = Context::getContext();
            }

            $front = true;
            if (!in_array($context->controller->controller_type, array('front', 'modulefront'))) {
                $front = false;
            }

            if ($page_number < 0) {
                $page_number = 0;
            }
            if ($nb_products < 1) {
                $nb_products = 10;
            }
            if (empty($order_by) || $order_by == 'position') {
                $order_by = 'date_add';
            }
            if (empty($order_way)) {
                $order_way = 'DESC';
            }
            if ($order_by == 'id_product' || $order_by == 'price' || $order_by == 'date_add' || $order_by == 'date_upd') {
                $order_by_prefix = 'product_shop';
            } elseif ($order_by == 'name') {
                $order_by_prefix = 'pl';
            }
            if (!Validate::isOrderBy($order_by) || !Validate::isOrderWay($order_way)) {
                die(Tools::displayError());
            }

            $sql_groups = '';
            if (Group::isFeatureActive()) {
                $groups = FrontController::getCurrentCustomerGroups();
                $sql_groups = ' AND EXISTS(SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp
				JOIN `' . _DB_PREFIX_ . 'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` ' . (count($groups) ? 'IN (' . implode(',',
                            $groups) . ')' : '= 1') . ')
				WHERE cp.`id_product` = p.`id_product`)';
            }

            if (strpos($order_by, '.') > 0) {
                $order_by = explode('.', $order_by);
                $order_by_prefix = $order_by[0];
                $order_by = $order_by[1];
            }

            if ($count) {
                $sql = 'SELECT COUNT(p.`id_product`) AS nb
					FROM `' . _DB_PREFIX_ . 'product` p
					' . Shop::addSqlAssociation('product', 'p') . '
					WHERE product_shop.`active` = 1
					AND product_shop.`condition` = "used"
					' . ($only_new ? 'AND product_shop.`date_add` > "' . date('Y-m-d',
                            strtotime('-' . ((int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20) . ' DAY')) : '') . '
					' . ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '') . '
					' . $sql_groups;
                return (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
            }

            $sql = new DbQuery();
            $sql->select(
                'p.*, product_shop.*, stock.out_of_stock, IFNULL(stock.quantity, 0) as quantity, pl.`description`, pl.`description_short`, pl.`link_rewrite`, pl.`meta_description`,
			pl.`meta_keywords`, pl.`meta_title`, pl.`name`, pl.`available_now`, pl.`available_later`, image_shop.`id_image` id_image, il.`legend`, m.`name` AS manufacturer_name,
			product_shop.`date_add` as new'
            );

            $sql->from('product', 'p');
            $sql->join(Shop::addSqlAssociation('product', 'p'));
            $sql->leftJoin('product_lang', 'pl', '
			p.`id_product` = pl.`id_product`
			AND pl.`id_lang` = ' . (int)$id_lang . Shop::addSqlRestrictionOnLang('pl')
            );
            $sql->leftJoin('image_shop', 'image_shop',
                'image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop=' . (int)$context->shop->id);
            $sql->leftJoin('image_lang', 'il',
                'image_shop.`id_image` = il.`id_image` AND il.`id_lang` = ' . (int)$id_lang);
            $sql->leftJoin('manufacturer', 'm', 'm.`id_manufacturer` = p.`id_manufacturer`');

            $sql->where('product_shop.`active` = 1');

            error_log("used: ".(int)Configuration::get('PS_USED_PRODUCT_SHOW_USED'));
            error_log("refurbished: ".(int)Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW'));
            error_log("both: ".(int)Configuration::get('PS_USED_PRODUCT_SHOW_USED') && (int)Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW'));
            if((int)Configuration::get('PS_USED_PRODUCT_SHOW_USED') && (int)Configuration::get('PS_USED_PRODUCT_SHOW_AS_NEW')){
                $sql->where('product_shop.`condition` IN ("used", "refurbished")');
            }
            else if((int)Configuration::get('PS_USED_PRODUCT_SHOW_USED')){
                $sql->where('product_shop.`condition` = "used"');
            }
            else{
                $sql->where('product_shop.`condition` = "refurbished"');
            }
            error_log(print_r($sql,true));

            if ($front) {
                $sql->where('product_shop.`visibility` IN ("both", "catalog")');
            }
            if ($only_new) {
                $sql->where('product_shop.`date_add` > "' . date('Y-m-d',
                        strtotime('-' . ((int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20) . ' DAY')) . '"');
            }
            if (Group::isFeatureActive()) {
                $groups = FrontController::getCurrentCustomerGroups();
                $sql->where('EXISTS(SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp
				JOIN `' . _DB_PREFIX_ . 'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` ' . (count($groups) ? 'IN (' . implode(',',
                            $groups) . ')' : '= 1') . ')
				WHERE cp.`id_product` = p.`id_product`)');
            }

            $sql->orderBy((isset($order_by_prefix) ? pSQL($order_by_prefix) . '.' : '') . '`' . pSQL($order_by) . '` ' . pSQL($order_way));
            $sql->limit($nb_products, $page_number * $nb_products);

            if (Combination::isFeatureActive()) {
                $sql->select('product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity, IFNULL(product_attribute_shop.id_product_attribute,0) id_product_attribute');
                $sql->leftJoin('product_attribute_shop', 'product_attribute_shop',
                    'p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop=' . (int)$context->shop->id);
            }
            $sql->join(Product::sqlStock('p', 0));

            $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

            if (!$result) {
                return false;
            }

            if ($order_by == 'price') {
                Tools::orderbyPrice($result, $order_way);
            }

            $products_ids = array();
            foreach ($result as $row) {
                $products_ids[] = $row['id_product'];
            }
            // Thus you can avoid one query per product, because there will be only one query for all the products of the cart
            Product::cacheFrontFeatures($products_ids, $id_lang);
            return Product::getProductsProperties((int)$id_lang, $result);
        }

        protected function getCacheId($name = null)
        {
            if ($name === null) {
                $name = 'BlockUsedProducts';
            }
            return parent::getCacheId($name . '|' . date('Ymd'));
        }
        //------- /UTILS --------
    }
}
