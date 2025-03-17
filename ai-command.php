<?php

namespace WP_CLI\AiCommand;

use WP_CLI\AiCommand\ToolRepository\CollectionToolRepository;
use WP_CLI\AiCommand\Tools\ImageTools;
use WP_CLI\AiCommand\Tools\MiscTools;
use WP_CLI\AiCommand\Tools\URLTools;
use WP_CLI\AiCommand\Tools\CommunityEvents;
use WP_CLI\AiCommand\Tools\MapRESTtoMCP;
use WP_CLI\AiCommand\Tools\MapCLItoMCP;
use WP_CLI;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$ai_command_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $ai_command_autoloader ) ) {
	require_once $ai_command_autoloader;
}

WP_CLI::add_command( 'ai', static function ( $args, $assoc_args ) {
	$server = new MCP\Server();
	$client = new MCP\Client($server);

	$tools = new ToolCollection();

	// TODO Register your tool here and add it to the collection

	$all_tools = [
		...(new ImageTools($client, $server))->get_tools(),
		...(new CommunityEvents($client))->get_tools(),
		...(new MiscTools($server))->get_tools(),
		...(new URLTools($server))->get_tools(),
		...(new MapRESTtoMCP())->map_rest_to_mcp(),
		...(new MapCLItoMCP())->map_cli_to_mcp(),

	];

		foreach ($all_tools as $tool) {
		$tools->add($tool);
	}

	$ai_command = new AiCommand(
		new CollectionToolRepository( $tools ),
		$server,
		$client
	);
	$ai_command( $args, $assoc_args );
} );
