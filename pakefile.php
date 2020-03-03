<?php
/**
 * eZExtensionBuilder pakefile:
 * a script to build & package eZPublish extensions (focused on Legacy Stack)
 *
 * Needs the Pake tool to run: https://github.com/indeyets/pake/wiki
 *
 * It should be installed from the web via composer - just declare
 * "require-dev": { "gggeek/ezextensionbuilder": "*" } in your main composer.json file
 * or run "composer install" after installing it
 *
 * @author    G. Giunta
 * @copyright (C) G. Giunta 2011-2020
 * @license   code licensed under the GNU GPL 2.0: see README file
 *
 * @todo move all known paths/names/... to class constants
 *
 * @bug at least on win, after using svn/git to checkout a project, the script does
 *      not have enough rights to remove the .svn/.git & checkout dirs...
 */

// We allow this script to be used both
// 1. by having it in the current directory and invoking pake: pake --tasks
// 2. using direct invocation: ezextbuilder --tasks
// The second form is in fact preferred. It works also when pakefile.php is not in the current dir,
// such as when installed via composer (this is also possible using pake invocation using the -f switch)
if ( !function_exists( 'pake_task' ) )
{
    require( __DIR__ . '/pakefile_bootstrap.php' );
}
else
{

    // This is unfortunately a necessary hack: ideally we would always check for
    // proper pake version, but version 0.1 of this extension was
    // shipped with a faulty pake_version, so we cannot check for required version
    // when using the bundled pake.
    // To aggravate things, version 0.1 did not upgrade the bundled pake when
    // upgrading to a new script, so we can not be sure that, even if the end user
    // updates to a newer pakefile, the bundled pake will be upgraded
    // (it will only be when the user does two consecutive updates)
    // Last but not least, when using a pake version installed via composer, that
    // also does not come with proper version tag...
    if ( !( isset( $GLOBALS['internal_pake'] ) && $GLOBALS['internal_pake'] ) )
    {
        pake_require_version( eZExtBuilder\Builder::MIN_PAKE_VERSION );
    }

    // *** declaration of the pake tasks ***

    // NB: up to pake 1.99.1 this will not work
    //pake_task( 'eZExtBuilder\GenericTasks::default' );
    function run_default( $task=null, $args=array(), $cliopts=array() )
    {
        eZExtBuilder\GenericTasks::run_default( $task, $args, $cliopts );
    }

    pake_task( 'default' );


    pake_task( 'eZExtBuilder\BuildTasks::all',
        'build', 'dist', 'build-dependencies' );

    pake_task( 'eZExtBuilder\BuildTasks::build',
        'init', 'code-checks',
        'update-ezinfo', 'update-license-headers', 'update-extra-files', 'update-package-xml',
        'generate-documentation', 'generate-md5sums', 'generate-package-filelist' );

    pake_task( 'eZExtBuilder\BuildTasks::init' );

    pake_task( 'eZExtBuilder\BuildTasks::code-checks',
        'check-php-files', 'check-templates', 'check-sql-files', 'check-gnu-files' );

    pake_task( 'eZExtBuilder\BuildTasks::check-php-files' );

    pake_task( 'eZExtBuilder\BuildTasks::check-templates' );

    pake_task( 'eZExtBuilder\BuildTasks::check-sql-files' );

    pake_task( 'eZExtBuilder\BuildTasks::check-gnu-files' );

    pake_task( 'eZExtBuilder\BuildTasks::update-ezinfo' );

    pake_task( 'eZExtBuilder\BuildTasks::update-license-headers' );

    pake_task( 'eZExtBuilder\BuildTasks::update-extra-files' );

    pake_task( 'eZExtBuilder\BuildTasks::update-package-xml' );

    pake_task( 'eZExtBuilder\BuildTasks::generate-documentation' );

    pake_task( 'eZExtBuilder\BuildTasks::generate-md5sums' );

    pake_task( 'eZExtBuilder\BuildTasks::generate-package-filelist' );

    pake_task( 'eZExtBuilder\BuildTasks::dist' );

    pake_task( 'eZExtBuilder\BuildTasks::build-dependencies' );

    /*
    pake_desc( 'Creates an ezpackage tarball.' );
    pake_task( 'generate-package-tarball', 'update-package-xml', 'generate-package-filelist' );
    */

    pake_task( 'eZExtBuilder\BuildTasks::fat-dist' );

    pake_task( 'eZExtBuilder\BuildTasks::generate-sample-package-xml' );

    pake_task( 'eZExtBuilder\BuildTasks::clean-all',
        'clean', 'dist-clean' );

    pake_task( 'eZExtBuilder\BuildTasks::clean' );

    pake_task( 'eZExtBuilder\BuildTasks::dist-clean' );


    pake_task( 'eZExtBuilder\ReportTasks::all-code-reports',
        'code-quality-reports', 'code-metrics-reports' );

    pake_task( 'eZExtBuilder\ReportTasks::code-quality-reports',
        'coding-style-report', 'code-mess-report', 'copy-paste-report', 'dead-code-report' );

    pake_task( 'eZExtBuilder\ReportTasks::code-mess-report' );

    pake_task( 'eZExtBuilder\ReportTasks::coding-style-report' );

    pake_task( 'eZExtBuilder\ReportTasks::copy-paste-report' );

    pake_task( 'eZExtBuilder\ReportTasks::dead-code-report' );

    pake_task( 'eZExtBuilder\ReportTasks::code-metrics-reports',
        'php-loc-report', 'php-pdepend-report' );

    pake_task( 'eZExtBuilder\ReportTasks::php-loc-report' );

    pake_task( 'eZExtBuilder\ReportTasks::php-pdepend-report' );


    pake_task( 'eZExtBuilder\GenericTasks::tool-version' );

    pake_task( 'eZExtBuilder\GenericTasks::show-properties' );

    pake_task( 'eZExtBuilder\GenericTasks::list-extensions' );

    pake_task( 'eZExtBuilder\GenericTasks::generate-extension-config' );

    pake_task( 'eZExtBuilder\GenericTasks::download-extension-config' );

    pake_task( 'eZExtBuilder\GenericTasks::convert-configuration' );

}
