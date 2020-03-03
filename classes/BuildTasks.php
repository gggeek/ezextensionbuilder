<?php
/**
 * A class containing all pake tasks related to the build process
 *
 * @author    G. Giunta
* @copyright (C) G. Giunta 2013-2020
 * @license   code licensed under the GNU GPL 2.0: see README file
 */

namespace eZExtBuilder;

use pakeException;
use pakeFinder;
use pakeGit;
use pakeSubversion;

class BuildTasks extends Builder
{
    /**
     * Downloads the extension from its source repository, removes files not to be built
     * (the list of files to be removed is in part hardcoded and in part specified in
     * the configuration file).
     * Options: skip-init, skip-init-fetch, skip-init-clean
     *
     * @todo add a dependency on a check-updates task that updates script itself?
     * @todo split this in two tasks and avoid this unsightly mess of options?
     */
    static function run_init( $task=null, $args=array(), $cliopts=array() )
    {
        $skip_init = @$cliopts['skip-init'];
        $skip_init_fetch = @$cliopts['skip-init-fetch'] || $skip_init;
        $skip_init_clean = @$cliopts['skip-init-clean'] || $skip_init;

        if ( ! $skip_init )
        {
            $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
            pake_mkdirs( self::getBuildDir( $opts ) );

            $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

            if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
                throw new PakeException( "Source code locked by another process" );
        }

        if ( ! $skip_init_fetch )
        {
            if ( @$opts['svn']['url'] != '' )
            {
                pake_echo( "Fetching code from SVN repository {$opts['svn']['url']}" );
                pakeSubversion::checkout( $opts['svn']['url'], $destdir );
                /// @todo test that we got at least one file
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
                {
                    sleep( 3 );
                }
            }
            else if ( @$opts['git']['url'] != '' )
            {
                pake_echo( "Fetching code from GIT repository {$opts['git']['url']}" );
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
                pake_echo( "Fetching code from local repository {$opts['file']['url']}" );
                /// @todo (!important) exclude stuff we know we're going to delete immediately afterwards
                $files = pakeFinder::type( 'any' )->relative()->in( $opts['file']['url'] );
                if ( count( $files ) == 0 )
                {
                    SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
                    throw new pakeException( "Empty source repo option: no files found in {$opts['file']['url']}" );
                }

                pake_mirror( $files, $opts['file']['url'], $destdir );
            }
            else
            {
                SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
                throw new pakeException( "Missing source repo option: either svn:url, git:url or file:url" );
            }
        }


        // remove files
        if ( ! $skip_init_clean )
        {
            // known files/dirs not to be packed / md5'ed
            /// @todo !important shall we make this configurable?
            /// @bug 'build' & 'dist' we should probably take from options
            $files = array( 'ant/', 'build.xml', '**/.svn', '.git/', 'build/', 'dist/', 'composer.phar', 'composer.lock', '.idea/', 'vendor/' );
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
            $files = self::pake_antpattern( $files, $destdir );
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

            SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
        }
    }

    /**
     * Builds the extension; options: --skip-init
     *
     * We rely on the pake dependency system to do the real stuff
     * (run pake -P to see tasks included in this one)
     */
    static function run_build( $task=null, $args=array(), $cliopts=array() )
    {
    }

    /**
     * Removes the build/ directory
     */
    static function run_clean( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        pake_remove_dir( $opts['build']['dir'] );

        SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
    }

