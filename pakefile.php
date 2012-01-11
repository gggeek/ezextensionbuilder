<?php
/**
* eZExtensionBuilder pakefile:
* a script to build & package eZPublish extensions
*
* Needs the Pake tool to run: https://github.com/indeyets/pake/wiki
* It can bootstrap, by downloading all required components from the web
*
* @author    G. Giunta
* @copyright (C) G. Giunta 2011
* @license   code licensed under the GNU GPL 2.0: see README file
* @version   SVN: $Id$
*
* @todo move all known paths/names/... to class constants
*
* @todo register custom pake tasks in classes to trim down this file
*
* @bug at least on win, after using svn/git to checkout a project, the script does
*      not have enough rights to remove the .svn/.git & checkout dirs...
*/

// too smart for your own good: allow this script to be gotten off web servers in source form
if ( isset( $_GET['show'] ) && $_GET['show'] == 'source' )
{
    echo file_get_contents( __FILE__ );
    exit;
}

// *** function definition (live code at the end) ***/

// Since this script might be included twice, we wrap any function in an ifdef

if ( !function_exists( 'register_ezc_autoload' ) )
{
    // try to force ezc autoloading. End user should have set php include path properly
    function register_ezc_autoload()
    {
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
    }
}

if ( !function_exists( 'run_default' ) )
{

// definition of the pake tasks

function run_default()
{
    pake_echo ( "eZ Extension Builder ver." . eZExtBuilder::$version . "\nSyntax: php pakefile.php [--\$general-options] \$task [\$extension] [--\$task-options].\n  If no extension name is provided, a default configuration file will be searched for.\n  Run: php pakefile.php --tasks to learn more about available tasks." );
}

function run_show_properties( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    pake_echo ( 'Build dir: ' . eZExtBuilder::getBuildDir( $opts ) );
    pake_echo ( 'Extension name: ' . $opts['extension']['name'] );
}

/**
* Downloads the extension from its source repository, removes files not to be built
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
        $opts = eZExtBuilder::getOpts( @$args[0] );
        pake_mkdirs( eZExtBuilder::getBuildDir( $opts ) );

        $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    }

    if ( ! $skip_init_fetch )
    {
        if ( @$opts['svn']['url'] != '' )
        {
            pake_echo( 'Fetching code from SVN repository' );
            pakeSubversion::checkout( $opts['svn']['url'], $destdir );
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
            }
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            {
                sleep( 3 );
            }
        }
        else if ( @$opts['file']['url'] != '' )
        {
            pake_echo( 'Fetching code from local repository' );
            /// @todo (!important) exclude stuff we know we're going to delete immediately afterwards
            $files = pakeFinder::type( 'any' )->in( $opts['file']['url'] );
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

        /// we figured a way to allow user to speficy both:
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

/// We rely on the pake dependency system here to do real stuff
function run_build( $task=null, $args=array(), $cliopts=array() )
{
}

function run_clean( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    pake_remove_dir( $opts['build']['dir'] );
}

function run_dist( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    if ( $opts['create']['tarball'] || $opts['create']['zip'] || $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
    {
        if ( !class_exists( 'ezcArchive' ) )
        {
            throw new pakeException( "Missing Zeta Components: cannot generate tar file. Use the environment var PHP_CLASSPATH" );
        }
        pake_mkdirs( $opts['dist']['dir'] );
        $rootpath = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

        if ( $opts['create']['tarball'] )
        {
            $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.tar.gz';
            eZExtBuilder::archiveDir( $rootpath, $target, ezcArchive::TAR );
        }

        if ( $opts['create']['zip'] )
        {
            $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.zip';
            eZExtBuilder::archiveDir( $rootpath, $target, ezcArchive::ZIP );
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
            eZExtBuilder::archiveDir( $toppath, $target, ezcArchive::TAR, true );

            if ( $opts['create']['pearpackage'] )
            {
                /// @todo ...
                pake_echo_error( "PEAR package creation not yet implemented" );
            }
        }

    }
}

function run_dist_clean( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    pake_remove_dir( $opts['dist']['dir'] );
}

function run_build_dependencies( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
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
            $tempconffile = "pake/options-tmp_$ext.yaml";
            pakeYaml::emitfile( $tempconf, $tempconffile );

            // download remote extension
            // nb: we can not run the init task here via invoke() because of already_invoked status,
            // so we use execute(). NB: this is fine as long as init has no prerequisites
            $task = pakeTask::get( 'init' );
            $task->execute( array( "tmp_$ext" ), array_merge( $cliopts, array( 'skip-init' => false, 'skip-init-fetch' => false, 'skip-init-clean' => true ) ) );

            // copy config file from ext dir to current config dir
            if ( is_file( eZExtBuilder::getBuildDir( $opts ) . "/$ext/pake/options-$ext.yaml" ) )
            {
                pake_copy( eZExtBuilder::getBuildDir( $opts ) . "/$ext/pake/options-$ext.yaml", "pake/options-$ext.yaml" );
            }
            else
            {
                throw new pakeException( "Missing spake/options.yaml extension in dependent extension $ext" );
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

function run_fat_dist( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    if ( !class_exists( 'ezcArchive' ) )
    {
        throw new pakeException( "Missing Zeta Components: cannot generate tar file. Use the environment var PHP_CLASSPATH" );
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

function run_all( $task=null, $args=array(), $cliopts=array() )
{
}

function run_clean_all( $task=null, $args=array(), $cliopts=array() )
{
}

function run_update_ezinfo( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];

    $files = pakeFinder::type( 'file' )->name( 'ezinfo.php' )->maxdepth( 0 )->in( $destdir );
    /*
    * Uses a regular expression to search and replace the correct string
    * Within the file, please note there is a limit of 25 sets to indent 3rd party
    * lib version numbers, if you use more than 25 spaces the version number will
    * not be updated correctly.
    * Also we set a limit of 1 replacement, to avoid fixing 3rd party lib versions
    */
    /// @todo use a real php parser instead
    pake_replace_regexp( $files, $destdir, array(
        '/^([\s]{1,25}\x27Version\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
        '/^([\s]{1,25}\x27License\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['license'] . '$3' ),
        1 );

    $files = pakeFinder::type( 'file' )->maxdepth( 0 )->name( 'extension.xml' )->in( $destdir );
    // here again, do not replace version of required extensions
    /// @todo use a real xml parser instead
    pake_replace_regexp( $files, $destdir, array(
        '#^([\s]{1,8}<version>)([^<]*)(</version>\r?\n?)#m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
        /// @bug we should use a better xml escaping here
        '#^([\s]{1,8}<license>)([^<]*)(</license>\r?\n?)#m' => '${1}' . htmlspecialchars( $opts['version']['license'] ) . '$3',
        '#^([\s]{1,8}<copyright>)Copyright \(C\) 1999-[\d]{4} eZ Systems AS(</copyright>\r?\n?)#m' => '${1}' . 'Copyright (C) 1999-' . strftime( '%Y' ). ' eZ Systems AS' . '$2' ),
        1 );
}

/**
* Update .php, .css and .js files replacing tokens found in the std eZ Systems header comment
* @todo use more tolerant comment tags (eg multiline comments)
* @todo parse tpl files too?
* @todo use other strings than these, since it's gonna be community extensions?
*/
function run_update_license_headers( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
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
    $opts = eZExtBuilder::getOpts( @$args[0] );
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
* Builds an html file of all doc/*.rst files, and removes the source
* @todo allow config file to specify doc dir
* @todo use local doxygen file if found, instead of std one
* @todo create api doc from php files using phpdoc too
*       example cli cmd: ${phpdocinstall}phpdoc -t ${phpdocdir}/html -ti 'eZ Publish' -pp -s -d lib/ezdb/classes,lib/ezdbschema/classes,lib/ezdiff/classes,lib/ezfile/classes,lib/ezi18n/classes,lib/ezimage/classes,lib/ezlocale/classes,lib/ezmath/classes,lib/ezpdf/classes,lib/ezsession/classes,lib/ezsoap/classes,lib/eztemplate/classes,lib/ezutils/classes,lib/ezxml/classes,kernel/classes,kernel/private/classes,kernel/common,cronjobs,update/common/scripts > ${phpdocdir}/generate.log
*/
function run_generate_documentation( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = eZExtBuilder::getBuildDir( $opts ) . '/' . $opts['extension']['name'];
    $docdir = $destdir . '/doc';
    $files = pakeFinder::type( 'file' )->name( '*.rst' )->in( $docdir );
    foreach ( $files as $i => $file )
    {
        // on 1st pass only: test if ezcDocumentRst can be found, write a nice error msg if not
        if ( !$i && !class_exists( 'ezcDocumentRst' ) )
        {
            throw new pakeException( "Missing Zeta Components: cannot generate html doc from rst. Use the environment var PHP_CLASSPATH" );
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
            $doxygen = 'doxygen';
        }
        $doxygen = escapeshellarg( $doxygen );
        $doxyfile = $destdir . '/doxyfile';
        pake_copy( 'pake/doxyfile_master', $doxyfile, array( 'override' => true ) );
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
* Creates a share/filelist.md5 file, with the checksul of all files in the build
*/
function run_generate_md5sums( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
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

function run_generate_package_filelist( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
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
 * Checks if a schema.sql file is present for
 * any supported database
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
 * Alternativate used are: sql/mysql/mysql.sql, sql/mysql/random.sql
 */
function run_check_sql_files( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
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

function run_check_gnu_files( $task=null, $args=array(), $cliopts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
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

function run_update_package_xml( $task=null, $args=array(), $cliopts=array() )
{
    /// @todo replace hostname, build time

    $opts = eZExtBuilder::getOpts( @$args[0] );
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

function run_generate_sample_package_xml( $task=null, $args=array(), $cliopts=array() )
{
    pake_copy( 'pake/package_master.xml', 'package.xml' );
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
        "pake/options-$extname.yaml",
        array( $extname => '', 'external' => 'dependencies', 'dependency' => 'extensions', 'repository' => array( 'svn', 'url' ) ),
        "extension:\n    name: $extname\n\n" );

    foreach( array( 'files.to.parse.txt' => 'to_parse', 'files.to.exclude.txt' => 'to_exclude' ) as $file => $option )
    {
        $src = "ant/$file";
        //$dst = "pake/$file";
        if ( file_exists( $src ) )
        {
            //$ok = !file_exists( $dst ) || ( pake_input( "Destionation file $dst exists. Overwrite? [y/n]", 'n' ) == 'y' );
            //$ok && pake_copy( $src, $dst, array( 'override' => true ) );
            if ( count( $in = file( $src, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES ) ) )
            {
                $in = "\n\nfiles:\n    $option: [" . implode( ', ', $in ) . "]\n";
                file_put_contents( "pake/options-$extname.yaml", $in, FILE_APPEND );
            }
        }
    }
}

function run_tool_upgrade_check( $task=null, $args=array(), $cliopts=array() )
{
    $latest = eZExtBuilder::latestVersion();
    if ( $latest == false )
    {
        pake_echo ( "Cannot determine latest version available. Please check that you can connect to the internet" );
    }
    else
    {
        $current = eZExtBuilder::$version;
        $check = version_compare( $latest, $current );
        if ( $check == -1 )
        {
            pake_echo ( "Danger, Will Robinson! You are running a newer version ($current) than the lastest available online ($latest)" );
        }
        else if( $check == 0 )
        {
            pake_echo ( "You are running the lastest available version: $latest" );
        }
        else
        {
            pake_echo ( "A newer version is available online: $latest (you are running $current)" );
            $ok = pake_input( "Do you want to upgrade? [y/n]", 'n' );
            if ( $ok == 'y' )
            {
                run_tool_upgrade(  $task, $args, $cliopts );
            }
        }
    }
}

/// @todo add a backup enable/disable option
function run_tool_upgrade( $task=null, $args=array(), $cliopts=array() )
{
    $latest = eZExtBuilder::latestVersion( true );
    if ( $latest == false )
    {
        pake_echo ( "Cannot download latest version available. Please check that you can connect to the internet" );
    }
    else
    {
        // 1st get the whole 'pake' dir contents, making a backup copy
        $tmpzipfile = tempnam( "tmp", "zip" );
        $zipfile = dirname( __FILE__ ) . '/pake/pakedir-' . eZExtBuilder::$version . '.zip';
        eZExtBuilder::archiveDir( dirname( __FILE__ ) . '/pake', $tmpzipfile, ezcArchive::ZIP );
        @unlink( $zipfile ); // otherwise pake_rename might complain
        pake_rename( $tmpzipfile, $zipfile );
        eZExtBuilder::bootstrap();

        // then update the pakefile itself, making a backup copy
        pake_copy( __FILE__, dirname( __FILE__ ) . '/pake/pakefile-' . eZExtBuilder::$version . '.php', array( 'override' => true ) );
        /// @todo test: does this work on windows?
        file_put_contents( __FILE__, $latest );
    }
}

/**
* Class implementing the core logic for our pake tasks
* @todo separate in another file?
*/
class eZExtBuilder
{
    static $options = null;
    static $defaultext = null;
    static $installurl = 'http://svn.projects.ez.no/ezextensionbuilder/stable/pake';
    static $version = '0.3';
    static $min_pake_version = '1.6.1';

    static function getBuildDir( $opts )
    {
        $dir = $opts['build']['dir'];
        if ( $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
        {
            $dir .= '/ezextension';
        }
        return $dir;
    }

    static function getDefaultExtName()
    {
        if ( self::$defaultext != null )
        {
            return self::$defaultext;
        }
        $files = pakeFinder::type( 'file' )->name( 'options-*.yaml' )->not_name( 'options-sample.yaml' )->not_name( 'options-ezextensionbuilder.yaml' )->maxdepth( 0 )->in( 'pake' );
        if ( count( $files ) == 1 )
        {
            self::$defaultext = substr( basename( $files[0] ), 8, -5 );
            pake_echo ( 'Found extension: ' . self::$defaultext );
            return self::$defaultext;
        }
        else if ( count( $files ) == 0 )
        {
            throw new pakeException( "Missing configuration file pake/options-[extname].yaml, cannot continue" );
        }
        else
        {
            throw new pakeException( "Multiple configuration files pake/options-*.yaml found, need to specify an extension name to continue" );
        }
    }

    static function getOpts( $extname='' )
    {
        if ( $extname == '' )
        {
            $extname = self::getDefaultExtName();
            //self::$defaultext = $extname;
        }
        if ( !isset( self::$options[$extname] ) || !is_array( self::$options[$extname] ) )
        {
            self::loadConfiguration( "pake/options-$extname.yaml", $extname );
        }
        return self::$options[$extname];
    }

    /// @bug this only works as long as all defaults are 2 levels deep
    static function loadConfiguration ( $infile='pake/options.yaml', $extname='' )
    {
        $mandatory_opts = array( /*'extension' => array( 'name' ),*/ 'version' => array( 'major', 'minor', 'release' ) );
        $default_opts = array(
            'build' => array( 'dir' => 'build' ),
            'dist' => array( 'dir' => 'dist' ),
            'create' => array( 'tarball' => false, 'zip' => false, 'filelist_md5' => true, 'doxygen_doc' => false, 'ezpackage' => false, 'pearpackage' => false ),
            'version' => array( 'license' => 'GNU General Public License v2.0' ),
            'releasenr' => array( 'separator' => '-' ),
            'files' => array( 'to_parse' => array(), 'to_exclude' => array(), 'gnu_dir' => '', 'sql_files' => array( 'db_schema' => 'schema.sql', 'db_data' => 'cleandata.sql' ) ),
            'dependencies' => array( 'extensions' => array() ) );
        /// @todo !important: test if !file_exists give a nicer warning than what we get from loadFile()
        $options = pakeYaml::loadFile( $infile );
        foreach( $mandatory_opts as $key => $opts )
        {
            foreach( $opts as $opt )
            {
                if ( !isset( $options[$key][$opt] ) )
                {
                    throw new pakeException( "Missing mandatory option: $key:$opt" );
                }
            }
        }
        if ( !isset( $options['extension']['name'] ) || $options['extension']['name'] == '' )
        {
            $options['extension']['name'] = $extname;
        }
        if ( !isset( $options['version']['alias'] ) || $options['version']['alias'] == '' )
        {
            $options['version']['alias'] = $options['version']['major'] . '.' . $options['version']['minor'];
        }
        foreach( $default_opts as $key => $opts )
        {
            if ( isset( $options[$key] ) && is_array( $options[$key] ) )
            {
                $options[$key] = array_merge( $opts, $options[$key] );
            }
            else
            {
                /// @todo echo a warning if $options[$key] is set but not array?
                $options[$key] = $opts;
            }
        }
        self::$options[$extname] = $options;
        return true;
    }

    /**
    * Converts a property file into a yaml file
    * @param array $transform an array of transformation rules such as eg. 'sourcetag' => 'desttag' (desttag can be empty for tag removal or an array for tag expansion)
    * @todo move to a separate class to slim down base class?
    * @todo make it capable to remove complete $ext.version.alias property
    */
    static function convertPropertyFileToYamlFile( $infile, $outfile='pake/options.yaml', $transform = array(), $prepend='' )
    {
        $current = array();
        $out = array();
        foreach ( file( $infile ) as $line )
        {
            $line = trim( $line );
            if ( $line == '' )
            {
                $out[] = '';
            }
            else if ( strpos( $line, '<!--' ) === 0 )
            {
                $out[] .= preg_replace( '/^<!-- *(.*) *-->$/', '# $1', $line );
            }
            else if ( strpos( $line, '=' ) != 0 )
            {
                $line = explode( '=', $line, 2 );
                $path = explode( '.', trim( $line[0] ) );
                foreach( $transform as $src => $dst )
                {
                    foreach( $path as $i => $element )
                    {
                        if ( $element == $src )
                        {
                            if ( $dst == '' )
                            {
                                unset( $path[$i] );
                            }
                            else if ( is_array( $dst ) )
                            {
                                array_splice( $path, $i-1, 1, $dst );
                            }
                            else
                            {
                                $path[$i] = $dst;
                            }
                        }
                    }
                }
                // elements index can have holes here, cannot trust them => reorder
                $path = array_values( $path );

                $value = $line[1];
                $token = array_pop( $path );

                if ( $path != $current )
                {
                    $skip = 0;
                    foreach( $path as $j => $element )
                    {
                        if ( $element == @$current[$j] )
                        {
                            $skip++;
                        }
                        else
                        {
                            break;
                        }
                    }

                    for( $j = $skip; $j < count( $path ); $j++ )
                    //foreach( $path as $j => $element )
                    {
                        $line = '';
                        for ( $i = 0; $i < $j; $i++ )
                        {
                            $line .= '    ';
                        }
                        $line .= $path[$j] . ':';
                        $out[] = $line;
                    }
                }
                $line = '';
                for ( $i = 0; $i < count( $path ); $i++ )
                {
                    $line .= '    ';
                }
                $line .= $token . ': ' . $value;
                $out[] = $line;
                $current = $path;
            }
            else
            {
                /// @todo log warning?
            }
        }
        pake_mkdirs( 'pake' );
        // ask confirmation if file exists
        $ok = !file_exists( $outfile ) || ( pake_input( "Destionation file $outfile exists. Overwrite? [y/n]", 'n' ) == 'y' );
        if ( $ok )
        {
            file_put_contents( $outfile, $prepend . implode( $out, "\n" ) );
            pake_echo_action( 'file+', $outfile );
        }
    }

    /**
    * Download from the web all files that make up the extension (except self)
    * and uncompress them in ./pake dir
    */
    static function bootstrap()
    {
        if ( is_file( 'pake ' ) )
        {
            echo "Error: could not create 'pake' directory to install the extension because a file named 'pake' exists";
            exit( -1 );
        }

        if ( is_dir( 'pake') )
        {
            /// @todo test: if dir is not empty, ask for confirmation,
            ///       least we overwrite something
        }

        if ( !is_dir( 'pake' ) && !mkdir( 'pake' ) )
        {
            echo "Error: could not create 'pake' directory to install the extension";
            exit( -1 );
        }

        // download components
        /// @todo use a recursive fget, so that we do not need to download a zip
        $src = self::$installurl.'/pake/ezextensionbuilder_pakedir.zip';
        $zipfile = tempnam( "tmp", "zip" );
        if ( !file_put_contents( $zipfile, file_get_contents( $src ) ) )
        {
            echo "Error: could not download source file $src";
            exit -1;
        }

        // unzip them
        $zip = new ZipArchive;
        if ( $zip->open( $zipfile ) !== true )
        {
            echo "Error: downloaded source file $src is not a valid zip file";
            exit -1;
        }
        if ( !$zip->extractTo( 'pake' ) )
        {
            echo "Error: could not decompress source file $zipfile";
            $zip->close();
            exit -1;
        }
        $zip->close();
        unlink( $zipfile );
    }

    /**
    * Checks the latest version available online
    * @return string the version nr. or the new version of the file, depending on input param (false in case of error)
    */
    static function latestVersion( $getfile=false )
    {
        $src = self::$installurl.'/pakefile.php?show=source';
        /// @todo test using curl for allow_url_fopen off
        if ( $source = pake_read_file( $src ) )
        {
            if ( $getfile )
            {
                return $source;
            }
            if ( preg_match( '/^[\s]*static \$version = \'([^\']+)\';/m', $source, $matches ) )
            {
                return $matches[1];
            }
        }
        return false;
    }

    /**
    * Creates an archive out of a directory
    */
    static function archiveDir( $sourcedir, $archivefile, $archivetype, $no_top_dir=false )
    {
        if ( substr( $archivefile, -3 ) == '.gz' )
        {
            $zipext = 'gz';
            $target = substr( $archivefile, 0, -3 );
        }
        else if ( substr( $archivefile, -4 ) == '.bz2' )
        {
            $zipext = 'bz2';
            $target = substr( $archivefile, 0, -4 );
        }
        else if ( substr( $archivefile, -6 ) == '.ezpkg' )
        {
            $zipext = 'ezpkg';
            $target = substr( $archivefile, 0, -6 ) . '.tar';
        }
        else
        {
            $zipext = false;
            $target = $archivefile;
        }
        $rootpath = str_replace( '\\', '/', realpath( $no_top_dir ? $sourcedir : dirname( $sourcedir ) ) );
        $files = pakeFinder::type( 'any' )->in( $sourcedir );
        // fix for win
        foreach( $files as $i => $file )
        {
            $files[$i] = str_replace( '\\', '/', $file );
        }
        // current ezc code does not like having folders in list of files to pack
        // unless they end in '/'
        foreach( $files as $i => $f )
        {
            if ( is_dir( $f ) )
            {
                $files[$i] = $files[$i] . '/';
            }
        }
        // we do not rely on this, not to depend on phar extension and also because it's slightly buggy if there are dots in archive file name
        //pakeArchive::createArchive( $files, $opts['build']['dir'], $target, true );
        $tar = ezcArchive::open( $target, $archivetype );
        $tar->truncate();
        $tar->append( $files, $rootpath );
        $tar->close();
        if ( $zipext )
        {
            $compress = 'zlib';
            if ( $zipext == 'bz2' )
            {
                $compress = 'bzip2';
            }
            $fp = fopen( "compress.$compress://" . ( $zipext == 'ezpkg' ? substr( $target, 0, -4 ) : $target ) . ".$zipext", 'wb9' );
            /// @todo read file by small chunks to avoid memory exhaustion
            fwrite( $fp, file_get_contents( $target ) );
            fclose( $fp );
            unlink( $target );
        }
        pake_echo_action( 'file+', $archivefile );
    }
}

}

// The following two functions we use, and submitted for inclusion in pake.
// While we wait for acceptance, we define them here...
if ( !function_exists( 'pake_replace_regexp_to_dir' ) )
{

function pake_replace_regexp_to_dir($arg, $src_dir, $target_dir, $regexps, $limit=-1)
{
    $files = pakeFinder::get_files_from_argument($arg, $src_dir, true);

    foreach ($files as $file)
    {
        $replaced = false;
        $content = pake_read_file($src_dir.'/'.$file);
        foreach ($regexps as $key => $value)
        {
            $content = preg_replace($key, $value, $content, $limit, $count);
            if ($count) $replaced = true;
        }

        pake_echo_action('regexp', $target_dir.DIRECTORY_SEPARATOR.$file);

        file_put_contents($target_dir.DIRECTORY_SEPARATOR.$file, $content);
    }
}

function pake_replace_regexp($arg, $target_dir, $regexps, $limit=-1)
{
    pake_replace_regexp_to_dir($arg, $target_dir, $target_dir, $regexps, $limit);
}

}

if ( !function_exists( 'pake_antpattern' ) )
{

/**
* Mimics ant pattern matching.
* Waiting for pake 1.6.2 or later to provide this natively
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

}


// *** Live code starts here ***

// First off, test if user is running directly this script
// (we allow both direct invocation via "php pakefile.php" and invocation via "php pake.php")
if ( !function_exists( 'pake_desc' ) )
{
    // Running script directly. look if pake is found in the folder where this script installs it: ./pake/src
    if ( file_exists( 'pake/src/bin/pake.php' ) )
    {
        include( 'pake/src/bin/pake.php' );

        // force ezc autoloading (including pake.php will have set include path from env var PHP_CLASSPATH)
        register_ezc_autoload();

        $GLOBALS['internal_pake'] = true;

        $pake = pakeApp::get_instance();
        $pake->run();
    }
    else
    {

        echo "Pake tool not found. Bootstrap needed\n  (automatic download of missing components from project.ez.no)\n";
        do
        {
            echo 'Continue? [y/n] ';
            $fp = fopen('php://stdin', 'r');
            $ok = trim( strtolower( fgets( $fp ) ) );
            fclose( $fp );
            if ( $ok == 'y' )
            {
                break;
            }
            else if ( $ok == 'n' )
            {
                exit ( 0 );
            }
            echo "\n";
        } while( true );

        eZExtBuilder::bootstrap();

        echo
            "Succesfully downloaded sources\n" .
            "  Next steps: copy pake/options-sample.yaml to pake/options.yaml, edit it\n" .
            "  then run again this script.\n".
            "  Use the environment var PHP_CLASSPATH for proper class autoloading of eg. Zeta Components";
        exit( 0 );

    }
}
else
{
    // pake is loaded

// force ezc autoloading (including pake.php will have set include path from env var PHP_CLASSPATH)
register_ezc_autoload();

// this is unfortunately a necessary hack: version 0.1 of this extension
// shipped with a faulty pake_version, so we cannot check for required version
// when using the bundled pake.
// To aggravate things, version 0.1 did not upgrade the bundled pake when
// upgrading to a new script, so we can not be sure that, even if the end user
// updates to a newer pakefile, the bundled pake will be upgraded
// (it will only be when the user does two consecutive updates)
if ( !( isset( $GLOBALS['internal_pake'] ) && $GLOBALS['internal_pake'] ) )
{
    pake_require_version( eZExtBuilder::$min_pake_version );
}

pake_desc( 'Shows help message' );
pake_task( 'default' );

pake_desc( 'Shows the properties for this build file' );
pake_task( 'show-properties' );

pake_desc( 'Downloads extension sources from svn/git and removes unwanted files' );
pake_task( 'init' );

pake_desc( 'Builds the extension. Options: --skip-init' );
pake_task( 'build', 'init', 'check-sql-files', 'check-gnu-files',
    'update-ezinfo', 'update-license-headers', 'update-extra-files', 'update-package-xml',
    'generate-documentation', 'generate-md5sums', 'generate-package-filelist' );

pake_desc( 'Removes the build/ directory' );
pake_task( 'clean' );

pake_desc( 'Creates a tarball of the built extension' );
pake_task( 'dist' );

pake_desc( 'Removes the dist/ directory' );
pake_task( 'dist-clean' );

pake_desc( 'Builds the extension and generates the tarball' );
pake_task( 'all', 'build', 'dist', 'build-dependencies' );

pake_desc( 'Removes the build/ and dist/ directories' );
pake_task( 'clean-all', 'clean', 'dist-clean' );

pake_desc( 'Creates a tarball of all extensions in the build/ directory' );
pake_task( 'fat-dist' );

pake_desc( 'Updates ezinfo.php and extension.xml with correct version numbers and licensing info' );
pake_task( 'update-ezinfo' );

pake_desc( 'Update license headers in source code files (php, js, css)' );
pake_task( 'update-license-headers' );

pake_desc( 'Updates extra files with correct version numbers and licensing info' );
pake_task( 'update-extra-files' );

pake_desc( 'Generates the documentation of the extension, if created in RST format in the doc/ folder, plus optionally API docs via doxygen. Options: --doxygen=/path/to/doxygen' );
pake_task( 'generate-documentation' );

//pake_desc( 'Checks PHP code coding standard, requires PHPCodeSniffer' );
//pake_task( 'coding-standards-check' );

pake_desc( 'Generates a share/filelist.md5 file with md5 checksums of all source files' );
pake_task( 'generate-md5sums' );

pake_desc( 'Checks if a schema.sql / cleandata.sql is available for all supported databases' );
pake_task( 'check-sql-files' );

pake_desc( 'Checks for presence of LICENSE and README files' );
pake_task( 'check-gnu-files' );

pake_desc( 'Generates an XML filelist definition for packaged extensions' );
pake_task( 'generate-package-filelist' );

pake_desc( 'Updates information in package.xml file used by packaged extensions' );
pake_task( 'update-package-xml' );

pake_desc( 'Build dependent extensions' );
pake_task( 'build-dependencies' );

/*
pake_desc( 'Creates an ezpackage tarball.' );
pake_task( 'generate-package-tarball', 'update-package-xml', 'generate-package-filelist' );
*/

pake_desc( 'Generates a sample package.xml to allow creation of packaged extension. NB: that file is to be completed by hand' );
pake_task( 'generate-sample-package-xml' );

pake_desc( 'Converts an existing ant properties file in its corresponding yaml version' );
pake_task( 'convert-configuration' );

pake_desc( 'Checks if a newer version of the tool is available online' );
pake_task( 'tool-upgrade-check' );

pake_desc( 'Upgrades to the latest version of the tool available online' );
pake_task( 'tool-upgrade' );

}

?>
