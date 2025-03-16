<?php

namespace WP_CLI\AiCommand;

use Felix_Arntz\AI_Services\Services\API\Enums\AI_Capability;
use Felix_Arntz\AI_Services\Services\API\Enums\Content_Role;
use Felix_Arntz\AI_Services\Services\API\Types\Content;
use Felix_Arntz\AI_Services\Services\API\Types\Parts;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Function_Call_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Parts\Text_Part;
use Felix_Arntz\AI_Services\Services\API\Types\Tools;
use Mcp\Client\Transport\StdioServerParameters;
use Mcp\Types\Tool;
use WP_CLI;
use WP_CLI_Command;
use Mcp\Client\Client;

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

		$result = $this->call_ai_service_with_prompt( $args[0] );

		WP_CLI::success( $result );
	}

	public function call_ai_service_with_prompt( string $prompt ) {
		$parts = new Parts();
		$parts->add_text_part( $prompt );
		$content = new Content( Content_Role::USER, $parts );

		return $this->call_ai_service( [ $content ] );
	}

	public function get_session() {
		$serverParams = new StdioServerParameters(
			command: 'php',
			args: [ __DIR__ . '/wp_server.php' ],
			env: null
		);

		$client = new Client();

		// Connect to the server using stdio transport
		return $client->connect(
			commandOrUrl: $serverParams->getCommand(),
			args: $serverParams->getArgs(),
			env: $serverParams->getEnv()
		);
	}

	private function call_ai_service( $contents ) {
		// See https://github.com/felixarntz/ai-services/issues/25.
		add_filter(
			'map_meta_cap',
			static function () {
				return [ 'exist' ];
			}
		);

		$session   = $this->get_session();
		$mcp_tools = $session->listTools();

		$function_declarations = [];

		/**
		 * @var Tool $mcp_tool
		 */
		foreach ( $mcp_tools->tools as $mcp_tool ) {
			$function_declarations[] = [
				'name'        => $mcp_tool->name,
				'description' => $mcp_tool->description,
				'parameters'  => json_decode( json_encode( $mcp_tool->inputSchema->jsonSerialize() ), true ),
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
					$result = $session->callTool( $part->get_name(), $part->get_args() );

					$function_result = json_decode( json_encode( $result->content[0] ), true );
					$function_result = [
						'result' => $function_result['text'],
					];

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
