<?php
// Unit tests cover only classes with no WordPress dependencies.
// vendor/ is absent when PHPUnit runs from a standalone phar.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}
require_once dirname( __DIR__ ) . '/includes/class-siteherd-signer.php';
