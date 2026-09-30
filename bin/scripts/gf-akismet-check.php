<?php
/**
 * Check existing Gravity Forms entries for spam using Akismet and/or a
 * gibberish-name heuristic, and optionally move the flagged entries to Spam.
 *
 * Run with no arguments (or `help`) to print usage:
 *   wp eval-file bin/scripts/gf-akismet-check.php
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this with `wp eval-file`.\n" );
}

$help = <<<'HELP'
NAME

  wp eval-file bin/scripts/gf-akismet-check.php

DESCRIPTION

  Checks existing Gravity Forms entries for spam using a gibberish-name
  heuristic (and Akismet, when it's active), and optionally moves flagged
  entries to Spam.

  The heuristic is the same one the GF Gibberish Name Spam Filter mu-plugin
  applies to new submissions, so this is for cleaning up older entries.

  Dry run by default: nothing is changed unless `apply` is passed.

SYNOPSIS

  wp eval-file bin/scripts/gf-akismet-check.php form=<id> [mark=<rule>] [limit=<n>] [skip-akismet] [format=<format>] [apply]

  Options are passed as key=value words with no leading dashes, because
  `wp eval-file` only accepts positional arguments.

OPTIONS

  form=<id>
    The Gravity Forms form ID to check. Required.

  [mark=<rule>]
    Which verdict marks an entry as spam. Any rule other than `pattern`
    requires Akismet.
    ---
    default: pattern
    options:
      - akismet   Akismet says spam
      - pattern   The name heuristic says spam
      - either    Either one says spam
      - both      Both say spam
    ---

  [limit=<n>]
    Only check the newest <n> active entries. Default: all active entries.

  [skip-akismet]
    Don't call Akismet even if it's active. Only valid with mark=pattern.

  [format=<format>]
    Render the report in a particular format. The summary line is written to
    STDERR, so `format=csv > report.csv` produces a clean file.
    ---
    default: table
    options:
      - table
      - csv
    ---

  [apply]
    Move flagged entries to Spam. Like the "Mark as spam" bulk action in the
    admin, this also reports each entry to Akismet as spam, so review a dry
    run first.

NAME HEURISTIC

  Flags an entry when any first or last name is a single word of 10+ letters,
  in mixed case, with 3+ capitals after the first letter
  (e.g. "DGVYwSUSadrHbGahYB"). Names like "McDonald" or "VanDerWoodsen" and
  names typed in all caps are not flagged.

EXAMPLES

    # Dry run: check all active entries in form 1
    $ wp eval-file bin/scripts/gf-akismet-check.php form=1

    # Heuristic only (no Akismet calls), saved to a CSV for review
    $ wp eval-file bin/scripts/gf-akismet-check.php form=1 skip-akismet format=csv > report.csv

    # Check only the newest 50 entries
    $ wp eval-file bin/scripts/gf-akismet-check.php form=1 limit=50

    # Move entries flagged by the heuristic to Spam
    $ wp eval-file bin/scripts/gf-akismet-check.php form=1 apply
HELP;

if ( empty( $args ) || in_array( ltrim( $args[0], '-' ), array( 'help', 'h' ), true ) ) {
	WP_CLI::line( $help );

	// List the site's forms so the right form=<id> is easy to find.
	if ( class_exists( 'GFAPI' ) ) {
		WP_CLI::line( "\nAVAILABLE FORMS\n" );
		foreach ( GFAPI::get_forms() as $f ) {
			WP_CLI::line( sprintf( '  %-4d %s (%d active entries)', $f['id'], $f['title'], GFAPI::count_entries( $f['id'], array( 'status' => 'active' ) ) ) );
		}
	}
	return;
}

if ( ! class_exists( 'GFAPI' ) ) {
	WP_CLI::error( 'Gravity Forms is not active.' );
}

// The name heuristic lives in the mu-plugin so the live spam filter and this script always agree.
if ( ! function_exists( 'scruffy_gf_find_gibberish_name' ) ) {
	WP_CLI::error( 'The GF Gibberish Name Spam Filter mu-plugin (web/app/mu-plugins/gf-gibberish-name-spam.php) is not loaded.' );
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
$mark         = isset( $opts['mark'] ) ? $opts['mark'] : 'pattern';
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

$form = GFAPI::get_form( $form_id );
if ( ! $form ) {
	WP_CLI::error( "Form $form_id not found." );
}

// Akismet is optional: check with it only when it's active and has a key.
$use_akismet = ! $skip_akismet && GFCommon::has_akismet() && Akismet::get_api_key();
if ( 'pattern' !== $mark && ! $use_akismet ) {
	WP_CLI::error( $skip_akismet ? "mark=$mark needs Akismet, but skip-akismet was passed." : "mark=$mark needs Akismet, which is not active or has no API key." );
}

// Collect the first/last name values from the form's first Name field for the report.
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

	$pattern_spam = '' !== scruffy_gf_find_gibberish_name( $form, $entry );
	$akismet_spam = $use_akismet ? GFCommon::is_akismet_spam( $form, $entry ) : null;

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
	$use_akismet ? $counts['akismet'] : 'n/a',
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
