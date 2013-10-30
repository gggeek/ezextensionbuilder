<?php
/**
 * eZExtensionBuilder pakefile:
 * a script to build & package eZPublish extensions
 *
 * Needs the Pake tool to run: https://github.com/indeyets/pake/wiki
 *
 * It should be installed from the web via composer - just declare
 * "require-dev": { "gggeek/ezextensionbuilder": "*" } in your main composer.json file
 *
 * @author    G. Giunta
 * @copyright (C) G. Giunta 2011-2013
 * @license   code licensed under the GNU GPL 2.0: see README file
 *
 * @todo move all known paths/names/... to class constants
 *
 * @todo register custom pake tasks in classes to trim down this file
 *
 * @bug at least on win, after using svn/git to checkout a project, the script does
 *      not have enough rights to remove the .svn/.git & checkout dirs...
 */

// We allow this script to be used both
// 1. by having it in the current directory and invoking pake: pake --tasks
// 2. using direct invocation: php pakefile.php --tasks
// The second form is in fact preferred. It works also when pakefile.php is not in the current dir,
// such as when installed via composer
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
    pake_require_version( eZExtBuilder::$min_pake_version );
}

// *** definition of the pake tasks ***

function run_default()
{
    pake_echo ( "eZ Extension Builder ver." . eZExtBuilder::$version .
        "\nSyntax: php pakefile.php [--\$general-options] \$task [\$extension] [--\$task-options].\n" .
        "  If no extension name is provided, a default configuration file will be searched for.\n" .
        "  Run: php pakefile.php --tasks to learn more about available tasks." );
}

/**
 * Creates a sample  yaml file used to drive the build for a given extension
 * Existing config files are overwritten.
 */
function run_generate_extension_config( $task=null, $args=array(), $cliopts=array() )
{
    if ( count( $args ) == 0 )
    {
        throw new pakeException( "Missing extension name" );
    }
    $extname = $args[0];
    $configfile = eZExtBuilder::getOptionsDir() . "/options-$extname.yaml";
    if ( file_exists( $configfile ) )
    {
        pake_echo( "File $configfile already exists. Must overwrite it to continue" );
        $ok = pake_input( "Do you want to overwrite it? [y/n]", 'n' );
        if ( $ok != 'y' )
        {
            return;
        }
    }
    pake_mkdirs( eZExtBuilder::getOptionsDir() );
    pake_copy( __DIR__ . '/options-sample.yaml', $configfile, array( 'override' => true ) );
    pake_echo( "Created file $configfile, now go and edit it" );
}

/**
 * Downloads the yaml file used to drive the build for a given extension, from projects.ez.no/github/some random url.
 * This is mostly useful for the case of generic build servers.
 * You have to provide the url to the config file as 2nd parameter, unless your extension is set up on projects.ez.no,
 * in which case we try to figure it out automatically.
 */
function run_download_extension_config( $task=null, $args=array(), $cliopts=array() )
{
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

        $targetfile = eZExtBuilder::getOptionsDir() . "/options-$extname.yaml";
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

    $configfile = eZExtBuilder::getOptionsDir() . "/options-$extname.yaml";
    if ( file_exists( $configfile ) )
    {
        pake_echo( "File $configfile already exists. Must overwrite it to continue" );
        $ok = pake_input( "Do you want to overwrite it them? [y/n]", 'n' );
        if ( $ok != 'y' )
        {
            return;
        }
    }
    pake_mkdirs( eZExtBuilder::getOptionsDir() );
    pake_write_file( $configfile, $extconf, true );
}

/**
 * Updates the yaml config file for an extension from its own scm url
 * This is mostly useful for the case of generic build servers.
 */
/*
function run_update_extension_config( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destfile = eZExtBuilder::getOptionsDir() . "/options-.yaml";
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
 * Displays the list of extensions which can be built (i.e. which have a config file available in the pake subdir)
 */
function run_list_extensions( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getAvailableExtNames();
    switch( count( $opts ) )
    {
        case 0:
            pake_echo ( 'Available extensions: -' );
            break;
        case 1:
            pake_echo ( 'Available extensions: ' . $opts[0] . ' (default)' );
            break;
        default:
            pake_echo ( 'Available extensions: ' . implode( ', ', $opts ) );
    }
}

/**
 * Shows the properties for this build file
 * @todo show more properties
 */
function run_show_properties( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    pake_echo ( print_r( $opts, true ) );
}

/**
 * Downloads the extension from its source repository, removes files not to be built
 * (the list of files to be removed is in part hardcoded and in part specified in
 * the configuration file)
 *
 * @todo add a dependency on a check-updates task that updates script itself?
 * @todo split this in two tasks and avoid this unsightly mess of options?
 */
