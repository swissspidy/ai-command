<?php

namespace WP_CLI\AiCommand;

use WP_CLI;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$ai_command_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $ai_command_autoloader ) ) {
	require_once $ai_command_autoloader;
}

WP_CLI::add_command( 'ai', AiCommand::class );


if(!function_exists('WP_CLI\AiCommand\tome_custom_log')) {
	function tome_custom_log( $message, $data = '' ) {

    $log = trailingslashit( \dirname(__FILE__) ) . 'log/';
    if ( ! is_dir( $log ) ) {
        mkdir( $log );
    }

    $file = $log . date( 'Y-m-d' ) . '.log';
    if ( ! is_file( $file ) ) {
        file_put_contents( $file, '' );
    }
    if ( ! empty( $data ) ) {
        $message = array( $message => $data );
    }
    $data_string = print_r( $message, true ) . "\n";
    file_put_contents( $file, $data_string, FILE_APPEND );
}
}
