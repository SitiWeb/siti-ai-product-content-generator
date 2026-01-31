<?php

class Groq_AI_Settings_Renderer {
	/** @var string */
	private $option_key;

	/** @var array */
	private $values = [];

	public function __construct( $option_key, $values = [] ) {
		$this->option_key = $option_key;
		$this->set_values( $values );
	}

	public function set_values( $values ) {
		$this->values = is_array( $values ) ? $values : [];
	}

	public function open_table( $args = [] ) {
		$defaults = [
			'class' => 'form-table',
			'role'  => 'presentation',
		];
		$args = wp_parse_args( $args, $defaults );

		printf( '<table %s>', $this->build_attr_string( $args ) );
	}

	public function close_table() {
		echo '</table>';
	}

	public function field( $args ) {
		$defaults = [
			'key'         => '',
			'name'        => '',
			'id'          => '',
			'label'       => '',
			'description' => '',
			'type'        => 'text',
			'placeholder' => '',
			'options'     => [],
			'attributes'  => [],
			'default'     => '',
			'value'       => null,
			'renderer'    => null,
			'row_attributes' => [],
			'row_class'      => '',
		];
		$args = wp_parse_args( $args, $defaults );

		if ( '' === $args['name'] && '' !== $args['key'] ) {
			$args['name'] = $this->build_field_name( $args['key'] );
		}

		if ( '' === $args['id'] && '' !== $args['key'] ) {
			$args['id'] = $this->build_field_id( $args['key'] );
		}

		if ( null === $args['value'] && '' !== $args['key'] ) {
			$args['value'] = $this->get_value( $args['key'], $args['default'] );
		}

		if ( ! isset( $args['attributes']['id'] ) && '' !== $args['id'] ) {
			$args['attributes']['id'] = $args['id'];
		}

		$type = $args['type'];

		$row_attributes = $this->prepare_row_attributes( $args );
		$row_attr_string = $row_attributes ? ' ' . $this->build_attr_string( $row_attributes ) : '';

		echo '<tr' . $row_attr_string . '>';
		$this->render_label_cell( $args );
		echo '<td>';

		if ( is_callable( $args['renderer'] ) ) {
			call_user_func( $args['renderer'], $args, $this );
		} else {
			switch ( $type ) {
				case 'textarea':
					$this->render_textarea( $args );
					break;
				case 'password':
					$this->render_input( 'password', $args );
					break;
				case 'number':
					$this->render_input( 'number', $args );
					break;
				case 'select':
					$this->render_select( $args );
					break;
				case 'checkbox':
					$this->render_checkbox( $args );
					break;
				case 'toggle':
					$this->render_toggle( $args );
					break;
				default:
					$this->render_input( 'text', $args );
			}
		}

		$this->render_description( $args['description'] );

		echo '</td>';
		echo '</tr>';
	}

	private function render_label_cell( $args ) {
		$label = $args['label'];
		$id    = $args['id'];
		echo '<th scope="row">';
		if ( '' !== $label ) {
			printf( '<label for="%s">%s</label>', esc_attr( $id ), esc_html( $label ) );
		}
		echo '</th>';
	}

	private function render_input( $type, $args ) {
		$attributes = $this->prepare_input_attributes( $args );
		printf( '<input type="%s" %s />', esc_attr( $type ), $attributes );
	}

	private function render_textarea( $args ) {
		$attributes = $this->prepare_input_attributes( $args, [ 'rows' => 4, 'class' => 'large-text' ] );
		printf( '<textarea %s>%s</textarea>', $attributes, esc_textarea( $args['value'] ) );
	}

	private function render_select( $args ) {
		$attributes = $this->prepare_input_attributes( $args );
		printf( '<select %s>', $attributes );
		foreach ( (array) $args['options'] as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $args['value'], $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private function render_checkbox( $args ) {
		$value = ! empty( $args['value'] );
		$attributes = $this->prepare_input_attributes( $args, [ 'class' => '' ] );
		printf( '<label><input type="checkbox" %s %s /> %s</label>', $attributes, checked( $value, true, false ), esc_html( $args['checkbox_label'] ?? '' ) );
	}

	private function render_toggle( $args ) {
		$value = ! empty( $args['value'] );
		$attributes = $this->prepare_input_attributes( $args, [ 'class' => '' ] );
		printf( '<label class="groq-ai-toggle"><input type="checkbox" %s %s /> <span class="groq-ai-toggle__slider"></span> %s</label>', $attributes, checked( $value, true, false ), esc_html( $args['checkbox_label'] ?? '' ) );
	}

	private function render_description( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return;
		}

		printf( '<p class="description">%s</p>', wp_kses_post( $text ) );
	}

	private function prepare_input_attributes( $args, $defaults = [] ) {
		$attributes = wp_parse_args( $args['attributes'], $defaults );
		$attributes['name'] = $args['name'];

		if ( ! isset( $attributes['id'] ) ) {
			$attributes['id'] = $args['id'];
		}

		if ( '' !== $args['placeholder'] ) {
			$attributes['placeholder'] = $args['placeholder'];
		}

		if ( ! isset( $attributes['class'] ) ) {
			$attributes['class'] = 'regular-text';
		}

		if ( ! in_array( $args['type'], [ 'checkbox', 'toggle', 'select', 'textarea' ], true ) ) {
			$attributes['value'] = $args['value'];
		}

		return $this->build_attr_string( $attributes );
	}
	private function prepare_row_attributes( $args ) {
		$attributes = [];
		if ( isset( $args['row_attributes'] ) && is_array( $args['row_attributes'] ) ) {
			$attributes = $args['row_attributes'];
		}

		$row_class = isset( $args['row_class'] ) ? trim( (string) $args['row_class'] ) : '';
		if ( '' !== $row_class ) {
			if ( isset( $attributes['class'] ) ) {
				$attributes['class'] .= ' ' . $row_class;
			} else {
				$attributes['class'] = $row_class;
			}
		}

		return array_filter(
			$attributes,
			function ( $value ) {
				return '' !== $value || 0 === $value || '0' === $value;
			}
		);
	}

	private function build_attr_string( $attributes ) {
		$buffer = [];
		foreach ( $attributes as $key => $value ) {
			if ( '' === $value && 0 !== $value && '0' !== $value ) {
				continue;
			}

			$buffer[] = sprintf( '%s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		return implode( ' ', $buffer );
	}

	private function build_field_name( $key ) {
		$segments = $this->split_key( $key );
		$name     = $this->option_key;

		foreach ( $segments as $segment ) {
			$name .= '[' . $segment . ']';
		}

		return $name;
	}

	private function build_field_id( $key ) {
		$segments = $this->split_key( $key );

		return 'groq-ai-' . implode( '-', $segments );
	}

	private function get_value( $key, $default = '' ) {
		$segments = $this->split_key( $key );
		$value    = $this->values;

		foreach ( $segments as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return $default;
			}
		}

		return $value;
	}

	private function split_key( $key ) {
		if ( is_array( $key ) ) {
			return $key;
		}

		$key = trim( (string) $key );
		if ( '' === $key ) {
			return [];
		}

		return array_map( 'sanitize_key', explode( '.', $key ) );
	}
}
