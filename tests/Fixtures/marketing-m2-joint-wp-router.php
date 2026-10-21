<?php
/**
 * Loopback-only disposable WordPress REST HTTP router for GitHub runner E2E.
 * Actual Core registered REST callback and authenticated HMAC dispatch are used.
 * Synthetic HTTPS flag is ONLY for localhost PHP -S; never production web.
 */
$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
if ( 0 !== strpos( $uri, '/wp-json/andy-core/v1/erp/snapshot/subscribers' ) ) {
    http_response_code( 404 );
    header( 'Content-Type: application/json' );
    echo '{"ok":false,"message":"isolated test route only"}';
    return true;
}
$docroot = getenv( 'M2_WP_DOCROOT' );
if ( ! $docroot || ! file_exists( $docroot . '/wp-load.php' ) ) {
    http_response_code( 503 );
    echo '{"ok":false}';
    return true;
}
$_SERVER['HTTPS'] = 'on';
require $docroot . '/wp-load.php';
$request = new WP_REST_Request( 'GET', '/andy-core/v1/erp/snapshot/subscribers' );
$request->set_query_params( $_GET );
foreach ( array(
    'X-YBY-Key-Id',
    'X-YBY-Connection-Key',
    'X-YBY-Timestamp',
    'X-YBY-Nonce',
    'X-YBY-Signature-Version',
    'X-YBY-Signature',
) as $header ) {
    $key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
    if ( isset( $_SERVER[$key] ) ) {
        $request->set_header( $header, (string) $_SERVER[$key] );
    }
}
$result = rest_do_request( $request );
http_response_code( $result->get_status() );
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
echo wp_json_encode( $result->get_data() );
return true;
