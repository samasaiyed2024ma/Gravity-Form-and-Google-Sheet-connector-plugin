<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolves Gravity Forms field values into sheet-ready strings.
 *
 * ── Template Syntax (field_type = 'custom') ─────────────────────────────────
 *
 * BASIC
 *   {28}              Full formatted value of field 28
 *
 * SUB-FIELDS  (dot notation — reads entry sub-inputs directly)
 *   {28.1}            Sub-input 1 of field 28
 *   {28.3}            Sub-input 3 (e.g. product quantity)
 *
 * MODIFIERS  (colon notation — works on any field type)
 *   {28:label}        Selected choice label(s), or field admin label for non-choice fields
 *   {28:value}        Raw stored value / selected choice value(s)
 *   {28:id}           The field's numeric ID
 *
 * NAMED SUB-FIELD ALIASES
 *   Name:     {28:prefix} {28:first} {28:middle} {28:last} {28:suffix}
 *   Address:  {28:street} {28:street2} {28:city} {28:state} {28:zip} {28:country}
 *   Product:  {28:name} {28:price} {28:qty}
 *   Date:     {28:date} {28:day} {28:month} {28:year}
 *   File:     {28:url}
 *
 * MULTI-LINE TEMPLATES
 *   Each line is processed independently.
 *   Lines where all referenced fields have empty entry values are skipped.
 *   If a line references a multi-choice field it loops once per selected choice.
 *
 *   Example:
 *     {26:label} - {28.3}
 *     {26:label} - {29.3}
 *     → One output line per selected choice that has a non-empty related value.
 */

class GFGS_Field_Mapper {

    // ── Sub-field maps ────────────────────────────────────────────────────────

    private const NAME_SUBFIELDS = [
        'prefix' => '.2',
        'first'  => '.3',
        'middle' => '.4',
        'last'   => '.6',
        'suffix' => '.8',
    ];

    private const ADDRESS_SUBFIELDS = [
        'street'  => '.1',
        'street2' => '.2',
        'city'    => '.3',
        'state'   => '.4',
        'zip'     => '.5',
        'country' => '.6',
    ];

