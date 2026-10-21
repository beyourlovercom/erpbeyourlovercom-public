<?php
/**
 * Disposable CI WordPress/MySQL fixture ONLY.
 * Called via WP-CLI against the runner database: no real data, no email.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! getenv( 'M2_CREDENTIALS_FILE' ) ) { exit( 8 ); }
if ( ! defined( 'YBY_NEWSLETTER_SCHEMA_APPLY' ) ) { define( 'YBY_NEWSLETTER_SCHEMA_APPLY', true ); }
if ( ! YBY_Newsletter_Store::install_schema() ) { fwrite( STDERR, "M2_JOINT_CORE_SCHEMA_FAILED\n" ); exit( 1 ); }

global $wpdb;
$site = YBY_Newsletter_Store::site_key();
if ( $site !== '127.0.0.1' ) { fwrite( STDERR, "M2_JOINT_WRONG_DISPOSABLE_SITE\n" ); exit( 1 ); }
$table = YBY_Newsletter_Store::table_name();
$names = array(
    'm2joint-pending@example.test' => 'pending',
    'm2joint-confirmed@example.test' => 'subscribed',
    'm2joint-unsubscribed@example.test' => 'unsubscribed',
);
foreach ( $names as $email => $status ) {
    $now = '2026-10-10 03:00:00';
    $row = array(
        'email' => $email, 'email_hash' => hash( 'sha256', $email ),
        'external_subscription_id' => YBY_Newsletter_Consent::external_id( $site, $email ),
        'site_key' => $site, 'status' => $status,
        'consent_source' => 'andy_core_newsletter_form',
        'consent_policy' => 'newsletter-v1', 'form_source' => 'm2-joint-synthetic',
        'source_page' => '/test/', 'locale' => 'en-US',
        'submitted_at' => $now,
        'subscribed_at' => 'subscribed' === $status ? $now : null,
        'unsubscribed_at' => 'unsubscribed' === $status ? $now : null,
        'status_updated_at' => $now,
        'confirm_hash' => null, 'confirm_expires_at' => null,
        'unsubscribe_hash' => hash( 'sha256', 'm2-runner-only-' . $email ),
        'created_at' => $now, 'updated_at' => $now,
    );
    if ( 1 !== $wpdb->insert( $table, $row ) ) { fwrite( STDERR, "M2_JOINT_INSERT_FAILED\n" ); exit( 1 ); }
}
// Historical Elementor snapshot source: same confirmed address, separate consent fact.
$forms = $wpdb->prefix . 'e_submissions';
$values = $wpdb->prefix . 'e_submissions_values';
$col = chr( 96 ) . 'key' . chr( 96 );
$wpdb->query( "CREATE TABLE " . $forms . " (id bigint unsigned NOT NULL AUTO_INCREMENT, form_name varchar(100), created_at_gmt datetime, PRIMARY KEY(id))" );
$wpdb->query( "CREATE TABLE " . $values . " (id bigint unsigned NOT NULL AUTO_INCREMENT, submission_id bigint unsigned, {$col} varchar(100), value varchar(255), PRIMARY KEY(id))" );
if ( 1 !== $wpdb->insert( $forms, array( 'form_name' => 'Signup', 'created_at_gmt' => '2026-10-09 02:00:00' ) ) ) { exit( 1 ); }
if ( 1 !== $wpdb->insert( $values, array( 'submission_id' => $wpdb->insert_id, 'key' => 'email', 'value' => 'm2joint-confirmed@example.test' ) ) ) { exit( 1 ); }

if ( ! YBY_Connector::save( array( 'enabled' => true, 'connection_key' => 'beyourlover.com', 'key_id' => 'm2-joint-ci-only' ) ) ) { exit( 1 ); }
$secret = YBY_Connector::generate_secret();
if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) { exit( 1 ); }
$destination = getenv( 'M2_CREDENTIALS_FILE' );
if ( false === file_put_contents( $destination, $secret ) ) { exit( 1 ); }
chmod( $destination, 0600 );
echo "M2_JOINT_CORE_FIXTURES_PASS 3 native synthetic states + 1 Elementor fact, no mail\n";
