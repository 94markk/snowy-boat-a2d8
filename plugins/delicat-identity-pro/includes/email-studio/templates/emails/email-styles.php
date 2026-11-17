<?php
defined( 'ABSPATH' ) || exit;
echo dipes_email_css( isset( $email ) ? $email : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
