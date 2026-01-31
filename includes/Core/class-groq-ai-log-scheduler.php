<?php

class Groq_AI_Log_Scheduler {
	/** @var Groq_AI_Settings_Manager */
	private $settings_manager;

	/** @var Groq_AI_Generation_Logger */
	private $logger;

	public function __construct( Groq_AI_Settings_Manager $settings_manager, Groq_AI_Generation_Logger $logger ) {
		$this->settings_manager = $settings_manager;
		$this->logger           = $logger;
	}

	public function ensure_logs_cleanup_schedule() {
		if ( wp_next_scheduled( 'groq_ai_cleanup_logs' ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'groq_ai_cleanup_logs' );
	}

	public function cleanup_logs() {
		$settings = $this->settings_manager->all();
		$retention_days = $this->settings_manager->get_logs_retention_days( $settings );
		$this->logger->cleanup_old_logs( $retention_days );
	}
}
