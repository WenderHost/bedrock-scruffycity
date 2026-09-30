<?php
/**
 * Check existing Gravity Forms entries for spam using Akismet and/or a
 * gibberish-name heuristic, and optionally move the flagged entries to Spam.
 *
 * Dry run by default: nothing is changed unless `apply` is passed.
 *
 * Usage:
 *   wp eval-file bin/scripts/gf-akismet-check.php form=<id> [mark=akismet|pattern|either|both] [limit=<n>] [skip-akismet] [format=table|csv] [apply]
 *
 * Arguments (key=value, no leading dashes, since `wp eval-file` only passes positional args):
 *   form=<id>       Required. The Gravity Forms form ID to check.
 *   mark=<rule>     Which verdict marks an entry as spam. Default: akismet.
 *                     akismet  Akismet says spam
 *                     pattern  Name heuristic says spam
 *                     either   Either one says spam
 *                     both     Both say spam
 *   limit=<n>       Only check the newest <n> active entries. Default: all.
 *   skip-akismet    Don't call Akismet (heuristic only; implies mark=pattern).
 *   format=<fmt>    Output format for the report: table (default) or csv.
 *   apply           Actually move flagged entries to Spam. Doing so also reports
 *                   each one to Akismet as spam, the same as the "Mark as spam"
 *                   bulk action in the admin.
 *
 * Examples:
 *   wp eval-file bin/scripts/gf-akismet-check.php form=1
 *   wp eval-file bin/scripts/gf-akismet-check.php form=1 mark=either format=csv > report.csv
 *   wp eval-file bin/scripts/gf-akismet-check.php form=1 mark=either apply
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this with `wp eval-file`.\n" );
}

if ( ! class_exists( 'GFAPI' ) ) {
	WP_CLI::error( 'Gravity Forms is not active.' );
}

// Parse key=value / flag positional args.
$opts = array();
foreach ( $args as $arg ) {
	$parts = explode( '=', ltrim( $arg, '-' ), 2 );
	$opts[ $parts[0] ] = isset( $parts[1] ) ? $parts[1] : true;
}

$form_id      = isset( $opts['form'] ) ? absint( $opts['form'] ) : 0;
$apply        = ! empty( $opts['apply'] );
$skip_akismet = ! empty( $opts['skip-akismet'] );
$mark         = $skip_akismet ? 'pattern' : ( isset( $opts['mark'] ) ? $opts['mark'] : 'akismet' );
$limit        = isset( $opts['limit'] ) ? absint( $opts['limit'] ) : 0;
$format       = isset( $opts['format'] ) ? $opts['format'] : 'table';

if ( ! $form_id ) {
	WP_CLI::error( 'Missing required argument form=<id>.' );
}
if ( ! in_array( $mark, array( 'akismet', 'pattern', 'either', 'both' ), true ) ) {
	WP_CLI::error( "Invalid mark=$mark. Use akismet, pattern, either, or both." );
}
if ( ! in_array( $format, array( 'table', 'csv' ), true ) ) {
	WP_CLI::error( "Invalid format=$format. Use table or csv." );
}
if ( $skip_akismet && isset( $opts['mark'] ) && 'pattern' !== $opts['mark'] ) {
	WP_CLI::error( 'skip-akismet only works with mark=pattern.' );
}

$form = GFAPI::get_form( $form_id );
if ( ! $form ) {
	WP_CLI::error( "Form $form_id not found." );
}

if ( ! $skip_akismet ) {
	if ( ! GFCommon::has_akismet() ) {
		WP_CLI::error( 'Akismet is not active. Use skip-akismet to run the name heuristic only.' );
	}
	if ( ! Akismet::get_api_key() ) {
		WP_CLI::error( 'Akismet has no API key configured.' );
	}
}

/**
 * Looks like keyboard-mash spam: a single run of 10+ letters with 3+ uppercase
 * letters after the first character, e.g. "DGVYwSUSadrHbGahYB". Real names such
 * as "McDonald" or "DeShawn" have at most one or two internal capitals.
 */
$is_gibberish = function ( $value ) {
	$value = trim( (string) $value );
	// Skip non-letter values and names typed in all caps.
	if ( ! preg_match( '/^[A-Za-z]{10,}$/', $value ) || ! preg_match( '/[a-z]/', $value ) ) {
		return false;
	}
	return preg_match_all( '/[A-Z]/', substr( $value, 1 ) ) >= 3;
};

