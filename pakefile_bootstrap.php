<?php
/**
 * eZExtensionBuilder pakefile bootstrapper.
 *
 * This code allows the original pakefile.php to be invoked standalone, which helps with it being stored in a separate dir
 * from the build dir (which is also supported by native pake but buggy at least up to pake 1.7.4).
 * It is messy by nature :-)
 *
 * @author    G. Giunta
 * @copyright (C) G. Giunta 2011-2013
 * @license   code licensed under the GNU GPL 2.0: see README file
 */

if ( class_exists( 'pakeApp' ) )
{
    // This should not happen, but just in case this file is included after pake is already loaded
    if ( !isset( $GLOBALS['internal_pake'] ) )
    {
        $GLOBALS['internal_pake'] = false;
    }
}
else
{
    // If installed via composer, set up composer autoloading
    if ( ( file_exists( __DIR__ . '/../../autoload.php' )  && $composerautoload = __DIR__ . '/../../autoload.php' ) ||
        ( file_exists( __DIR__ . '/vendor/autoload.php' )  && $composerautoload = __DIR__ . '/vendor/autoload.php' ) )
    {
        include_once( $composerautoload );
    }

    // We look if pake is found in
    // - the folder where composer installs it (assuming this is also installed by composer) - taken care by code above
    // - the folder where composer installs it (assuming this is is the root project) - taken care by code above
    // - the folder where this script used to install it (before composer  usage, versions up to 0.4): ./pake/src
    if ( !class_exists( 'pakeApp' ) && ( file_exists( 'pake/src/bin/pake.php' ) /*&& $pakesrc = 'pake/src/bin/pake.php' ) ||
        ( file_exists( __DIR__ . '/../../indeyets/pake/bin/pake.php' ) && $pakesrc = __DIR__ . '/../../indeyets/pake/bin/pake.php' ) ||
        ( file_exists( __DIR__ . '/vendor/indeyets/pake/bin/pake.php' ) && $pakesrc = __DIR__ . '/vendor/indeyets/pake/bin/pake.php' )*/ ) )
    {
        include_once( 'pake/src/bin/pake.php' );
    }

    if ( !class_exists( 'pakeApp' ) )
    {
        echo "Pake tool not found. Bootstrap needed.\nTry running 'composer install' or 'composer update'\n";
        exit( -1 );
    }

    $GLOBALS['internal_pake'] = true;

}

// Try harder to set up ezc autoloading.
// Only useful in non-composer mode (including pake.php might have reset the include path from env var PHP_CLASSPATH),
if ( !class_exists( 'ezcBase' ) )
{
    @include( 'ezc/Base/base.php' ); // pear install
    if ( !class_exists( 'ezcBase' ) )
    {
        @include( 'Base/src/base.php' ); // tarball download / svn install
    }
    if ( class_exists( 'ezcBase' ) )
    {
        spl_autoload_register( array( 'ezcBase', 'autoload' ) );
    }
}


if ( !function_exists( 'pake_exception_default_handler' ) )
{
    // same bootstrap code as done by pake_cli_init.php, which we do not bother searching for in the composer dir
    function pake_exception_default_handler( $exception )
    {
        pakeException::render( $exception );
        exit( 1 );
    }
}
set_exception_handler( 'pake_exception_default_handler' );
mb_internal_encoding( 'utf-8' );

// pakeApp will include again the main pakefile.php, and execute all the pake_task() calls found in it
$pake = pakeApp::get_instance();
if ( getcwd() !== __DIR__ )
{
    // Running from another directory compared to where pakefile is.
    // Pake 1.7.4 and earlier has a bug: it does not support specification of pakefile.php using absolute paths, at least on windows
    /// @todo to support pakefile.php in other locations, subclass pakeApp and override load_pakefile()
    $retval = $pake->run( preg_replace( '#^' . preg_quote( getcwd() . DIRECTORY_SEPARATOR ) . '#', '', __DIR__ . '/pakefile.php' ) );
}
else
{
    $retval = $pake->run();
}

if ($retval === false )
{
    exit(1);
}

?>
