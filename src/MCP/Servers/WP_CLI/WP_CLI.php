<?php

namespace WP_CLI\AiCommand\MCP\Servers\WP_CLI;

use WP_CLI\AiCommand\MCP\Server;
use WP_CLI\AiCommand\MCP\Servers\WP_CLI\Tools\CliCommands;

class WP_CLI extends Server {
	public function __construct() {
		parent::__construct( 'WP-CLI' );

		$all_tools = [
			...( new CliCommands( $this->logger ) )->get_tools(),
		];

		foreach ( $all_tools as $tool ) {
			$this->register_tool( $tool );
		}
	}
}
