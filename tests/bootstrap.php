<?php
// Zero-dependency assertion harness (no PHPUnit — vendor/ ships to all sites).
$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function it( $name, callable $fn ) {
    try {
        $fn();
        echo "  ok  - $name\n";
    } catch ( \Throwable $e ) {
        $GLOBALS['__fails']++;
        echo "  FAIL- $name\n        " . $e->getMessage() . "\n";
    }
    $GLOBALS['__tests']++;
}

function assert_true( $cond, $msg = 'expected true' ) {
    if ( ! $cond ) { throw new \Exception( $msg ); }
}
function assert_contains( $needle, $haystack, $msg = '' ) {
    if ( strpos( $haystack, $needle ) === false ) {
        throw new \Exception( $msg ?: "expected to contain: $needle\n        got: $haystack" );
    }
}
function assert_not_contains( $needle, $haystack, $msg = '' ) {
    if ( strpos( $haystack, $needle ) !== false ) {
        throw new \Exception( $msg ?: "expected NOT to contain: $needle\n        got: $haystack" );
    }
}
function assert_eq( $expected, $actual, $msg = '' ) {
    if ( $expected !== $actual ) {
        throw new \Exception( $msg ?: "expected [$expected] got [$actual]" );
    }
}

function done() {
    echo "\n{$GLOBALS['__tests']} tests, {$GLOBALS['__fails']} failures\n";
    exit( $GLOBALS['__fails'] > 0 ? 1 : 0 );
}
