<?php

namespace App\Support;

/**
 * Central registry of permission-name strings so spatie/permission seeders,
 * CheckoutService, ReturnService, and controllers all reference the same
 * constants instead of magic strings that could silently drift apart.
 */
final class PosPermissions
{
    public const PRICE_OVERRIDE = 'pos.price-override';
    public const DISCOUNT_ABOVE_THRESHOLD = 'pos.discount-above-threshold';
    public const RETURNS_CREATE = 'pos.returns-create';
    public const VOID_BEFORE_FINALIZE = 'pos.void-before-finalize';
    public const STOCK_ADJUST = 'pos.stock-adjust';
    public const REPORTS_VIEW = 'pos.reports-view';
    public const COMPLIANCE_DASHBOARD = 'pos.compliance-dashboard';
    public const TERMINAL_MANAGE = 'pos.terminal-manage';
    public const USER_MANAGE = 'pos.user-manage';
    public const PRODUCT_MANAGE = 'pos.product-manage';
    public const PURCHASE_MANAGE = 'pos.purchase-manage';
    public const FURTHER_TAX_OVERRIDE = 'pos.further-tax-override';
    public const CUSTOMER_MANAGE = 'pos.customer-manage';

    public static function all(): array
    {
        return [
            self::PRICE_OVERRIDE,
            self::DISCOUNT_ABOVE_THRESHOLD,
            self::RETURNS_CREATE,
            self::VOID_BEFORE_FINALIZE,
            self::STOCK_ADJUST,
            self::REPORTS_VIEW,
            self::COMPLIANCE_DASHBOARD,
            self::TERMINAL_MANAGE,
            self::USER_MANAGE,
            self::PRODUCT_MANAGE,
            self::PURCHASE_MANAGE,
            self::FURTHER_TAX_OVERRIDE,
            self::CUSTOMER_MANAGE,
        ];
    }
}
