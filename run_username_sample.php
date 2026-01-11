<?php
// Temporary script to run username sample generation from CLI for diagnostics.
// Run with: C:\MAMP\bin\php\php8.2.14\php.exe run_username_sample.php

chdir( __DIR__ );
require_once __DIR__ . '/../../../wp-load.php';

// Ensure plugin autoloaded
if ( ! class_exists( 'ARG_LLM' ) ) {
    echo "ARG_LLM class not loaded\n";
    exit(1);
}

$diagn = ARG_LLM::generate_usernames( 10, array( 'diagnostics' => true, 'candidate_multiplier' => 3 ) );

// Pretty-print diagnostics
echo "\n=== Username Sample Diagnostics ===\n";
var_export( $diagn );
echo "\n=== End ===\n";

if ( $diagn ) {
    echo "\nSaved diagnostics to option arg_last_username_sample.\n";
}

// Now run a mock response test to exercise parsing and raw fallback
$mock_texts = <<<'EOT'
["alpha1", "Beta-User", "charlie_33",]
Some preface [ "delta", "echo", "foxtrot" ] and more text
["unclosed"
EOT;
$mock_body = json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $mock_texts, ), 'finish_reason' => 'length' ) ) ) );

$diagn2 = ARG_LLM::generate_usernames( 10, array( 'diagnostics' => true, 'candidate_multiplier' => 3, 'mock_remote_body' => $mock_body ) );

echo "\n=== Mock Response Diagnostics ===\n";
var_export( $diagn2 );
echo "\n=== End Mock ===\n";

if ( $diagn2 ) {
    echo "\nSaved mock diagnostics to option arg_last_username_sample.\n";
}
