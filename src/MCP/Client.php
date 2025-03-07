<?php

namespace WP_CLI\AiCommand\MCP;

use Exception;
use Felix_Arntz\AI_Services\Services\API\Enums\AI_Capability;
use Felix_Arntz\AI_Services\Services\API\Enums\Content_Role;
use Felix_Arntz\AI_Services\Services\API\Helpers;
use Felix_Arntz\AI_Services\Services\API\Types\Content;
use Felix_Arntz\AI_Services\Services\API\Types\Parts;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Function_Call_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Text_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Tools;

class Client {

	private $server; // Instance of MCPServer

	public function __construct( Server $server ) {
		$this->server = $server;
	}

	public function sendRequest( $method, $params = [] ) {
		$request = [
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
			'id'      => uniqid(), // Generate a unique ID for each request
		];

		$requestData  = json_encode( $request );
		$responseData = $this->server->processRequest( $requestData );
		$response     = json_decode( $responseData, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( 'Invalid JSON response: ' . json_last_error_msg() );
		}

		if ( isset( $response['error'] ) ) {
			throw new Exception( "JSON-RPC Error: " . $response['error']['message'], $response['error']['code'] );
		}

		return $response['result'];
	}

	public function __call( $name, $arguments ) { // Magic method for calling any method
		return $this->sendRequest( $name, $arguments[0] ?? [] );
	}

	public function list_resources() {
		return $this->sendRequest( 'resources/list' );
	}

	public function read_resource( $uri ) {
		return $this->sendRequest( 'resources/read', [ 'uri' => $uri ] );
	}

	public function generate_image( string $prompt ) {
		// See https://github.com/felixarntz/ai-services/issues/25.
		add_filter(
			'map_meta_cap',
			static function () {
				return [ 'exist' ];
			}
		);

		try {
			$service = ai_services()->get_available_service(
				[
					'capabilities' => [
						AI_Capability::IMAGE_GENERATION,
					]
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

			$image_url = '';
			foreach ( $candidates->get( 0 )->get_content()->get_parts() as $part ) {
				if ( $part instanceof \Felix_Arntz\AI_Services\Services\API\Types\Parts\Inline_Data_Part ) {
					$image_url = $part->get_base64_data(); // Data URL.
					break;
				}
				if ( $part instanceof \Felix_Arntz\AI_Services\Services\API\Types\Parts\File_Data_Part ) {
					$image_url = $part->get_file_uri(); // Actual URL. May have limited TTL (often 1 hour).
					break;
				}
			}

			// See https://github.com/felixarntz/ai-services/blob/main/docs/Accessing-AI-Services-in-PHP.md for further processing.

			return $image_url;
		} catch ( Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		\WP_CLI::error( 'Could not generate image.' );
	}

	public function call_ai_service_with_prompt( string $prompt ) {
		$parts = new Parts();
		$parts->add_text_part( $prompt );
		$content = new Content( Content_Role::USER, $parts );

		return $this->call_ai_service( [ $content ] );
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
				"name"        => $tool['name'],
				"description" => $tool['description'] ?? "", // Provide a description
				"parameters"  => $tool['inputSchema'] ?? [], // Provide the inputSchema
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
					]
				]
			);

			\WP_CLI::log( "Making request..." . print_r( $contents, true ) );

			$candidates = $service
				->get_model(
					[
						'feature'      => 'text-generation',
						'tools'        => $tools,
						'capabilities' => [
							AI_Capability::MULTIMODAL_INPUT,
							AI_Capability::TEXT_GENERATION,
							AI_Capability::FUNCTION_CALLING,
						],
					]
				)
				->generate_text( $contents );

			$text = '';
			foreach ( $candidates->get( 0 )->get_content()->get_parts() as $part ) {
				if ( $part instanceof Text_Part ) {
					if ( $text !== '' ) {
						$text .= "\n\n";
					}
					$text .= $part->get_text();
				} elseif ( $part instanceof Function_Call_Part ) {
					$function_result = $this->{$part->get_name()}( $part->get_args() );

					// Odd limitation of add_function_response_part().
					if ( ! is_array( $function_result ) ) {
						$function_result = [ $function_result ];
					}

					$function_result = [ 'result' => $function_result ];
					$parts = new Parts();
					$parts->add_function_response_part( $part->get_id(),$part->get_name(), $function_result );
					$content    = new Content( Content_Role::USER, $parts );
					$new_contents[] = $content;
				}
			}

			if ( $new_contents !== $contents ) {
				return $this->call_ai_service( $new_contents );
			}

			return $text;
		} catch ( Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}
}
