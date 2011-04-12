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

function run_show_properties( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    pake_echo ( 'Build dir: ' . $opts['build']['dir'] );
    pake_echo ( 'Extension name: ' . $opts['extension']['name'] );
}

/**
* Downloads the extension from its source repository, removes files not to be built
* @todo add a dependency on a check-updates task that updates script itself?
* @todo split this in two tasks and avoid this unsightly mess of options?
*/
function run_init( $task=null, $args=array(), $opts=array() )
{
    $skip_init = @$opts['skip-init'];
    $skip_init_fetch = @$opts['skip-init-fetch'] || $skip_init;
    $skip_init_clean = @$opts['skip-init-clean'] || $skip_init;

    if ( !$skip_init )
    {
        $opts = eZExtBuilder::getOpts( @$args[0] );
        pake_mkdirs( $opts['build']['dir'] );

        $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    }

    if ( ! $skip_init_fetch )
    {
        if ( @$opts['svn']['url'] != '' )
        {
            pake_echo( 'Fetching code from SVN repository' );
            pakeSubversion::checkout( $opts['svn']['url'], $destdir );
        }
        else if ( @$opts['git']['url'] != '' )
        {
            pake_echo( 'Fetching code from GIT repository' );
            pakeGit::clone_repository( $opts['git']['url'], $destdir );
            if ( @$opts['git']['branch'] != '' )
            {
                /// @todo allow to check out a specific branch
                pakeGit::checkout_repo( $destdir, @$opts['git']['branch'] );
            }
        }
        else if ( @$opts['file']['url'] != '' )
        {
            pake_echo( 'Fetching code from local repository' );
            /// @todo exlude stuff we know we're going to delete immediately afterwards
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
        /// @todo 'build' & 'dist' we should take from options
        $files = array( 'ant', 'build.xml', 'pake', 'pakefile.php', '.svn', '.git', '.gitignore', 'build', 'dist' );
        // files from user configuration
        $files = array_merge( $files, $opts['files']['to_exclude'] );

        /// @todo figure a way to allow user to speficy both:
        ///       files in a spefici subdir
        ///       files to be removed globally (ie. from any subdir)
        $files = pakeFinder::type( 'any' )->name( $files )->in( $destdir );
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
}

/// We rely on the pake dependency system here to do real stuff
function run_build( $task=null, $args=array(), $opts=array() )
{
}

function run_clean( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    pake_remove_dir( $opts['build']['dir'] );
}

function run_dist( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    if ( $opts['create']['tarball'] || $opts['create']['zip'] )
    {
        if ( !class_exists( 'ezcArchive' ) )
        {
            throw new pakeException( "Missing Zeta Components: cannot generate tar file. Use the environment var PHP_CLASSPATH" );
        }
        pake_mkdirs( $opts['dist']['dir'] );
        // get absolute path to build dir
        $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( $opts['build']['dir'] );
        $rootpath = dirname( $rootpath[0] );

        if ( $opts['create']['tarball'] )
        {
            $files = pakeFinder::type( 'any' )->in( $opts['build']['dir'] . '/' . $opts['extension']['name'] );
            $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.tar';
            // we do not rely on this, not to depend on phar extension and also because it's slightly buggy if there are dots in archive file name
            //pakeArchive::createArchive( $files, $opts['build']['dir'], $target, true );
            $tar = ezcArchive::open( $target, ezcArchive::TAR );
            $tar->truncate();
            $tar->append( $files, $rootpath );
            $tar->close();
            $fp = fopen( 'compress.zlib://' . $target . '.gz', 'wb9' );
            /// @todo read file by small chunks to avoid memory exhaustion
            fwrite( $fp, file_get_contents( $target ) );
            fclose( $fp );
            unlink( $target );
            pake_echo_action( 'file+', $target . '.gz' );
        }

        if ( $opts['create']['zip'] )
        {
            $files = pakeFinder::type( 'any' )->in( $opts['build']['dir'] . '/' . $opts['extension']['name'] );
            /// current ezc code does not like having folders in list of files to pack
            /// unless they end in '/'
            foreach( $files as $i => $f )
            {
                if ( is_dir( $f ) )
                {
                    $files[$i] = $files[$i] . '/';
                }
            }
            $target = $opts['dist']['dir'] . '/' . $opts['extension']['name'] . '-' . $opts['version']['alias'] . '.' . $opts['version']['release'] . '.zip';
            $tar = ezcArchive::open( $target, ezcArchive::ZIP );
            $tar->truncate();
            $tar->append( $files, $rootpath );
            $tar->close();
            pake_echo_action( 'file+', $target );
        }
    }
}

function run_dist_clean( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    pake_remove_dir( $opts['dist']['dir'] );
}

function run_build_dependencies( $task=null, $args=array(), $opts=array() )
{
    $current = @$args[0];
    $opts = eZExtBuilder::getOpts( $current );
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
            $task->execute( array( "tmp_$ext" ), array_merge( $opts, array( 'skip-init' => false, 'skip-init-fetch' => false, 'skip-init-clean' => true ) ) );

            // copy config file from ext dir to current config dir
            if ( is_file( $opts['build']['dir'] . "/$ext/pake/options-$ext.yaml" ) )
            {
                pake_copy( $opts['build']['dir'] . "/$ext/pake/options-$ext.yaml", "pake/options-$ext.yaml" );
            }
            else
            {
                throw new pakeException( "Missing spake/options.yaml extension in dependent extension $ext" );
            }

            // finish the init-task
            $task->execute( array( "tmp_$ext" ), array_merge( $opts, array( 'skip-init' => false, 'skip-init-fetch' => true, 'skip-init-clean' => false ) ) );
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

function run_fat_dist( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    if ( !class_exists( 'ezcArchive' ) )
    {
        throw new pakeException( "Missing Zeta Components: cannot generate tar file. Use the environment var PHP_CLASSPATH" );
    }
    pake_mkdirs( $opts['dist']['dir'] );
    $files = pakeFinder::type( 'any' )->in( $opts['build']['dir'] );
    // get absolute path to build dir
    $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( $opts['build']['dir'] );
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

function run_all( $task=null, $args=array(), $opts=array() )
{
}

function run_clean_all( $task=null, $args=array(), $opts=array() )
{
}

function run_update_ezinfo( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];

    $files = pakeFinder::type( 'file' )->name( 'ezinfo.php' )->maxdepth( 0 )->in( $destdir );
    /*
       * Uses a regular expression to search and replace the correct string
       * Within the file, please note there is a limit of 25 sets to indent 3rd party
       * lib version numbers, if you use more than 25 spaces the version number will
       * not be updated correctly
    */
    /// @todo use a real php parser instead
    pake_replace_regexp( $files, $destdir, array(
        '/^([\s]{1,25}\x27Version\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
        '/^([\s]{1,25}\x27License\x27[\s]+=>[\s]+[\x27\x22])(.*)([\x27\x22],?\r?\n?)/m' => '${1}' . $opts['version']['license'] . '$3' ) );

    $files = pakeFinder::type( 'file' )->maxdepth( 0 )->name( 'extension.xml' )->in( $destdir );
    // here again, do not replace version of required extensions
    /// @todo use a real xml parser instead
    pake_replace_regexp( $files, $destdir, array(
        '#^([\s]{1,8}<version>)([^<]*)(</version>\r?\n?)#m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3' ) );
    /// @bug we should use a better xml escaping here
    pake_replace_regexp( $files, $destdir, array(
        '#^([\s]{1,8}<license>)([^<]*)(</license>\r?\n?)#m' => '${1}' . htmlspecialchars( $opts['version']['license'] ) . '$3',
        '#^([\s]{1,8}<copyright>)Copyright \(C\) 1999-[\d]{4} eZ Systems AS(</copyright>\r?\n?)#m' => '${1}' . 'Copyright (C) 1999-' . strftime( '%Y' ). ' eZ Systems AS' . '$2' ) );
}

/**
* Update .php, .css and .js files replacing tokens found in the std eZ Systems header comment
* @todo use more tolerant comment tags (eg multiline comments)
* @todo parse tpl files too?
* @todo use other strings than these, since it's gonna be community extensions?
*/
function run_update_license_headers( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
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
function run_update_extra_files( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    $extrafiles = $opts['files']['to_parse'];
    $files = pakeFinder::type( 'file' )->name( $extrafiles )->in( $destdir );
    pake_replace_tokens( $files, $destdir, '[', ']', array(
        'EXTENSION_VERSION' => $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'],
        'EXTENSION_PUBLISH_VERSION' => $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '.' . $opts['ezp']['version']['release'],
        'EXTENSION_LICENSE' => $opts['version']['license'] ) );
}

/**
* Builds an html file of all doc/*.rst files, and removes the source
* @todo allow config file to specify doc dir
* @todo parse any doxygen file found, too
* @todo create api doc from php files
*       example cli cmd: ${phpdocinstall}phpdoc -t ${phpdocdir}/html -ti 'eZ Publish' -pp -s -d lib/ezdb/classes,lib/ezdbschema/classes,lib/ezdiff/classes,lib/ezfile/classes,lib/ezi18n/classes,lib/ezimage/classes,lib/ezlocale/classes,lib/ezmath/classes,lib/ezpdf/classes,lib/ezsession/classes,lib/ezsoap/classes,lib/eztemplate/classes,lib/ezutils/classes,lib/ezxml/classes,kernel/classes,kernel/private/classes,kernel/common,cronjobs,update/common/scripts > ${phpdocdir}/generate.log
*/
function run_generate_documentation( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
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

}

/**
* Creates a share/filelist.md5 file, with the checksul of all files in the build
*/
function run_generate_md5sums( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    // make sure we do not add to checksum file the file itself
    @unlink( $destdir . '/share/filelist.md5'  );
    $files = pakeFinder::type( 'file' )->in( $destdir );
    $out = array();
    $rootpath =  pakeFinder::type( 'directory' )->name( $opts['extension']['name'] )->in( $opts['build']['dir'] );
    foreach( $files as $file )
    {
        $out[] = md5_file( $file ) . '  ' . ltrim( str_replace( array( $rootpath[0], '\\' ), array( '', '/' ), $file ), '/' );
    }
    pake_mkdirs( $destdir . '/share' );
    file_put_contents( $destdir . '/share/filelist.md5', implode( "\n", $out ) );
    pake_echo_action('file+', $destdir . '/share/filelist.md5' );
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
function run_check_sql_files( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];

    $schemafiles = array( 'share' => 'db_schema.dba', 'sql/mysql' => 'schema.sql', 'sql/oracle' => 'schema.sql', 'sql/postgresql' => 'schema.sql' );
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

    $datafiles = array( 'share' => 'db_data.dba', 'sql/mysql' => 'cleandata.sql', 'sql/oracle' => 'cleandata.sql', 'sql/postgresql' => 'cleandata.sql' );
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

function run_check_gnu_files( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    $files = pakeFinder::type( 'file' )->name( array( 'README', 'LICENSE' ) )->maxdepth( 0 )->in( $destdir );
    if ( count( $files ) != 2 )
    {
        throw new pakeException( "README and/or LICENSE files missing. Please fix" );
    }
}

function run_update_package_xml( $task=null, $args=array(), $opts=array() )
{
    $opts = eZExtBuilder::getOpts( @$args[0] );
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    $files = pakeFinder::type( 'file' )->name( 'package.xml' )->maxdepth( 1 )->in( $destdir );
    if ( count( $files ) == 1 )
    {
        pake_replace_regexp( $files, $destdir, array(
            // <version>xxx</version>
            '#^(    \074version\076)(.*)(\074/version\076\r?\n?)$#m' => '${1}' . $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '.' . $opts['ezp']['version']['release'] . '$3',
            // <named-version>xxx</named-version>
            '#^(    \074named-version\076)(.*)(\074/named-version\076\r?\n?)$#m' => '${1}' . $opts['ezp']['version']['major'] . '.' . $opts['ezp']['version']['minor'] . '$3',
            // <package version="zzzz"
            '#^(    \074version\076)(.*)(\074/version\076\r?\n?)$#m' => '${1}' . $opts['version']['major'] . '.' . $opts['version']['minor'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3',
            // <number>xxxx</number>
            '#^(    \074number\076)(.*)(\074/number\076\r?\n?)$#m' => '${1}' . $opts['version']['alias'] . '$3',
            // <release>yyy</release>
            '#^(    \074release\076)(.*)(\074/release\076\r?\n?)$#m' => '${1}' . $opts['version']['release'] . '$3' ) );
    }
}

/// @todo allow user to specify extension name on the command line
function run_convert_configuration( $task=null, $args=array(), $opts=array() )
{
    $extname = @$opts['extension'];
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

function run_tool_upgrade_check( $task=null, $args=array(), $opts=array() )
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
            $ok = pake_select_input( "Do you want to upgrade? ", array( 'y', 'n' ), 'n' );
            if ( $ok == 'y' )
            {
                run_tool_upgrade(  $task, $args, $opts );
            }
        }
    }
}

/// @todo add a backup enable/disable option
function run_tool_upgrade( $task=null, $args=array(), $opts=array() )
{
    $latest = eZExtBuilder::latestVersion( true );
    if ( $latest == false )
    {
        pake_echo ( "Cannot download latest version available. Please check that you can connect to the internet" );
    }
    else
    {
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
    static $version = '0.1';

    static function getDefaultExtName()
    {
        if ( self::$defaultext != null )
        {
            return self::$defaultext;
        }
        $files = pakeFinder::type( 'file' )->name( 'options-*.yaml' )->not_name( 'options-sample.yaml' )->maxdepth( 1 )->in( 'pake' );
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

    /// @bug this only works as long as all defaults are 2 leles deep
    static function loadConfiguration ( $infile='pake/options.yaml', $extname='' )
    {
        $mandatory_opts = array( /*'extension' => array( 'name' ),*/ 'version' => array( 'major', 'minor', 'release' ) );
        $default_opts = array(
            'build' => array( 'dir' => 'build' ),
            'dist' => array( 'dir' => 'dist' ),
            'create' => array( 'tarball' => false, 'zip' => false ),
            'version' => array( 'license' => 'GNU General Public License v2.0' ),
            'releasenr' => array( 'separator' => '-' ),
            'files' => array( 'to_parse' => array(), 'to_exclude' => array(),
            'dependencies' => array( 'extensions' => array() )) );
        /// @todo !important: test i !file_exists give a nicer warning than what we get from loadFile()
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

            if ( isset($options[$key] ) && is_array( $options[$key] ) )
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
        /// @todo test using curl for allow_url-fopen off
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
}

}

// The following two functions we use, and submitted for inclusion in pake.
// While we wait for acceptance, we define them here...
if ( !function_exists( 'pake_replace_regexp_to_dir' ) )
{

function pake_replace_regexp_to_dir($arg, $src_dir, $target_dir, $regexps)
{
    $files = pakeFinder::get_files_from_argument($arg, $src_dir, true);

    foreach ($files as $file)
    {
        $replaced = false;
        $content = pake_read_file($src_dir.'/'.$file);
        foreach ($regexps as $key => $value)
        {
            $content = preg_replace($key, $value, $content, -1, $count);
            if ($count) $replaced = true;
        }

        pake_echo_action('regexp', $target_dir.DIRECTORY_SEPARATOR.$file);

        file_put_contents($target_dir.DIRECTORY_SEPARATOR.$file, $content);
    }
}

function pake_replace_regexp($arg, $target_dir, $regexps)
{
    pake_replace_regexp_to_dir($arg, $target_dir, $target_dir, $regexps);
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

        // add our own cli options

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

pake_desc( 'Shows help message' );
pake_task( 'default' );

pake_desc( 'Shows the properties for this build file' );
pake_task( 'show-properties' );

pake_desc( 'Downloads extension sources from svn/git and removes unwanted files' );
pake_task( 'init' );

pake_desc( 'Builds the extension. Options: --skip-init' );
pake_task( 'build', 'init', 'update-ezinfo', 'update-license-headers', 'update-extra-files',
     'generate-documentation', 'generate-md5sums', 'check-sql-files', 'check-gnu-files',
     'update-package-xml' );

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

pake_desc( 'Generates the documentation of the extension, if created in RST format' );
pake_task( 'generate-documentation' );

//pake_desc( 'Checks PHP code coding standard, requires PHPCodeSniffer' );
//pake_task( 'coding-standards-check' );

pake_desc( 'Generates a share/filelist.md5 file with md5 checksums of all source files' );
pake_task( 'generate-md5sums' );

pake_desc( 'Checks if a schema.sql / cleandata.sql is available for all supported databases' );
pake_task( 'check-sql-files' );

pake_desc( 'Checks for presence of LICENSE and README files' );
pake_task( 'check-gnu-files' );


//pake_desc( 'Generates an XML definition for eZ Publish extension package types' );
//pake_task( 'generate-ezpackage-xml-definition' );

pake_desc( 'Updates version numbers in package.xml file' );
pake_task( 'update-package-xml' );


pake_desc( 'Build dependent extensions' );
pake_task( 'build-dependencies' );

/*
pake_desc( 'Creates tarballs for ezpackages.' );
pake_task( 'create-package-tarballs' );
*/

pake_desc( 'Converts an existing ant properties file in its corresponding yaml version. Options: --extension=$extname' );
pake_task( 'convert-configuration' );

pake_desc( 'Checks if a newer version of the tool is available online' );
pake_task( 'tool-upgrade-check' );

pake_desc( 'Upgrades to the latest version of the tool available online' );
pake_task( 'tool-upgrade' );

}

?>
