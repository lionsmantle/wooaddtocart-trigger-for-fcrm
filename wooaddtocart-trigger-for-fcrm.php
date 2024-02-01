<?php

/**
 * Plugin Name: FluentCRM WooCommerce Add to Cart Trigger
 * Description: Adds an automation trigger to FluentCRM for WooCommerce Add to Cart
 * Author: LionsMantle
 * Author URI: https://www.lionsmantle.com
 * Version: 1.0
 * Text Domain: wooaddtocart-trigger-for-fcrm
 */

namespace FCRMWooAddtoCart\Automation;

if(!defined('ABSPATH')) die; // If this file is called directly, abort.


use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;


class WooAddToCartTrigger extends BaseTrigger
{
    
    public function __construct()
    {

        $this->triggerName = 'woocommerce_add_to_cart';
        $this->priority = 22;
        $this->actionArgNum = 6;
        parent::__construct();

    }

    public function getTrigger()
    {
        
        return [
            'category'    => __('WooCommerce', 'lm-fcrm-wooaddtocart'),
            'label'       => __('Item Added to Cart', 'lm-fcrm-wooaddtocart'),
            'description' => __('This funnel will start when an item is added to the cart', 'lm-fcrm-wooaddtocart'),
            'icon'        => 'fc-icon-woo_new_order',
        ];

    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('WooCommerce Item Added to Cart', 'fluentcampaign-pro'),
            'sub_title' => __('This funnel will start when an item is added to the cart', 'fluentcampaign-pro'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluentcampaign-pro'),
                    'placeholder' => __('Select Status', 'fluentcampaign-pro')
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An Automated double-optin email will be sent for new subscribers', 'fluentcampaign-pro') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ]
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'product_ids'        => [],
            'product_categories' => [],
            'run_multiple'       => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'product_ids'        => [
                'type'        => 'rest_selector',
                'option_key'  => 'woo_products',
                'is_multiple' => true,
                'label'       => __('Target Products', 'fluentcampaign-pro'),
                'help'        => __('Select for which products this automation will run', 'fluentcampaign-pro'),
                'inline_help' => __('Keep it blank to run to any product purchase', 'fluentcampaign-pro')
            ],
            'product_categories' => [
                'type'        => 'rest_selector',
                'option_key'  => 'woo_categories',
                'is_multiple' => true,
                'label'       => __('OR Target Product Categories', 'fluentcampaign-pro'),
                'help'        => __('Select for which product category the automation will run', 'fluentcampaign-pro'),
                'inline_help' => __('Keep it blank to run to any category products', 'fluentcampaign-pro')
            ],
            'run_multiple'       => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluentcampaign-pro'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluentcampaign-pro')
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
      
        $cartItemKey = $originalArgs[0];
        $cartItem = WC()->cart->get_cart_item( $cartItemKey );
        $productId = $cartItem['product_id'];

        $userId = get_current_user_id();

        $subscriberData = FunnelHelper::prepareUserData($userId);

        if (!is_email($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $productId, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);
        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = (!empty($subscriberData['subscription_status'])) ? $subscriberData['subscription_status'] : 'subscribed';
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $productId
        ]);
    }

    function isProductIdOrCategoryMatched($productId, $conditions) {

        $cartAddIds = [$productId];

        // check the products ids
        if (!empty($conditions['product_ids'])) {
            if (array_intersect($cartAddIds, $conditions['product_ids'])) {
                return true;
            }

            if (empty($conditions['product_categories'])) {
                return false;
            }
        }

        if (!empty($conditions['product_categories'])) {
            $categoryMatch = fluentCrmDb()->table('term_relationships')
                ->whereIn('term_taxonomy_id', $conditions['product_categories'])
                ->whereIn('object_id', $cartAddIds)
                ->count();

            if (!$categoryMatch) {
                return false;
            }
        }

        return true;
    }

    private function isProcessable($funnel, $productId, $subscriberData)
    {
        $conditions = (array)$funnel->conditions;

        $result = $this->isProductIdOrCategoryMatched($productId, $conditions);

        if (!$result) {
            return false;
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        // check run_only_one
        if ($subscriber) {
            $funnelSub = FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id);
            if ($funnelSub) {
                $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
                if ($multipleRun) {
                    FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
                }
                return $multipleRun;
            }
        }

        return true;

    }
}


add_action('fluent_crm/after_init', function () {
    new WooAddToCartTrigger();
});

