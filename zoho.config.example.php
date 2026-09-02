<?php
/**
 * Zoho CRM configuration for zoho-lead-endpoint.php.
 *
 * Copy this to config/zoho.php beside the endpoint (or point ZOHO_CONFIG_PATH
 * at it) and fill in the four credentials. Keep it out of any public directory
 * listing and out of version control: the refresh token is permanent and gives
 * full read/write access to the CRM.
 *
 * Getting the credentials, once:
 *   1. https://api-console.zoho.com > Self Client (or Server-based Application)
 *   2. Scope: ZohoCRM.modules.Leads.CREATE,ZohoCRM.modules.Leads.READ
 *   3. Exchange the grant code for a refresh token. The refresh token does not
 *      expire; the endpoint trades it for a one-hour access token and caches it.
 *
 * Use the data centre the CRM actually lives in. For the UAE/India accounts
 * that is usually .in, for the EU .eu, otherwise .com. Getting this wrong
 * returns "invalid_client" from the token endpoint.
 */

return [
    // Set false to stop the endpoint accepting leads without removing it.
    'enabled' => true,

    // --- credentials -------------------------------------------------------
    'client_id'       => '1000.XXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
    'client_secret'   => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'refresh_token'   => '1000.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',

    // --- data centre -------------------------------------------------------
    'accounts_domain' => 'https://accounts.zoho.com',      // .in / .eu / .com
    'api_domain'      => 'https://www.zohoapis.com',       // must match the above

    // --- where the lead lands ---------------------------------------------
    'module'      => 'Leads',
    'lead_source' => 'Website',

    // Upsert key: a repeat enquiry from the same address updates the lead
    // rather than creating a second one.
    'duplicate_check_fields' => ['User_ID'],
    'user_id_source'         => 'email',

    /*
     * Zoho API names for the fields the form fills. Left side is what the
     * endpoint asks for, right side is the API name in this CRM — check them
     * under Setup > Developer Space > APIs > API Names, since custom fields
     * carry a _c suffix and a wrong name makes Zoho reject the whole record.
     *
     * Type_of_Service is a picklist. The endpoint only sends values on its
     * allow-list and folds anything else into the description, so the
     * "Type of service" options on the contact page section must match the
     * picklist exactly: PR/Media, Influencers/Creators, PreOrder,
     * Fragrance Retailers, Distributors & Importers, E-Commerce Retailers,
     * Hotel Chains.
     */
    'field_map' => [
        'first_name'  => 'First_Name',
        'last_name'   => 'Last_Name',
        'email'       => 'Email',
        'phone'       => 'Phone',
        'company'     => 'Company',
        'message'     => 'Description',
        'service'     => 'Type_of_Service',
        'lead_source' => 'Lead_Source',
        'user_id'     => 'User_ID',
    ],
];
