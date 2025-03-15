<?php

namespace WP_CLI\AiCommand;

use WP_CLI;
use WP_CLI_Command;

/**
 *
 * Resources: File-like data that can be read by clients (like API responses or file contents)
 * Tools: Functions that can be called by the LLM (with user approval)
 * Prompts: Pre-written templates that help users accomplish specific tasks
 *
 * MCP follows a client-server architecture where:
 *
 * Hosts are LLM applications (like Claude Desktop or IDEs) that initiate connections
 * Clients maintain 1:1 connections with servers, inside the host application
 * Servers provide context, tools, and prompts to clients
 */
class AiCommand extends WP_CLI_Command {
	/**
	 * Greets the world.
	 *
	 * ## OPTIONS
	 *
	 *  <prompt>
	 *  : AI prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Greet the world.
	 *     $ wp ai "What are the titles of my last three posts?"
	 *     Success: Hello World!
	 *
	 *     # Greet the world.
	 *     $ wp ai "create 10 test posts about swiss recipes and include generated featured images"
	 *     Success: Hello World!
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Indexed array of positional arguments.
	 * @param array $assoc_args Associative array of associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$server = new MCP\Server();
		$client = new MCP\Client($server);

		$this->register_tools($server, $client);

		$this->register_resources($server);

		$result = $client->call_ai_service_with_prompt( $args[0] );

		WP_CLI::success( $result );
	}

	// Register tools for AI processing
	private function register_tools($server, $client) {
		$server->register_tool(
			[
				'name'        => 'calculate_total',
				'description' => 'Calculates the total price.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'price'    => [
							'type'        => 'integer',
							'description' => 'The price of the item.',
						],
						'quantity' => [
							'type'        => 'integer',
							'description' => 'The quantity of items.',
						],
					],
					'required'   => [ 'price', 'quantity' ],
				],
				'callable'    => function ( $params ) {
					$price    = $params['price'] ?? 0;
					$quantity = $params['quantity'] ?? 1;

					return $price * $quantity;
				},
			]
		);

		$server->register_tool(
			[
				'name'        => 'greet',
				'description' => 'Greets the user.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'name' => [
							'type'        => 'string',
							'description' => 'The name of the user.',
						],
					],
					'required'   => [ 'name' ],
				],
				'callable'    => function ( $params ) {
					return 'Hello, ' . $params['name'] . '!';
				},
			]
		);

		$server->register_tool(
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
				'callable'    => function ( $params ) use ( $client ) {
					return $client->get_image_from_ai_service( $params['prompt'] );
				},
			]
		);

		$server->register_tool(
			[
				'name'        => 'flush_object_cache',
				'description' => 'Flushes the WordPress object cache, removing all cached objects.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'enable' => [
							'type'        => 'boolean',
							'description' => 'True to enable maintenance mode, false to disable it.',
						],
					],
					'required'   => [],
				],
				'callable'    => function ( $params ) {
					$result = wp_cache_flush();
					return $result ? 'Object cache successfully flushed' : 'Failed to flush object cache';
				},
			]
		);
	}

	// Register resources for AI access
	private function register_resources($server) {
		// Register Users resource
		$server->register_resource([
				'name'        => 'users',
				'uri'         => 'data://users',
				'description' => 'List of users',
				'mimeType'    => 'application/json',
				'dataKey'     => 'users', // Data will be fetched from 'users'
		]);

		// Register Product Catalog resource
		$server->register_resource([
				'name'        => 'product_catalog',
				'uri'         => 'file://./products.json',
				'description' => 'Product catalog',
				'mimeType'    => 'application/json',
				'filePath'    => './products.json', // Data will be fetched from products.json
		]);
	}
}
