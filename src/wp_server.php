<?php

ini_set( 'max_execution_time', '0' );

require __DIR__ . '/../vendor/autoload.php';


use Mcp\Server\Server;
use Mcp\Server\ServerRunner;
use Mcp\Types\CallToolResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Resource;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use Mcp\Types\Tool;
use Mcp\Types\ToolInputSchema;
use WP_CLI\AiCommand\MapRESTtoMCP;
use WP_CLI\AiCommand\WpAiClient;

// Load WordPress.
if ( isset( $argv[1] ) ) {
	$wp_path = $argv[1];
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $wp_path . '/' );
	}
	if ( file_exists( $wp_path . '/wp-config.php' ) ) {
		require_once $wp_path . '/wp-config.php';
	}
}

// Create a server instance
$server = new Server( 'wp-server' );

$tools = [];

function register_tool( array $tool_definition ): void {
	global $tools;

	if ( ! isset( $tool_definition['name'] ) || ! is_callable( $tool_definition['callable'] ) ) {
		throw new InvalidArgumentException( "Invalid tool definition. Must be an array with 'name' and 'callable'." );
	}

	$name         = $tool_definition['name'];
	$callable     = $tool_definition['callable'];
	$description  = $tool_definition['description'] ?? null;
	$input_schema = $tool_definition['inputSchema'] ?? null;

	// TODO: This is a temporary limit.
	if ( count( $tools ) >= 128 ) {
		var_dump( 'Too many tools, max is 128' );
		return;
	}

	$tools[ $name ] = [
		'tool'        => new Tool(
			$name,
			ToolInputSchema::fromArray(
				$input_schema,
			),
			$description
		),
		'callable'    => $callable,
		'inputSchema' => $input_schema,
	];
}

register_tool(
	[
		'name'        => 'greet-user',
		'description' => 'Greet someone',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'name' => [
					'type'        => 'string',
					'description' => 'Name',
				],
			],
			'required'   => [ 'name' ],
		],
		'callable'    => static function ( $arguments ) {
			$name = $arguments['name'];

			return new CallToolResult(
				[
					new TextContent(
						"Hello my friend, $name"
					),
				]
			);
		},
	]
);

register_tool(
	[
		'name'        => 'fetch_wp_community_events',
		'description' => 'Fetches upcoming WordPress community events near a specified city or the user\'s current location. If no events are found in the exact location, nearby events within a specific radius will be considered.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'location' => [
					'type'        => 'string',
					'description' => 'City name or "near me" for auto-detected location. If no events are found in the exact location, the tool will also consider nearby events within a specified radius (default: 100 km).',
				],
			],
			'required'   => [ 'location' ],  // We only require the location
		],
		'callable'    => static function ( $params ) {
			$location_input = strtolower( trim( $params['location'] ) );

			// Manually include the WP_Community_Events class if it's not loaded
			if ( ! class_exists( 'WP_Community_Events' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-community-events.php';
			}

			$location = [
				'description' => $location_input,
			];

			$events_instance = new WP_Community_Events( 0, $location );

			// Get events from WP_Community_Events
			$events = $events_instance->get_events( $location_input );

			// Check for WP_Error
			if ( is_wp_error( $events ) ) {
				return new CallToolResult(
					[
						new TextContent(
							$events->get_error_message()
						),
					],
					true
				);
			}

			return new CallToolResult(
				[
					new TextContent(
						json_encode( $events['events'] )
					),
				]
			);
		},
	]
);

register_tool(
	[
		'name'        => 'generate_image',
		'description' => 'Generates an image.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'prompt' => [
					'type'        => 'string',
					'description' => 'The prompt for generating the image.',
				],
			],
			'required'   => [ 'prompt' ],
		],
		'callable'    => function ( $params ) {
			$client = new WpAiClient();

			return new CallToolResult(
				[
					new TextContent(
						$client->get_image_from_ai_service( $params['prompt'] )
					),
				]
			);
		},
	]
);

// WordPress REST calls
$rest_tools = new MapRESTtoMCP();

foreach ( $rest_tools->map_rest_to_mcp() as $tool ) {
		register_tool( $tool );
		// Stop after registering GET POSTS because otherwise there are timeouts.
		break;
}

// Add tool handlers
$server->registerHandler(
	'tools/list',
	function ( $params ) use ( $tools ) {
		$prepared_tools = [];
		foreach ( $tools as $tool ) {
			$prepared_tools[] = $tool['tool'];
		}
		return new ListToolsResult( $prepared_tools );
	}
);

$server->registerHandler(
	'tools/call',
	function ( $params ) use ( $tools ) {
		$found_tool = null;
		foreach ( $tools as $name => $tool ) {
			if ( $name === $params->name ) {
				$found_tool = $tool;
				break;
			}
		}

		if ( ! $found_tool ) {
			throw new InvalidArgumentException( "Unknown tool: {$params->name}" );
		}

		return call_user_func( $found_tool['callable'], $params->arguments );
	}
);

// Add resource handlers
$server->registerHandler(
	'resources/list',
	function ( $params ) {
		$resource = new Resource(
			'Greeting Text',
			'example://greeting',
			'A simple greeting message',
			'text/plain'
		);
		return new ListResourcesResult( [ $resource ] );
	}
);

$server->registerHandler(
	'resources/read',
	function ( $params ) {
		$uri = $params->uri;
		if ( $uri !== 'example://greeting' ) {
			throw new InvalidArgumentException( "Unknown resource: {$uri}" );
		}

		return new ReadResourceResult(
			[
				new TextResourceContents(
					'Hello from the example MCP server!',
					$uri,
					'text/plain'
				),
			]
		);
	}
);

// Create initialization options and run server
$options = $server->createInitializationOptions();

$runner = new ServerRunner( $server, $options );
$runner->run();