// Collect the first/last name values from the form's first Name field.
$name_fields = GFAPI::get_fields_by_type( $form, array( 'name' ) );
$name_field  = $name_fields ? $name_fields[0] : null;
$get_names   = function ( $entry ) use ( $name_field ) {
	if ( ! $name_field ) {
		return array( '', '' );
	}
	$id = $name_field->id;
	if ( empty( $name_field->inputs ) ) {
		return array( rgar( $entry, (string) $id ), '' );
	}
	return array( rgar( $entry, "$id.3" ), rgar( $entry, "$id.6" ) );
};

$email_fields = GFAPI::get_fields_by_type( $form, array( 'email' ) );
$email_id     = $email_fields ? (string) $email_fields[0]->id : null;

if ( ! $name_field && 'akismet' !== $mark ) {
	WP_CLI::warning( 'This form has no Name field, so the name heuristic will never flag anything.' );
}

$paging = array( 'offset' => 0, 'page_size' => $limit ? $limit : 10000 );
$sort   = array( 'key' => 'id', 'direction' => 'DESC' );
$ids    = GFAPI::get_entry_ids( $form_id, array( 'status' => 'active' ), $sort, $paging );

if ( ! $ids ) {
	WP_CLI::success( "No active entries in form $form_id." );
	return;
}

fwrite( STDERR, sprintf( "Checking %d active entries in form %d (%s), mark=%s%s...\n", count( $ids ), $form_id, $form['title'], $mark, $apply ? ', APPLYING' : ', dry run' ) );

$rows    = array();
$flagged = array();
$counts  = array( 'akismet' => 0, 'pattern' => 0 );

$progress = 'table' === $format ? WP_CLI\Utils\make_progress_bar( 'Checking', count( $ids ) ) : null;

foreach ( $ids as $id ) {
	$entry = GFAPI::get_entry( $id );
	if ( is_wp_error( $entry ) ) {
		WP_CLI::warning( "Entry $id: " . $entry->get_error_message() );
		continue;
	}

	list( $first, $last ) = $get_names( $entry );

	$pattern_spam = $is_gibberish( $first ) || $is_gibberish( $last );
	$akismet_spam = $skip_akismet ? null : GFCommon::is_akismet_spam( $form, $entry );

	switch ( $mark ) {
		case 'akismet':
			$spam = $akismet_spam;
			break;
		case 'pattern':
			$spam = $pattern_spam;
			break;
		case 'either':
			$spam = $akismet_spam || $pattern_spam;
			break;
		default:
			$spam = $akismet_spam && $pattern_spam;
	}

	$counts['akismet'] += $akismet_spam ? 1 : 0;
	$counts['pattern'] += $pattern_spam ? 1 : 0;
	if ( $spam ) {
		$flagged[] = $id;
	}

	$rows[] = array(
		'id'      => $id,
		'date'    => $entry['date_created'],
		'name'    => trim( "$first $last" ),
		'email'   => $email_id ? rgar( $entry, $email_id ) : '',
		'akismet' => null === $akismet_spam ? '-' : ( $akismet_spam ? 'spam' : 'ham' ),
		'pattern' => $pattern_spam ? 'spam' : 'ham',
		'flagged' => $spam ? 'YES' : '',
	);

	if ( $progress ) {
		$progress->tick();
	}
}

if ( $progress ) {
	$progress->finish();
}

WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'date', 'name', 'email', 'akismet', 'pattern', 'flagged' ) );

// Summary goes to STDERR so `format=csv > file.csv` stays clean.
$summary = sprintf(
	'%d checked. Akismet: %s spam. Pattern: %d spam. Flagged (mark=%s): %d.',
	count( $rows ),
	$skip_akismet ? 'n/a' : $counts['akismet'],
	$counts['pattern'],
	$mark,
	count( $flagged )
);
fwrite( STDERR, $summary . "\n" );

if ( ! $apply ) {
	fwrite( STDERR, "Dry run: nothing changed. Re-run with `apply` to move flagged entries to Spam.\n" );
	return;
}

$moved = 0;
foreach ( $flagged as $id ) {
	$result = GFAPI::update_entry_property( $id, 'status', 'spam' );
	if ( false === $result ) {
		WP_CLI::warning( "Entry $id: could not update status." );
		continue;
	}
	$moved++;
}

WP_CLI::success( "Moved $moved of " . count( $flagged ) . ' flagged entries to Spam.' );
