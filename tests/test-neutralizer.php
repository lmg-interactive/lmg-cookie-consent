<?php
require __DIR__ . '/bootstrap.php';

// Load the class without WordPress. ABSPATH guard would exit; define it.
define( 'ABSPATH', __DIR__ );
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
require __DIR__ . '/../includes/class-lmg-consent-blocker.php';

function neut( $html ) {
    return LMG_Consent_Blocker::neutralize_html( $html, LMG_Consent_Blocker::default_patterns() );
}

$doc = "<!doctype html><html><head>%s</head><body></body></html>";

it( 'neutralizes a gtag.js loader as analytics', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>' );
    $out = neut( $in );
    assert_contains( 'type="text/plain"', $out );
    assert_contains( 'data-lmg-consent="analytics"', $out );
    assert_contains( 'src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"', $out );
} );

it( 'neutralizes a gtm.js container as marketing', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-XYZ"></script>' );
    $out = neut( $in );
    assert_contains( 'data-lmg-consent="marketing"', $out );
} );

it( 'neutralizes legacy analytics.js as analytics', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script src="https://www.google-analytics.com/analytics.js"></script>' );
    assert_contains( 'data-lmg-consent="analytics"', neut( $in ) );
} );

it( 'replaces an existing type attribute (text/plain wins)', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script type="text/javascript" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' );
    $out = neut( $in );
    assert_contains( 'type="text/plain"', $out );
    assert_not_contains( 'type="text/javascript"', $out );
} );

it( 'is idempotent — does not double-process', function () use ( $doc ) {
    $in   = sprintf( $doc, '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' );
    $once = neut( $in );
    $twice= neut( $once );
    assert_eq( $once, $twice );
} );

it( 'leaves inline gtag config untouched', function () use ( $doc ) {
    $inline = '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("config","G-1");</script>';
    $out = neut( sprintf( $doc, $inline ) );
    assert_not_contains( 'data-lmg-consent', $out );
    assert_contains( 'gtag("config","G-1")', $out );
} );

it( 'leaves unrelated external scripts untouched', function () use ( $doc ) {
    $out = neut( sprintf( $doc, '<script src="https://cdn.example.com/app.js"></script>' ) );
    assert_not_contains( 'data-lmg-consent', $out );
} );

it( 'neutralizes multiple loaders on one page', function () use ( $doc ) {
    $in  = sprintf( $doc,
        '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' .
        '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-2"></script>' );
    $out = neut( $in );
    assert_eq( 2, substr_count( $out, 'data-lmg-consent' ) );
} );

it( 'returns non-HTML input unchanged', function () {
    $json = '{"foo":"https://www.googletagmanager.com/gtag/js?id=G-1"}';
    assert_eq( $json, neut( $json ) );
} );

it( 'handles single-quoted src', function () use ( $doc ) {
    $in  = sprintf( $doc, "<script async src='https://www.googletagmanager.com/gtag/js?id=G-Q'></script>" );
    assert_contains( 'data-lmg-consent="analytics"', neut( $in ) );
} );

it( 'blocking_enabled defaults true', function () {
    assert_true( LMG_Consent_Blocker::blocking_enabled() === true );
} );

done();
