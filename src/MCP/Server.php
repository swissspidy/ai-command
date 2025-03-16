<?php

namespace WP_CLI\AiCommand\MCP;

use Exception;
use WP_CLI;
use InvalidArgumentException;

class Server {

	private array $data      = [];
	private array $tools     = [];
	private array $resources = [];

	public function __construct() {
		// Sample data (replace with your actual data handling)
		$this->data['users']    = [
			[
				'id'    => 1,
				'name'  => 'Alice',
				'email' => 'alice@example.com',
			],
			[
				'id'    => 2,
				'name'  => 'Bob',
				'email' => 'bob@example.com',
			],
		];
		$this->data['products'] = [
			[
				'id'    => 101,
				'name'  => 'Product A',
				'price' => 20,
			],
			[
				'id'    => 102,
				'name'  => 'Product B',
				'price' => 30,
			],
		];
	}

	public function register_tool( array $tool_definition ): void {
		if ( ! isset( $tool_definition['name'] ) || ! is_callable( $tool_definition['callable'] ) ) {
			throw new InvalidArgumentException( "Invalid tool definition. Must be an array with 'name' and 'callable'." );
		}

		$name         = $tool_definition['name'];
		$callable     = $tool_definition['callable'];
		$description  = $tool_definition['description'] ?? null;
		$input_schema = $tool_definition['inputSchema'] ?? null;

		// TODO: This is a temporary limit.
		if ( count( $this->tools ) >= 128 ) {
			WP_CLI::debug( 'Too many tools, max is 128', 'tools' );
			return;
		}

		$this->tools[ $name ] = [
			'name'        => $name,
			'callable'    => $callable,
			'description' => $description,
			'inputSchema' => $input_schema,
		];
	}

	public function register_resource( array $resource_definition ) {
		// Validate the resource definition (similar to tool validation)
		if ( ! isset( $resource_definition['name'] ) || ! isset( $resource_definition['uri'] ) ) {
			throw new InvalidArgumentException( 'Invalid resource definition.' );
		}

		$this->resources[ $resource_definition['name'] ] = $resource_definition;
	}

	public function get_capabilities(): array {
		$capabilities = [
			'version'        => '1.0', // MCP version (adjust as needed)
			'methods'        => [],
			'data_resources' => [],
		];

		foreach ( $this->tools as $tool ) { // Iterate through the tools array
			$capabilities['methods'][] = [ // Add each tool as an element in the array
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			];
		}

		// Add data resources
		// Add resources to capabilities
		foreach ( $this->resources as $resource ) {
			$capabilities['data_resources'] = [
				'name' => $resource['name'],
				// You can add more details about the resource here if needed
			];
		}

		return $capabilities;
	}

	public function handle_request( string $request_data ): false|string {
		$request = json_decode( $request_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return $this->create_error_response( null, 'Invalid JSON', - 32700 ); // Parse error
		}

		if ( ! isset( $request['jsonrpc'] ) || '2.0' !== $request['jsonrpc'] ) {
			return $this->create_error_response( $request['id'] ?? null, 'Invalid JSON-RPC version', - 32600 ); // Invalid Request
		}

		if ( ! isset( $request['method'] ) ) {
			return $this->create_error_response( $request['id'] ?? null, 'Missing method', - 32600 ); // Invalid Request
		}

		$method = $request['method'];
		$params = $request['params'] ?? [];
		$id     = $request['id'] ?? null;

		if ( 'get_capabilities' === $method ) { // Handle capabilities request
			$capabilities = $this->get_capabilities();

			return $this->create_success_response( $id, $capabilities );
		}

		try {
			// Check if it's a data access request (starts with "get_")
			if ( str_starts_with( $method, 'get_' ) ) {
				$resource = substr( $method, 4 ); // Extract the resource name (e.g., "users" from "get_users")

				if ( isset( $this->data[ $resource ] ) ) {
					$result = $this->handle_get_request( '/' . $resource, $params ); // Re-use handleGetRequest
				} elseif ( isset( $this->data[ "{$resource}s" ] ) ) {
					$result = $this->handle_get_request( '/' . "{$resource}s", $params ); // Re-use handleGetRequest
				} else {
					return $this->create_error_response( $id, 'Resource not found', - 32601 ); // Method not found
				}
			} elseif ( 'resources/list' === $method ) {
				$result = $this->list_resources();
			} elseif ( 'resources/read' === $method ) {
				$result = $this->read_resource( $params['uri'] ?? null );
			} else {  // Treat as a tool call

				$tool = $this->tools[ $method ] ?? null;
				if ( ! $tool ) {
					return $this->create_error_response( $id, 'Method not found', - 32601 );
				}

				// Validate input parameters against the schema
				$input_schema = $tool['inputSchema'] ?? null;
				if ( $input_schema ) {
					$is_valid = $this->validate_input( $params, $input_schema );
					if ( ! $is_valid['valid'] ) {
						return $this->create_error_response( $id, 'Invalid input parameters: ' . implode( ', ', $is_valid['errors'] ), - 32602 ); // Invalid params
					}
				}

				$result = call_user_func( $tool['callable'], $params );  // Call the 'callable' property

				return $this->create_success_response( $id, $result ); // Return success immediately

			}

			return $this->create_success_response( $id, $result );

		} catch ( Exception $e ) {
			return $this->create_error_response( $id, $e->getMessage(), - 32000 ); // Application error
		}
	}

