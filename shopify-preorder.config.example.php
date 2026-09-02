<?php
/**
 * Copy this file to shopify-preorder.config.php beside preorderendpoint.php
 * and fill in a valid Shopify Admin API token.
 *
 * Keep the real file out of git. Required scope: write_draft_orders.
 */

return [
    'admin_token' => 'shpat_REPLACE_WITH_REAL_TOKEN',
];