function run_init( $task=null, $args=array(), $cliopts=array() )
{
    $skip_init = @$cliopts['skip-init'];
    $skip_init_fetch = @$cliopts['skip-init-fetch'] || $skip_init;
    $skip_init_clean = @$cliopts['skip-init-clean'] || $skip_init;

    if ( ! $skip_init )
    {
        $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
        pake_mkdirs( eZExtBuilder::getBuildDir( $opts ) );

        $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    }

    if ( ! $skip_init_fetch )
    {
        if ( @$opts['svn']['url'] != '' )
        {
            pake_echo( 'Fetching code from SVN repository' );
            pakeSubversion::checkout( $opts['svn']['url'], $destdir );
            /// @todo test that we got at least one file
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            {
                sleep( 3 );
            }
        }
        else if ( @$opts['git']['url'] != '' )
        {
            pake_echo( 'Fetching code from GIT repository' );
            pakeGit::clone_repository( $opts['git']['url'], $destdir );
            if ( @$opts['git']['branch'] != '' )
            {
                /// @todo test checking out a specific branch
                pakeGit::checkout_repo( $destdir, $opts['git']['branch'] );
                /// @todo test that we got at least one file
            }
            if ( strtoupper( substr( PHP_OS, 0, 3) ) === 'WIN' )
            {
                sleep( 3 );
            }
        }
        else if ( @$opts['file']['url'] != '' )
        {
            pake_echo( 'Fetching code from local repository' );
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
    }


    // remove files
    if ( ! $skip_init_clean )
    {
        // known files/dirs not to be packed / md5'ed
        /// @todo !important shall we make this configurable?
        /// @bug 'build' & 'dist' we should probably take from options
        $files = array( 'ant/', 'build.xml', '**/.svn', '.git/', 'build/', 'dist/' );
        // hack! when packing ourself, we need to keep this stuff
        if ( $opts['extension']['name'] != 'ezextensionbuilder' )
        {
            $files = array_merge( $files, array( 'pake/', 'pakefile.php', '**/.gitignore' ) );
        }
        // files from user configuration
        $files = array_merge( $files, $opts['files']['to_exclude'] );

        /// we figured a way to allow user to specify both:
        ///       files in a specific subdir
        ///       files to be removed globally (ie. from any subdir)
        //pakeFinder::type( 'any' )->name( $files )->in( $destdir );
        $files = pake_antpattern( $files, $destdir );
        foreach( $files as $key => $file )
        {
            if ( is_dir( $file ) )
            {
                pake_remove_dir( $file );
                unset( $files[$key] );
            }
        }
        pake_remove( $files, '' );
    }

    if ( ! $skip_init )
    {
        // move package file where it has to be
        $file = pakeFinder::type( 'file' )->name( 'package.xml' )->maxdepth( 0 )->in( $destdir );
        if ( count( $file ) )
        {
            if ( $opts['create']['tarball'] || $opts['create']['zip'] )
            {
                pake_rename( $destdir . '/package.xml', $destdir . '/../../package.xml' );
            }
            else
            {
                pake_remove( $file, '' );
            }
        }
    }
}

/**
 * Builds the extension; options: --skip-init
 *
 * We rely on the pake dependency system to do the real stuff
 * (run pake -P to see tasks included in this one)
 */
function run_build( $task=null, $args=array(), $cliopts=array() )
{
}

/**
 * Removes the build/ directory
 */
function run_clean( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    pake_remove_dir( $opts['build']['dir'] );
}

/**
 * Creates a tarball of the built extension
 *
 * Depending on configuration options, different versions of the extenion tarball
 * are generated by this task: .tar.gz, .zip, .ezpkg
 */
function run_dist( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    if ( $opts['create']['tarball'] || $opts['create']['zip'] || $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
    {
        pake_mkdirs( $opts['dist']['dir'] );
        $rootpath = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

        if ( $opts['create']['tarball'] )
        {
            $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.tar.gz';
            eZExtBuilder::archiveDir( $rootpath, $target );
        }

        if ( $opts['create']['zip'] )
        {
            $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.zip';
            eZExtBuilder::archiveDir( $rootpath, $target );
        }

        if ( $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
        {
            $toppath = $opts['build']['dir'];

            // check if package.xml file is there
            $file = pakeFinder::type( 'file' )->name( 'package.xml' )->maxdepth( 0 )->in( $toppath );
            if ( !count( $file ) )
            {
                pake_echo_error( "File 'package.xml' missing in build dir $rootpath. Cannot create package(s)" );
                return;
            }

            // cleanup if extra files/dirs found
            $dirs = array();
            $dirs = pakeFinder::type( 'directory' )->not_name( array( 'documents', 'ezextension' ) )->maxdepth( 0 )->in( $toppath );
            $dirs = array_merge( $dirs, pakeFinder::type( 'directory' )->in( $toppath . '/documents' ) );
            $dirs = array_merge( $dirs, pakeFinder::type( 'directory' )->not_name( $opts['extension']['name'] )->maxdepth( 0 )->in( $toppath . '/ezextension' ) );
            $files = pakeFinder::type( 'file' )->not_name( 'package.xml' )->maxdepth( 0 )->in( $toppath );
            $files = array_merge( $files, pakeFinder::type( 'file' )->in( $toppath . '/documents' ) );
            $files = array_merge( $files, pakeFinder::type( 'file' )->not_name( 'extension-' . $opts['extension']['name']. '.xml' )->maxdepth( 0 )->in( $toppath . '/ezextension' ) );
            if ( count( $dirs ) || count( $files ) )
            {
                pake_echo( "Extra files/dirs found in build dir. Must remove them to continue:\n  " . implode( "\n  ", $dirs ) . "  ". implode( "\n  ", $files ) );
                $ok = pake_input( "Do you want to delete them? [y/n]", 'n' );
                if ( $ok != 'y' )
                {
                    return;
                }
                foreach( $files as $file )
                {
                    pake_remove( $file, '' );
                }
                foreach( $dirs as $dir )
                {
                    pake_remove_dir( $dir );
                }
            }
            // prepare missing folders/files
            /// @todo we should not blindly copy LICENSE and README, but inspect actual package.xml file
            ///       and copy any files mentioned there
            pake_copy( $rootpath . '/' . $opts['files']['gnu_dir'] . '/LICENSE', $toppath . '/documents/LICENSE' );
            pake_copy( $rootpath . '/' . $opts['files']['gnu_dir'] . '/README', $toppath . '/documents/README' );
            $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '_extension.ezpkg';
            eZExtBuilder::archiveDir( $toppath, $target, true );

            if ( $opts['create']['pearpackage'] )
            {
                /// @todo ...
                pake_echo_error( "PEAR package creation not yet implemented" );
            }
        }

    }
}

/**
 * Removes the dist/ directory
 */
function run_dist_clean( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    pake_remove_dir( $opts['dist']['dir'] );
}

/**
 * Builds dependent extensions
 */
function run_build_dependencies( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $current = $opts['extension']['name'];
    foreach( $opts['dependencies']['extensions'] as $ext => $source )
    {
        // avoid loops
        if ( $ext != $current )
        {
            // create a temporary config file to drive the init task
            // this could be done better in memory...
            foreach( $source as $type => $def )
            {
                break;
            }
            $tempconf = array( 'extension' => array( 'name' => $ext ),  'version' => array( 'major' => 0, 'minor' => 0, 'release' => 0 ), $type => $def );
            $tempconffile = eZExtBuilder::getOptionsDir() . "/options-tmp_$ext.yaml";
            pakeYaml::emitfile( $tempconf, $tempconffile );

            // download remote extension
            // nb: we can not run the init task here via invoke() because of already_invoked status,
            // so we use execute(). NB: this is fine as long as init has no prerequisites
            $task = pakeTask::get( 'init' );
            $task->execute( array( "tmp_$ext" ), array_merge( $cliopts, array( 'skip-init' => false, 'skip-init-fetch' => false, 'skip-init-clean' => true ) ) );

            // copy config file from ext dir to current config dir
            if ( is_file( eZExtBuilder::getBuildDir( $opts ) . "/$ext/pake/options-$ext.yaml" ) )
            {
                pake_copy( eZExtBuilder::getBuildDir( $opts ) . "/$ext/pake/options-$ext.yaml", eZExtBuilder::getOptionsDir() . "/options-$ext.yaml" );
            }
            else
            {
                throw new pakeException( "Missing pake/options.yaml extension in dependent extension $ext" );
            }

            // finish the init-task
            $task->execute( array( "tmp_$ext" ), array_merge( $cliopts, array( 'skip-init' => false, 'skip-init-fetch' => true, 'skip-init-clean' => false ) ) );
            pake_remove( $tempconffile, '' );

            // and build it. Here again we cannot use 'invoke', but we know 'build' has prerequisites
            // so we execute them one by one
            $task = pakeTask::get( 'build' );
            foreach( $task->get_prerequisites() as $pretask )
            {
                $pretask = pakeTask::get( $pretask );
                $pretask->execute( array( $ext ), array_merge( $opts, array( 'skip-init' => true ) ) );
            }
            $task->execute( array( $ext ), array_merge( $opts, array( 'skip-init' => true ) ) );
        }
    }
}

/**
 * Creates a tarball of all extensions in the build/ directory
 *
 */
function run_fat_dist( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    if ( !class_exists( 'ezcArchive' ) )
    {
        throw new pakeException( "Missing Zeta Components: cannot generate tar file. Use the environment var PHP_CLASSPATH or change include_path in php.ini" );
    }
    pake_mkdirs( $opts['dist']['dir'] );
    $files = pakeFinder::type( 'any' )->in( eZExtBuilder::getBuildDir( $opts ) );
    // get absolute path to build dir
    $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( eZExtBuilder::getBuildDir( $opts ) );
    $rootpath = dirname( $rootpath[0] );
    $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '-bundle.tar';
    // we do not rely on this, not to depend on phar extension and also because it's slightly buggy if there are dots in archive file name
    //pakeArchive::createArchive( $files, $opts['build']['dir'], $target, true );
    $tar = ezcArchive::open( $target, ezcArchive::TAR );
    $tar->appendToCurrent( $files, $rootpath );
    $tar->close();
    $fp = fopen( 'compress.zlib://' . $target . '.gz', 'wb9' );
    /// @todo read file by small chunks to avoid memory exhaustion
    fwrite( $fp, file_get_contents( $target ) );
    fclose( $fp );
    unlink( $target );
    pake_echo_action( 'file+', $target . '.gz' );
}

/**
 * Builds the extension and generates the tarball
 *
 * We rely on the pake dependency system to do the real stuff
 * (run pake -P to see tasks included in this one)
 */
function run_all( $task=null, $args=array(), $cliopts=array() )
{
}

/**
 * Removes the build/ and dist/ directories
 *
 * We rely on the pake dependency system to do the real stuff
 * (run pake -P to see tasks included in this one)
 */
function run_clean_all( $task=null, $args=array(), $cliopts=array() )
{
}

/**
 * Updates the ezinfo.php and extension.xml files with the version number and
 * license tag from configuration.
 *
 * Uses a regular expression to search and replace the correct strings in ezinfo.php
 * Within the file, please note there is a limit of 25 spaces to avoid indenting
 * 3rd party lib version numbers, if you use more than 25 spaces the version number
 * and license string will not be updated correctly.
 * Also we set a limit of 1 replacement, to avoid fixing 3rd party lib versions.
 *
 * For the extension.xml file, max indentation is set to 8 chars.
 */
function run_update_ezinfo( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

    $files = pakeFinder::type( 'file' )->name( 'ezinfo.php' )->maxdepth( 0 )->in( $destdir );
    /// @todo use a real php parser instead
    pake_replace_regexp( $files, $destdir, array(
            '/^([\s]{1,25}\x27Version\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
            '/^([\s]{1,25}\x27License\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['license'] . '$3' ),
        1 );

    $files = pakeFinder::type( 'file' )->maxdepth( 0 )->name( 'extension.xml' )->in( $destdir );
    /// @todo use a real xml parser instead
    pake_replace_regexp( $files, $destdir, array(
            '#^([\s]{1,8}<version>)([^<]*)(</version>\r?\n?)#m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
            /// @bug we should use a better xml escaping here
            '#^([\s]{1,8}<license>)([^<]*)(</license>\r?\n?)#m' => '${1}' . htmlspecialchars( $opts['version']['license'] ) . '$3',
            '#^([\s]{1,8}<copyright>)Copyright \(C\) 1999-[\d]{4} eZ Systems AS(</copyright>\r?\n?)#m' => '${1}' . 'Copyright (C) 1999-' . strftime( '%Y' ). ' eZ Systems AS' . '$2' ),
        1 );
}

/**
 * Updates .php, .css and .js files replacing tokens found in the std eZ Systems header comment
 *
 * Recognized tokens (in regexp form) are:
 * // SOFTWARE RELEASE: (.*)
 * Copyright (C) 1999-[\d]{4} eZ Systems AS
 * .*@version //autogentag//\r?\n?
 *
 * @todo use more tolerant comment tags (eg multiline comments)
 * @todo parse tpl files too?
 * @todo use other strings than these, since it's gonna be community extensions?
 */
function run_update_license_headers( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    $files = pakeFinder::type( 'file' )->name( array( '*.php', '*.css', '*.js' ) )->in( $destdir );
    pake_replace_regexp( $files, $destdir, array(
        '#// SOFTWARE RELEASE: (.*)#m' => '// SOFTWARE RELEASE: ' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'],
        '/Copyright \(C\) 1999-[\d]{4} eZ Systems AS/m' => 'Copyright (C) 1999-' . strftime( '%Y' ). ' eZ Systems AS',
        '#(.*@version )//autogentag//(\r?\n?)#m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$2' ) );
}

/**
 * Updates all files specified in user configuration,
 * replacing the tokens [EXTENSION_VERSION], [EXTENSION_PUBLISH_VERSION] and [EXTENSION_LICENSE]
 */
function run_update_extra_files( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    $extrafiles = $opts['files']['to_parse'];
    //$files = pakeFinder::type( 'file' )->name( $extrafiles )->in( $destdir );
    /// @todo shall we make sure we only retrieve files, not directories?
    $files = pake_antpattern( $extrafiles, $destdir );
    $tokens = array(
        'EXTENSION_VERSION' => $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'],
        'EXTENSION_LICENSE' => $opts['version']['license'] );
    if ( @$opts['ezp']['version']['major'] )
    {
        $tokens['EXTENSION_PUBLISH_VERSION'] = $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '.' . $opts['ezp']['version']['release'];
    }
    pake_replace_tokens( $files, $destdir, '[', ']', $tokens );
}

/**
 * Generates the documentation of the extension, if created in RST format in the doc/ folder, plus optionally API docs via doxygen; options: --doxygen=/path/to/doxygen
 *
 * Builds an html file of all doc/*.rst files, and removes the source, then, if
 * configured in the options file, uses doxygen to create html documentation of
 * php source code in doc/api. The location of the doxygen binary can be specified
 * via a command-line option
 *
 * @todo allow config file to specify doc dir
 * @todo use local doxygen file if found, instead of std one
 * @todo create api doc from php files using phpdoc too
 *       example cli cmd: ${phpdocinstall}phpdoc -t ${phpdocdir}/html -ti 'eZ Publish' -pp -s -d lib/ezdb/classes,lib/ezdbschema/classes,lib/ezdiff/classes,lib/ezfile/classes,lib/ezi18n/classes,lib/ezimage/classes,lib/ezlocale/classes,lib/ezmath/classes,lib/ezpdf/classes,lib/ezsession/classes,lib/ezsoap/classes,lib/eztemplate/classes,lib/ezutils/classes,lib/ezxml/classes,kernel/classes,kernel/private/classes,kernel/common,cronjobs,update/common/scripts > ${phpdocdir}/generate.log
 */
function run_generate_documentation( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    $docdir = $destdir . '/doc';
    $files = pakeFinder::type( 'file' )->name( '*.rst' )->in( $docdir );
    foreach ( $files as $i => $file )
    {
        // on 1st pass only: test if ezcDocumentRst can be found, write a nice error msg if not
        if ( !$i && !class_exists( 'ezcDocumentRst' ) )
        {
            throw new pakeException( "Missing Zeta Components: cannot generate html doc from rst. Use the environment var PHP_CLASSPATH or change include_path in php.ini" );
        }
        $dst = substr( $file, 0, -3 ) . 'html';
        $document = new ezcDocumentRst();
        $document->loadFile( $file );
        $docbook = $document->getAsXhtml();
        file_put_contents( $dst, $docbook->save() );
        pake_echo_action( 'file+', $dst );
        pake_remove( $file, '' );
    }

    /*
    * A few extension have Makefiles to generate documentation
    * We remove them as well as the original .rst files
    * NB: this is not done anymore since version 0.1. Use files.to_exclude option instead
    */
    //pake_remove( pakeFinder::type( 'file' )->name( 'Makefile' )->in( $destdir ), '' );

    // doxygen
    if ( $opts['create']['doxygen_doc'] )
    {
        pake_mkdirs( $docdir . '/api' );
        $doxygen = @$cliopts['doxygen'];
        if ( $doxygen == '' )
        {
            $doxygen = eZExtBuilder::getTool( 'doxygen', $opts );
        }
        else
        {
            $doxygen = escapeshellarg( $doxygen );
        }
        $doxyfile = $destdir . '/doxyfile';
        pake_copy( __DIR__ . '/doxyfile_master', $doxyfile, array( 'override' => true ) );
        file_put_contents( $doxyfile,
            "\nPROJECT_NAME = " . $opts['extension']['name'] .
            "\nPROJECT_NUMBER = " . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] .
            "\nOUTPUT_DIRECTORY = " . $docdir . '/api' .
            "\nINPUT = " . $destdir .
            "\nEXCLUDE = " . $destdir . '/settings' .
            "\nSTRIP_FROM_PATH = " . $destdir, FILE_APPEND );
        $out = pake_sh( $doxygen . ' ' . escapeshellarg( $doxyfile ) );
        pake_remove( $doxyfile, '' );
        // cleanup leftover files, just in case dot tool is not found
        $files = pakeFinder::type( 'file' )->name( array( '*.dot', '*.md5', '*.map', 'installdox' ) )->in( $docdir . '/api' );
        pake_remove( $files, '' );
    }
}

/**
 * Creates a share/filelist.md5 file, with the checksum of all files in the build.
 *
 * This task is only run if in the configuration file md5 creation is specified.
 */
function run_generate_md5sums( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    if ( $opts['create']['filelist_md5'] )
    {
        $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
        // make sure we do not add to checksum file the file itself
        @unlink( $destdir . '/share/filelist.md5'  );
        $files = pakeFinder::type( 'file' )->in( $destdir );
        $out = array();
        $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( eZExtBuilder::getBuildDir( $opts ) );
        foreach( $files as $file )
        {
            $out[] = md5_file( $file ) . '  ' . ltrim( str_replace( array( $rootpath[0], '\\' ), array( '', '/' ), $file ), '/' );
        }
        pake_mkdirs( $destdir . '/share' );
        file_put_contents( $destdir . '/share/filelist.md5', implode( "\n", $out ) );
        pake_echo_action('file+', $destdir . '/share/filelist.md5' );
    }
}

/**
 * Generates the xml file listing all the files in the extension that is used as
 * part of an eZ Package description.
 *
 * This task is only run if in the configuration file package creation is specified.
 */
function run_generate_package_filelist( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    if ( $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
    {
        $doc = new DOMDocument( '1.0', 'utf-8' );
        $doc->formatOutput = true;

        $packageRoot = $doc->createElement( 'extension' );
        $packageRoot->setAttribute( 'name', $opts['extension']['name'] );

        $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( eZExtBuilder::getBuildDir( $opts ) );
        $dirs =  pakeFinder::type( 'directory' )->in( eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'] );
        foreach( $dirs as $dir )
        {
            $name = basename( $dir );
            $path = dirname( $dir );
            $path = str_replace( array( $rootpath[0], '\\' ), array( '', '/' ), $path );
            $fileNode = $doc->createElement( 'file' );
            $fileNode->setAttribute( 'name', $name );
            if ( $path )
                $fileNode->setAttribute( 'path', $path );
            $fileNode->setAttribute( 'type', 'dir' );
            $packageRoot->appendChild( $fileNode );
        }
        $files =  pakeFinder::type( 'file' )->in( eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'] );
        foreach( $files as $file )
        {
            //$dir = ;
            $name = basename( $file );
            $path = dirname( $file );
            $path = str_replace( array( $rootpath[0], '\\' ), array( '', '/' ), $path );
            $fileNode = $doc->createElement( 'file' );
            $fileNode->setAttribute( 'name', $name );
            if ( $path )
                $fileNode->setAttribute( 'path', $path );
            $fileNode->setAttribute( 'md5sum', md5_file( $file ) );
            $packageRoot->appendChild( $fileNode );
        }
        $doc->appendChild( $packageRoot );

        $doc->save( eZExtBuilder::getBuildDir( $opts ) . '/extension-' . $opts['extension']['name'] . '.xml' );
        pake_echo_action( 'file+', eZExtBuilder::getBuildDir( $opts ) . '/extension-' . $opts['extension']['name'] . '.xml' );
    }
}

/**
 * Checks if a schema.sql file is present for any supported database (or none at all)
 *
 * The accepted directory structure is:
 *
 * myextension
 * |___share
 * |   |___db_schema.dba
 * |   `___db_data.dba
 * `__ sql
 *     |__ mysql
 *     |   |__ cleandata.sql
 *     |   `__ schema.sql
 *     |__ oracle
 *     |   |__ cleandata.sql
 *     |   `__ schema.sql
 *     `__ postgresql
 *         |__ cleandata.sql
 *         `__ schema.sql
 *
 * NB: there are NOT a lot of extensions currently following this schema.
 * Alternative used are: sql/mysql/mysql.sql, sql/mysql/random.sql
 */
function run_check_sql_files( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

    $schemafile = $opts['files']['sql_files']['db_schema'];
    $schemafiles = array( 'share' => 'db_schema.dba', 'sql/mysql' => $schemafile, 'sql/oracle' => $schemafile, 'sql/postgresql' => $schemafile );
    if ( $schemafile == '$db.sql' )
    {
        $schemafiles = array( 'share' => 'db_schema.dba', 'sql/mysql' => 'mysql.sql', 'sql/oracle' => 'oracle.sql', 'sql/postgresql' => 'postgresql.sql' );
    }
    $count = 0;
    foreach( $schemafiles as $dir => $file )
    {
        $files = pakeFinder::type( 'file' )->name( $file )->maxdepth( 0 )->in( $destdir . "/$dir" );
        if ( count( $files ) )
        {
            if ( filesize( $files[0] ) == 0 )
            {
                throw new pakeException( "Sql schema file {$files[0]} is empty. Please fix" );
            }
            $count++;
        }

    }
    if ( $count > 0 && $count < 4 )
    {
        throw new pakeException( "Found some sql schema files but not all of them. Please fix" );
    }

    $datafile = $opts['files']['sql_files']['db_data'];
    $datafiles = array( 'share' => 'db_data.dba', 'sql/mysql' => $datafile, 'sql/oracle' => $datafile, 'sql/postgresql' => $datafile );
    if ( $datafile == '$db.sql' )
    {
        $datafiles = array( 'share' => 'db_data.dba', 'sql/mysql' => 'mysql.sql', 'sql/oracle' => 'oracle.sql', 'sql/postgresql' => 'postgresql.sql' );
    }
    $count = 0;
    foreach( $datafiles as $dir => $file )
    {
        $files = pakeFinder::type( 'file' )->name( $file )->maxdepth( 0 )->in( $destdir . "/$dir" );
        if ( count( $files ) )
        {
            if ( filesize( $files[0] ) == 0 )
            {
                throw new pakeException( "Sql data file {$files[0]} is empty. Please fix" );
            }
            $count++;
        }
    }
    if ( $count > 0 && $count < 4 )
    {
        throw new pakeException( "Found some sql data files but not all of them. Please fix" );
    }
}

/**
 * Checks for presence of files README and LICENSE, by default in extension root
 * directory - but a config parameter is allowed to specify their location
 */
function run_check_gnu_files( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    if ( $opts['files']['gnu_dir'] )
    {
        $destdir .= '/' . $opts['files']['gnu_dir'];
    }
    $files = pakeFinder::type( 'file' )->name( array( 'README', 'LICENSE' ) )->maxdepth( 0 )->in( $destdir );
    if ( count( $files ) != 2 )
    {
        throw new pakeException( "README and/or LICENSE files missing. Please fix" );
    }
}

/**
 * Runs all code quality checks (NB: this can take a while)
 *
 * We rely on the pake dependency system to do the real stuff
 * (run pake -P to see tasks included in this one)
 */
function run_check_code_quality( $task=null, $args=array(), $cliopts=array() )
{
}

/**
 * Checks for validity all template files - needs a working eZP install somewhere to get the tpl syntax checker script;
 * options: --php=path/to/php/executable (otherwise, if not in your path, use config. file),
 * --ezp=path/to/eZPublish/installation (if empty, it is assumed we are building from within an eZP installation)
 */
function run_check_templates( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    $files = pakeFinder::type( 'file' )->name( array( '*.tpl' ) )->maxdepth( 0 )->in( $destdir );
    if ( count( $files ) )
    {
        $php = @$cliopts['php'];
        if ( $php == '' )
        {
            $php = eZExtBuilder::getTool( 'php', $opts );
        }
        else
        {
            $php = escapeshellarg( $php );
        }
        if ( strpos( pake_sh( $php . " -v" ), 'PHP' ) === false )
        {
            throw new pakeException( "$php does not seem to be a valid php executable" );
        }

        $ezp = @$cliopts['ezp'];
        if ( $ezp == '' )
        {
            $ezp = @$opts['ezublish']['install_dir_LS'];
        }
        if ( $ezp == '' )
        {
            // assume we're running inside an eZ installation
            $ezp = '../..';
        }
        if ( !file_exists( $ezp . '/bin/php/eztemplatecheck.php' ) )
        {
            throw new pakeException( "$ezp does not seem to be a valid eZ Publish install" );
        }

        // get absolute path to build dir
        $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( eZExtBuilder::getBuildDir( $opts ) );
        $rootpath = dirname( $rootpath[0] );
        $out = pake_sh( "cd " . escapeshellarg( $ezp ) . " && " . escapeshellarg( $php ) . " bin/php/eztemplatecheck.php " . escapeshellarg( $rootpath ) );
        if ( strpos( $out, 'Some templates did not validate' ) !== false )
        {
            throw new pakeException( $out );
        }
    }
}

/**
 * Checks for validity all php files; options: --php=path/to/php/executable (otherwise $PATH is searched for "php")
 */
function run_check_php_files( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    $files = pakeFinder::type( 'file' )->name( array( '*.php' ) )->in( $destdir );
    if ( count( $files ) )
    {
        $php = @$cliopts['php'];
        if ( $php == '' )
        {
            $php = eZExtBuilder::getTool( 'php', $opts );
        }
        else
        {
            $php = escapeshellarg( $php );
        }
        if ( strpos( pake_sh( $php . " -v" ), 'PHP' ) === false )
        {
            throw new pakeException( "$php does not seem to be a valid php executable" );
        }

        foreach ( pakeFinder::type( 'file' )->name( array( '*.php' ) )->in( $destdir ) as $file )
        {
            if ( strpos( pake_sh( $php . " -l " . escapeshellarg( $file ) ), 'No syntax errors detected' ) === false )
            {
                throw new pakeException( "$file does not seem to be a valid php file" );
            }
        }
    }
}

/**
 * Generates a "code messyness" report using PHPMD. The rules to check can be set via configuration options
 */
function run_check_code_mess( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $phpmd = eZExtBuilder::getTool( 'phpmd', $opts, true );
    $out = '';
    if ( $opts['tools']['phpmd']['report']  != '' )
    {
        $out = " > " . escapeshellarg( $opts['tools']['phpmd']['report'] );
    }
    try
    {
        // phpmd will exit with a non-0 value aws soon as there is any violation (which generates an exception in pake_sh),
        // but we do not consider this a fatal error, as phpmd is really nitpicky ;-)
        pake_sh( "$phpmd " . escapeshellarg( eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) . " " .
            escapeshellarg( $opts['tools']['phpmd']['format'] ) . " " .
            escapeshellarg( $opts['tools']['phpmd']['rules'] ) . $out );
    }
    catch ( pakeException $e )
    {
    }
}

/**
 * Generates a "coding style violations" report using PHPCodeSniffer.
 * The rules to check can be set via configuration options, default being "ezcs" (@see https://github.com/ezsystems/ezcs)
 */
function run_check_coding_style( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $phpcs = eZExtBuilder::getTool( 'phpcs', $opts, true );
    $out = '';
    if ( $opts['tools']['phpcs']['report']  != '' )
    {
        $out = " --report-{$opts['tools']['phpcs']['format']}=" . escapeshellarg( $opts['tools']['phpcs']['report'] );
    }

    // in case we use the standard rule set, try to install it (after composer has downloaded it)
    // nb: this could become a task of its own...
    $rulesDir = eZExtBuilder::getVendorDir() . '/squizlabs/php_codesniffer/Codesniffer/Standards/' . $opts['tools']['phpcs']['rules'] ;
    if ( !is_dir( $rulesDir ) )
    {
        if ( $opts['tools']['phpcs']['rules'] == 'ezcs' )
        {
            $sourceDir = eZExtBuilder::getVendorDir() . '/ezsystems/ezcs/php/ezcs';
            if ( is_dir( $sourceDir ) )
            {
                pake_symlink( $sourceDir, $rulesDir );
            }
        }
    }

    pake_sh( "$phpcs --standard=" . escapeshellarg( $opts['tools']['phpcs']['rules'] ) . " " .
        "--report=" . escapeshellarg( $opts['tools']['phpcs']['format'] ) . " " .
        // if we do not filter on php files, phpcs can go in a loop trying to parse tpl files
        "--extensions=php " . /*"--encoding=utf8 " .*/
        $out .
        escapeshellarg( eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'] ) );
}

/**
 * Updates information in package.xml file used by packaged extensions
 */
function run_update_package_xml( $task=null, $args=array(), $cliopts=array() )
{
    /// @todo replace hostname, build time

    $opts = eZExtBuilder::getOpts( @$args[0], $cliopts );
    $destdir = $opts['build']['dir'];
    $files = pakeFinder::type( 'file' )->name( 'package.xml' )->maxdepth( 0 )->in( $destdir );
    if ( count( $files ) == 1 )
    {
        // original format
        pake_replace_regexp( $files, $destdir, array(
            // <name>xxx</name>
            '#^( *\074name\076)(.*)(\074/name\076\r?\n?)$#m' => '${1}' . $opts['extension']['name'] . '_extension' . '$3',
            // <version>xxx</version>
            '#^( *\074version\076)(.*)(\074/version\076\r?\n?)$#m' => '${1}' . $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '.' . $opts['ezp']['version']['release'] . '$3',
            // <named-version>xxx</named-version>
            '#^( *\074named-version\076)(.*)(\074/named-version\076\r?\n?)$#m' => '${1}' . $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '$3',
            // <package version="zzzz"
            //'#^( *\074package +version=")(.*)("\r?\n?)$#m' => '${1}' . $opts['version']['major'] . '.' . $opts['version']['minor'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
            // <number>xxxx</number>
            '#^( *\074number\076)(.*)(\074/number\076\r?\n?)$#m' => '${1}' . $opts['version']['alias'] . '$3',
            // <release>yyy</release>
            '#^( *\074release\076)(.*)(\074/release\076\r?\n?)$#m' => '${1}' . $opts['version']['release'] . '$3',

            '#^( *\074timestamp\076)(.*)(\074/timestamp\076\r?\n?)$#m' => '${1}' . time() . '$3',
            '#^( *\074host\076)(.*)(\074/host\076\r?\n?)$#m' => '${1}' . gethostname() . '$3',
            '#^( *\074licence\076)(.*)(\074/licence\076\r?\n?)$#m' => '${1}' . $opts['version']['license'] . '$3',
        ) );
        // replacing a token based on its value instead of its location (text immediately before and after,
        // as done above) has a disadvantage: we cannot execute the substitution many
        // times on the same text, as the 1st substitution will remove the token's
        // value. This means we have to reinit the build to get a 100% updated
        // package file. Unfortunately hunting for xml attributes not based on
        // token values needs a real xml parser, simplistic regexps are not enough...
        pake_replace_tokens( $files, $destdir, '{', '}', array(
            '$name' => $opts['extension']['name'],
            '$version' => $opts['version']['alias'],
            '$ezp_version' => $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '.' . $opts['ezp']['version']['release']
        ) );
    }
}
/**
 * Generates a sample package.xml to allow creation of packaged extension
 *
 * NB: that file is to be completed by hand
 */
function run_generate_sample_package_xml( $task=null, $args=array(), $cliopts=array() )
{
    pake_copy( __DIR__ . 'package_master.xml', 'package.xml' );
    // tokens not replaced here are replaced at build time
    // tokens in square brackets are supposed to be edited by the developer
    $tokens = array(
        '$summary' => '[Summary]',
        '$description' => '[Description]',
        '$vendor' => '',
        '$maintainers' => '',
        '$documents' => '',
        '$changelog' => '',
        '$simple-files' => '',
        '$state' => '[State]',
        '$requires' => ''
    );
    //$files = pakeFinder::type( 'file' )->name( 'package.xml' )->maxdepth( 0 )->in( '.' );
    pake_replace_tokens( 'package.xml', '.', '{', '}', $tokens );
    pake_echo ( "File package.xml generated. Please replace all tokens in square brackets in it (but do not replace values in curly brackets) then commit it to sources in the top dir of the extension" );
}

/**
 * Converts an existing ant properties file in its corresponding yaml version
 *
 * Converts the .properties files used to hold configuration settings for old
 * versions of ezextensionbuilder (the ones based on ant) to a .yaml configuration
 * file that is suitable for this version of the script.
 * It is recommended to inspect by hand the generated .yaml file after executing
 * the conversion.
 */
function run_convert_configuration( $task=null, $args=array(), $cliopts=array() )
{
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

    eZExtBuilder::convertPropertyFileToYamlFile(
        "ant/$extname.properties",
        eZExtBuilder::getConfigDir() . "/options-$extname.yaml",
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
                file_put_contents( eZExtBuilder::getConfigDir() . "options-$extname.yaml", $in, FILE_APPEND );
            }
        }
    }
}

/**
 * Displays current version nr
 */
function run_tool_version( $task=null, $args=array(), $cliopts=array() )
{
    pake_echo( "eZ Extension Builder ver." . eZExtBuilder::$version . "\nRunning on pake " . pakeApp::VERSION );
}


// *** helper functions ***

/**
 * Mimics ant pattern matching.
 * NB: in pake 1.6.3 and later this functionality is supported natively. To be removed
 *
 * @see http://ant.apache.org/manual/dirtasks.html#patterns
 * @todo more complete testing
 * @bug looking for " d i r / * * / " will return subdirs but not dir itself
 */
function pake_antpattern( $files, $rootdir )
{
    $results = array();
    foreach( $files as $file )
    {
        //echo " Beginning with $file in dir $rootdir\n";

        // safety measure: try to avoid multiple scans
        $file = str_replace( '/**/**/', '/**/', $file );

        $type = 'any';
        // if user set '/ 'as last char: we look for directories only
        if ( substr( $file, -1 ) == '/' )
        {
            $type = 'dir';
            $file = substr( $file, 0, -1 );
        }
        // managing 'any subdir or file' as last item: trick!
        if ( strlen( $file ) >= 3 && substr( $file, -3 ) == '/**' )
        {
            $file .= '/*';
        }

        $dir = dirname( $file );
        $file = basename( $file );
        if ( strpos( $dir, '**' ) !== false )
        {
            $split = explode( '/', $dir );
            $path = '';
            foreach( $split as $i => $part )
            {
                if ( $part != '**' )
                {
                    $path .= "/$part";
                }
                else
                {
                    //echo "  Looking for subdirs in dir $rootdir{$path}\n";
                    $newfile = implode( '/', array_slice( $split, $i + 1 ) ) . "/$file" . ( $type == 'dir'? '/' : '' );
                    $dirs = pakeFinder::type( 'dir' )->in( $rootdir . $path );
                    // also cater for the case '** matches 0 subdirs'
                    $dirs[] = $rootdir . $path;
                    foreach( $dirs as $newdir )
                    {
                        //echo "  Iterating in $newdir, looking for $newfile\n";
                        $found = pake_antpattern( array( $newfile ), $newdir );
                        $results = array_merge( $results, $found );
                    }
                    break;
                }
            }
        }
        else
        {
            //echo "  Looking for $type $file in dir $rootdir/$dir\n";
            $found = pakeFinder::type( $type )->name( $file )->maxdepth( 0 )->in( $rootdir . '/' . $dir );
            //echo "  Found: " . count( $found ) . "\n";
            $results = array_merge( $results, $found );
        }
    }
    return $results;
}

// *** declaration of the pake tasks ***

pake_task( 'default' );

pake_task( 'generate-extension-config' );

pake_task( 'download-extension-config' );

pake_task( 'list-extensions' );

pake_task( 'show-properties' );

pake_task( 'init' );

pake_task( 'build',
    'init', 'check-php-files', 'check-templates', 'check-sql-files', 'check-gnu-files',
    'update-ezinfo', 'update-license-headers', 'update-extra-files', 'update-package-xml',
    'generate-documentation', 'generate-md5sums', 'generate-package-filelist' );

pake_task( 'clean' );

pake_task( 'dist' );

pake_task( 'dist-clean' );

pake_task( 'all',
    'build', 'dist', 'build-dependencies' );

pake_task( 'clean-all', 'clean', 'dist-clean' );

pake_task( 'fat-dist' );

pake_task( 'update-ezinfo' );

pake_task( 'update-license-headers' );

pake_task( 'update-extra-files' );

pake_task( 'generate-documentation' );

//pake_task( 'check-coding-standards' );


pake_task( 'check-php-files' );

pake_task( 'check-templates' );

pake_task( 'check-code-mess' );

pake_task( 'check-coding-style' );

pake_task( 'check-code-quality',
    'check-php-files', 'check-templates', 'check-coding-style', 'check-code-mess' );

pake_task( 'generate-md5sums' );

pake_task( 'check-sql-files' );

pake_task( 'check-gnu-files' );

pake_task( 'generate-package-filelist' );

pake_task( 'update-package-xml' );

pake_task( 'build-dependencies' );

/*
pake_desc( 'Creates an ezpackage tarball.' );
pake_task( 'generate-package-tarball', 'update-package-xml', 'generate-package-filelist' );
*/

pake_task( 'generate-sample-package-xml' );

pake_task( 'convert-configuration' );

pake_task( 'tool-version' );

}