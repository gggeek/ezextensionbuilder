<?php
/**
 * A class containing all generic pake tasks
 *
 * @author    G. Giunta
 * @copyright (C) G. Giunta 2013
 * @license   code licensed under the GNU GPL 2.0: see README file
 */

namespace eZExtBuilder;

use pakeApp;

class GenericTasks extends Builder
{
    static function run_default( $task=null, $args=array(), $cliopts=array() )
    {
        pake_echo ( "eZ Extension Builder ver." . self::VERSION .
            "\n\nSyntax: ezextbuilder [--\$pake-options] \$task [\$extension] [--\$general-options] [--\$task-options].\n" .
            "  If no extension name is provided, a default configuration file will be searched for.\n" .
            "  General options:\n" .
            "    --config-dir=\$dir             to be used instead of ./pake\n" .
            "    --config-file=\$file           to be used instead of ./pake/options-\$ext.yaml\n" .
            "    --user-config-file=\$file      to be used instead of ./pake/options-user.yaml\n" .
            "    --option.\$option.\$name=\$value to override any configuration setting\n" .
            "  Run: ezextbuilder help to learn about the options for pake.\n" .
            "  Run: ezextbuilder --tasks to learn more about available tasks.\n"
        );
    }

    /**
     * Displays current-version number
     */
    static function run_tool_version( $task=null, $args=array(), $cliopts=array() )
    {
        pake_echo( "eZ Extension Builder ver." . self::VERSION . "\nRunning on pake " . pakeApp::VERSION );
    }

