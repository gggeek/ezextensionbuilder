<?php
/**
 * A class containing all pake tasks related to report generation
 *
 * @author    G. Giunta
 * @copyright (C) G. Giunta 2013
 * @license   code licensed under the GNU GPL 2.0: see README file
 */

namespace eZExtBuilder;

use pakeException;

class ReportTasks extends Builder
{
    /**
     * Generates all code reports (NB: this can take a while)
     *
     * We rely on the pake dependency system to do the real stuff
     * (run pake -P to see tasks included in this one)
     */
    static function run_all_code_reports( $task=null, $args=array(), $cliopts=array() )
    {
    }

    /**
     * Generates all code quality reports (NB: this can take a while)
     *
     * We rely on the pake dependency system to do the real stuff
     * (run pake -P to see tasks included in this one)
     */
    static function run_code_quality_reports( $task=null, $args=array(), $cliopts=array() )
    {
    }

    /**
     * Generates a "code messyness" report using PHPMD. The rules to check can be set via configuration options
     */
    static function run_code_mess_report( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getReportDir( $opts ) . '/' . $opts['extension']['name'];
        $phpmd = self::getTool( 'phpmd', $opts, true );
        /*$out = '';
        if ( $opts['tools']['phpmd']['report']  != '' )
        {
            $out = " > " . escapeshellarg( $opts['tools']['phpmd']['report'] );
        }*/
        try
        {
            // phpmd will exit with a non-0 value as soon as there is any violation (which generates an exception in pake_sh),
            // but we do not consider this a fatal error, as we are only generating reports
            $out  = pake_sh( "$phpmd " . escapeshellarg( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) . " " .
                escapeshellarg( $opts['tools']['phpmd']['format'] ) . " " .
                escapeshellarg( $opts['tools']['phpmd']['rules'] ) );
        }
        catch ( pakeException $e )
        {
            $out = preg_replace( '/^Problem executing command/', '', $e->getMessage() );
        }
        pake_mkdirs( $destdir );
        pake_write_file( $destdir . '/phpmd.' . str_replace( 'text', 'txt', $opts['tools']['phpmd']['format'] ), $out, true );

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Generates a "coding style violations" report using PHPCodeSniffer.
     * The rules to check can be set via configuration options, default being "ezcs" (@see https://github.com/ezsystems/ezcs)
     */
    static function run_coding_style_report( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getReportDir( $opts ) . '/' . $opts['extension']['name'];
        $phpcs = self::getTool( 'phpcs', $opts, true );

        // in case we use the standard rule set, try to install it (after composer has downloaded it)
        // nb: this could become a task of its own...
        $rulesDir = self::getVendorDir() . '/squizlabs/php_codesniffer/Codesniffer/Standards/' . $opts['tools']['phpcs']['rules'] ;
        if ( !is_dir( $rulesDir ) )
        {
            if ( $opts['tools']['phpcs']['rules'] == 'ezcs' )
            {
                $sourceDir = self::getVendorDir() . '/ezsystems/ezcs/php/ezcs';
                if ( is_dir( $sourceDir ) )
                {
                    pake_symlink( $sourceDir, $rulesDir );
                }
            }
        }

        // phpcs will exit with a non-0 value as soon as there is any violation (which generates an exception in pake_sh),
        // but we do not consider this a fatal error, as we are only generating reports
        try
        {
            $out = pake_sh( "$phpcs --standard=" . escapeshellarg( $opts['tools']['phpcs']['rules'] ) . " " .
                "--report=" . escapeshellarg( $opts['tools']['phpcs']['format'] ) . " " .
                // if we do not filter on php files, phpcs can go in a loop trying to parse tpl files
                "--extensions=php " . /*"--encoding=utf8 " .*/
                escapeshellarg( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) );
        }
        catch ( pakeException $e )
        {
            $out = preg_replace( '/^Problem executing command/', '', $e->getMessage() );
        }
        pake_mkdirs( $destdir );
        pake_write_file( $destdir . '/phpcs.txt', $out, true );

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Generates a "copy-pasted code" report using phpcpd
     */
    static function run_copy_paste_report( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getReportDir( $opts ) . '/' . $opts['extension']['name'];
        $phpcpd = self::getTool( 'phpcpd', $opts, true );
        // phpcpd will exit with a non-0 value as soon as there is any violation (which generates an exception in pake_sh),
        // but we do not consider this a fatal error, as we are only generating reports
        try
        {
            $out = pake_sh( "$phpcpd " .
                escapeshellarg( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) );
        }
        catch ( pakeException $e )
        {
            $out = preg_replace( '/^Problem executing command/', '', $e->getMessage() );
        }
        pake_mkdirs( $destdir );
        pake_write_file( $destdir . '/phpcpd.txt', $out, true );

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Generates all code metrics reports (NB: this can take a while)
     *
     * We rely on the pake dependency system to do the real stuff
     * (run pake -P to see tasks included in this one)
     */
    static function run_code_metrics_reports( $task=null, $args=array(), $cliopts=array() )
    {
    }

    /**
     * Generates a "lines of code" report using phploc.
     */
    static function run_php_loc_report( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getReportDir( $opts ) . '/' . $opts['extension']['name'];
        $phploc = self::getTool( 'phploc', $opts, true );

        $out = pake_sh( "$phploc -n " .
            escapeshellarg( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) );

        pake_mkdirs( $destdir );
        pake_write_file( $destdir . '/phploc.txt', $out, true );

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Generates images and xml report using pdepend.
     */
    static function run_php_pdepend_report( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getReportDir( $opts ) . '/' . $opts['extension']['name'];
        $pdepend = self::getTool( 'pdepend', $opts, true );

        pake_mkdirs( $destdir );
        $out = pake_sh( $pdepend .
            " --jdepend-chart=" . escapeshellarg( self::getReportDir( $opts ) . '/' . $opts['extension']['name'] . '/jdependchart.svg' ) .
            " --overview-pyramid=" . escapeshellarg( self::getReportDir( $opts ) . '/' . $opts['extension']['name'] . '/overview-pyramid.svg' ) .
            " --summary-xml=" . escapeshellarg( self::getReportDir( $opts ) . '/' . $opts['extension']['name'] . '/summary.xml' ) .
            " " . escapeshellarg( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) );

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Generates a "lines of code" report using phploc.
     */
    static function run_dead_code_report( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getReportDir( $opts ) . '/' . $opts['extension']['name'];
        $phpdcd = self::getTool( 'phpdcd', $opts, true );

        $out = pake_sh( "$phpdcd " .
            escapeshellarg( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) );

        pake_mkdirs( $destdir );
        pake_write_file( $destdir . '/phpdcd.txt', $out, true );

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }
} 