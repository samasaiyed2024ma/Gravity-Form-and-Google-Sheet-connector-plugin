<?php
/**
 * Centralised logging utility for the GFGS plugin.
 *
 * All log calls throughout the plugin should go through this class so that
 * the log destination (WP_DEBUG_LOG, GF logger, etc.) can be swapped in one
 * place without touching every caller.
 *
 * Usage:
 *   GFGS_Logger::debug( 'Something happened' );
 *   GFGS_Logger::error( 'Something went wrong: ' . $e->getMessage() );
 *
 * To add a new log channel (e.g. a database log table) in the future:
 *   1. Add a new private static method, e.g. `log_to_db()`.
 *   2. Call it from `write()`.
 *
 * @package GFGS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Logger {

	/** Prefix prepended to every log entry. */
	private const PREFIX = '[GF Google Sheets]';

	/**
	 * Log a debug / informational message.
	 *
	 * Only written when WP_DEBUG_LOG is enabled.
	 *
	 * @param  string $message Human-readable message.
	 * @return void
	 */
	public static function debug( string $message ): void {
		self::write( 'debug', $message );
	}

	/**
	 * Log a notice-level message.
	 *
	 * @param  string $message Human-readable message.
	 * @return void
	 */
	public static function notice( string $message ): void {
		self::write( 'notice', $message );
	}

	/**
	 * Log an error-level message.
	 *
	 * @param  string $message Human-readable message.
	 * @return void
	 */
	public static function error( string $message ): void {
		self::write( 'error', $message );
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	/**
	 * Write a message to the active log destination(s).
	 *
	 * @param  string $level   Severity: 'debug' | 'notice' | 'error'.
	 * @param  string $message Log content.
	 * @return void
	 */
	private static function write( string $level, string $message ): void {
		// Only log to error_log when WP_DEBUG_LOG is on.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '%s [%s] %s', self::PREFIX, strtoupper( $level ), $message ) );
		}
	}
}