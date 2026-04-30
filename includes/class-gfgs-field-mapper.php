<?php
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
 * IMAGE CHOICE MODIFIERS
 *   {28}              Selected choice label(s)
 *   {28:img_url}      URL(s) of the selected image(s), one per line.
 *                     Works with single, multiple, range, and exact number modes.
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
 * 
 * @package GFGS
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFGS_Field_Mapper {

    // ── Sub-field maps ────────────────────────────────────────────────────────

	/** @var array<string, string> Named-modifier → sub-input suffix for name fields. */
    private const NAME_SUBFIELDS = [
        'prefix' => '.2',
        'first'  => '.3',
        'middle' => '.4',
        'last'   => '.6',
        'suffix' => '.8',
    ];

   	/** @var array<string, string> Named-modifier → sub-input suffix for address fields. */
    private const ADDRESS_SUBFIELDS = [
        'street'  => '.1',
        'street2' => '.2',
        'city'    => '.3',
        'state'   => '.4',
        'zip'     => '.5',
        'country' => '.6',
    ];

	/** @var array<string, string> Named-modifier → sub-input suffix for product fields. */
    private const PRODUCT_SUBFIELDS = [
        'name'  => '.1',
        'price' => '.2',
        'qty'   => '.3',
    ];

    /** @var string[] Entry-level meta keys accessible via the 'meta' field_type. */
	private const META_FIELDS = [
		'entry_id',
		'date_created',
		'source_url',
		'user_ip',
		'created_by',
		'payment_status',
	];

    // ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Build the full column→value row for one entry using a feed's field map.
	 *
	 * Each mapping in $field_map is expected to have:
	 *   - 'sheet_column' — the target column header string.
	 *   - 'field_id'     — the GF field ID or meta key.
	 *   - 'field_type'   — 'standard' | 'meta' | 'custom'.
	 *
	 * @param  array  $field_map    Feed field mappings.
	 * @param  array  $entry        GF entry array.
	 * @param  array  $form         GF form array.
	 * @param  array  $date_formats Optional map of column → PHP date format string.
	 * @return array<string, string>  column => resolved value.
	 */
	public static function build_row( $field_map, $entry, $form, $date_formats = [] ) {
		$row = [];

		foreach ( $field_map as $mapping ) {
			$column     = sanitize_text_field( $mapping['sheet_column'] ?? '' );
			$gf_field   = $mapping['field_id']  ?? '';
			$field_type = $mapping['field_type'] ?? 'standard';

			if ( '' === $column || '' === (string) $gf_field ) {
				continue;
			}

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

    /**
	 * Resolve a single field value to a sheet-ready string.
	 *
	 * @param  mixed       $field_id    GF field ID, meta key, or custom template string.
	 * @param  string      $field_type  'standard' | 'meta' | 'custom'.
	 * @param  array       $entry       GF entry array.
	 * @param  array       $form        GF form array.
	 * @param  string|null $date_format PHP date format string for date fields (optional).
	 * @return string
	 */
	public static function resolve_value( $field_id, $field_type, $entry, $form, $date_format = null ) {
		if ( 'custom' === $field_type ) {
			return self::interpolate_custom( (string) $field_id, $entry, $form );
		}

		if ( 'meta' === $field_type || in_array( $field_id, self::META_FIELDS, true ) ) {
			return self::resolve_meta( (string) $field_id, $entry );
		}

		// Colon modifier in field_id e.g. "26:label".
		if ( is_string( $field_id ) && strpos( $field_id, ':' ) !== false ) {
			return self::resolve_modifier( $field_id, $entry, $form );
		}

		$field = self::get_field( $form, (string) $field_id );

		if ( ! $field ) {
			return (string) ( $entry[ $field_id ] ?? '' );
		}

		if ( 'date' === $field->type && $date_format ) {
			return self::format_date_field( $field, $entry, $date_format );
		}

		return self::format_field( $field, $entry );
	}

	/**
	 * Evaluate a feed's conditional logic against an entry.
	 *
	 * Returns true (process feed) when:
	 *   - Conditions are not enabled, or
	 *   - No rules are defined, or
	 *   - GFCommon::evaluate_conditional_logic() returns true.
	 *
	 * @param  array $conditions Feed conditions array {enabled, logic, rules[]}.
	 * @param  array $entry      GF entry array.
	 * @param  array $form       GF form array.
	 * @return bool
	 */
	public static function check_conditions( $conditions, $entry, $form ) {
		if ( empty( $conditions['enabled'] ) || empty( $conditions['rules'] ) ) {
			return true;
		}

		$gf_conditions = [
			'logicType' => ( $conditions['logic'] ?? 'all' ) === 'all' ? 'all' : 'any',
			'rules'     => array_map(
				static function ( array $rule ): array {
					return [
						'fieldId'  => $rule['field_id'] ?? '',
						'operator' => $rule['operator']  ?? 'is',
						'value'    => $rule['value']      ?? '',
					];
				},
				$conditions['rules']
			),
		];

		return GFCommon::evaluate_conditional_logic( $gf_conditions, $form, $entry );
	}

    // ── Custom Template Interpolation ─────────────────────────────────────────

	/**
	 * Evaluate a multi-line custom template against an entry.
	 *
	 * Splits on newlines, processes each line independently, and joins
	 * non-empty results. Lines where every referenced field is empty are
	 * omitted so no blank rows appear in the sheet.
	 *
	 * @param  string $template Custom template string.
	 * @param  array  $entry    GF entry array.
	 * @param  array  $form     GF form array.
	 * @return string
	 */
	private static function interpolate_custom( $template, $entry, $form ) {
		$lines  = explode( "\n", $template );
		$output = [];

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$result = self::process_single_line( $line, $entry, $form );
			if ( '' !== $result ) {
				$output[] = $result;
			}
		}

		return implode( "\n", $output );
	}

	/**
	 * Process a single template line, handling multi-value field looping.
	 *
	 * For image_choice and other multi-choice fields, the line is rendered once
	 * per selected choice and the results are joined with newlines.
	 *
	 * @param  string $template Single line of a custom template.
	 * @param  array  $entry    GF entry array.
	 * @param  array  $form     GF form array.
	 * @return string
	 */
	private static function process_single_line( $template, $entry, $form ) {
		preg_match_all( '/\{(\d+)(?:(\.\d+(?:\.\d+)*)|:([\w]+))?\}/', $template, $tag_matches, PREG_SET_ORDER );

		if ( empty( $tag_matches ) ) {
			return $template; // Plain text — always output.
		}

		// Skip line if ALL referenced fields are empty.
		$all_empty = true;
		foreach ( $tag_matches as $tag ) {
			$base_id  = $tag[1];
			$dot_part = ( isset( $tag[2] ) && '' !== $tag[2] ) ? $tag[2] : null;
			$modifier = ( isset( $tag[3] ) && '' !== $tag[3] ) ? strtolower( $tag[3] ) : null;
			$field    = self::get_field( $form, $base_id );

			if ( ! $field ) {
				$raw_id = $dot_part ? $base_id . $dot_part : $base_id;
				$val    = rgar( $entry, $raw_id );
				if ( null !== $val && '' !== $val ) {
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

		if ( $all_empty ) {
			return '';
		}

		// Find the first multi-choice field referenced in this line.
		$found_ids = array_unique( array_column( $tag_matches, 1 ) );

		$repeater_field = null;
		foreach ( $found_ids as $id ) {
			$field = self::get_field( $form, $id );
			if ( $field && self::is_multi_choice( $field ) ) {
				$repeater_field = $field;
				break;
			}
		}

		// No repeater: single pass.
		if ( ! $repeater_field ) {
			return self::process_template_tags( $template, $entry, $form );
		}

		$results = [];

		if ( self::is_checkbox_style( $repeater_field ) ) {
			// Checkbox-style: values stored in sub-inputs.
			foreach ( (array) $repeater_field->inputs as $index => $input ) {
				if ( rgar( $entry, (string) $input['id'] ) ) {
					$row = self::process_template_tags( $template, $entry, $form, $repeater_field->id, $index );
					if ( '' !== $row ) {
						$results[] = $row;
					}
				}
			}
		} else {
			// CSV/JSON-style: values stored as a list in entry[field_id].
			$raw    = rgar( $entry, $repeater_field->id );
			$values = self::normalize_multi_values( $raw );

			if ( empty( $values ) && '' !== $raw && null !== $raw ) {
				$values = [ trim( (string) $raw ) ];
			}

			foreach ( $values as $val ) {
				$row = self::process_template_tags( $template, $entry, $form, $repeater_field->id, trim( $val ) );
				if ( '' !== $row ) {
					$results[] = $row;
				}
			}
		}

		return implode( "\n", $results );
	}

	/**
	 * Determine whether a field has a non-empty value in the entry.
	 *
	 * @param  GF_Field    $field    GF field object.
	 * @param  array       $entry    GF entry array.
	 * @param  string|null $dot_part Dot-notation sub-field suffix (e.g. '.3').
	 * @param  string|null $modifier Colon-notation modifier (e.g. 'label').
	 * @return bool
	 */
	private static function field_has_entry_value( $field, $entry, $dot_part, $modifier ) {
		if ( null !== $dot_part ) {
			$val = rgar( $entry, $field->id . $dot_part );
			return null !== $val && '' !== $val;
		}

		if ( self::is_checkbox_style( $field ) ) {
			foreach ( (array) $field->inputs as $input ) {
				if ( rgar( $entry, (string) $input['id'] ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'product' === $field->type ) {
			$qty = rgar( $entry, $field->id . '.3' );
			return null !== $qty && '' !== $qty && '0' !== $qty;
		}

		if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
			if ( null !== rgar( $entry, $field->id ) && '' !== rgar( $entry, $field->id ) ) {
				return true;
			}
			foreach ( $field->inputs as $input ) {
				$val = rgar( $entry, (string) $input['id'] );
				if ( null !== $val && '' !== $val ) {
					return true;
				}
			}
			return false;
		}

		if ( null !== $modifier ) {
			$sub_map = array_merge( self::NAME_SUBFIELDS, self::ADDRESS_SUBFIELDS, self::PRODUCT_SUBFIELDS );
			if ( isset( $sub_map[ $modifier ] ) ) {
				$val = rgar( $entry, $field->id . $sub_map[ $modifier ] );
				return null !== $val && '' !== $val && '0' !== $val;
			}
		}

		$val = rgar( $entry, $field->id );
		if ( is_array( $val ) ) {
			return ! empty( $val );
		}

		return null !== $val && '' !== $val;
	}

	/**
	 * Replace all template tags in a single line with their resolved values.
	 *
	 * When called inside a loop ($loop_id is set), tags referencing the
	 * repeater field are resolved against the current $loop_context value.
	 *
	 * @param  string      $template     Template line.
	 * @param  array       $entry        GF entry array.
	 * @param  array       $form         GF form array.
	 * @param  mixed       $loop_id      Field ID of the repeater field (null = no loop).
	 * @param  mixed       $loop_context Current loop value (sub-input index or choice value).
	 * @return string
	 */
	private static function process_template_tags( $template, $entry, $form, $loop_id = null, $loop_context = null ) {
		return preg_replace_callback(
			'/\{(\d+)(?:(\.\d+(?:\.\d+)*)|:([\w]+))?\}/',
			static function ( array $matches ) use ( $entry, $form, $loop_id, $loop_context ) {
				$base_id  = $matches[1];
				$dot_part = ( isset( $matches[2] ) && '' !== $matches[2] ) ? $matches[2] : null;
				$modifier = ( isset( $matches[3] ) && '' !== $matches[3] ) ? strtolower( $matches[3] ) : null;
				$raw_id   = $dot_part ? $base_id . $dot_part : $base_id;

				$field = self::get_field( $form, $base_id );

				if ( ! $field ) {
					return (string) ( $entry[ $raw_id ] ?? '' );
				}

				// Resolve within a repeater loop.
				if ( null !== $loop_id && $base_id === (string) $loop_id ) {
					if ( 'image_choice' === $field->type ) {
						return self::resolve_image_choice_in_loop( $field, $modifier, $loop_context );
					}

					if ( in_array( $modifier, [ 'label', 'value', null ], true ) ) {
						return self::resolve_single_choice_from_loop( $field, $modifier ?? 'value', $loop_context );
					}
				}

				if ( null !== $dot_part ) {
					return (string) rgar( $entry, $raw_id );
				}

				if ( null !== $modifier ) {
					return self::resolve_named_modifier( $field, $modifier, $entry, $form );
				}

				return self::format_field( $field, $entry );
			},
			$template
		);
	}

	/**
	 * Resolve an image_choice tag inside a repeater loop iteration.
	 *
	 * For checkbox-style, $loop_context is the sub-input array index.
	 * For radio/other,    $loop_context is the stored choice value string.
	 *
	 * @param  GF_Field    $field        The image_choice field.
	 * @param  string|null $modifier     Tag modifier ('img_url', 'value', 'label', or null).
	 * @param  mixed       $loop_context Current loop context.
	 * @return string
	 */
	private static function resolve_image_choice_in_loop( $field, $modifier, $loop_context ) {
		if ( self::is_checkbox_style( $field ) ) {
			$matched_choice = $field->choices[ $loop_context ] ?? null;
		} else {
			$stored_val     = (string) $loop_context;
			$matched_choice = null;
			foreach ( (array) $field->choices as $choice ) {
				if ( (string) ( $choice['value'] ?? '' ) === $stored_val ||
					 (string) ( $choice['text']  ?? '' ) === $stored_val ) {
					$matched_choice = $choice;
					break;
				}
			}
		}

		if ( 'img_url' === $modifier ) {
			return $matched_choice ? (string) ( $matched_choice['file_url'] ?? '' ) : '';
		}

		if ( 'value' === $modifier ) {
			return $matched_choice ? (string) ( $matched_choice['value'] ?? '' ) : '';
		}

		return $matched_choice ? (string) ( $matched_choice['text'] ?? '' ) : '';
	}

    // ── Named Modifier Resolution ─────────────────────────────────────────────

	/**
	 * Dispatch resolution for all named modifiers ({field_id:modifier}).
	 *
	 * @param  GF_Field $field    GF field object.
	 * @param  string   $modifier Modifier name (lower-cased).
	 * @param  array    $entry    GF entry array.
	 * @param  array    $form     GF form array.
	 * @return string
	 */
	private static function resolve_named_modifier(	$field, $modifier, $entry, $form ) {
		// Universal modifiers.
		if ( 'id' === $modifier ) {
			return (string) $field->id;
		}

		if ( 'label' === $modifier ) {
			return self::has_choices( $field )
				? self::resolve_choice_modifier( $field, $entry, 'label' )
				: (string) $field->label;
		}

		if ( 'value' === $modifier ) {
			return self::has_choices( $field )
				? self::resolve_choice_modifier( $field, $entry, 'value' )
				: (string) rgar( $entry, $field->id );
		}

		// Image choice: {field:img_url}.
		if ( 'img_url' === $modifier && 'image_choice' === $field->type ) {
			return self::resolve_image_choice_img_url( $field, $entry );
		}

		// Named sub-field aliases.
		if ( 'name' === $field->type && isset( self::NAME_SUBFIELDS[ $modifier ] ) ) {
			return (string) rgar( $entry, $field->id . self::NAME_SUBFIELDS[ $modifier ] );
		}

		if ( 'address' === $field->type && isset( self::ADDRESS_SUBFIELDS[ $modifier ] ) ) {
			return (string) rgar( $entry, $field->id . self::ADDRESS_SUBFIELDS[ $modifier ] );
		}

		if ( 'product' === $field->type && isset( self::PRODUCT_SUBFIELDS[ $modifier ] ) ) {
			$sub = rgar( $entry, $field->id . self::PRODUCT_SUBFIELDS[ $modifier ] );
			return ( 'name' === $modifier && '' === $sub ) ? (string) $field->label : (string) $sub;
		}

		// Date modifiers: {field:date}, {field:day}, {field:month}, {field:year}.
		if ( 'date' === $field->type ) {
			$raw = rgar( $entry, $field->id );
			$ts  = $raw ? strtotime( $raw ) : 0;
			switch ( $modifier ) {
				case 'date':  return $ts ? gmdate( 'Y-m-d', $ts ) : (string) $raw;
				case 'day':   return $ts ? gmdate( 'd', $ts ) : '';
				case 'month': return $ts ? gmdate( 'm', $ts ) : '';
				case 'year':  return $ts ? gmdate( 'Y', $ts ) : '';
			}
		}

		// File upload: {field:url}.
		if ( 'fileupload' === $field->type && 'url' === $modifier ) {
			$val = rgar( $entry, $field->id );
			if ( $field->multipleFiles ) {
				$files = json_decode( $val, true );
				return is_array( $files ) ? implode( "\n", $files ) : (string) $val;
			}
			return (string) $val;
		}

		// Fallback: try matching against input labels.
		if ( ! empty( $field->inputs ) ) {
			foreach ( $field->inputs as $input ) {
				if ( strtolower( trim( $input['label'] ?? '' ) ) === $modifier ) {
					return (string) rgar( $entry, (string) $input['id'] );
				}
			}
		}

		return (string) rgar( $entry, $field->id );
	}

	// ── Image Choice Resolvers ────────────────────────────────────────────────

	/**
	 * Resolve {field_id:img_url} outside of a loop (all selected images).
	 *
	 * Returns one URL per line for multi-select, or a single URL for radio.
	 *
	 * @param  GF_Field $field GF image_choice field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function resolve_image_choice_img_url( $field, $entry ) {
		if ( ! is_array( $field->choices ) || empty( $field->choices ) ) {
			return '';
		}

		// Checkbox-style: selections stored in sub-inputs.
		if ( self::is_checkbox_style( $field ) ) {
			$urls = [];
			foreach ( (array) $field->inputs as $index => $input ) {
				$val = rgar( $entry, (string) $input['id'] );
				if ( '' === $val || null === $val ) {
					continue;
				}
				$choice = $field->choices[ $index ] ?? null;
				if ( $choice && ! empty( $choice['file_url'] ) ) {
					$urls[] = (string) $choice['file_url'];
				}
			}
			return implode( "\n", $urls );
		}

		// Radio/other: single value in entry[field_id].
		$raw = rgar( $entry, $field->id );
		if ( '' === $raw || null === $raw ) {
			return '';
		}

		// Build value → file_url lookup (with text as a fallback key).
		$url_lookup = [];
		foreach ( $field->choices as $choice ) {
			$file_url = (string) ( $choice['file_url'] ?? '' );
			if ( '' === $file_url ) {
				continue;
			}
			$val  = (string) ( $choice['value'] ?? '' );
			$text = (string) ( $choice['text']  ?? '' );
			if ( '' !== $val ) {
				$url_lookup[ $val ] = $file_url;
			}
			if ( '' !== $text && ! isset( $url_lookup[ $text ] ) ) {
				$url_lookup[ $text ] = $file_url;
			}
		}

		$selected = self::normalize_multi_values( $raw );
		if ( empty( $selected ) ) {
			$selected = [ trim( (string) $raw ) ];
		}

		$urls = [];
		foreach ( $selected as $val ) {
			if ( isset( $url_lookup[ $val ] ) ) {
				$urls[] = $url_lookup[ $val ];
			}
		}

		return implode( "\n", $urls );
	}

	/**
	 * Resolve :label or :value for a field with a choices array.
	 *
	 * @param  GF_Field $field    GF field object.
	 * @param  array    $entry    GF entry array.
	 * @param  string   $modifier 'label' or 'value'.
	 * @return string  Comma-separated values for multi-select, single string otherwise.
	 */
	private static function resolve_choice_modifier( $field, $entry, $modifier ) {
		$items = [];

		if ( self::is_checkbox_style( $field ) ) {
			foreach ( (array) $field->inputs as $idx => $input ) {
				if ( rgar( $entry, (string) $input['id'] ) ) {
					$choice  = $field->choices[ $idx ] ?? null;
					$items[] = ( 'label' === $modifier )
						? ( $choice['text']  ?? $choice['value'] ?? '' )
						: ( $choice['value'] ?? '' );
				}
			}
		} elseif ( self::is_multi_choice( $field ) ) {
			foreach ( self::normalize_multi_values( rgar( $entry, $field->id ) ) as $v ) {
				if ( 'label' === $modifier ) {
					$label = $v;
					foreach ( $field->choices as $c ) {
						if ( (string) $c['value'] === $v ) {
							$label = $c['text'];
							break;
						}
					}
					$items[] = $label;
				} else {
					$items[] = $v;
				}
			}
		} else {
			// Single-select (radio, select, drop-down, image_choice radio).
			$val = (string) rgar( $entry, $field->id );
			if ( '' === $val ) {
				return '';
			}

			if ( 'label' === $modifier ) {
				foreach ( $field->choices as $c ) {
					if ( (string) $c['value'] === $val ) {
						return $c['text'] ?? $val;
					}
				}
				return $val;
			}

			return $val;
		}

		return implode( ', ', $items );
	}

	/**
	 * Handle the {field_id:modifier} syntax in standard (non-custom) field mapping.
	 *
	 * @param  string $field_modifier Combined "field_id:modifier" string.
	 * @param  array  $entry          GF entry array.
	 * @param  array  $form           GF form array.
	 * @return string
	 */
	private static function resolve_modifier( $field_modifier, $entry, $form ) {
		[ $raw_id, $modifier ] = array_pad( explode( ':', $field_modifier, 2 ), 2, 'value' );

		$modifier = strtolower( trim( $modifier ) );
		$base_id  = explode( '.', $raw_id )[0];
		$field    = self::get_field( $form, $base_id );

		if ( ! $field ) {
			return (string) rgar( $entry, $raw_id );
		}

		return self::resolve_named_modifier( $field, $modifier, $entry, $form );
	}
	/**
	 * Resolve a single choice from a repeater loop context.
	 *
	 * @param  GF_Field $field    GF field object.
	 * @param  string   $modifier 'label' or 'value'.
	 * @param  mixed    $context  Sub-input index (checkbox) or choice value string.
	 * @return string
	 */
	private static function resolve_single_choice_from_loop( $field, $modifier,	$context ) {
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

		if ( ! $choice ) {
			return '';
		}

		return ( 'label' === $modifier )
			? ( $choice['text'] ?? $choice['value'] )
			: $choice['value'];
	}

    // ── Meta Fields ───────────────────────────────────────────────────────────

	/**
	 * Resolve an entry-level meta key to its value.
	 *
	 * @param  string $field_id Meta field key.
	 * @param  array  $entry    GF entry array.
	 * @return string
	 */
	private static function resolve_meta( $field_id, $entry ) {
		// Some meta keys use different keys inside the entry array.
		$key_map = [
			'entry_id' => 'id',
			'user_ip'  => 'ip',
		];

		$key = $key_map[ $field_id ] ?? $field_id;
		return (string) ( $entry[ $key ] ?? '' );
	}

    // ── Field Formatters ──────────────────────────────────────────────────────

	/**
	 * Format a GF field value for output.
	 *
	 * Dispatches to a private format_{type}() method if one exists,
	 * otherwise falls back to GFCommon::get_lead_field_display().
	 *
	 * To add support for a new field type, add a private static format_{type}() method.
	 *
	 * @param  GF_Field $field GF field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	public static function format_field( $field, $entry ) {
		$method = 'format_' . $field->type;

		if ( method_exists( __CLASS__, $method ) ) {
			return self::$method( $field, $entry );
		}

		return GFCommon::get_lead_field_display( $field, rgar( $entry, $field->id ), $entry, false, 'text' );
	}

 	/**
	 * Format an image_choice field — resolves stored values to display labels.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_image_choice( $field, $entry ) {
		if ( self::is_checkbox_style( $field ) ) {
			$labels = [];
			foreach ( (array) $field->inputs as $index => $input ) {
				$val = rgar( $entry, (string) $input['id'] );
				if ( '' === $val || null === $val ) {
					continue;
				}
				$choice   = $field->choices[ $index ] ?? null;
				$labels[] = $choice
					? (string) ( $choice['text'] ?? $choice['value'] ?? $val )
					: (string) $val;
			}
			return implode( "\n", $labels );
		}

		$raw = rgar( $entry, $field->id );
		if ( '' === $raw || null === $raw ) {
			return '';
		}

		$resolve_label = static function ( string $stored ) use ( $field ): string {
			foreach ( (array) $field->choices as $choice ) {
				if ( (string) ( $choice['value'] ?? '' ) === $stored ||
					 (string) ( $choice['text']  ?? '' ) === $stored ) {
					return (string) ( $choice['text'] ?? $stored );
				}
			}
			return $stored;
		};

		$values = self::normalize_multi_values( $raw );
		if ( ! empty( $values ) ) {
			return implode( "\n", array_map( $resolve_label, $values ) );
		}

		return $resolve_label( trim( (string) $raw ) );
	}

 	/**
	 * Format multi choice values.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_multi_choice( $field, $entry ) {
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

	/**
	 * Format checkbox values.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_checkbox( $field, $entry ) {
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

	/**
	 * Format multi select values.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_multiselect( $field, $entry ) {
		return implode( "\n", self::normalize_multi_values( rgar( $entry, $field->id ) ) );
	}

	/**
	 * Format name.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_name( $field, $entry ) {
		return trim( implode( ' ', array_filter( [
			rgar( $entry, $field->id . '.2' ),
			rgar( $entry, $field->id . '.3' ),
			rgar( $entry, $field->id . '.6' ),
		] ) ) );
	}

	/**
	 * Format an address.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_address( $field, $entry ) {
		return implode( ', ', array_filter( [
			rgar( $entry, $field->id . '.1' ),
			rgar( $entry, $field->id . '.2' ),
			rgar( $entry, $field->id . '.3' ),
			rgar( $entry, $field->id . '.4' ),
			rgar( $entry, $field->id . '.5' ),
			rgar( $entry, $field->id . '.6' ),
		] ) );
	}

 	/**
	 * Format fileupload.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_fileupload( $field, $entry ) {
		$val = rgar( $entry, $field->id );

		if(is_array($val)){
			return implode("\n", $val);
		}

		$files = json_decode( $val, true );

		if(json_last_error() === JSON_ERROR_NONE && is_array($files)){
			return implode("\n", $files);
		}

		return (string) $val;
	}

	/**
	 * Format list.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_list( $field, $entry ) {
		$val = rgar( $entry, $field->id );
		if ( is_string( $val ) ) {
			$val = maybe_unserialize( $val );
		}
		if ( ! is_array( $val ) ) {
			return (string) $val;
		}

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

	/**
	 * Format product.
	 * 
	 * @param GF_Field $field GF Field object.
	 * @param  array    $entry GF entry array.
	 * @return string
	 */
	private static function format_product( $field, $entry ) {
		$product_name = '';
		$price        = '';
		$qty          = '';

		if ( 'singleproduct' === $field->inputType ) {
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

		if ( empty( $product_name ) ) {
			return '';
		}

		$parts = [ 'Product: ' . $product_name ];
		if ( '' !== $price ) $parts[] = 'Price: '    . $price;
		if ( '' !== $qty   ) $parts[] = 'Quantity: ' . $qty;

		return implode( ' | ', $parts );
	}

	/**
	 * Format a date field value using a custom PHP date format string.
	 *
	 * @param  GF_Field $field       Date field.
	 * @param  array    $entry       GF entry.
	 * @param  string   $date_format PHP date() format string, or 'timestamp'.
	 * @return string
	 */
	private static function format_date_field( $field, $entry, $date_format ) {
		$raw = rgar( $entry, $field->id );
		if ( empty( $raw ) ) {
			return '';
		}
		$ts = strtotime( $raw );
		if ( ! $ts ) {
			return $raw;
		}
		return ( 'timestamp' === $date_format ) ? (string) $ts : gmdate( $date_format, $ts );
	}

 	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Return true for field types that can hold multiple selected values.
	 *
	 * image_choice is multi-value only when inputType is not 'radio'.
	 *
	 * @param  GF_Field $field GF field object.
	 * @return bool
	 */
	private static function is_multi_choice( $field ) {
		if ( in_array( $field->type, [ 'checkbox', 'multiselect', 'multi_choice' ], true ) ) {
			return true;
		}

		if ( 'image_choice' === $field->type ) {
			return 'radio' !== strtolower( (string) ( $field->inputType ?? 'radio' ) );
		}

		return false;
	}

	/**
	 * Return true when selected values are stored across sub-inputs (e.g. 26.1, 26.2).
	 *
	 * @param  GF_Field $field GF field object.
	 * @return bool
	 */
	private static function is_checkbox_style( $field ) {
		return 'checkbox' === $field->type
			|| ( 'multi_choice'   === $field->type && 'checkbox' === $field->inputType )
			|| ( 'image_choice'   === $field->type && 'checkbox' === $field->inputType );
	}

	/**
	 * Return true when a field has a non-empty choices array.
	 *
	 * @param  GF_Field $field GF field object.
	 * @return bool
	 */
	private static function has_choices( $field ) {
		return ! empty( $field->choices ) && is_array( $field->choices );
	}

	/**
	 * Normalise a raw multi-value entry (PHP array, JSON string, or CSV)
	 * into a plain, trimmed, non-empty string array.
	 *
	 * @param  mixed $raw Raw entry value.
	 * @return string[]
	 */
	private static function normalize_multi_values( $raw ) {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', $raw ) ) );
		}

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'trim', $decoded ) ) );
			}
			return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
		}

		return [];
	}

	/**
	 * Find a GF_Field object by ID within a form's fields array.
	 *
	 * @param  array  $form     GF form array.
	 * @param  string $field_id Field ID to locate.
	 * @return GF_Field|null
	 */
	private static function get_field( $form, $field_id ) {
		foreach ( $form['fields'] as $field ) {
			if ( (string) $field->id === $field_id ) {
				return $field;
			}
		}
		return null;
	}
}