    private const PRODUCT_SUBFIELDS = [
        'name'  => '.1',
        'price' => '.2',
        'qty'   => '.3',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

	/**
     * Build the full column→value row for one entry and one feed's field map.
     */
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

		// Colon modifier in field_id e.g. "26:label"
        if ( is_string( $field_id ) && strpos( $field_id, ':' ) !== false ) {
            return self::resolve_modifier( $field_id, $entry, $form );
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

	/**
     * Evaluate feed conditional logic against an entry.
     */
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

    /**
     * Entry point for custom templates.
     * Splits multi-line templates and processes each line independently.
     * Empty results are dropped so no blank lines appear in the sheet.
     */
    private static function interpolate_custom( string $template, array $entry, array $form ) : string {
        $lines  = explode( "\n", $template );
        $output = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;

            $result = self::process_single_line( $line, $entry, $form );
            if ( $result !== '' ) {
                $output[] = $result;
            }
        }

        return implode( "\n", $output );
    }

    /**
     * Processes one template line.
     * If the line references a multi-choice field it loops once per selected choice.
     * Otherwise it does a single-pass substitution.
     */
	private static function process_single_line( string $template, array $entry, array $form ) : string {
		// Collect all tags in this line
		preg_match_all( '/\{(\d+)(?:(\.\d+(?:\.\d+)*)|:([\w]+))?\}/', $template, $tag_matches, PREG_SET_ORDER );

		if ( empty( $tag_matches ) ) {
			return $template; // Plain text line, no tags — always output
		}

	    // Check if every field referenced in this line has an empty entry value.
		// If ALL fields are empty, skip the line entirely.
		$all_empty = true;
		foreach ( $tag_matches as $tag ) {
			$base_id  = $tag[1];
			$dot_part = isset( $tag[2] ) && $tag[2] !== '' ? $tag[2] : null;
			$modifier = isset( $tag[3] ) && $tag[3] !== '' ? strtolower( $tag[3] ) : null;
			$field    = self::get_field( $form, $base_id );

			if ( ! $field ) {
				// Unknown field — check raw entry value
				$raw_id = $dot_part ? $base_id . $dot_part : $base_id;
				if ( rgar( $entry, $raw_id ) !== '' && rgar( $entry, $raw_id ) !== null ) {
					$all_empty = false;
					break;
				}
				continue;
			}

			if ( self::field_has_entry_value( $field, $entry, $dot_part, $modifier ) ) {
				$all_empty = false;
				break;
			}
		}

		if ( $all_empty ) return '';

		// Proceed with normal resolution
		$found_ids = array_unique( array_column( $tag_matches, 1 ) );

		$repeater_field = null;
		foreach ( $found_ids as $id ) {
			$field = self::get_field( $form, $id );
			if ( $field && self::is_multi_choice( $field ) ) {
				$repeater_field = $field;
				break;
			}
		}

		if ( ! $repeater_field ) {
			return self::process_template_tags( $template, $entry, $form );
		}

		$results = [];

		if ( self::is_checkbox_style( $repeater_field ) ) {
			foreach ( (array) $repeater_field->inputs as $index => $input ) {
				if ( rgar( $entry, (string) $input['id'] ) ) {
					$row = self::process_template_tags( $template, $entry, $form, $repeater_field->id, $index );
					if ( $row !== '' ) $results[] = $row;
				}
			}
		} else {
			foreach ( self::normalize_multi_values( rgar( $entry, $repeater_field->id ) ) as $val ) {
				$row = self::process_template_tags( $template, $entry, $form, $repeater_field->id, trim( $val ) );
				if ( $row !== '' ) $results[] = $row;
			}
		}

		return implode( "\n", $results );
	}

	/**
	 * Checks whether a field has a real non-empty value in the entry.
	 * This is used to decide whether to skip a line entirely.
	 * It always checks the actual entry data — never the field label or config.
	 */
	private static function field_has_entry_value( GF_Field $field, array $entry, ?string $dot_part, ?string $modifier ) : bool {

		// ── Dot sub-field e.g. {36.3} — check that exact key ─────────────────
		if ( $dot_part !== null ) {
			$val = rgar( $entry, $field->id . $dot_part );
			return $val !== '' && $val !== null;
		}

		// ── Checkbox-style multi-choice — any sub-input checked? ──────────────
		if ( self::is_checkbox_style( $field ) ) {
			foreach ( (array) $field->inputs as $input ) {
				if ( rgar( $entry, (string) $input['id'] ) ) return true;
			}
			return false;
		}

		// ── Product field — check qty (.3) as the filled indicator ───────────
		// GF never stores entry[36], only entry[36.1], entry[36.2], entry[36.3]
		if ( $field->type === 'product' ) {
			$qty = rgar( $entry, $field->id . '.3' );
			return $qty !== '' && $qty !== null && $qty !== '0';
		}

		// ── Any field that has sub-inputs but no top-level key ────────────────
		// (quantity, shipping, option, etc.) — check if ANY sub-input has a value
		if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
			// First try the top-level key
			$top = rgar( $entry, $field->id );
			if ( $top !== '' && $top !== null ) return true;

			// Fall back to checking sub-inputs
			foreach ( $field->inputs as $input ) {
				$val = rgar( $entry, (string) $input['id'] );
				if ( $val !== '' && $val !== null ) return true;
			}
			return false;
		}

		// ── Named modifier — check the specific sub-input it maps to ──────────
		if ( $modifier !== null ) {
			$sub_map = array_merge(
				self::NAME_SUBFIELDS,
				self::ADDRESS_SUBFIELDS,
				self::PRODUCT_SUBFIELDS
			);
			if ( isset( $sub_map[ $modifier ] ) ) {
				$val = rgar( $entry, $field->id . $sub_map[ $modifier ] );
				return $val !== '' && $val !== null && $val !== '0';
			}
		}

		// ── All other fields — check top-level entry key ───────────────────────
		$val = rgar( $entry, $field->id );
		if ( is_array( $val ) ) return ! empty( $val );
		return $val !== '' && $val !== null;
	}
	
