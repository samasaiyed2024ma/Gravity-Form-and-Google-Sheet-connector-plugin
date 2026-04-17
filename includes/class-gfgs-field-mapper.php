<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolves Gravity Forms field values, including special types
 * (list, product, checkbox, etc.) into sheet-ready strings.
 */
class GFGS_Field_Mapper {

    public static function build_row( array $field_map, array $entry, array $form, array $date_formats = [] ) : array {
        $row = [];
        foreach ( $field_map as $mapping ) {
            $column     = sanitize_text_field( $mapping['sheet_column'] ?? '' );
            $gf_field   = $mapping['field_id'] ?? '';
            $field_type = $mapping['field_type'] ?? 'standard';

            if ( '' === $column || '' === $gf_field ) continue;

            $date_format = $date_formats[ $column ] ?? null;
            $row[ $column ] = self::resolve_value( $gf_field, $field_type, $entry, $form, $date_format );
        }
        return $row;
    }

    public static function resolve_value( $field_id, string $field_type, array $entry, array $form, ?string $date_format = null ) : string {
        if ( $field_type === 'custom' ) {
            return self::interpolate_custom( (string) $field_id, $entry, $form );
        }

        if ( $field_type === 'meta' || in_array( $field_id, [ 'entry_id', 'date_created', 'source_url', 'user_ip', 'created_by', 'payment_status' ] ) ) {
            return self::resolve_meta( $field_id, $entry );
        }

        if ( is_string( $field_id ) && strpos( $field_id, ':' ) !== false ) {
            return self::resolve_subfield( $field_id, $entry, $form );
        }

        $field = self::get_field( $form, (string) $field_id );
        if ( ! $field ) {
            return (string) ( $entry[ $field_id ] ?? '' );
        }

        if ( $field->type === 'date' && $date_format ) {
            return self::format_date_field( $field, $entry, $date_format );
        }

        return self::format_field( $field, $entry );
    }

	// ── Custom Interpolation ───────────────────────────────────────────

    private static function interpolate_custom( string $template, array $entry, array $form ) : string {
        // Find all field IDs in the template
        preg_match_all( '/\{(\d+(?:\.\d+)?)(?::(?:label|value))?\}/', $template, $matches );
        $found_ids = array_unique( $matches[1] );

        // Detect if there is a multi-choice field (Checkbox or MultiSelect) to loop over
        $repeater_field = null;
        foreach ( $found_ids as $id ) {
            $field = self::get_field( $form, explode( '.', $id )[0] );
            if ( $field && in_array( $field->type, [ 'checkbox', 'multiselect' ] ) ) {
                $repeater_field = $field;
                break;
            }
        }

        // If no multi-choice field, process normally
        if ( ! $repeater_field ) {
            return self::process_template_tags( $template, $entry, $form );
        }

        // If multi-choice, loop the TEMPLATE for every choice made
        $results = [];
        if ( $repeater_field->type === 'checkbox' ) {
            foreach ( $repeater_field->inputs as $index => $input ) {
                if ( rgar( $entry, (string) $input['id'] ) ) {
                    $results[] = self::process_template_tags( $template, $entry, $form, $repeater_field->id, $index );
                }
            }
        } elseif ( $repeater_field->type === 'multiselect' ) {
            $vals = rgar( $entry, $repeater_field->id );
            $vals = is_array( $vals ) ? $vals : array_filter( explode( ',', (string)$vals ) );
            foreach ( $vals as $val ) {
                $results[] = self::process_template_tags( $template, $entry, $form, $repeater_field->id, trim( $val ) );
            }
        }

        return implode( ', ', $results );
    }

	private static function process_template_tags( string $template, array $entry, array $form, $loop_id = null, $loop_context = null ) : string {
		return preg_replace_callback(
			'/\{(\d+(?:\.\d+)?)(?::(label|value))?\}/',
			function( $matches ) use ( $entry, $form, $loop_id, $loop_context ) {
				$raw_id   = $matches[1];
				$modifier = $matches[2] ?? ''; // Empty string if no :label or :value
				$base_id  = explode( '.', $raw_id )[0];
				$field    = self::get_field( $form, $base_id );

				if ( ! $field ) return (string) ( $entry[ $raw_id ] ?? '' );

				// 1. If we are in a multi-choice loop (Checkbox/Multiselect)
				if ( $loop_id && (string)$base_id === (string)$loop_id ) {
					return self::resolve_single_choice_from_loop( $field, $modifier ?: 'value', $loop_context );
				}

				// 2. If there is NO modifier (e.g. {12}), use the full field formatter
				// This fixes Address, Product, File, etc.
				if ( empty( $modifier ) ) {
					return self::format_field( $field, $entry );
				}

				// 3. Otherwise, resolve via subfield logic (:label or :value)
				return self::resolve_subfield( $raw_id . ':' . $modifier, $entry, $form );
			},
			$template
		);
	}

