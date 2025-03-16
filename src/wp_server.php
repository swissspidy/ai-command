<?php

// A basic example server with a list of prompts for testing

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
use Mcp\Types\ToolInputProperties;
use Mcp\Types\ToolInputSchema;

// Create a server instance
$server = new Server( 'wp-server' );

$tools = [
	[
		'tool'         =>
			new Tool(
				'add-numbers',
				new ToolInputSchema(
					ToolInputProperties::fromArray(
						[
							'num1' => [
								'type'        => 'number',
								'description' => 'First number',
							],
							'num2' => [
								'type'        => 'number',
								'description' => 'Second number',
							],
						]
					),
					[ 'num1', 'num2' ]
				),
				'Adds two numbers together'
			),
		'callback' => static function ( $arguments ) {
			// Validate and convert arguments to numbers
			$num1 = $arguments['num1'];
			$num2 = $arguments['num2'];

			$sum = $num1 + $num2;
			return new CallToolResult(
			content: [
			new TextContent(
			text: "The sum of {$num1} and {$num2} is {$sum}"
			),
			]
			);
		},
	],
	[
		'tool'         =>
			new Tool(
				'greet-user',
				new ToolInputSchema(
					ToolInputProperties::fromArray(
						[
							'name' => [
								'type'        => 'string',
								'description' => 'Name',
							],
						]
					),
					[ 'name' ]
				),
				'Greet someone'
			),
		'callback' => static function ( $arguments ) {
			$name = $arguments['name'];
			return new CallToolResult(
			content: [
			new TextContent(
			text: "Hello my friend, $name"
			),
			]
			);
		},
	],
];

function register_tool( array $tool_definition ): void {
	$name         = $tool_definition['name'];
	$callable     = $tool_definition['callable'];
	$description  = $tool_definition['description'] ?? null;
	$input_schema = $tool_definition['inputSchema'] ?? null;

	$tools[ $name ] = [
		'tool'         =>
			new Tool(
				$name,
				$input_schema,
				$description
			),
		'callback' => $callable,
	];
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
		foreach ( $tools as $tool ) {
			if ( $tool['tool']->name === $params->name ) {
				$found_tool = $tool;
				break;
			}
		}

		if ( ! $found_tool ) {
			throw new InvalidArgumentException( "Unknown tool: {$params->name}" );
		}

		return call_user_func( $found_tool['callback'], $params->arguments );
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
			contents: [
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
$initOptions = $server->createInitializationOptions();
$runner      = new ServerRunner( $server, $initOptions );
$runner->run();