    /**
     * Substitutes every tag in a single template line.
     *
     * Supported tag forms:
     *   {28}          full formatted value
     *   {28.3}        dot sub-field  → entry[28.3]
     *   {28:modifier} named modifier (label, value, id, first, last, city, qty, …)
     */
    private static function process_template_tags(
        string $template,
        array  $entry,
        array  $form,
               $loop_id      = null,
               $loop_context = null
    ) : string {
        // Match {digits}, {digits.digits}, {digits:word}
        return preg_replace_callback(
            '/\{(\d+)(?:(\.\d+(?:\.\d+)*)|:([\w]+))?\}/',
            function ( $matches ) use ( $entry, $form, $loop_id, $loop_context ) {
                $base_id  = $matches[1];                               // always the field ID
                $dot_part = isset( $matches[2] ) && $matches[2] !== '' ? $matches[2] : null; // e.g. ".3"
                $modifier = isset( $matches[3] ) && $matches[3] !== '' ? strtolower( $matches[3] ) : null; // e.g. "label"
                $raw_id   = $dot_part ? $base_id . $dot_part : $base_id; // e.g. "28.3" or "28"

                $field = self::get_field( $form, $base_id );

                // Unknown field — read entry directly or return empty
                if ( ! $field ) {
                    return (string) ( $entry[ $raw_id ] ?? '' );
                }

                // ── Inside a multi-choice loop ─────────────────────────────
                if ( $loop_id !== null && $base_id === (string) $loop_id ) {
                    // :label or :value inside a loop refers to the current choice
                    if ( $modifier === 'label' || $modifier === 'value' || $modifier === null ) {
                        return self::resolve_single_choice_from_loop(
                            $field,
                            $modifier ?? 'value',
                            $loop_context
                        );
                    }
                    // Any other modifier falls through to normal resolution below
                }

                // ── Dot sub-field e.g. {28.3} ──────────────────────────────
                if ( $dot_part !== null ) {
                    return (string) rgar( $entry, $raw_id );
                }

                // ── Named modifier e.g. {28:label} ────────────────────────
                if ( $modifier !== null ) {
                    return self::resolve_named_modifier( $field, $modifier, $entry, $form );
                }

                // ── Plain tag e.g. {28} ───────────────────────────────────
                return self::format_field( $field, $entry );
            },
            $template
        );
    }

    // ── Named Modifier Resolution ─────────────────────────────────────────────

    /**
     * Central dispatcher for all named modifiers.
     * Called both from process_template_tags and from resolve_value (standard field mapping).
     *
     * Universal modifiers (all field types):
     *   label   → field admin label
     *   value   → raw entry value
     *   id      → field numeric ID
     *
     * Choice modifiers (radio, select, checkbox, multiselect, multi_choice):
     *   label   → selected choice label(s)
     *   value   → selected choice value(s)
     *
     * Name field:   prefix, first, middle, last, suffix
     * Address field: street, street2, city, state, zip, country
     * Product field: name, price, qty
     * Date field:   date, day, month, year
     * File upload:  url
     */
    private static function resolve_named_modifier( GF_Field $field, string $modifier, array $entry, array $form ) : string {
        // ── Universal modifiers ───────────────────────────────────────────────
        if ( $modifier === 'id' ) {
            return (string) $field->id;
        }

        if ( $modifier === 'label' ) {
            // Choice fields → selected choice label(s)
            if ( self::has_choices( $field ) ) {
                return self::resolve_choice_modifier( $field, $entry, 'label' );
            }
            // All others → admin field label
            return (string) $field->label;
        }

        if ( $modifier === 'value' ) {
            // Choice fields → selected choice value(s)
            if ( self::has_choices( $field ) ) {
                return self::resolve_choice_modifier( $field, $entry, 'value' );
            }
            // All others → raw entry value
            return (string) rgar( $entry, $field->id );
        }

        // ── Name field ────────────────────────────────────────────────────────
        if ( $field->type === 'name' && isset( self::NAME_SUBFIELDS[ $modifier ] ) ) {
            return (string) rgar( $entry, $field->id . self::NAME_SUBFIELDS[ $modifier ] );
        }

        // ── Address field ─────────────────────────────────────────────────────
        if ( $field->type === 'address' && isset( self::ADDRESS_SUBFIELDS[ $modifier ] ) ) {
            return (string) rgar( $entry, $field->id . self::ADDRESS_SUBFIELDS[ $modifier ] );
        }

        // ── Product field ─────────────────────────────────────────────────────
        if ( $field->type === 'product' && isset( self::PRODUCT_SUBFIELDS[ $modifier ] ) ) {
            $sub = rgar( $entry, $field->id . self::PRODUCT_SUBFIELDS[ $modifier ] );
            // For product name, fall back to field label when sub-input is empty (singleproduct)
            if ( $modifier === 'name' && $sub === '' ) {
                return (string) $field->label;
            }
            return (string) $sub;
        }

        // ── Date field ────────────────────────────────────────────────────────
        if ( $field->type === 'date' ) {
            $raw = rgar( $entry, $field->id );
            $ts  = $raw ? strtotime( $raw ) : 0;

            switch ( $modifier ) {
                case 'date':  return $ts ? date( 'Y-m-d', $ts ) : $raw;
                case 'day':   return $ts ? date( 'd', $ts ) : '';
                case 'month': return $ts ? date( 'm', $ts ) : '';
                case 'year':  return $ts ? date( 'Y', $ts ) : '';
            }
        }

        // ── File upload ───────────────────────────────────────────────────────
        if ( $field->type === 'fileupload' && $modifier === 'url' ) {
            $val = rgar( $entry, $field->id );
            if ( $field->multipleFiles ) {
                $files = json_decode( $val, true );
                return is_array( $files ) ? implode( "\n", $files ) : (string) $val;
            }
            return (string) $val;
        }

        // ── Fallback: try to find a matching input label ──────────────────────
        if ( ! empty( $field->inputs ) ) {
            foreach ( $field->inputs as $input ) {
                if ( strtolower( trim( $input['label'] ?? '' ) ) === $modifier ) {
                    return (string) rgar( $entry, (string) $input['id'] );
                }
            }
        }

        // Unknown modifier — return raw entry value
        return (string) rgar( $entry, $field->id );
    }

