<?php
/**
 * Plugin Name: GF Gibberish Name Spam Filter
 * Description: Marks Gravity Forms submissions as spam when a first or last name looks like random keyboard mash (e.g. "DGVYwSUSadrHbGahYB"). Spam entries are saved with Spam status and no notifications are sent.
 * Author:      WenMark Digital
 */

/**
 * Looks like keyboard-mash spam: a single run of 10+ letters, in mixed case,
 * with 3+ uppercase letters after the first character. Real names such as
 * "McDonald" or "VanDerWoodsen" have at most two internal capitals, and names
 * typed in all caps are ignored.
 *
 * @param string $value A name value.
 *
 * @return bool
 */
function scruffy_gf_is_gibberish_name( $value ) {
	$value = trim( (string) $value );
	if ( ! preg_match( '/^[A-Za-z]{10,}$/', $value ) || ! preg_match( '/[a-z]/', $value ) ) {
		return false;
	}

	return preg_match_all( '/[A-Z]/', substr( $value, 1 ) ) >= 3;
}

/**
 * Returns the first gibberish name value found in an entry's Name fields.
 *
 * @param array $form  The form.
 * @param array $entry The entry.
 *
 * @return string The matching value, or an empty string if none matched.
 */
function scruffy_gf_find_gibberish_name( $form, $entry ) {
	foreach ( GFAPI::get_fields_by_type( $form, array( 'name' ) ) as $field ) {
		$keys = empty( $field->inputs ) ? array( (string) $field->id ) : array( "{$field->id}.3", "{$field->id}.6" );
		foreach ( $keys as $key ) {
			$value = rgar( $entry, $key );
			if ( scruffy_gf_is_gibberish_name( $value ) ) {
				return $value;
			}
		}
	}

	return '';
}

add_filter( 'gform_entry_is_spam', function ( $is_spam, $form, $entry ) {
	if ( $is_spam ) {
		return $is_spam;
	}

	$match = scruffy_gf_find_gibberish_name( $form, $entry );
	if ( '' === $match ) {
		return false;
	}

	// Shows up in the spam entry's note, e.g. "Reason: Name "DGVYwSUSadrHbGahYB" looks randomly generated."
	GFCommon::set_spam_filter( rgar( $form, 'id' ), 'Gibberish Name', sprintf( 'Name "%s" looks randomly generated.', $match ) );

	return true;
}, 10, 3 );
