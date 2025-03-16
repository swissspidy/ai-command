<?php

declare(strict_types=1);

namespace WP_CLI\AiCommand;

use BadMethodCallException;
use WP_REST_Controller;
use WP_REST_Posts_Controller;
use WP_REST_Taxonomies_Controller;
use WP_REST_Users_Controller;

class RouteInformation {


	public function __construct(
		private string $route,
		private string $method,
		private $callback,
	) {
	}

	public function get_sanitized_route_name(): string {
		$route = $this->route;

		preg_match_all( '/\(?P<(\w+)>/', $this->route, $matches );

		foreach ( $matches[1] as $match ) {
			$route = preg_replace( '/(\(\?P<' . $match . '>.*\))/', 'p_' . $match, $route, 1 );
		}

		return $this->method . '_' . sanitize_title( $route );
	}

	public function get_method(): string {
		return $this->method;
	}

	public function is_create(): bool {
		return $this->method === 'POST';
	}

	public function is_update(): bool {
		return $this->method === 'PUT' || $this->method === 'PATCH';
	}

	public function is_delete(): bool {
		return $this->method === 'DELETE';
	}

	public function is_get(): bool {
		return $this->method === 'GET';
	}

	public function is_singular(): bool {
		// Always true
		if ( str_ends_with( $this->route, '(?P<id>[\d]+)' ) ) {
			return true;
		}

		// Never true
		if ( ! str_contains( $this->route, '?P<id>' ) ) {
			return false;
		}

		return false;
	}

	public function is_list(): bool {
		return ! $this->is_singular();
	}

	public function is_wp_rest_controller(): bool {
		// The callback form for a WP_REST_Controller is [ WP_REST_Controller, method ]
		if ( ! is_array( $this->callback ) ) {
			return false;
		}

		$allowed = [
			WP_REST_Posts_Controller::class,
			WP_REST_Users_Controller::class,
			WP_REST_Taxonomies_Controller::class,
		];

		foreach ( $allowed as $controller ) {
			if ( $this->callback[0] instanceof $controller ) {
				return true;
			}
		}

		return false;
	}

	public function get_wp_rest_controller(): WP_REST_Controller {
		if ( ! $this->is_wp_rest_controller() ) {
			throw new BadMethodCallException( 'The callback needs to be a WP_Rest_Controller' );
		}

		return $this->callback[0];
	}
}