    /**
     * Shows the properties for the current build configuration files (and options given on command-line)
     */
    static function run_show_properties( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], $cliopts );
        pake_echo ( print_r( $opts, true ) );
    }

    /**
     * Displays the list of extensions which can be built (which have a config file available in the pake subdir)
     */
    static function run_list_extensions( $task=null, $args=array(), $cliopts=array() )
    {
        self::setConfigDir( $cliopts );
        $exts = self::getAvailableExtNames();
        switch( count( $exts ) )
        {
            case 0:
                pake_echo ( 'Available extensions: -' );
                break;
            case 1:
                pake_echo ( 'Available extensions: ' . $exts[0] . ' (default)' );
                break;
            default:
                pake_echo ( 'Available extensions: ' . implode( ', ', $exts ) );
        }
    }


    /**
     * Creates a sample yaml configuration file used to drive the build for a given extension.
     * Will ask to overwrite an existing config file if found, unless option overwrite-existing is given
     */
    static function run_generate_extension_config( $task=null, $args=array(), $cliopts=array() )
    {
        self::setConfigDir( $cliopts );
        $overwrite = @$cliopts['overwrite-existing'];
        if ( count( $args ) == 0 )
        {
            throw new pakeException( "Missing extension name" );
        }
        $extname = $args[0];
        $configfile = self::getOptionsDir() . "/options-$extname.yaml";
        if ( file_exists( $configfile ) && ! $overwrite )
        {
            pake_echo( "File $configfile already exists. Must overwrite it to continue" );
            $ok = pake_input( "Do you want to overwrite it? [y/n]", 'n' );
            if ( $ok != 'y' )
            {
                return;
            }
        }
        pake_mkdirs( self::getOptionsDir() );
        pake_copy( self::getResourceDir() . '/options-sample.yaml', $configfile, array( 'override' => true ) );
        pake_echo( "Created file $configfile, now go and edit it" );
    }

    /**
     * Downloads the yaml file used to drive the build for a given extension, from projects.ez.no/github/some random url.
     * You have to provide the url to the config file as 2nd parameter, unless your extension is set up on projects.ez.no,
     * in which case we try to figure it out automatically.
     * Will ask to overwrite an existing config file if found, unless option overwrite-existing is given
     */
    static function run_download_extension_config( $task=null, $args=array(), $cliopts=array() )
    {
        self::setConfigDir( $cliopts );
        $overwrite = @$cliopts['overwrite-existing'];
        if ( count( $args ) == 0 )
        {
            throw new pakeException( "Missing extension name" );
        }
        $extname = $args[0];
        if ( count( $args ) > 1 )
        {
            $exturl = $args[1];
        }
        else
        {
            /// @todo add support for custom branches

            $page = pake_read_file( 'http://projects.ez.no/' . $extname );
            if ( !preg_match( '#<a +href *= *"([^"]+)" [^>]+>Source</a>#', $page, $matches ) )
            {
                throw new pakeException( "Can not download or parse http://projects.ez.no/$extname" );
            }
            /// @todo we should test that $matches[1] is not an absolute url
            $exturl = 'http://projects.ez.no' . $matches[1];
            $extpage = pake_read_file( $exturl );
            if ( preg_match( '#<code>svn checkout <a href="([^"]+)">#', $extpage, $matches ) )
            {
                $source = 'svn';
                //$exturl = $matches[1];
            }
            else if ( preg_match( '#<a +href *= *"https://github.com/([^/]+)/([^"]+)"#', $extpage, $matches ) )
            {
                $source = 'github';
                $username = $matches[1];
                $gitext = rtrim( $matches[2], '/' );
            }
            else
            {
                throw new pakeException( "Can not download or parse $exturl" );
            }

            pake_echo ( "Scm system found: $source" );

            $targetfile = self::getOptionsDir() . "/options-$extname.yaml";
            if ( $source == 'github' )
            {
                $branch = 'master';

                $exturl = "https://github.com/$username/$gitext/raw/$branch/$targetfile";
            }
            elseif ( $source == 'svn' )
            {
                $extpage = pake_read_file( "http://svn.projects.ez.no/$extname" );
                if ( preg_match( '#<li><a href="([tT]runk)">[tT]runk</a></li>>#', $extpage, $matches ) )
                {
                    $branch = $matches[1];
                }
                else
                {
                    /// @todo what if there is no 'trunk' but there are branches?
                    $branch = '';
                }

                pake_echo ( "Branch found: $branch" );

                // for extensions still on projects.ez.no svn, try different possibilities
                $exturl = "http://svn.projects.ez.no/$extname/$branch/extension/$extname/$targetfile";
                if ( !file_exists( $exturl ) )
                {
                    $exturl = "http://svn.projects.ez.no/$extname/$branch/$targetfile";
                }
                if ( !file_exists( $exturl ) )
                {
                    $exturl = "http://svn.projects.ez.no/$extname/$branch/packages/{$extname}_extension/ezextension/$extname/$targetfile";
                }
                if ( !file_exists( $exturl ) )
                {
                    throw new pakeException( "Can not download from $source build config file $targetfile" );
                }
            }
            else
            {
                throw new pakeException( "Can not download from scm build config file for $extname" );
            }
        }

        /// @todo check that $extconf is a valid yaml file with minimal params
        $extconf = pake_read_file( $exturl );

        $configfile = self::getOptionsDir() . "/options-$extname.yaml";
        if ( file_exists( $configfile ) && ! $overwrite )
        {
            pake_echo( "File $configfile already exists. Must overwrite it to continue" );
            $ok = pake_input( "Do you want to overwrite it them? [y/n]", 'n' );
            if ( $ok != 'y' )
            {
                return;
            }
        }
        pake_mkdirs( self::getOptionsDir() );
        pake_write_file( $configfile, $extconf, true );
    }

    /**
     * Updates the yaml config file for an extension from its own scm url
     * This is mostly useful for the case of generic build servers.
     */
    /*
    static function run_update_extension_config( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0] );
        $destfile = self::getOptionsDir() . "/options-.yaml";
        if ( @$opts['svn']['url'] != '' )
        {
            pake_echo( 'Updating yaml config from SVN repository' );
            pakeSubversion::checkout( $opts['svn']['url'], $destfile );
            /// @todo test that we got at least one file
        }
        else if ( @$opts['git']['url'] != '' )
        {
            pake_echo( 'Updating yaml config from GIT repository' );
            pakeGit::clone_repository( $opts['git']['url'], $destfile );
            if ( @$opts['git']['branch'] != '' )
            {
                /// @todo test checking out a specific branch
                pakeGit::checkout_repo( $destdir, $opts['git']['branch'] );
                /// @todo test that we got at least one file
            }
        }
        else if ( @$opts['file']['url'] != '' )
        {
            pake_echo( 'Updating yaml config from local repository' );
            /// @todo (!important) exclude stuff we know we're going to delete immediately afterwards
            $files = pakeFinder::type( 'any' )->in( $opts['file']['url'] );
            if ( count( $files ) == 0 )
            {
                throw new pakeException( "Empty source repo option: no files found in {$opts['file']['url']}" );
            }

            pake_mirror( $files, $opts['file']['url'], $destdir );
        }
        else
        {
            throw new pakeException( "Missing source repo option: either svn:url, git:url or file:url" );
        }
    }*/

    /**
     * Converts an existing ant properties file in its corresponding yaml version
     *
     * Converts the .properties files used to hold configuration settings for old
     * versions of ezextensionbuilder (the ones based on ant) to a .yaml configuration
     * file that is suitable for this version of the script.
     * It is recommended to inspect by hand the generated .yaml file after executing
     * the conversion.
     */
    static function run_convert_configuration( $task=null, $args=array(), $cliopts=array() )
    {
        self::setConfigDir( $cliopts );
        $extname = @$args[0];
        if ( $extname == '' )
        {
            $extname = dirname( __FILE__ );
        }
        while ( !is_file( "ant/$extname.properties" ) )
        {
            $extname = pake_input( 'What is the name of the current extension?' );
            if ( !is_file( "ant/$extname.properties" ) )
            {
                pake_echo( "File ant/$extname.properties not found" );
            }
        }

        self::convertPropertyFileToYamlFile(
            "ant/$extname.properties",
            self::getConfigDir() . "/options-$extname.yaml",
            array( $extname => '', 'external' => 'dependencies', 'dependency' => 'extensions', 'repository' => array( 'svn', 'url' ) ),
            "extension:\n    name: $extname\n\n" );

        foreach( array( 'files.to.parse.txt' => 'to_parse', 'files.to.exclude.txt' => 'to_exclude' ) as $file => $option )
        {
            $src = "ant/$file";
            if ( file_exists( $src ) )
            {
                //$ok = !file_exists( $dst ) || ( pake_input( "Destionation file $dst exists. Overwrite? [y/n]", 'n' ) == 'y' );
                //$ok && pake_copy( $src, $dst, array( 'override' => true ) );
                if ( count( $in = file( $src, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES ) ) )
                {
                    $in = "\n\nfiles:\n    $option: [" . implode( ', ', $in ) . "]\n";
                    file_put_contents( self::getConfigDir() . "options-$extname.yaml", $in, FILE_APPEND );
                }
            }
        }
    }

} 