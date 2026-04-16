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
            $column   = sanitize_text_field( $mapping['sheet_column'] ?? '' );
            $gf_field = $mapping['field_id'] ?? '';
            $field_type = $mapping['field_type'] ?? 'standard';

            if ( '' === $column || '' === $gf_field ) continue;

            $date_format = $date_formats[ $column ] ?? null;
             $row[ $column ] = self::resolve_value( $gf_field, $field_type, $entry, $form, $date_format );
        }
        return $row;
    }

        // ── Value resolution ──────────────────────────────────────────────────────

    public static function resolve_value($field_id, string $field_type, array $entry, array $form, ?string $date_format = null): string{
       // Custom value — return as-is (supports literal text)
        if ( $field_type === 'custom' ) {
            return (string) $field_id;
        }
 
        // Entry meta fields
        if ( $field_type === 'meta' ) {
            return self::resolve_meta( $field_id, $entry );
        }
 
        // Standard: try meta keys first for backwards compat
        $meta_keys = [ 'entry_id', 'date_created', 'source_url', 'user_ip', 'created_by', 'payment_status' ];
        if ( in_array( $field_id, $meta_keys, true ) ) {
            return self::resolve_meta( $field_id, $entry );
        }
 
        // Standard GF field
        $field = self::get_field( $form, (string) $field_id );
 
        if ( ! $field ) {
            return (string) ( $entry[ $field_id ] ?? '' );
        }
 
        // Date formatting
        if ( $field->type === 'date' && $date_format ) {
            return self::format_date_field( $field, $entry, $date_format );
        }
 
        return self::format_field( $field, $entry );
    }

    private static function resolve_meta( string $field_id, array $entry ) : string {
        switch ( $field_id ) {
            case 'entry_id':       return (string) ( $entry['id']             ?? '' );
            case 'date_created':   return (string) ( $entry['date_created']   ?? '' );
            case 'source_url':     return (string) ( $entry['source_url']     ?? '' );
            case 'user_ip':        return (string) ( $entry['ip']             ?? '' );
            case 'created_by':     return (string) ( $entry['created_by']     ?? '' );
            case 'payment_status': return (string) ( $entry['payment_status'] ?? '' );
            default:               return (string) ( $entry[ $field_id ]      ?? '' );
        }
    }

    // ── Date field with format ─────────────────────────────────────────────────
 
    private static function format_date_field( GF_Field $field, array $entry, string $date_format ) : string {
        $raw = rgar( $entry, $field->id );
        if ( empty( $raw ) ) return '';
 
        if ( $date_format === 'timestamp' ) {
            $ts = strtotime( $raw );
            return $ts !== false ? (string) $ts : $raw;
        }
 
        $ts = strtotime( $raw );
        return $ts !== false ? date( $date_format, $ts ) : $raw;
    }
 
    // ── Field formatters ───────────────────────────────────────────────────────
 
    public static function format_field(GF_Field $field, array $entry): string{
        switch($field->type){
            case 'list':
                return self::format_list($field, $entry);
            
            case 'product':
                return self::format_product($field, $entry);
            
            case 'checkbox':
                return self::format_checkbox( $field, $entry );

            case 'name':
                return self::format_name( $field, $entry );

            case 'address':
                return self::format_address( $field, $entry );

            case 'fileupload':
                return self::format_fileupload( $field, $entry );

            case 'multiselect':
                $val = rgar($entry, $field->id);
                return is_array($val) ? implode(', ', $val) : ($val ?? '');
            
            default:
                $val = GFFormsModel::get_lead_field_value($entry, $field);
                return GFCommon::get_lead_field_display($field, $val, '', false, 'text');
        }
    }

        // ── Special field formatters ──────────────────────────────────────────────
    private static function format_list(GF_Field $field, array $entry):string{
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
        $product_name = rgar( $entry, $field->id . '.3' ) ?: rgar( $entry, $field->id );
        $price        = rgar( $entry, $field->id . '.2' );
        $qty          = rgar( $entry, $field->id . '.4' );

        $parts = [];
        if ( $product_name !== '' ) $parts[] = 'Product: ' . $product_name;
        if ( $price !== '' )        $parts[] = 'Price: '   . $price;
        if ( $qty !== '' )          $parts[] = 'Qty: '     . $qty;

        return implode( ' | ', $parts );
    }

    private static function format_checkbox( GF_Field $field, array $entry ) : string {
        $checked = [];
        foreach ( $field->choices as $choice ) {
            $input_id = $field->get_input_id_from_choice_value( $choice['value'] ?? '' );
            if ( rgar( $entry, $input_id ) ) {
                $checked[] = $choice['text'] ?? $choice['value'];
            }
        }
        return implode( ', ', $checked );
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

    // ── Utility ───────────────────────────────────────────────────────────────

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
 
        // Map our rule keys to GF's expected format
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