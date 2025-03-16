<?php

namespace WP_CLI\AiCommand\MCP;

use Exception;
use Felix_Arntz\AI_Services\Services\API\Enums\AI_Capability;
use Felix_Arntz\AI_Services\Services\API\Enums\Content_Role;
use Felix_Arntz\AI_Services\Services\API\Helpers;
use Felix_Arntz\AI_Services\Services\API\Types\Blob;
use Felix_Arntz\AI_Services\Services\API\Types\Content;
use Felix_Arntz\AI_Services\Services\API\Types\Parts;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\File_Data_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Function_Call_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Inline_Data_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Text_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Text_Generation_Config;
use Felix_Arntz\AI_Services\Services\API\Types\Tools;
use WP_CLI;

class Client {

	private $server; // Instance of MCPServer

	public function __construct( Server $server ) {
		$this->server = $server;
	}

	public function send_request( $method, $params = [] ) {
		$request = [
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => uniqid( '', true ), // Generate a unique ID for each request
		];

		$request_data  = json_encode( $request );
		$response_data = $this->server->process_request( $request_data );
		$response      = json_decode( $response_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( 'Invalid JSON response: ' . json_last_error_msg() );
		}

		if ( isset( $response['error'] ) ) {
			throw new Exception( 'JSON-RPC Error: ' . $response['error']['message'], $response['error']['code'] );
		}

		return $response['result'];
	}

	public function __call( $name, $arguments ) {
		// Magic method for calling any method
		return $this->send_request( $name, $arguments[0] ?? [] );
	}

	public function list_resources() {
		return $this->send_request( 'resources/list' );
	}

	public function read_resource( $uri ) {
		return $this->send_request( 'resources/read', [ 'uri' => $uri ] );
	}

