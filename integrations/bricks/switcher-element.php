<?php
/**
 * Bricks element: Language Switcher. Loaded only when Bricks is the active theme.
 *
 * @package Lango7
 */

namespace Lango7\Integrations\Bricks;

defined( 'ABSPATH' ) || exit;

/**
 * Same markup as the block and [lango7_switcher], with Bricks' own style controls.
 */
class Switcher_Element extends \Bricks\Element {

	// phpcs:disable WordPress.NamingConventions.ValidVariableName -- Bricks' property names.
	public $category = 'general';
	public $name     = 'lango7-switcher';
	public $icon     = 'ti-world';
	// phpcs:enable

	public function get_label() {
		return esc_html__( 'Language Switcher', 'lango7' );
	}

	public function get_keywords() {
		return array( 'language', 'translate', 'lango7' );
	}

	public function set_controls() {
		$this->controls['style'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Style', 'lango7' ),
			'type'        => 'select',
			'options'     => array(
				'list'     => esc_html__( 'List of links', 'lango7' ),
				'dropdown' => esc_html__( 'Dropdown', 'lango7' ),
				'globe'    => esc_html__( 'Globe icon', 'lango7' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'List of links', 'lango7' ),
		);
		$this->controls['names'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Show languages as', 'lango7' ),
			'type'        => 'select',
			'options'     => array(
				'native' => esc_html__( 'Native name (Français)', 'lango7' ),
				'name'   => esc_html__( 'English name (French)', 'lango7' ),
				'code'   => esc_html__( 'Code (FR)', 'lango7' ),
			),
			'inline'      => true,
			'placeholder' => esc_html__( 'Native name (Français)', 'lango7' ),
		);
		$this->controls['gap'] = array(
			'tab'      => 'content',
			'label'    => esc_html__( 'Gap', 'lango7' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => array(
				array(
					'property' => 'gap',
					'selector' => '.lango7-switcher--list ul',
				),
			),
			'required' => array( 'style', '!=', array( 'dropdown', 'globe' ) ),
		);
		$this->controls['typography'] = array(
			'tab'   => 'content',
			'label' => esc_html__( 'Typography', 'lango7' ),
			'type'  => 'typography',
			'css'   => array(
				array(
					'property' => 'font',
					'selector' => 'a, summary',
				),
			),
		);
		$this->controls['activeTypography'] = array(
			'tab'   => 'content',
			'label' => esc_html__( 'Current language typography', 'lango7' ),
			'type'  => 'typography',
			'css'   => array(
				array(
					'property' => 'font',
					'selector' => 'a[aria-current]',
				),
			),
		);
	}

	public function render() {
		$settings = $this->settings;
		$markup   = \Lango7\Plugin::instance()->switcher()->render(
			array(
				'style' => $settings['style'] ?? 'list',
				'names' => $settings['names'] ?? 'native',
			)
		);
		if ( '' === $markup && ( bricks_is_builder() || bricks_is_builder_call() ) ) {
			$markup = esc_html__( 'Language Switcher: add a language in Translate → Settings.', 'lango7' );
		}
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $markup . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Switcher::render() and Bricks.
	}
}