	private static function resolve_single_choice_from_loop( $field, $modifier, $context ) : string {
        if ( $field->type === 'checkbox' ) {
            $choice = $field->choices[ $context ] ?? null;
        } else { // multiselect
            foreach ( $field->choices as $c ) {
                if ( (string)$c['value'] === (string)$context ) { $choice = $c; break; }
            }
        }
        if ( ! isset( $choice ) ) return '';
        return ( $modifier === 'label' ) ? ( $choice['text'] ?? $choice['value'] ) : $choice['value'];
    }
	
	// ── Sub-field and Formatting Logic ────────────────────────────────────────

    private static function resolve_subfield( string $subfield, array $entry, array $form ) : string {
        $parts    = explode( ':', $subfield, 2 );
        $raw_id   = $parts[0] ?? '';
        $modifier = strtolower( trim( $parts[1] ?? 'value' ) );
        $base_id  = explode( '.', $raw_id )[0];

        $field = self::get_field( $form, $base_id );
        if ( ! $field ) return (string) rgar( $entry, $raw_id );

        // If it's a checkbox/multiselect and called directly (not via interpolation loop)
        if ( in_array( $field->type, [ 'checkbox', 'multiselect' ] ) ) {
            return self::resolve_choice_subfield( $field, $entry, $modifier );
        }

        $raw_value = rgar( $entry, $raw_id );
        if ( $modifier === 'label' && ! empty( $field->inputs ) ) {
            foreach ( $field->inputs as $input ) {
                if ( (string) $input['id'] === $raw_id ) return $input['label'] ?? $raw_value;
            }
        }

        return (string) $raw_value;
    }
	
	private static function resolve_choice_subfield( $field, $entry, $modifier ) : string {
        $items = [];
        if ( $field->type === 'checkbox' ) {
            foreach ( $field->inputs as $idx => $input ) {
                if ( rgar( $entry, (string)$input['id'] ) ) {
                    $choice = $field->choices[$idx] ?? null;
                    $items[] = ( $modifier === 'label' ) ? ($choice['text'] ?? $choice['value']) : $choice['value'];
                }
            }
        } else { // Radio, Select, Multiselect
            $val = rgar( $entry, $field->id );
            $vals = is_array($val) ? $val : array_filter(explode(',', (string)$val));
            foreach ($vals as $v) {
                $v = trim($v);
                $label = $v;
                foreach ($field->choices as $c) {
                    if ((string)$c['value'] === $v) { $label = $c['text']; break; }
                }
                $items[] = ($modifier === 'label') ? $label : $v;
            }
        }
        return implode(', ', $items);
    }
	
	private static function resolve_meta( string $field_id, array $entry ) : string {
        $keys = [ 'entry_id' => 'id', 'user_ip' => 'ip' ];
        $target = $keys[$field_id] ?? $field_id;
        return (string) ($entry[$target] ?? '');
    }
	
	private static function format_date_field( $field, $entry, $date_format ) : string {
        $raw = rgar( $entry, $field->id );
        if ( empty( $raw ) ) return '';
        $ts = strtotime( $raw );
        if ( ! $ts ) return $raw;
        return ( $date_format === 'timestamp' ) ? (string) $ts : date( $date_format, $ts );
    }
	
  	public static function format_field( GF_Field $field, array $entry ) : string {
        // Uses the specific format methods if they exist, else default GF display
        $method = "format_{$field->type}";
        if ( method_exists( __CLASS__, $method ) ) {
            return self::$method( $field, $entry );
        }
        return GFCommon::get_lead_field_display( $field, rgar( $entry, $field->id ), '', false, 'text' );
    }

    private static function format_checkbox( GF_Field $field, array $entry ) : string {
        $checked = [];
        foreach ( (array) $field->inputs as $index => $input ) {
            if ( rgar( $entry, (string) $input['id'] ) ) {
                $choice = $field->choices[ $index ] ?? null;
                $checked[] = $choice ? ( $choice['text'] ?? $choice['value'] ) : rgar( $entry, (string) $input['id'] );
            }
        }
        return implode( "\n", $checked );
    }
	
