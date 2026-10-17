<?php
/**
 * Debounced regeneration through WP-Cron.
 *
 * @package AiReadyWp
 * @since   1.0.0
 */

namespace Fer\AiReady\Cron;

use Fer\AiReady\Generator;

/**
 * Schedules a single rebuild after edits settle.
 *
 * Each request clears the previous timestamp and schedules a new one, so a
 * burst of saves produces one rebuild. The scheduled callback is the only
 * place this class generates files.
 *
 * @since 1.0.0
 */
final class Regenerator {
	const HOOK = 'ai_ready_wp_regenerate';

	/**
	 * Index generator.
	 *
	 * @since 1.0.0
	 *
	 * @var Generator
	 */
	private $generator;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Generator $generator Index generator.
	 */
	public function __construct( Generator $generator ) {
		$this->generator = $generator;
	}

	/**
	 * Replace any pending rebuild with one due after the debounce interval.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function schedule() {
		$delay = (int) apply_filters( 'ai_ready_wp_debounce_seconds', MINUTE_IN_SECONDS );
		$delay = max( 15, min( HOUR_IN_SECONDS, $delay ) );
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + $delay, self::HOOK );
	}

	/**
	 * Cron callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run() {
		$this->generator->generate();
	}
}
