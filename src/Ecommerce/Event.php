<?php

namespace Sfere\Ecommerce;

/**
 * GA4 e-commerce event names (sfere-sdk docs/EVENTS.md). Other names are accepted and forwarded
 * unchanged; these are the ones the conversion destinations map.
 */
final class Event
{
    public const VIEW_ITEM = 'view_item';

    public const VIEW_ITEM_LIST = 'view_item_list';

    public const SELECT_ITEM = 'select_item';

    public const ADD_TO_CART = 'add_to_cart';

    public const REMOVE_FROM_CART = 'remove_from_cart';

    public const ADD_TO_WISHLIST = 'add_to_wishlist';

    public const VIEW_CART = 'view_cart';

    public const BEGIN_CHECKOUT = 'begin_checkout';

    public const ADD_SHIPPING_INFO = 'add_shipping_info';

    public const ADD_PAYMENT_INFO = 'add_payment_info';

    public const PURCHASE = 'purchase';

    public const REFUND = 'refund';

    public const SEARCH = 'search';

    public const SIGN_UP = 'sign_up';

    public const LOGIN = 'login';

    public const GENERATE_LEAD = 'generate_lead';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    private function __construct() {}
}
