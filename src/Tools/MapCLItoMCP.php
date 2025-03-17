<?php

namespace WP_CLI\AiCommand\Tools;

use WP_CLI;
use WP_CLI\AiCommand\Entity\Tool;
use WP_CLI\Dispatcher;
use WP_CLI\SynopsisParser;

class MapCLItoMCP {

	public function map_cli_to_mcp() : array {

		// Expose WP-CLI commands as tools
		$commands = [
			'cache',
			'config',
			'core',
			'maintenance-mode',
			'profile',
			'rewrite',
		];

		$tools = [];

		foreach ( $commands as $command ) {
			$command_to_run  = WP_CLI::get_runner()->find_command_to_run( [ $command ] );
			list( $command ) = $command_to_run;

			if ( ! is_object( $command ) ) {
				continue;
			}

			$command_name = $command->get_name();

			if ( ! $command->can_have_subcommands() ) {

				$command_desc     = $command->get_shortdesc() ?? "Runs WP-CLI command: $command_name";
				$command_synopsis = $command->get_synopsis();
				$synopsis_spec    = SynopsisParser::parse( $command_synopsis );

				$properties = [];
				$required   = [];

				$properties['dummy'] = [
					'type'        => 'string',
					'description' => 'Dummy parameter',
				];

				WP_CLI::debug( 'Synopsis for command: ' . $command_name . ' - ' . print_r( $command_synopsis, true ), 'ai' );

				foreach ( $command_synopsis as $arg ) {
					if ( $arg['type'] === 'positional' || $arg['type'] === 'assoc' ) {
						$prop_name                = str_replace( '-', '_', $arg['name'] );
						$properties[ $prop_name ] = [
							'type'        => 'string',
							'description' => $arg['description'] ?? "Parameter {$arg['name']}",
						];

						if ( ! isset( $arg['optional'] ) || ! $arg['optional'] ) {
							$required[] = $prop_name;
						}
					}
				}

				$tool = new Tool([
						'name'        => 'wp_cli_' . str_replace( ' ', '_', $command_name ),
						'description' => $command_desc,
						'inputSchema' => [
							'type'       => 'object',
							'properties' => $properties,
							'required'   => $required,
						],
						'callable'    => function ( $params ) use ( $command_name, $synopsis_spec ) {
							$args       = [];
							$assoc_args = [];

							// Process positional arguments first
							foreach ( $synopsis_spec as $arg ) {
								if ( $arg['type'] === 'positional' ) {
									$prop_name = str_replace( '-', '_', $arg['name'] );
									if ( isset( $params[ $prop_name ] ) ) {
										$args[] = $params[ $prop_name ];
									}
								}
							}

							// Process associative arguments and flags
							foreach ( $params as $key => $value ) {
								// Skip positional args and dummy param
								if ( $key === 'dummy' ) {
									continue;
								}

								// Check if this is an associative argument
								foreach ( $synopsis_spec as $arg ) {
									if ( ( $arg['type'] === 'assoc' || $arg['type'] === 'flag' ) &&
										str_replace( '-', '_', $arg['name'] ) === $key ) {
										$assoc_args[ str_replace( '_', '-', $key ) ] = $value;
										break;
									}
								}
							}

							ob_start();
							WP_CLI::run_command( array_merge( explode( ' ', $command_name ), $args ), $assoc_args );
							return ob_get_clean();
						},
					]
				);

				$tools[] = $tool;
				
			} else {

				\WP_CLI::debug( $command_name . ' subcommands: ' . print_r( $command->get_subcommands(), true ), 'ai' );

				foreach ( $command->get_subcommands() as $subcommand ) {

					if ( WP_CLI::get_runner()->is_command_disabled( $subcommand ) ) {
						continue;
					}

					$subcommand_name     = $subcommand->get_name();
					$subcommand_desc     = $subcommand->get_shortdesc() ?? "Runs WP-CLI command: $subcommand_name";
					$subcommand_synopsis = $subcommand->get_synopsis();
					$synopsis_spec       = SynopsisParser::parse( $subcommand_synopsis );

					$properties = [];
					$required   = [];

					$properties['dummy'] = [
						'type'        => 'string',
						'description' => 'Dummy parameter',
					];

					foreach ( $synopsis_spec as $arg ) {
						if ( $arg['type'] === 'positional' || $arg['type'] === 'assoc' ) {
							$prop_name                = str_replace( '-', '_', $arg['name'] );
							$properties[ $prop_name ] = [
								'type'        => 'string',
								'description' => $arg['description'] ?? "Parameter {$arg['name']}",
							];

						}
						/*
						// Handle flag type parameters (boolean)
						if ($arg['type'] === 'flag') {
							$prop_name = str_replace('-', '_', $arg['name']);
							$properties[ $prop_name ] = [
								'type' => 'boolean',
								'description' => $arg['description'] ?? "Flag {$arg['name']}",
								'default' => false
							];
						}*/

						if ( ! isset( $arg['optional'] ) || ! $arg['optional'] ) {
							$required[] = $prop_name;
						}
					}
					$tool = new Tool([					
							'name'        => 'wp_cli_' . str_replace( ' ', '_', $command_name ) . '_' . str_replace( ' ', '_', $subcommand_name ),
							'description' => $subcommand_desc,
							'inputSchema' => [
								'type'       => 'object',
								'properties' => $properties,
								'required'   => $required,
							],
							'callable'    => function ( $params ) use ( $command_name, $subcommand_name, $synopsis_spec ) {

								\WP_CLI::debug( 'Subcommand: ' . $subcommand_name . ' - Received params: ' . print_r( $params, true ), 'ai' );

								$args       = [];
								$assoc_args = [];

								// Process positional arguments first
								foreach ( $synopsis_spec as $arg ) {
									if ( $arg['type'] === 'positional' ) {
										$prop_name = str_replace( '-', '_', $arg['name'] );
										if ( isset( $params[ $prop_name ] ) ) {
											$args[] = $params[ $prop_name ];
										}
									}
								}

								// Process associative arguments and flags
								foreach ( $params as $key => $value ) {
									// Skip positional args and dummy param
									if ( $key === 'dummy' ) {
										continue;
									}

									// Check if this is an associative argument
									foreach ( $synopsis_spec as $arg ) {
										if ( ( $arg['type'] === 'assoc' || $arg['type'] === 'flag' ) &&
											str_replace( '-', '_', $arg['name'] ) === $key ) {
											$assoc_args[ str_replace( '_', '-', $key ) ] = $value;
											break;
										}
									}
								}

								ob_start();
								WP_CLI::run_command( array_merge( [ $command_name, $subcommand_name ], $args ), $assoc_args );
								return ob_get_clean();
							},
						]
					);

					$tools[] = $tool;
				}
			}
		}

		return $tools;
	}
}
