<?php
/*
Plugin Name: Comgate platební brána pro Easy Digital Downloads
Plugin URL: https://cleverstart.cz
Description: Platební brána Comgate pro plugin Easy Digital Downloads
Version: 1.0.12
Author: Pavel Janíček
Author URI: https://cleverstart.cz
*/

require 'class_edd_comgate.php';
require __DIR__ . '/vendor/autoload.php';

$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://plugins.cleverstart.cz/?action=get_metadata&slug=eddcomgate',
	__FILE__, //Full path to the main plugin file or functions.php.
	'eddcomgate'
);

function cleverstart_comgate_init(){
  new EDD_Comgate();
}

cleverstart_comgate_init();
