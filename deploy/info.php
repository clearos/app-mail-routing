<?php

/////////////////////////////////////////////////////////////////////////////
// General information
/////////////////////////////////////////////////////////////////////////////

$app['basename'] = 'mail_routing';
$app['version'] = '2.3.0';
$app['release'] = '1';
$app['vendor'] = 'ClearFoundation';
$app['packager'] = 'ClearFoundation';
$app['license'] = 'GPLv3';
$app['license_core'] = 'LGPLv3';
$app['description'] = lang('mail_routing_app_description');

/////////////////////////////////////////////////////////////////////////////
// App name and categories
/////////////////////////////////////////////////////////////////////////////

$app['name'] = lang('mail_routing_app_name');
$app['category'] = lang('base_category_server');
$app['subcategory'] = lang('base_subcategory_mail');
$app['menu_enabled'] = FALSE;

/////////////////////////////////////////////////////////////////////////////
// Packaging
/////////////////////////////////////////////////////////////////////////////

$app['core_only'] =  TRUE;

$app['core_requires'] = array(
    'app-network-core',
    'app-smtp-core',
    'cyrus-sasl-plain',
    'php-pear-Net-LMTP',
    'php-pear-Net-SMTP >= 1.7.1',
);

$app['core_directory_manifest'] = array(
    '/var/clearos/mail_routing' => array(),
    '/var/clearos/mail_routing/backup' => array(),
    '/var/spool/filter' => array(),
);

$app['core_file_manifest'] = array(
    'mailprefilter' => array(
        'target' => '/usr/sbin/mailprefilter',
        'mode' => '0755',
        'owner' => 'root',
        'group' => 'root',
    ),
    'mailpostfilter' => array(
        'target' => '/usr/sbin/mailpostfilter',
        'mode' => '0755',
        'owner' => 'root',
        'group' => 'root',
    ),
);