	// Must not have the same name as the tool, otherwise it takes precedence.
	public function get_image_from_ai_service( string $prompt ) {
		// See https://github.com/felixarntz/ai-services/issues/25.
		add_filter(
			'map_meta_cap',
			static function () {
				return [ 'exist' ];
			}
		);

		try {
			$service    = ai_services()->get_available_service(
				[
					'capabilities' => [
						AI_Capability::IMAGE_GENERATION,
					],
				]
			);
			$candidates = $service
				->get_model(
					[
						'feature'      => 'image-generation',
						'capabilities' => [
							AI_Capability::IMAGE_GENERATION,
						],
					]
				)
				->generate_image( $prompt );

		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$image_url = '';
		foreach ( $candidates->get( 0 )->get_content()->get_parts() as $part ) {
			if ( $part instanceof Inline_Data_Part ) {
				$image_url  = $part->get_base64_data(); // Data URL.
				$image_blob = Helpers::base64_data_url_to_blob( $image_url );

				if ( $image_blob ) {
					$filename  = tempnam( '/tmp', 'ai-generated-image' );
					$parts     = explode( '/', $part->get_mime_type() );
					$extension = $parts[1];
					rename( $filename, $filename . '.' . $extension );
					$filename .= '.' . $extension;

					file_put_contents( $filename, $image_blob->get_binary_data() );

					$image_url = $filename;
				}

				break;
			}

			if ( $part instanceof File_Data_Part ) {
				$image_url = $part->get_file_uri(); // Actual URL. May have limited TTL (often 1 hour).
				// TODO: Save as file or so.
				break;
			}
		}

		// See https://github.com/felixarntz/ai-services/blob/main/docs/Accessing-AI-Services-in-PHP.md for further processing.

		WP_CLI::debug( "Generated image: $image_url", 'ai' );

		return $image_url;
	}

	public function call_ai_service_with_prompt( string $prompt ) {
		\WP_CLI::debug( "Prompt: {$prompt}", 'mcp_server' );
		$parts = new Parts();
		$parts->add_text_part( $prompt );

		$contents = [
			new Content( Content_Role::USER, $parts ),
		];

//		$parts = new Parts();
//		$parts->add_inline_data_part(
//			'image/png',
//			Helpers::blob_to_base64_data_url( new Blob( file_get_contents( '/private/tmp/ai-generated-imaget1sjmomi30i31C1YtZy.png' ), 'image/png' ) ),
//		);
//
//		$contents[] = $parts;

		return $this->call_ai_service( $contents );
	}

	private function call_ai_service( $contents ) {
		// See https://github.com/felixarntz/ai-services/issues/25.
		add_filter(
			'map_meta_cap',
			static function () {
				return [ 'exist' ];
			}
		);

		$capabilities = $this->get_capabilities();

		$function_declarations = [];

		foreach ( $capabilities['methods'] ?? [] as $tool ) {
			$function_declarations[] = [
				'name'        => $tool['name'],
				'description' => $tool['description'] ?? '', // Provide a description
				'parameters'  => $tool['inputSchema'] ?? [], // Provide the inputSchema
			];
		}

		$new_contents = $contents;

		$tools = new Tools();
		$tools->add_function_declarations_tool( $function_declarations );

		try {
			$service = ai_services()->get_available_service(
				[
					'capabilities' => [
						AI_Capability::MULTIMODAL_INPUT,
						AI_Capability::TEXT_GENERATION,
						AI_Capability::FUNCTION_CALLING,
					],
				]
			);

			\WP_CLI::debug( 'Making request...' . print_r( $contents, true ), 'ai' );

			// if ( $service->get_service_slug() === 'openai' ) {
			// 	$model = 'gpt-4o';
			// } else {
			// 	$model = 'gemini-2.0-flash-exp';
			// }

			$candidates = $service
				->get_model(
					[
						'feature'          => 'text-generation',
						// 'model'            => $model,
						//                      'tools'        => $tools,
							'capabilities' => [
								AI_Capability::MULTIMODAL_INPUT,
								AI_Capability::TEXT_GENERATION,
						//                          AI_Capability::FUNCTION_CALLING,
							],
						'generationConfig' => Text_Generation_Config::from_array(
							array(
								'responseModalities' => array(
									'Text',
									'Image',
								),
							)
						),
					]
				)
				->generate_text( $contents );

			$text = '';
			foreach ( $candidates->get( 0 )->get_content()->get_parts() as $part ) {
				if ( $part instanceof Text_Part ) {
					if ( '' !== $text ) {
						$text .= "\n\n";
					}
					$text .= $part->get_text();
				} elseif ( $part instanceof Function_Call_Part ) {
					$function_result = $this->{$part->get_name()}( $part->get_args() );

					// Capture the function name here
					$function_name = $part->get_name();
					echo "Output generated with the '$function_name' tool:\n"; // Log the function name

					// Odd limitation of add_function_response_part().
					if ( ! is_array( $function_result ) ) {
						$function_result = [ $function_result ];
					}

					$function_result = [ 'result' => $function_result ];

					$parts = new Parts();
					$parts->add_function_call_part( $part->get_id(), $part->get_name(), $part->get_args() );
					$new_contents[] = new Content( Content_Role::MODEL, $parts );

					$parts = new Parts();
					$parts->add_function_response_part( $part->get_id(), $part->get_name(), $function_result );
					$content        = new Content( Content_Role::USER, $parts );
					$new_contents[] = $content;
				} elseif ( $part instanceof Inline_Data_Part ) {
					$image_url  = $part->get_base64_data(); // Data URL.
					$image_blob = Helpers::base64_data_url_to_blob( $image_url );

					if ( $image_blob ) {
						$filename  = tempnam( '/tmp', 'ai-generated-image' );
						$parts     = explode( '/', $part->get_mime_type() );
						$extension = $parts[1];
						rename( $filename, $filename . '.' . $extension );
						$filename .= '.' . $extension;

						file_put_contents( $filename, $image_blob->get_binary_data() );

						$image_url = $filename;
					} else {
						$binary_data = base64_decode( $image_url );
						if ( false !== $binary_data ) {
							$image_blob = new Blob( $binary_data, $part->get_mime_type() );

							$filename  = tempnam( '/tmp', 'ai-generated-image' );
							$parts     = explode( '/', $part->get_mime_type() );
							$extension = $parts[1];
							rename( $filename, $filename . '.' . $extension );
							$filename .= '.' . $extension;

							file_put_contents( $filename, $image_blob->get_binary_data() );

							$image_url = $filename;
						}
					}

					$text .= "Generated image: $image_url\n";

					break;
				}

				if ( $part instanceof File_Data_Part ) {
					$image_url = $part->get_file_uri(); // Actual URL. May have limited TTL (often 1 hour).
					// TODO: Save as file or so.
					break;
				}
			}

			if ( $new_contents !== $contents ) {
				return $this->call_ai_service( $new_contents );
			}

			// Keep the session open to continue chatting.

			WP_CLI::line( $text );

			$response = \cli\prompt( '', false, '' );

			$parts = new Parts();
			$parts->add_text_part( $response );
			$content        = new Content( Content_Role::USER, $parts );
			$new_contents[] = $content;
			return $this->call_ai_service( $new_contents );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}
}
