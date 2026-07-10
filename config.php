<?php 

define('ROOT_PATH', dirname(dirname(__FILE__)).'/');
define('FRAMEWORK_PATH', dirname(__FILE__).'/');
define('CLASSES_PATH', FRAMEWORK_PATH.'classes/');
define('API_SERVICES_PATH', dirname(dirname(__FILE__)).'/api_services/');
define('MAIL_TPL_PATH', FRAMEWORK_PATH.'mail_templates/');

define('LOG_DIR_PATH', FRAMEWORK_PATH.'log/');

// Set Timezone Settinngs and take TIMESTAMP
date_default_timezone_set('Asia/Kolkata');
define('TIME_NOW', time());


// DEBUG System
define('MB_LOG_OFF'   , 0);
define('MB_LOG_SCREEN', 1);
define('MB_LOG_FILE'  , 2);

if(isset($_GET['as21l4dk7fjlkasjdflk']))
{
	define('MB_SYS_LOG',   MB_LOG_SCREEN);
	define('MB_DB_LOG',    MB_LOG_SCREEN);
	define('MB_SMS_LOG',   MB_LOG_SCREEN);
	define('MB_EMAIL_LOG', MB_LOG_SCREEN);

	define('USE_TESTDATA', true);
}
else
{
	// Disabled by Arnav Lohiya due to SYS_LOG permissions warning noise.
	define('MB_SYS_LOG',   MB_LOG_OFF);
	define('MB_DB_LOG',    MB_LOG_OFF);
	define('MB_SMS_LOG',   MB_LOG_FILE);
	define('MB_EMAIL_LOG', MB_LOG_OFF);

	define('USE_TESTDATA', false);
}



