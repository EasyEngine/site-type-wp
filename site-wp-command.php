<?php

if ( ! defined ( 'SITE_WP_TEMPLATE_ROOT' ) ) {
	define( 'SITE_WP_TEMPLATE_ROOT', __DIR__ . '/templates' );
}

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'wp', 'Site_WP_Command' );