    /**
     * Resolve :label or :value for any field that has a choices array.
     * Handles checkbox-style (sub-inputs), multiselect (JSON/CSV), and single-select.
     */
    private static function resolve_choice_modifier( GF_Field $field, array $entry, string $modifier ) : string {
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
        } elseif ( self::is_multi_choice( $field ) ) {
            // multiselect / multi_choice (select style)
            foreach ( self::normalize_multi_values( rgar( $entry, $field->id ) ) as $v ) {
                if ( $modifier === 'label' ) {
                    $label = $v;
                    foreach ( $field->choices as $c ) {
                        if ( (string) $c['value'] === $v ) { $label = $c['text']; break; }
                    }
                    $items[] = $label;
                } else {
                    $items[] = $v;
                }
            }
        } else {
            // Single-select (radio, select, drop-down)
            $val = (string) rgar( $entry, $field->id );
            if ( $val === '' ) return '';

            if ( $modifier === 'label' ) {
                foreach ( $field->choices as $c ) {
                    if ( (string) $c['value'] === $val ) return $c['text'] ?? $val;
                }
                return $val;
            }
            return $val;
        }

        return implode( ', ', $items );
    }

    /**
     * Handles the {field_id:modifier} syntax when used in standard (non-custom) field mapping.
     * e.g. field_id stored as "26:label" in the feed config.
     */
    private static function resolve_modifier( string $field_modifier, array $entry, array $form ) : string {
        $parts    = explode( ':', $field_modifier, 2 );
        $raw_id   = $parts[0] ?? '';
        $modifier = strtolower( trim( $parts[1] ?? 'value' ) );
        $base_id  = explode( '.', $raw_id )[0];

        $field = self::get_field( $form, $base_id );
        if ( ! $field ) {
            return (string) rgar( $entry, $raw_id );
        }

        return self::resolve_named_modifier( $field, $modifier, $entry, $form );
    }

    private static function resolve_single_choice_from_loop( GF_Field $field, string $modifier, $context ) : string {
        $choice = null;

        if ( self::is_checkbox_style( $field ) ) {
            $choice = $field->choices[ $context ] ?? null;
        } else {
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
            $full_raw     = rgar( $entry, $field->id );
            $value_parts  = explode( '|', $full_raw );
            $clean_value  = $value_parts[0];
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
     * True when a field has a choices array (radio, select, checkbox, multiselect, multi_choice).
     */
    private static function has_choices( GF_Field $field ) : bool {
        return ! empty( $field->choices ) && is_array( $field->choices );
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