$config = array(
	// 'site_url' => 'http://rexecom.co',
	'site_url' => 'http://192.168.0.120/rexecom_retail',
	'session_name' => 'SQIEKCJ',
	'city_cookie_name' => 'CT3CK#9',
	'admin_session_name' => 'AD2O#DKW',
	'app_uid_salt' => '-rexecom#@18%299%35',
	'notification_logo_url' => 'http://loagma.com/img/retail_green_logo.jpg',

	'admin_types' => array(
		'admin', 'superadmin',
		'partner',
		'accountant', 'manager',
		'agent',
		'b2b'
	),

	// Unit Type
	'read_only_unit_type' => array(1),

	'public_apis' => array(
		'app/regsiter',
		'user/login',
	),

	'category_image_slug_depth' => 2,
	'category_image_root_directory' => 'img/category/',

	'category_defuault_image_url' => 'img/default.avatar.png',

	// Varirety
	// Increament this config value if you want to reset all varieties cache at once.
	'variety_config_cache_version' => 2, 
	
	// Variety Types implemented in backend
	'variety_types' => array(
		'plain_text',
		'color_text',
		'image_options',
	),

	// Variety Types specified here must have first item as Image Source path as the first element in "attr_data" array.
	'variety_types_with_image' => array(
		'image_options',
	),

	'unit_types_old' => array(
		'kg' => 'kg',
		'gm' => 'gm',
		'ml' => 'ml',
		'litre' => 'ltr',
		'dozen' => 'dozen(s)',
		'piece' => 'zpiece(s)',
		'pack' => 'pack',
		'nos' => 'Nos',
	    'bunch' => 'Buncch',
	    'box' => 'Box',
		'25bag' => '25kg Bag',
		'30bag' => '30kg Bag',
		'50bag' => '50kg Bag',
	),
	
	'unit_types' => array(
        'Nos' => 'Nos',
        '5 Ltr' => '5 Ltr',
        '10 Kgs' => '10 Kgs',
        '15 Ltr' => '15 Ltr',
        '15 Kg' => '15 Kg',
        '100 Kgs' => '100 Kgs',
        '5 Kg' => '5 Kg',
        '20 Kg' => '20 Kg',
        '25 Kg' => '25 Kg',
        '30 Kg' => '30 Kg',
        '40 Kg' => '40 Kg',
        '50 kg' => '50 kg',
        '60 kg' => '60 kg',
        '1 kg' => '1 kg',
        '24 Kg' => '24 Kg',
        '200gm' => '200gm',
        '500 Gms.' => '500 Gms.',
        '250 Gms.' => '250 Gms.',
        '5 Ltr 1 Jar' => '5 Ltr 1 Jar',
        '10 Nos' => '10 Nos',
        '5 Nos' => '5 Nos',
        '3 Nos' => '3 Nos',
        '12 Nos' => '12 Nos',
        '16 x 1 Ltr Cs' => '16 x 1 Ltr Cs',
        '4 x 5 Ltr Cs' => '4 x 5 Ltr Cs',
        '500 ml x 32 Cs' => '500 ml x 32 Cs',
        '9 x 2 Ltr Cs' => '9 x 2 Ltr Cs',
        '35 Kg' => '35 Kg',
        '26 Kg' => '26 Kg',
        '100 Gm' => '100 Gm',
        'kg' => 'kg',
        'gm' => 'gm',
        '500gm' => '500gm',
        '250gm' => '250gm',
        '200gm' => '200gm',
        '150gm' => '150gm',
        '100gm' => '100gm',
        '50gm' => '50gm',
        'ml' => 'ml',
        '100ml' => '100ml',
        '200ml' => '200ml',
        '250ml' => '250ml',
        '500ml' => '500ml',
        'litre' => 'ltr',
        'dozen' => 'dozen(s)',
        'pack' => 'pack',
        'nos' => 'Nos',
        'pack2' => 'Pack of 2',
        'pack3' => 'Pack of 3',
        'pack4' => 'Pack of 4',
        'pack5' => 'Pack of 5',
        'pack6' => 'Pack of 6',
        'pack9' => 'Pack of 9',
        'pack10' => 'Pack of 10',
        'pack12' => 'Pack of 12',
        'pack15' => 'Pack of 15',
        'pack16' => 'Pack of 16',
        'pack20' => 'Pack of 20',
        'pack24' => 'Pack of 24',
        'pack40' => 'Pack of 40',
        'box' => 'Box',
        '25bag' => '25kg Bag',
        '30bag' => '30kg Bag',
        '50bag' => '50kg Bag',
        'bag' => "Bag",
        'piece' => 'zpiece(s)',
        'bunch' => 'Bunch',
        'tin' => "Tin",
        'pouch' => "Pouch",
        "cs" => "Cs",
        "barrel" => "Barrel",
        "jar" => "Jar",
    ),

	'unit_factors_old' => array(
		'kg' => 1000,
		'gm' => 1,
		'ml' => 1,
		'litre' => 1000,
		'dozen' => 12,
		'piece' => 1,
		'pack' => 1,
		'nos' => 1,
		'bunch' => 1,
	    'box' => 1,
		'25bag' => 25000,
		'30bag' => 30000,
		'50bag' => 50000,
		'bag' => 1,
	),
	
	'unit_factors' => array(
	    "Nos" => 1,
        "5 Ltr" => 4.55,
        "10 Kgs" => 10,
        "15 Ltr" => 13.65,
        "15 Kg" => 15,
        "100 Kgs" => 100,
        "5 Kg" => 5,
        "20 Kg" => 20,
        "25 Kg" => 25,
        "30 Kg" => 30,
        "40 Kg" => 40,
        "50 kg" => 50,
        "60 kg" => 60,
        "1 kg" => 1,
        '24 Kg' => 24,
        "200gm" => 0.2,
        "500 Gms." => 0.5,
        "250 Gms." => 0.25,
        "5 Ltr 1 Jar" => 0.25,
        "10 Nos" => 10,
        "5 Nos" => 5,
        "3 Nos" => 3,
        "12 Nos" => 12,
        "16 x 1 Ltr Cs" => 14.56,
        "4 x 5 Ltr Cs" => 18.2,
        "500 ml x 32 Cs" => 14.56,
        "9 x 2 Ltr Cs" => 16.38,
        "35 Kg" => 35,
        "26 Kg" => 26,
        '100 Gm' => 0.1,
		'kg' => 1,
		'gm' => 0.001,
		'500gm' => 0.5,
		'250gm' => 0.250,
		'200gm' => 0.200,
		'150gm' => 0.150,
		'100gm' => 0.1,
		'50gm' => 0.05,
		'ml' => 0.001,
		'100ml' => 0.100,
		'200ml' => 0.200,
		'250ml' => 0.250,
		'500ml' => 0.500,
		'litre' => 1,
		'dozen' => 12,
		'pack' => 1,
		'nos' => 1,
	    'pack2' => 2,
	    'pack3' => 3,
	    'pack4' => 4,
	    'pack5' => 5,
	    'pack6' => 6,
	    'pack9' => 9,
	    'pack10' => 10,
	    'pack12' => 12,
	    'pack15' => 15,
	    'pack16' => 16,
	    'pack20' => 20,
	    'pack24' => 24,
	    'pack40' => 40,
	    'box' => 1,
		'25bag' => 25,
		'30bag' => 30,
		'50bag' => 50,
		'piece' =>1,
		'bunch' => 1,
		'tin' => 1,
		'pouch' => 1,
		"cs" => 1,
		"barrel" => 1,
		"jar" => 1,
		"bag" => 1,
	),

	// Photos
	'photo_root_directory' => 'img/product/',
	// Product Photos Slug level
	'photo_slug_level' => 3,


	// OTP System
	'otp_purpose' => array(
		'mobile_verification', 
	),
	
	//Notifications
	'out_of_stock_notify_numbers' => array(
	    '9182357476',//Deepthi
	    '8019500007',
	    '9090695050',
	    '7064800681'//Rajesh
	    ),
//'8019500007',
	   // '9090695050'
	// Pagination
	'products_list_page_size' => 1000,
	'orders_list_page_size' => 10,
	
	'sellers_list_page_size' => 20,
	'favorites_list_page_size' => 20,
	'feedback_topic_list_page_size' => 10,
	'feedback_comment_list_page_size' => 20,
	'search_result_products_page_size' => 20,


	// Orders
	'default_order_state' => 'pending', //pending, processing, registered, dispatched, delivered, cancelled
	'deliver_info_allowed_keys' => array(
		'name', 'address', 'contactno', 'comment', 'couponcode', 'latitude', 'longitude',
	),

	// SMTP Settings
	/* 'smtp_host'      => 'ssl://smtp.gmail.com',
	'smtp_user'      => 'rexecommerce@gmail.com',
	'smtp_user-name' => 'RexEcom Enterprise',
	'smtp_password'  => 'amol9191@9191',
	'smtp_secure'    => 'ssl',
	'smtp_port'      => 465, */

	'smtp_host'      => 'sg3plcpnl0076.prod.sin3.secureserver.net',
	'smtp_user'      => 'no-reply@rexecomretail.com',
	'smtp_user-name' => 'RexEcom Retail',
	'smtp_password'  => 'k9N#ks0dx9l',
	'smtp_secure'    => 'ssl',
	'smtp_port'      => 465,
	

	// PayUMoney Details : Live
	'merchant_key' => 'HdUQBQrg',
	'payu_salt' => 'YSdWMls3G1',
	'payu_api_url' => 'https://secure.payu.in',  // https://test.payu.in

	// PayUMoney Details : TEST
	/*'merchant_key' => 'rjQUPktU',
	'payu_salt' => 'e5iIg1jwi8',
	'payu_api_url' => 'https://test.payu.in',  // https://secure.payu.in*/
	// 5123456789012346
	// 05/20
	// 123


);



if(!isset($cache_rebuild_stack))
{
	$cache_rebuild_stack = array();
}

if(strpos($_SERVER['HTTP_HOST'], 'loagma.com') !== false)
{
	$config['site_url'] = 'https://loagma.com';
}


?>
