<?php

namespace WP_CLI\AiCommand;

use WP_CLI\AiCommand\ToolRepository\CollectionToolRepository;
use WP_CLI\AiCommand\Tools\FileTools;
use WP_CLI\AiCommand\Tools\URLTools;
use WP_CLI;
use WP_CLI_Command;
use WP_Community_Events;
use WP_Error;

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

	public function __construct(
		private CollectionToolRepository $tools,
		private WP_CLI\AiCommand\MCP\Server $server,
		private WP_CLI\AiCommand\MCP\Client $client
	) {
		parent::__construct();
	}

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
		$this->register_tools($this->server);
		$this->register_resources($this->server);

		$result = $this->client->call_ai_service_with_prompt( $args[0] );

		WP_CLI::success( $result );
	}

	// Register tools for AI processing
	private function register_tools($server) : void {
		$filters = apply_filters( 'wp_cli/ai_command/command/filters', [] );

		foreach( $this->tools->find_all( $filters ) as $tool ) {
			$server->register_tool( $tool->get_data() );
		}

		$this->register_media_resources($server);

		return;

		new FileTools( $server );
		new URLTools( $server );
	}

	// Register resources for AI access
	private function register_resources( $server ) {
		// Register Users resource
		$server->register_resource(
			[
				'name'        => 'users',
				'uri'         => 'data://users',
				'description' => 'List of users',
				'mimeType'    => 'application/json',
				'dataKey'     => 'users', // Data will be fetched from 'users'
			]
		);

		// Register Product Catalog resource
		$server->register_resource(
			[
				'name'        => 'product_catalog',
				'uri'         => 'file://./products.json',
				'description' => 'Product catalog',
				'mimeType'    => 'application/json',
				'filePath'    => './products.json', // Data will be fetched from products.json
			]
		);
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

			$server->register_resource(
				[
					'name'        => 'media_' . $media_id,
					'uri'         => 'media://' . $media_id,
					'description' => $media_title,
					'mimeType'    => $media_type,
					'callable'    => function () use ( $media_id, $media_url, $media_type ) {
						$data = [
							'id'        => $media_id,
							'url'       => $media_url,
							'filepath'  => get_attached_file( $media_id ),
							'alt'       => get_post_meta( $media_id, '_wp_attachment_image_alt', true ),
							'mime_type' => $media_type,
							'metadata'  => wp_get_attachment_metadata( $media_id ),
						];

						return $data;
					},
				]
			);
		}

		// Also register a media collection resource
		$server->register_resource(
			[
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
				},
			]
		);
	}
}