	private static function format_list( GF_Field $field, array $entry ) : string {
        $val = rgar( $entry, $field->id );
        if ( is_string( $val ) ) {
            $val = maybe_unserialize( $val );
        }
        if ( ! is_array( $val ) ) return (string) $val;

        $lines = [];
        if ( $field->enableColumns ) {
            // Multi-column list
            foreach ( $val as $row ) {
                if ( is_array( $row ) ) {
                    $lines[] = implode( ' | ', array_values( $row ) );
                }
            }
        } else {
            // Single-column list
            foreach ( $val as $row ) {
                if ( is_array( $row ) ) {
                    $lines[] = reset( $row );
                } else {
                    $lines[] = $row;
                }
            }
        }
        return implode( "\n", $lines );
    }

	private static function format_product( GF_Field $field, array $entry ) : string {
		$inputType = $field->inputType;
		$parts     = [];
		$product_name = '';
		$price        = '';
		$qty          = '';

		if ( $inputType === 'singleproduct' ) {
			$product_name = $field->label;
			$price        = rgar( $entry, $field->id . '.2' );
			$qty          = rgar( $entry, $field->id . '.3' );
		} else {
			// 1. Get the full raw value (e.g., "First Choice|30")
			$full_raw_value = rgar( $entry, $field->id );

			// 2. Extract just the name part for comparison
			// This turns "First Choice|30" into "First Choice"
			$value_parts = explode( '|', $full_raw_value );
			$comparison_value = $value_parts[0];

			$product_name = RGFormsModel::get_choice_text( $field, $full_raw_value );

			if ( is_array( $field->choices ) ) {
				foreach ( $field->choices as $choice ) {
					// 3. Compare against the cleaned name part
					if ( (string) $choice['value'] === (string) $comparison_value ) {
						$price = $choice['price'];
						break; 
					}
				}
			}

			// If price is still empty, it might be the second half of the pipe
			if ( empty( $price ) && isset( $value_parts[1] ) ) {
				$price = $value_parts[1];
			}

			$qty = rgar( $entry, $field->id . '.3' );
		}

		if ( ! empty( $product_name ) ) {
			$parts[] = 'Product: ' . $product_name;
			if ( $price !== '' )    $parts[] = 'Price: ' . $price;
			if ( $qty !== '' )      $parts[] = 'quantity: ' . $qty;

			return implode( ' | ', $parts );
		}

		return ''; 
	}
	
	private static function format_name( GF_Field $field, array $entry ) : string {
        $prefix = rgar( $entry, $field->id . '.2' );
        $first  = rgar( $entry, $field->id . '.3' );
        $last   = rgar( $entry, $field->id . '.6' );
        return trim( "$prefix $first $last" );
    }

    private static function format_address( GF_Field $field, array $entry ) : string {
        $parts = [
            rgar( $entry, $field->id . '.1' ),
            rgar( $entry, $field->id . '.2' ),
            rgar( $entry, $field->id . '.3' ),
            rgar( $entry, $field->id . '.4' ),
            rgar( $entry, $field->id . '.5' ),
            rgar( $entry, $field->id . '.6' ),
        ];
        return implode( ', ', array_filter( $parts ) );
    }
	
	private static function format_fileupload( GF_Field $field, array $entry ) : string {
        $val = rgar( $entry, $field->id );
        if ( $field->multipleFiles ) {
            $files = json_decode( $val, true );
            return is_array( $files ) ? implode( "\n", $files ) : $val;
        }
        return $val;
    }
	
	private static function format_multiselect(GF_Field $field, array $entry) : string{
		$val = rgar( $entry, $field->id );
		if ( is_string( $val ) ) {
			$decoded = json_decode( $val, true );
			$val     = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $val ) ) );
		}
		return is_array( $val ) ? implode( "\n", $val ) : (string) $val;
	} 

    private static function get_field( array $form, string $field_id ) : ?GF_Field {
        foreach ( $form['fields'] as $f ) {
            if ( (string) $f->id === $field_id ) return $f;
        }
        return null;
    }
	
	/**
     * Evaluate a feed's conditional logic against an entry.
     */
    public static function check_conditions( array $conditions, array $entry, array $form ) : bool {
        if ( empty( $conditions['enabled'] ) ) return true;
        if ( empty( $conditions['rules'] ) )   return true;

        $gf_conditions = [
            'logicType' => ( $conditions['logic'] ?? 'all' ) === 'all' ? 'all' : 'any',
            'rules'     => array_map( function( $rule ) {
                return [
                    'fieldId'  => $rule['field_id']  ?? '',
                    'operator' => $rule['operator']  ?? 'is',
                    'value'    => $rule['value']      ?? '',
                ];
            }, $conditions['rules'] ),
        ];

        return GFCommon::evaluate_conditional_logic(
            (array) $gf_conditions,
            $form,
            $entry
        );
    }
}