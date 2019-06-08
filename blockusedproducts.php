<?php
/*
* 2007-2016 PrestaShop
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
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

class BlockUsedProducts extends Module
{
	protected static $cache_new_products;

	public function __construct()
	{
		$this->name = 'BlockUsedProducts';
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
			&& Configuration::updateValue('USED_PRODUCTS_NBR', 5)
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
		if (Tools::isSubmit('submitBlockUsedProducts'))
		{
			if (!($productNbr = Tools::getValue('USED_PRODUCTS_NBR')) || empty($productNbr))
				$output .= $this->displayError($this->l('Please complete the "products to display" field.'));
			elseif ((int)($productNbr) == 0)
				$output .= $this->displayError($this->l('Invalid number.'));
			else
			{
				Configuration::updateValue('PS_NB_DAYS_USED_PRODUCT', (int)(Tools::getValue('PS_NB_DAYS_USED_PRODUCT')));
				Configuration::updateValue('PS_BLOCK_USEDPRODUCTS_DISPLAY', (int)(Tools::getValue('PS_BLOCK_USEDPRODUCTS_DISPLAY')));
				Configuration::updateValue('USED_PRODUCTS_NBR', (int)($productNbr));
				if((bool) Tools::getValue('PS_USED_PRODUCT_UPDATE')){
                    $this->updateUsedProducts();
                    $output .= $this->displayWarning($this->l('Product add date was updated'));
                }
				$this->_clearCache('*');
				$output .= $this->displayConfirmation($this->l('Settings updated'));
			}
		}
		return $output.$this->renderForm();
	}

	protected function updateUsedProducts(){
	    $updatedUsedProducts = $this->queryGetUsedProducts((int) $this->context->language->id, 0, (int)Configuration::get('USED_PRODUCTS_NBR'), false, null, null, null, true);
        $q = 'UPDATE '._DB_PREFIX_.'product_shop ps'.
            ' INNER JOIN '._DB_PREFIX_.'product p ON ps.id_product = p.id_product'.
            ' SET `ps`.`date_add`="'.date('Y-m-d H:i:s', strtotime('-'.(Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20).' DAY')).'" '.
            ' WHERE `ps`.`date_add` > "'.date('Y-m-d', strtotime('-'.(Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20).' DAY')).'"'.
            ' AND `p`.`condition`="used"';
        Db::getInstance()->execute($q, false);
        $products_ids = array();
        foreach ($updatedUsedProducts as $row) {
            $products_ids[] = $row['id_product'];
        }
        Product::cacheFrontFeatures($products_ids, (int) $this->context->language->id);
        $this->_clearCache('*');
    }

	protected function getUsedProducts()
	{
		if (!Configuration::get('USED_PRODUCTS_NBR'))
			return;
		$usedProducts = false;
		if (Configuration::get('PS_NB_DAYS_USED_PRODUCT'))
		    $usedProducts = $this->queryGetUsedProducts((int) $this->context->language->id, 0, (int)Configuration::get('USED_PRODUCTS_NBR'));
		if (!$usedProducts && Configuration::get('PS_BLOCK_USEDPRODUCTS_DISPLAY'))
			return;
		return $usedProducts;
	}

	public function hookRightColumn($params)
	{
		if (!$this->isCached('blockusedproducts.tpl', $this->getCacheId()))
		{
			if (!isset(BlockUsedProducts::$cache_new_products))
				BlockUsedProducts::$cache_new_products = $this->getUsedProducts();

			$this->smarty->assign(array(
				'used_products' => BlockUsedProducts::$cache_new_products,
				'mediumSize' => Image::getSize(ImageType::getFormatedName('medium')),
				'homeSize' => Image::getSize(ImageType::getFormatedName('home'))
			));
		}

		if (BlockUsedProducts::$cache_new_products === false)
			return false;

		return $this->display(__FILE__, 'blockusedproducts.tpl', $this->getCacheId());
	}

	protected function getCacheId($name = null)
	{
		if ($name === null)
			$name = 'BlockUsedProducts';
		return parent::getCacheId($name.'|'.date('Ymd'));
	}

	public function hookLeftColumn($params)
	{
		return $this->hookRightColumn($params);
	}

	public function hookdisplayHomeTab($params)
	{
		if (!$this->isCached('tab.tpl', $this->getCacheId('blockusedproducts-tab')))
			BlockUsedProducts::$cache_new_products = $this->getUsedProducts();

		if (BlockUsedProducts::$cache_new_products === false)
			return false;

		return $this->display(__FILE__, 'tab.tpl', $this->getCacheId('blockusedproducts-tab'));
	}

	public function hookdisplayHomeTabContent($params)
	{
		if (!$this->isCached('blockusedproducts_home.tpl', $this->getCacheId('blockusedproducts-home')))
		{
			$this->smarty->assign(array(
				'used_products' => BlockUsedProducts::$cache_new_products,
				'mediumSize' => Image::getSize(ImageType::getFormatedName('medium')),
				'homeSize' => Image::getSize(ImageType::getFormatedName('home'))
			));
		}

		if (BlockUsedProducts::$cache_new_products === false)
			return false;

		return $this->display(__FILE__, 'blockusedproducts_home.tpl', $this->getCacheId('blockusedproducts-home'));
	}

	public function hookHeader($params)
	{
		if (isset($this->context->controller->php_self) && $this->context->controller->php_self == 'index')
			$this->context->controller->addCSS(_THEME_CSS_DIR_.'product_list.css');

		$this->context->controller->addCSS($this->_path.'blockusedproducts.css', 'all');
	}

	public function hookAddProduct($params)
	{
		$this->_clearCache('*');
	}

	public function hookUpdateProduct($params)
	{
		$this->_clearCache('*');
	}

	public function hookDeleteProduct($params)
	{
		$this->_clearCache('*');
	}

	public function _clearCache($template, $cache_id = NULL, $compile_id = NULL)
	{
		parent::_clearCache('blockusedproducts.tpl');
		parent::_clearCache('blockusedproducts_home.tpl', 'blockusedproducts-home');
		parent::_clearCache('tab.tpl', 'blockusedproducts-tab');
	}

	public function renderForm()
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
						'type'  => 'text',
						'label' => $this->l('Define the number of days to set back the product add date'),
						'name'  => 'PS_NB_DAYS_USED_PRODUCT',
						'class' => 'fixed-width-xs',
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
				),
				'submit' => array(
					'title' => $this->l('Save'),
				),
			),
		);

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table =  $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitBlockUsedProducts';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);
		$helper->tpl_vars['fields_value']['PS_USED_PRODUCT_UPDATE'] = 0;

		return $helper->generateForm(array($fields_form));
	}

	public function getConfigFieldsValues()
	{
		return array(
			'PS_NB_DAYS_USED_PRODUCT' => Tools::getValue('PS_NB_DAYS_USED_PRODUCT', Configuration::get('PS_NB_DAYS_USED_PRODUCT')),
			'PS_BLOCK_USEDPRODUCTS_DISPLAY' => Tools::getValue('PS_BLOCK_USEDPRODUCTS_DISPLAY', Configuration::get('PS_BLOCK_USEDPRODUCTS_DISPLAY')),
			'USED_PRODUCTS_NBR' => Tools::getValue('USED_PRODUCTS_NBR', Configuration::get('USED_PRODUCTS_NBR')),
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
    private function queryGetUsedProducts($id_lang, $page_number = 0, $nb_products = 10, $count = false, $order_by = null, $order_way = null, Context $context = null, $only_new = false)
    {
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
            $sql_groups = ' AND EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
				JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
				WHERE cp.`id_product` = p.`id_product`)';
        }

        if (strpos($order_by, '.') > 0) {
            $order_by = explode('.', $order_by);
            $order_by_prefix = $order_by[0];
            $order_by = $order_by[1];
        }

        if ($count) {
            $sql = 'SELECT COUNT(p.`id_product`) AS nb
					FROM `'._DB_PREFIX_.'product` p
					'.Shop::addSqlAssociation('product', 'p').'
					WHERE product_shop.`active` = 1
					AND product_shop.`condition` = "used"
					'.($only_new ? 'AND product_shop.`date_add` > "'.date('Y-m-d', strtotime('-'.(Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20).' DAY')): '').'
					'.($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '').'
					'.$sql_groups;
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
			AND pl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('pl')
        );
        $sql->leftJoin('image_shop', 'image_shop', 'image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop='.(int)$context->shop->id);
        $sql->leftJoin('image_lang', 'il', 'image_shop.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)$id_lang);
        $sql->leftJoin('manufacturer', 'm', 'm.`id_manufacturer` = p.`id_manufacturer`');

        $sql->where('product_shop.`active` = 1');
        $sql->where('product_shop.`condition` = "used"');
        if ($front) {
            $sql->where('product_shop.`visibility` IN ("both", "catalog")');
        }
        if($only_new) {
            $sql->where('product_shop.`date_add` > "' . date('Y-m-d', strtotime('-' . (Configuration::get('PS_NB_DAYS_USED_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_USED_PRODUCT') : 20) . ' DAY')).'"');
        }
        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql->where('EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
				JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
				WHERE cp.`id_product` = p.`id_product`)');
        }

        $sql->orderBy((isset($order_by_prefix) ? pSQL($order_by_prefix).'.' : '').'`'.pSQL($order_by).'` '.pSQL($order_way));
        $sql->limit($nb_products, $page_number * $nb_products);

        if (Combination::isFeatureActive()) {
            $sql->select('product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity, IFNULL(product_attribute_shop.id_product_attribute,0) id_product_attribute');
            $sql->leftJoin('product_attribute_shop', 'product_attribute_shop', 'p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop='.(int)$context->shop->id);
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
}
