<?php
declare(strict_types=1);

return [
    'enabled' => true,
    'accounts_domain' => 'https://accounts.zoho.com',
    'api_domain' => 'https://www.zohoapis.com',
    'client_id' => '1000.5T6TU1NOJEYKO0JMEPCDN1WPTZMT1R',
    'client_secret' => '7d0cc19132ac3dacfe378b0dda0045b350f86e40d2',
    'refresh_token' => '1000.3b93ae33d7366618f769d67b170d9a9a.854ecb3a7368f788c6c1230d9a25f6b8',
    'module' => 'Leads',
    'lead_source' => 'WorldofHawas-Shopify-Website',
    'duplicate_check_fields' => ['User_ID'],
    'user_id_source' => 'email',
    'field_map' => [
        'user_id' => 'User_ID',
        'first_name' => 'First_Name',
        'last_name' => 'Last_Name',
        'company' => 'Company',
        'phone' => 'Phone',
        'email' => 'Email',
        'lead_source' => 'Lead_Source',
        'service' => 'Type_of_Service',
        'message' => 'Description',
    ],
];