    /**
     * Creates a tarball of the built extension.
     *
     * Depending on configuration options, different versions of the extenion tarball
     * are generated by this task: .tar.gz, .zip, .ezpkg
     */
    static function run_dist( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( $opts['create']['tarball'] || $opts['create']['zip'] || $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
        {
            if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
                throw new PakeException( "Source code locked by another process" );

            pake_mkdirs( $opts['dist']['dir'] );
            $rootpath = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

            if ( $opts['create']['tarball'] )
            {
                $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.tar.gz';
                self::archiveDir( $rootpath, $target );
            }

            if ( $opts['create']['zip'] )
            {
                $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.zip';
                self::archiveDir( $rootpath, $target );
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
                self::archiveDir( $toppath, $target, true );

                if ( $opts['create']['pearpackage'] )
                {
                    /// @todo ...
                    pake_echo_error( "PEAR package creation not yet implemented" );
                }
            }

            SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
        }
    }

    /**
     * Removes the dist/ directory
     */
    static function run_dist_clean( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        pake_remove_dir( $opts['dist']['dir'] );
    }

    /**
     * Builds dependent extensions
     *
     * @todo add locking support
     */
    static function run_build_dependencies( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
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
                $tempconffile = self::getOptionsDir() . "/options-tmp_$ext.yaml";
                pakeYaml::emitfile( $tempconf, $tempconffile );

                // download remote extension
                // nb: we can not run the init task here via invoke() because of already_invoked status,
                // so we use execute(). NB: this is fine as long as init has no prerequisites
                $task = pakeTask::get( 'init' );
                $task->execute( array( "tmp_$ext" ), array_merge( $cliopts, array( 'skip-init' => false, 'skip-init-fetch' => false, 'skip-init-clean' => true ) ) );

                // copy config file from ext dir to current config dir
                if ( is_file( self::getBuildDir( $opts ) . "/$ext/pake/options-$ext.yaml" ) )
                {
                    pake_copy( self::getBuildDir( $opts ) . "/$ext/pake/options-$ext.yaml", self::getOptionsDir() . "/options-$ext.yaml" );
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
     * @todo add locking support
     */
    static function run_fat_dist( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        pake_mkdirs( $opts['dist']['dir'] );
        $files = pakeFinder::type( 'any' )->in( self::getBuildDir( $opts ) );
        // get absolute path to build dir
        $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( self::getBuildDir( $opts ) );
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
    static function run_all( $task=null, $args=array(), $cliopts=array() )
    {
    }

    /**
     * Removes the build/ and dist/ directories
     *
     * We rely on the pake dependency system to do the real stuff
     * (run pake -P to see tasks included in this one)
     */
    static function run_clean_all( $task=null, $args=array(), $cliopts=array() )
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
    static function run_update_ezinfo( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

        $files = pakeFinder::type( 'file' )->name( 'ezinfo.php' )->maxdepth( 0 );

        /// @todo use a real php parser instead
        pake_replace_regexp( $files, $destdir, array(
                '/^([\s]{1,25}\x27Version\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
                '/^([\s]{1,25}\x27License\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['license'] . '$3' ),
            1 );

        $files = pakeFinder::type( 'file' )->maxdepth( 0 )->name( 'extension.xml' );
        /// @todo use a real xml parser instead
        pake_replace_regexp( $files, $destdir, array(
                '#^([\s]{1,8}<version>)([^<]*)(</version>\r?\n?)#m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
                /// @bug we should use a better xml escaping here
                '#^([\s]{1,8}<license>)([^<]*)(</license>\r?\n?)#m' => '${1}' . htmlspecialchars( $opts['version']['license'] ) . '$3',
                '#^([\s]{1,8}<copyright>)Copyright \(C\) 1999-[\d]{4} eZ Systems AS(</copyright>\r?\n?)#m' => '${1}' . 'Copyright (C) 1999-' . strftime( '%Y' ). ' eZ Systems AS' . '$2' ),
            1 );

        SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
    }

    /**
     * Updates .php, .css and .js files replacing tokens found in the standard eZ Systems header comment
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
    static function run_update_license_headers( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
        $files = pakeFinder::type( 'file' )->name( array( '*.php', '*.css', '*.js' ) );
        pake_replace_regexp( $files, $destdir, array(
            '#// SOFTWARE RELEASE: (.*)#m' => '// SOFTWARE RELEASE: ' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'],
            '/Copyright \(C\) 1999-[\d]{4} eZ Systems AS/m' => 'Copyright (C) 1999-' . strftime( '%Y' ). ' eZ Systems AS',
            '#(.*@version )//autogentag//(\r?\n?)#m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$2' ) );

        SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
    }

    /**
     * Updates all files specified in user configuration,
     * replacing the tokens [EXTENSION_VERSION], [EXTENSION_PUBLISH_VERSION] and [EXTENSION_LICENSE]
     */
    static function run_update_extra_files( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
        $extrafiles = $opts['files']['to_parse'];
        //$files = pakeFinder::type( 'file' )->name( $extrafiles )->in( $destdir );
        /// @todo shall we make sure we only retrieve files, not directories?
        $files = self::pake_antpattern( $extrafiles, $destdir );
        $tokens = array(
            'EXTENSION_VERSION' => $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'],
            'EXTENSION_LICENSE' => $opts['version']['license'] );
        if ( @$opts['ezp']['version']['major'] )
        {
            $tokens['EXTENSION_PUBLISH_VERSION'] = $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '.' . $opts['ezp']['version']['release'];
        }
        pake_replace_tokens( $files, $destdir, '[', ']', $tokens );

        SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
    }

    /**
     * Generates the documentation of the extension, if created in RST format in the doc/ folder, plus optionally
     * API docs via doxygen; options: --doxygen=/path/to/doxygen
     *
     * Builds an html file of all doc/*.rst files, and removes the source, then, if
     * configured in the options file, uses doxygen to create html documentation of
     * php source code in doc/api. The location of the doxygen binary can be specified
     * via a configuration option
     *
     * @todo allow config file to specify doc dir
     * @todo use local doxygen file if found, instead of std one
     * @todo create api doc from php files using phpdoc too
     *       example cli cmd: ${phpdocinstall}phpdoc -t ${phpdocdir}/html -ti 'eZ Publish' -pp -s -d lib/ezdb/classes,lib/ezdbschema/classes,lib/ezdiff/classes,lib/ezfile/classes,lib/ezi18n/classes,lib/ezimage/classes,lib/ezlocale/classes,lib/ezmath/classes,lib/ezpdf/classes,lib/ezsession/classes,lib/ezsoap/classes,lib/eztemplate/classes,lib/ezutils/classes,lib/ezxml/classes,kernel/classes,kernel/private/classes,kernel/common,cronjobs,update/common/scripts > ${phpdocdir}/generate.log
     */
    static function run_generate_documentation( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
        $docdir = $destdir . '/doc';
        $files = pakeFinder::type( 'file' )->name( '*.rst' )->in( $docdir );
        foreach ( $files as $i => $file )
        {
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
            $doxygen = self::getTool( 'doxygen', $opts );
            $doxyfile = $destdir . '/doxyfile';
            pake_copy( self::getResourceDir() . '/doxyfile_master', $doxyfile, array( 'override' => true ) );
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

        SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
    }

    /**
     * Creates a share/filelist.md5 file, with the checksum of all files in the build.
     *
     * This task is only run if in the configuration file md5 creation is specified.
     */
    static function run_generate_md5sums( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( $opts['create']['filelist_md5'] )
        {
            if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
                throw new PakeException( "Source code locked by another process" );

            $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
            // make sure we do not add to checksum file the file itself
            @unlink( $destdir . '/share/filelist.md5'  );
            $files = pakeFinder::type( 'file' )->in( $destdir );
            $out = array();
            $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( self::getBuildDir( $opts ) );
            foreach( $files as $file )
            {
                $out[] = md5_file( $file ) . '  ' . ltrim( str_replace( array( $rootpath[0], '\\' ), array( '', '/' ), $file ), '/' );
            }
            pake_mkdirs( $destdir . '/share' );
            file_put_contents( $destdir . '/share/filelist.md5', implode( "\n", $out ) );
            pake_echo_action('file+', $destdir . '/share/filelist.md5' );

            SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
        }
    }

    /**
     * Generates the xml file listing all the files in the extension that is used as
     * part of an eZ Package description.
     *
     * This task is only run if in the configuration file package creation is specified.
     */
    static function run_generate_package_filelist( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
        {
            if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
                throw new PakeException( "Source code locked by another process" );

            $doc = new DOMDocument( '1.0', 'utf-8' );
            $doc->formatOutput = true;

            $packageRoot = $doc->createElement( 'extension' );
            $packageRoot->setAttribute( 'name', $opts['extension']['name'] );

            $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( self::getBuildDir( $opts ) );
            $dirs =  pakeFinder::type( 'directory' )->in( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] );
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
            $files =  pakeFinder::type( 'file' )->in( self::getBuildDir( $opts ) . '/' . $opts['extension']['name'] );
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

            $doc->save( self::getBuildDir( $opts ) . '/extension-' . $opts['extension']['name'] . '.xml' );
            pake_echo_action( 'file+', self::getBuildDir( $opts ) . '/extension-' . $opts['extension']['name'] . '.xml' );

            SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
        }
    }

    /**
     * Runs all code compliance checks.
     *
     * We rely on the pake dependency system to do the real stuff
     * (run pake -P to see tasks included in this one)
     */
    static function run_code_checks( $task=null, $args=array(), $cliopts=array() )
    {
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
    static function run_check_sql_files( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

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
                    SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
                    throw new pakeException( "Sql schema file {$files[0]} is empty. Please fix" );
                }
                $count++;
            }

        }
        if ( $count > 0 && $count < 4 )
        {
            SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
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
                    SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
                    throw new pakeException( "Sql data file {$files[0]} is empty. Please fix" );
                }
                $count++;
            }
        }
        if ( $count > 0 && $count < 4 )
        {
            SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
            throw new pakeException( "Found some sql data files but not all of them. Please fix" );
        }

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Checks for presence of files README and LICENSE, by default in extension root
     * directory - but a config parameter is allowed to specify their location
     */
    static function run_check_gnu_files( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
        if ( $opts['files']['gnu_dir'] )
        {
            $destdir .= '/' . $opts['files']['gnu_dir'];
        }
        $files = pakeFinder::type( 'file' )->name( array( 'README', 'LICENSE' ) )->maxdepth( 0 )->in( $destdir );
        if ( count( $files ) != 2 )
        {
            SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
            throw new pakeException( "README and/or LICENSE files missing. Please fix" );
        }

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Checks for validity all template files - needs a working eZP install somewhere to get the tpl syntax checker script;
     * use config options to specify the path to php executable if needed, as well as the path to an
     * eZPublish installation
     */
    static function run_check_templates( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
        $files = pakeFinder::type( 'file' )->name( array( '*.tpl' ) )->maxdepth( 0 )->in( $destdir );
        if ( count( $files ) )
        {
            $php = self::getTool( 'php', $opts );
            if ( strpos( pake_sh( $php . " -v" ), 'PHP' ) === false )
            {
                SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
                throw new pakeException( "$php does not seem to be a valid php executable" );
            }

            $ezp = @$opts['ezublish']['install_dir_LS'];
            if ( $ezp == '' )
            {
                // assume we're running inside an eZ installation
                $ezp = '../..';
            }
            if ( !file_exists( $ezp . '/bin/php/eztemplatecheck.php' ) )
            {
                SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
                throw new pakeException( "$ezp does not seem to be a valid eZ Publish install" );
            }

            // get absolute path to build dir
            $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( self::getBuildDir( $opts ) );
            $rootpath = dirname( $rootpath[0] );
            $out = pake_sh( "cd " . escapeshellarg( $ezp ) . " && " . escapeshellarg( $php ) . " bin/php/eztemplatecheck.php " . escapeshellarg( $rootpath ) );
            if ( strpos( $out, 'Some templates did not validate' ) !== false )
            {
                SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
                throw new pakeException( $out );
            }
        }

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Checks for validity all php files; use config options to specify the path to php executable if needed
     */
    static function run_check_php_files( $task=null, $args=array(), $cliopts=array() )
    {
        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_SH, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = self::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
        $files = pakeFinder::type( 'file' )->name( array( '*.php' ) )->in( $destdir );
        if ( count( $files ) )
        {
            $php = self::getTool( 'php', $opts );
            if ( strpos( pake_sh( $php . " -v" ), 'PHP' ) === false )
            {
                SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
                throw new pakeException( "$php does not seem to be a valid php executable" );
            }

            foreach ( pakeFinder::type( 'file' )->name( array( '*.php' ) )->in( $destdir ) as $file )
            {
                if ( strpos( pake_sh( $php . " -l " . escapeshellarg( $file ) ), 'No syntax errors detected' ) === false )
                {
                    SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
                    throw new pakeException( "$file does not seem to be a valid php file" );
                }
            }
        }

        SharedLock::release( $opts['extension']['name'], LOCK_SH, $opts );
    }

    /**
     * Updates information in package.xml file used by packaged extensions
     */
    static function run_update_package_xml( $task=null, $args=array(), $cliopts=array() )
    {
        /// @todo replace hostname, build time

        $opts = self::getOpts( @$args[0], @$args[1], $cliopts );
        if ( !SharedLock::acquire( $opts['extension']['name'], LOCK_EX, $opts ) )
            throw new PakeException( "Source code locked by another process" );

        $destdir = $opts['build']['dir'];
        $files = pakeFinder::type( 'file' )->name( 'package.xml' )->maxdepth( 0 );
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

        SharedLock::release( $opts['extension']['name'], LOCK_EX, $opts );
    }

    /**
     * Generates a sample package.xml to allow creation of packaged extension
     *
     * NB: that file is to be completed by hand
     */
    static function run_generate_sample_package_xml( $task=null, $args=array(), $cliopts=array() )
    {
        pake_copy( self::getResourceDir() . '/package_master.xml', 'package.xml' );
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
}
