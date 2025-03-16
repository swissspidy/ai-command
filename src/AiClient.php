<?php

namespace WP_CLI\AiCommand;

use Felix_Arntz\AI_Services\Services\API\Enums\AI_Capability;
use Felix_Arntz\AI_Services\Services\API\Enums\Content_Role;
use Felix_Arntz\AI_Services\Services\API\Helpers;
use Felix_Arntz\AI_Services\Services\API\Types\Content;
use Felix_Arntz\AI_Services\Services\API\Types\Parts;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Function_Call_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Inline_Data_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Text_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Tools;
use Mcp\Client\Client;
use Mcp\Client\ClientSession;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\Tool;
use WP_CLI;

class AiClient {
	protected array $tools = [];
	private array $sessions;


	public function __construct() {
		$this->sessions = $this->get_sessions();

		$function_declarations = [];

		foreach ( $this->sessions as $session ) {
			/**
			 * @var Tool $mcp_tool
			 */
			foreach ( $session->listTools()->tools as $mcp_tool ) {
				$parameters = json_decode( json_encode( $mcp_tool->inputSchema->jsonSerialize() ), true );
				unset( $parameters['additionalProperties'], $parameters['$schema'] );

				// Not having any properties doesn't seem to work.
				if ( empty( $parameters['properties'] ) ) {
					$parameters['properties'] = [
						'dummy' => [
							'type' => 'string',
						],
					];
				}

				if ( $mcp_tool->name === 'edit_file' || $mcp_tool->name === 'search_files' ) {
					continue;
				}

				$function_declarations[] = [
					'name'        => $mcp_tool->name,
					'description' => $mcp_tool->description,
					'parameters'  => $parameters,
				];
			}
		}

		$this->tools = $function_declarations;
	}

	public function get_servers() {
		$config_file  = __DIR__ . '/../ai-command.json';
		$json_content = file_get_contents( realpath( $config_file ) );
		return json_decode( $json_content, true );
	}

	/**
	 * @return ClientSession[]
	 */
	public function get_sessions(): array {
		$sessions = [];
		foreach ( $this->get_servers() as $server ) {
			$sessions[] = $this->get_session( $server );
		}

		return $sessions;
	}

	public function get_session( $server ) {
		$serverParams = new StdioServerParameters(
			...$server
		);

		return ( new Client() )->connect(
			commandOrUrl: $serverParams->getCommand(),
			args: $serverParams->getArgs(),
			env: $serverParams->getEnv()
		);
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

		$new_contents = $contents;

		$tools = new Tools();
		$tools->add_function_declarations_tool( $this->tools );

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

			if ( $service->get_service_slug() === 'openai' ) {
				$model = 'gpt-4o';
			} else {
				$model = 'gemini-2.0-flash';
			}

			$candidates = $service
				->get_model(
					[
						'feature'      => 'text-generation',
						'model'        => $model,
						'tools'        => $tools,
						'capabilities' => [
							//                          AI_Capability::MULTIMODAL_INPUT,
							AI_Capability::TEXT_GENERATION,
							AI_Capability::FUNCTION_CALLING,
						],
					],
					[
						'options' => [
							'timeout' => 6000,
						],
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
					$function_result = [
						'error' => 'unknown tool',
					];

					// Find the right tool from the right server.
					foreach ( $this->sessions as $session ) {
						foreach ( $session->listTools()->tools as $mcp_tool ) {
							if ( $part->get_name() === $mcp_tool->name ) {
								$result          = $session->callTool( $part->get_name(), $part->get_args() );
								$function_result = json_decode( json_encode( $result->content[0] ), true );
								$function_result = [
									'result' => $function_result['text'],
								];
								break 2;
							}
						}
					}

					$function_name = $part->get_name();
					echo "Output generated with the '$function_name' tool:\n";

					$parts = new Parts();
					$parts->add_function_call_part( $part->get_id(), $part->get_name(), $part->get_args() );
					$new_contents[] = new Content( Content_Role::MODEL, $parts );

					$parts = new Parts();
					$parts->add_function_response_part( $part->get_id(), $part->get_name(), $function_result );
					$content        = new Content( Content_Role::USER, $parts );
					$new_contents[] = $content;
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
