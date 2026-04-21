<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolves Gravity Forms field values into sheet-ready strings.
 */
class GFGS_Field_Mapper {

    // ── Public API ────────────────────────────────────────────────────────────

    public static function build_row( array $field_map, array $entry, array $form, array $date_formats = [] ) : array {
        $row = [];

        foreach ( $field_map as $mapping ) {
            $column     = sanitize_text_field( $mapping['sheet_column'] ?? '' );
            $gf_field   = $mapping['field_id']  ?? '';
            $field_type = $mapping['field_type'] ?? 'standard';

            if ( '' === $column || '' === $gf_field ) continue;

            $row[ $column ] = self::resolve_value(
                $gf_field,
                $field_type,
                $entry,
                $form,
                $date_formats[ $column ] ?? null
            );
        }

        return $row;
    }

    public static function resolve_value( $field_id, string $field_type, array $entry, array $form, ?string $date_format = null ) : string {
        if ( $field_type === 'custom' ) {
            return self::interpolate_custom( (string) $field_id, $entry, $form );
        }

        $meta_fields = [ 'entry_id', 'date_created', 'source_url', 'user_ip', 'created_by', 'payment_status' ];
        if ( $field_type === 'meta' || in_array( $field_id, $meta_fields, true ) ) {
            return self::resolve_meta( (string) $field_id, $entry );
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

    public static function check_conditions( array $conditions, array $entry, array $form ) : bool {
        if ( empty( $conditions['enabled'] ) ) return true;
        if ( empty( $conditions['rules'] ) )   return true;

        $gf_conditions = [
            'logicType' => ( $conditions['logic'] ?? 'all' ) === 'all' ? 'all' : 'any',
            'rules'     => array_map( function ( $rule ) {
                return [
                    'fieldId'  => $rule['field_id'] ?? '',
                    'operator' => $rule['operator']  ?? 'is',
                    'value'    => $rule['value']      ?? '',
                ];
            }, $conditions['rules'] ),
        ];

        return GFCommon::evaluate_conditional_logic( (array) $gf_conditions, $form, $entry );
    }

    // ── Custom Template Interpolation ─────────────────────────────────────────

    private static function interpolate_custom( string $template, array $entry, array $form ) : string {
        preg_match_all( '/\{(\d+(?:\.\d+)?)(?::(?:label|value))?\}/', $template, $matches );
        $found_ids = array_unique( $matches[1] );

        // Find the first multi-choice field referenced in the template
        $repeater_field = null;
        foreach ( $found_ids as $id ) {
            $field = self::get_field( $form, explode( '.', $id )[0] );
            if ( $field && self::is_multi_choice( $field ) ) {
                $repeater_field = $field;
                break;
            }
        }

        // No multi-choice field — single pass
        if ( ! $repeater_field ) {
            return self::process_template_tags( $template, $entry, $form );
        }

        // Loop the template once per selected value
        $results = [];

        if ( self::is_checkbox_style( $repeater_field ) ) {
            foreach ( (array) $repeater_field->inputs as $index => $input ) {
                if ( rgar( $entry, (string) $input['id'] ) ) {
                    $results[] = self::process_template_tags( $template, $entry, $form, $repeater_field->id, $index );
                }
            }
        } else {
            foreach ( self::normalize_multi_values( rgar( $entry, $repeater_field->id ) ) as $val ) {
                $results[] = self::process_template_tags( $template, $entry, $form, $repeater_field->id, trim( $val ) );
            }
        }

        return implode( "\n", $results );
    }

    private static function process_template_tags( string $template, array $entry, array $form, $loop_id = null, $loop_context = null ) : string {
        return preg_replace_callback(
            '/\{(\d+(?:\.\d+)?)(?::(label|value))?\}/',
            function ( $matches ) use ( $entry, $form, $loop_id, $loop_context ) {
                $raw_id   = $matches[1];
                $modifier = $matches[2] ?? '';
                $base_id  = explode( '.', $raw_id )[0];
                $field    = self::get_field( $form, $base_id );

                if ( ! $field ) {
                    return (string) ( $entry[ $raw_id ] ?? '' );
                }

                // Inside a loop — resolve only the current iteration's value
                if ( $loop_id !== null && (string) $base_id === (string) $loop_id ) {
                    return self::resolve_single_choice_from_loop( $field, $modifier ?: 'value', $loop_context );
                }

                // No modifier e.g. {12} — use full field formatter
                if ( $modifier === '' ) {
                    return self::format_field( $field, $entry );
                }

                // :label or :value modifier
                return self::resolve_subfield( $raw_id . ':' . $modifier, $entry, $form );
            },
            $template
        );
    }

    private static function resolve_single_choice_from_loop( GF_Field $field, string $modifier, $context ) : string {
        $choice = null;

        if ( self::is_checkbox_style( $field ) ) {
            // $context is the numeric index into $field->choices
            $choice = $field->choices[ $context ] ?? null;
        } else {
            // $context is the selected value string
            foreach ( $field->choices as $c ) {
                if ( (string) $c['value'] === (string) $context ) {
                    $choice = $c;
                    break;
                }
            }
        }

        if ( ! $choice ) return '';

        return ( $modifier === 'label' )
            ? ( $choice['text'] ?? $choice['value'] )
            : $choice['value'];
    }

    // ── Sub-field Resolution ──────────────────────────────────────────────────

    private static function resolve_subfield( string $subfield, array $entry, array $form ) : string {
        $parts    = explode( ':', $subfield, 2 );
        $raw_id   = $parts[0] ?? '';
        $modifier = strtolower( trim( $parts[1] ?? 'value' ) );
        $base_id  = explode( '.', $raw_id )[0];

        $field = self::get_field( $form, $base_id );
        if ( ! $field ) {
            return (string) rgar( $entry, $raw_id );
        }

        if ( self::is_multi_choice( $field ) ) {
            return self::resolve_choice_subfield( $field, $entry, $modifier );
        }

        $raw_value = rgar( $entry, $raw_id );
        if ( $modifier === 'label' && ! empty( $field->inputs ) ) {
            foreach ( $field->inputs as $input ) {
                if ( (string) $input['id'] === $raw_id ) {
                    return $input['label'] ?? $raw_value;
                }
            }
        }

        return (string) $raw_value;
    }

    private static function resolve_choice_subfield( GF_Field $field, array $entry, string $modifier ) : string {
        $items = [];

        if ( self::is_checkbox_style( $field ) ) {
            foreach ( (array) $field->inputs as $idx => $input ) {
                if ( rgar( $entry, (string) $input['id'] ) ) {
                    $choice  = $field->choices[ $idx ] ?? null;
                    $items[] = ( $modifier === 'label' )
                        ? ( $choice['text']  ?? $choice['value'] ?? '' )
                        : ( $choice['value'] ?? '' );
                }
            }
        } else {
            foreach ( self::normalize_multi_values( rgar( $entry, $field->id ) ) as $v ) {
                $label = $v;
                foreach ( $field->choices as $c ) {
                    if ( (string) $c['value'] === $v ) { $label = $c['text']; break; }
                }
                $items[] = ( $modifier === 'label' ) ? $label : $v;
            }
        }

        return implode( ', ', $items );
    }

    // ── Meta Fields ───────────────────────────────────────────────────────────

    private static function resolve_meta( string $field_id, array $entry ) : string {
        $map    = [ 'entry_id' => 'id', 'user_ip' => 'ip' ];
        $target = $map[ $field_id ] ?? $field_id;
        return (string) ( $entry[ $target ] ?? '' );
    }

    // ── Field Formatters ──────────────────────────────────────────────────────

    public static function format_field( GF_Field $field, array $entry ) : string {
        $method = 'format_' . $field->type;
        if ( method_exists( __CLASS__, $method ) ) {
            return self::$method( $field, $entry );
        }
        // Full $entry required as third param since GF 2.9.29
        return GFCommon::get_lead_field_display( $field, rgar( $entry, $field->id ), $entry, false, 'text' );
    }

    private static function format_multi_choice( GF_Field $field, array $entry ) : string {
        if ( self::is_checkbox_style( $field ) ) {
            $checked = [];
            foreach ( (array) $field->inputs as $index => $input ) {
                $val = rgar( $entry, (string) $input['id'] );
                if ( ! empty( $val ) ) {
                    $choice    = $field->choices[ $index ] ?? null;
                    $checked[] = $choice ? ( $choice['text'] ?? $choice['value'] ) : $val;
                }
            }
            return implode( "\n", $checked );
        }

        return implode( "\n", self::normalize_multi_values( rgar( $entry, $field->id ) ) );
    }

    private static function format_checkbox( GF_Field $field, array $entry ) : string {
        $checked = [];
        foreach ( (array) $field->inputs as $index => $input ) {
            if ( rgar( $entry, (string) $input['id'] ) ) {
                $choice    = $field->choices[ $index ] ?? null;
                $checked[] = $choice
                    ? ( $choice['text'] ?? $choice['value'] )
                    : rgar( $entry, (string) $input['id'] );
            }
        }
        return implode( "\n", $checked );
    }

    private static function format_multiselect( GF_Field $field, array $entry ) : string {
        return implode( "\n", self::normalize_multi_values( rgar( $entry, $field->id ) ) );
    }

    private static function format_name( GF_Field $field, array $entry ) : string {
        return trim( implode( ' ', array_filter( [
            rgar( $entry, $field->id . '.2' ),
            rgar( $entry, $field->id . '.3' ),
            rgar( $entry, $field->id . '.6' ),
        ] ) ) );
    }

    private static function format_address( GF_Field $field, array $entry ) : string {
        return implode( ', ', array_filter( [
            rgar( $entry, $field->id . '.1' ),
            rgar( $entry, $field->id . '.2' ),
            rgar( $entry, $field->id . '.3' ),
            rgar( $entry, $field->id . '.4' ),
            rgar( $entry, $field->id . '.5' ),
            rgar( $entry, $field->id . '.6' ),
        ] ) );
    }

    private static function format_fileupload( GF_Field $field, array $entry ) : string {
        $val = rgar( $entry, $field->id );
        if ( $field->multipleFiles ) {
            $files = json_decode( $val, true );
            return is_array( $files ) ? implode( "\n", $files ) : (string) $val;
        }
        return (string) $val;
    }

    private static function format_list( GF_Field $field, array $entry ) : string {
        $val = rgar( $entry, $field->id );
        if ( is_string( $val ) ) {
            $val = maybe_unserialize( $val );
        }
        if ( ! is_array( $val ) ) return (string) $val;

        $lines = [];
        foreach ( $val as $row ) {
            if ( is_array( $row ) ) {
                $lines[] = $field->enableColumns
                    ? implode( ' | ', array_values( $row ) )
                    : reset( $row );
            } else {
                $lines[] = $row;
            }
        }
        return implode( "\n", $lines );
    }

    private static function format_product( GF_Field $field, array $entry ) : string {
        $product_name = '';
        $price        = '';
        $qty          = '';

        if ( $field->inputType === 'singleproduct' ) {
            $product_name = $field->label;
            $price        = rgar( $entry, $field->id . '.2' );
            $qty          = rgar( $entry, $field->id . '.3' );
        } else {
            $full_raw    = rgar( $entry, $field->id );
            $value_parts = explode( '|', $full_raw );
            $clean_value = $value_parts[0];
            $product_name = RGFormsModel::get_choice_text( $field, $full_raw );

            if ( is_array( $field->choices ) ) {
                foreach ( $field->choices as $choice ) {
                    if ( (string) $choice['value'] === (string) $clean_value ) {
                        $price = $choice['price'];
                        break;
                    }
                }
            }

            if ( empty( $price ) && isset( $value_parts[1] ) ) {
                $price = $value_parts[1];
            }

            $qty = rgar( $entry, $field->id . '.3' );
        }

        if ( empty( $product_name ) ) return '';

        $parts = [ 'Product: ' . $product_name ];
        if ( $price !== '' ) $parts[] = 'Price: '    . $price;
        if ( $qty   !== '' ) $parts[] = 'Quantity: ' . $qty;

        return implode( ' | ', $parts );
    }

    private static function format_date_field( GF_Field $field, array $entry, string $date_format ) : string {
        $raw = rgar( $entry, $field->id );
        if ( empty( $raw ) ) return '';
        $ts = strtotime( $raw );
        if ( ! $ts ) return $raw;
        return ( $date_format === 'timestamp' ) ? (string) $ts : date( $date_format, $ts );
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * True for any field type that holds multiple selected values.
     */
    private static function is_multi_choice( GF_Field $field ) : bool {
        return in_array( $field->type, [ 'checkbox', 'multiselect', 'multi_choice' ], true );
    }

    /**
     * True when selected values are stored across sub-inputs (26.1, 26.2 …)
     * rather than as a single JSON/CSV value on the field ID itself.
     */
    private static function is_checkbox_style( GF_Field $field ) : bool {
        return $field->type === 'checkbox' ||
               ( $field->type === 'multi_choice' && $field->inputType === 'checkbox' );
    }

    /**
     * Normalises a raw multi-value entry (PHP array, JSON string, or CSV)
     * into a plain, trimmed, non-empty string array.
     */
    private static function normalize_multi_values( $raw ) : array {
        if ( is_array( $raw ) ) {
            return array_values( array_filter( array_map( 'trim', $raw ) ) );
        }
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                return array_values( array_filter( array_map( 'trim', $decoded ) ) );
            }
            return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
        }
        return [];
    }

    /**
     * Finds a GF_Field object by its ID within a form's fields array.
     */
    private static function get_field( array $form, string $field_id ) : ?GF_Field {
        foreach ( $form['fields'] as $field ) {
            if ( (string) $field->id === $field_id ) return $field;
        }
        return null;
    }
}