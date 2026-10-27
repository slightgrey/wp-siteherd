<?php
/**
 * Collects actions and filters, then registers them with WordPress in run().
 *
 * @package WP_Siteherd
 */

class Siteherd_Loader {

	/** @var array<int, array> */
	protected $actions = array();

	/** @var array<int, array> */
	protected $filters = array();

	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	public function run() {
		foreach ( $this->filters as $f ) {
			add_filter( $f['hook'], array( $f['component'], $f['callback'] ), $f['priority'], $f['accepted_args'] );
		}
		foreach ( $this->actions as $a ) {
			add_action( $a['hook'], array( $a['component'], $a['callback'] ), $a['priority'], $a['accepted_args'] );
		}
	}
}
