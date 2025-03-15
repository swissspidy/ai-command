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
 	*  [--id=<id>]
	 *  : Optional ID parameter.
	 *
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

		$id = isset($assoc_args['id']) ? intval($assoc_args['id']) : null;


		$server = new MCP\Server();

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

		// Register resources:
		$server->register_resource(
			[
				'name'        => 'users',
				'uri'         => 'data://users',
				'description' => 'List of users',
				'mimeType'    => 'application/json',
				'dataKey'     => 'users', // This tells getResourceData() to look in the $data array
			]
		);

		$server->register_resource(
			[
				'name'        => 'product_catalog',
				'uri'         => 'file://./products.json',
				'description' => 'Product catalog',
				'mimeType'    => 'application/json',
				'filePath'    => './products.json', // This tells getResourceData() to read from a file
			]
		);

		$this->register_media_resources($server);


		$client = new MCP\Client( $server );

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
				'name'        => 'modify_image',
				'description' => 'Modifies the image with a given prompt.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'prompt' => [
							'type'        => 'string',
							'description' => 'The prompt for modifying the image.',
						],
						'media_uri' => [
							'type' => 'string',
							'description' => 'The uri to the media resource'
						]
					],
					'required'   => [ 'prompt', 'media_uri' ],
				],
				'callable'    => function ( $params ) use ( $client, $server ) {


					// Get the media resource data
					$media_uri = $params['media_uri'];
					$media_data = $server->get_resource_data($media_uri);


					if (!$media_data) {
						return ['error' => 'Media resource not found'];
					}

					// Extract the image URL from the media data
					$image_url = $media_data['url'] ?? null;

					if (!$image_url) {
							return ['error' => 'Image URL not found in media resource'];
					}



					// Now modify the image using the AI service
					$modified_image = $client->modify_image_from_ai_service(
							$params['prompt'],
							 $media_data['filepath']
					);

				return $modified_image;
				},
			]
		);

		$server->register_tool([
			'name'        => 'read_media',
				'description' => 'Reads the media file.',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'media_id' => [
							'type'        => 'integer',
							'description' => 'The ID of the media library.',
						],
						'media_uri' => [
							'type' => 'string',
							'description' => 'The uri to the media resource'
						]
					],
					'required'   => [ 'media_id', 'media_uri' ],
				],
				'callable'    => function ( $params ) use ( $client ) {
					return;
				},
			]);

		$result = $client->call_ai_service_with_prompt( $args[0] );

		WP_CLI::success( $result );
	}

	protected function register_media_resources( $server ) {

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => - 1,
		);

		$media_items = get_posts( $args );

		foreach ( $media_items as $media ) {

			$media_id    = $media->ID;
			$media_url   = wp_get_attachment_url( $media_id );
			$media_type  = get_post_mime_type( $media_id );
			$media_title = get_the_title( $media_id );

			$server->register_resource( [
				'name'        => 'media_' . $media_id,
				'uri'         => 'media://' . $media_id,
				'description' => $media_title,
				'mimeType'    => $media_type,
				'callable'    => function () use ( $media_id, $media_url, $media_type ) {
					$data = [
						'id'          => $media_id,
						'url'         => $media_url,
						'filepath'    => get_attached_file( $media_id ),
						'alt'         => get_post_meta( $media_id, '_wp_attachment_image_alt', true ),
						'mime_type'   => $media_type,
						'metadata'    => wp_get_attachment_metadata( $media_id ),
					];

					return $data;
				}
			] );
		}

		// Also register a media collection resource
		$server->register_resource( [
			'name'        => 'media_collection',
			'uri'         => 'data://media',
			'description' => 'Collection of all media items',
			'mimeType'    => 'application/json',
			'callable'    => function () {

				$args = array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => - 1,
					'fields'         => 'ids',
				);

				$media_ids = get_posts( $args );
				$media_map = [];

				foreach ( $media_ids as $id ) {
					$media_map[ $id ] = 'media://' . $id;
				}

				return $media_map;
			}
		] );
	}
}
