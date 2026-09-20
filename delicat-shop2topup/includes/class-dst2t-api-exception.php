<?php

defined( 'ABSPATH' ) || exit;

final class DST2T_API_Exception extends RuntimeException {
	/** @var string */
	private $api_code;

	/** @var int */
	private $http_status;

	/** @var int */
	private $retry_after;

	/** @var array */
	private $details;

	public function __construct( $message, $api_code = 'UNKNOWN_ERROR', $http_status = 0, $retry_after = 0, $details = array() ) {
		parent::__construct( (string) $message );
		$this->api_code   = sanitize_key( (string) $api_code );
		$this->api_code   = strtoupper( $this->api_code );
		$this->http_status = absint( $http_status );
		$this->retry_after = max( 0, absint( $retry_after ) );
		$this->details     = is_array( $details ) ? $details : array();
	}

	public function get_api_code() {
		return $this->api_code;
	}

	public function get_http_status() {
		return $this->http_status;
	}

	public function get_retry_after() {
		return $this->retry_after;
	}

	public function get_details() {
		return $this->details;
	}

	public function is_temporary() {
		return in_array(
			$this->api_code,
			array( 'TRANSPORT_ERROR', 'INVALID_RESPONSE', 'RATE_LIMIT_EXCEEDED', 'INTERNAL_ERROR', 'SERVICE_UNAVAILABLE' ),
			true
		) || $this->http_status >= 500;
	}
}

