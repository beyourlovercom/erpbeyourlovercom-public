<?php

return [
    'contract_version' => 'P1.9-v1',
    'gateway_ownership' => [
        'primary_card' => [
            'owner' => 'woocommerce_payments',
            'normal_checkout' => 'enabled',
        ],
        'apple_pay' => [
            'owner' => 'woocommerce_payments',
            'placement' => 'cart_and_checkout_only',
        ],
        'google_pay' => [
            'owner' => 'woocommerce_payments',
            'placement' => 'cart_and_checkout_only',
        ],
        'paypal_wallet' => [
            'owner' => 'ppcp',
            'normal_checkout' => 'enabled',
        ],
        'klarna_bnpl' => [
            'owner' => 'airwallex_klarna',
            'normal_checkout' => 'enabled',
        ],
        'local_payment_methods' => [
            'owner' => 'woocommerce_payments',
            'availability' => 'capability_and_region_based',
        ],
        'card_hot_standby' => [
            'owner' => 'cpsw_stripe_element',
            'normal_checkout' => 'hidden',
            'purpose' => 'controlled_emergency_fallback_only',
        ],
        'paypal_card' => [
            'owner' => 'ppcp_card',
            'normal_checkout' => 'hidden_candidate',
        ],
        'airwallex_card' => [
            'owner' => 'airwallex_card',
            'normal_checkout' => 'hidden_candidate',
        ],
    ],
    'checkout_handoff' => [
        'product_page_express_payment' => 'disabled',
        'cart_checkout_express_payment' => 'capability_driven',
        'normal_card_entry_points' => 1,
        'issuer_decline_auto_retry' => 'disabled',
        'payment_safety_source' => 'erp_payment_authority_gate',
        'fulfillment_payment_inference' => 'prohibited',
        'stripe_role' => 'hot_standby',
        'paypal_card_role' => 'not_normal_primary_card_ui',
        'airwallex_card_role' => 'not_normal_primary_card_ui',
    ],
    'capabilities' => [
        'payment_authority_gate' => 'implemented',
        'stale_pending_preview' => 'implemented_read_only',
        'payment_observability' => 'implemented_read_only',
        'gateway_economics' => 'implemented_read_only',
        'actual_fee_woocommerce_payments' => 'supported',
        'actual_fee_stripe' => 'supported',
        'actual_fee_paypal' => 'supported',
        'actual_fee_airwallex' => 'unavailable',
        'raw_provider_authorization_attempts' => 'unavailable',
        'canonical_webhook_event_store' => 'unavailable',
        'dispute_chargeback_cost_evidence' => 'unavailable',
    ],
    'production_prerequisites' => [
        'explicit_backup_and_production_schema_gate',
        'production_code_deploy_gate',
        'read_only_post_deploy_payment_health_and_closure_preview',
        'separately_approved_historical_evidence_refresh_if_desired',
        'checkout_routing_changes_only_under_checkout_experience_production_gate',
        'no_gateway_removal_before_checkout_regression_and_payment_uat',
    ],
];