	public function list_resources() {
		$result = [];
		foreach ( $this->resources as $resource ) {
			$result[] = [
				'uri'         => $resource['uri'],
				'name'        => $resource['name'],
				'description' => $resource['description'] ?? null,
				'mimeType'    => $resource['mimeType'] ?? null,
			];
		}

		return $result;
	}

	private function read_resource( $uri ) {
		// Find the resource by URI
		$resource = null;
		foreach ( $this->resources as $r ) {
			if ( $r['uri'] === $uri ) {
				$resource = $r;
				break;
			}
		}

		if ( ! $resource ) {
			throw new Exception( 'Resource not found.' );
		}

		// Access the resource data (replace with your actual data access logic)
		$data = $this->get_resource_data( $resource );

		// Determine if it's text or binary
		$is_binary = isset( $resource['mimeType'] ) && ! str_starts_with( $resource['mimeType'], 'text/' );

		return [
			'uri'                            => $resource['uri'],
			'mimeType'                       => $resource['mimeType'] ?? null,
			( $is_binary ? 'blob' : 'text' ) => $data,
		];
	}

	public function get_resource_data( $mcp_resource ) {

		// return the resource element based on the path
		if(str_starts_with($mcp_resource, 'media://')) {
			return $this->get_media_data($mcp_resource);
		}


		// Example: If the resource is a file, read the file contents.
		if ( isset( $mcp_resource['filePath'] ) ) {
			return file_get_contents( $mcp_resource['filePath'] );
		}

		// Example: If the resource is in the $data array, return the data.
		if ( isset( $mcp_resource['dataKey'] ) ) {
			return $this->data[ $mcp_resource['dataKey'] ];
		}

		//... other data access logic...

		throw new Exception( 'Unable to access resource data.' );
	}


	private function get_media_data($mcp_resource) {
		foreach($this->resources as $resource) {
			if($resource['uri'] === $mcp_resource) {
					$callback_response = $resource['callable']();
					return $callback_response;
			}
		}
	}

	// TODO: use a dedicated JSON schema validator library
	private function validate_input( $input, $schema ): array {
		$errors = [];
		foreach ( $schema['properties'] ?? [] as $param_name => $param_schema ) {
			if ( isset( $param_schema['required'] ) && true === $param_schema['required'] && ! isset( $input[ $param_name ] ) ) {
				$errors[] = $param_name . ' is required';
			}
			// Add more validation rules as needed (e.g., type checking)
			if ( isset( $input[ $param_name ], $param_schema['type'] ) ) {
				$input_type = gettype( $input[ $param_name ] );
				if ( $input_type !== $param_schema['type'] ) {
					$errors[] = $param_name . ' must be of type ' . $param_schema['type'] . ' but ' . $input_type . ' was given.';
				}
			}
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	private function handle_get_request( $path, $params ) {
		$parts    = explode( '/', ltrim( $path, '/' ) );
		$resource = $parts[0];
		$id       = $params['id'] ?? null; // Simplified parameter handling

		if ( isset( $this->data[ $resource ] ) ) {
			$data = $this->data[ $resource ];

			if ( null !== $id ) {
				foreach ( $data as $item ) {
					if ( $item['id'] === $id ) {
						return $item;
					}
				}
				throw new Exception( 'Resource not found' );
			}

			return $data;
		}

		throw new Exception( 'Resource not found' );
	}

	private function create_success_response( $id, $result ): false|string {
		return json_encode(
			[
				'jsonrpc' => '2.0',
				'result'  => $result,
				'id'      => $id,
			],
			JSON_THROW_ON_ERROR
		);
	}

	private function create_error_response( $id, $message, $code ): false|string {
		return json_encode(
			[
				'jsonrpc' => '2.0',
				'error'   => [
					'code'    => $code,
					'message' => $message,
				],
				'id'      => $id,
			],
			JSON_THROW_ON_ERROR
		);
	}

	public function process_request( $request_data ): false|string {
		return $this->handle_request( $request_data );
	}
}
