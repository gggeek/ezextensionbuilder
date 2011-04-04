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
* @license
* @version   SVN: $Id$
*
* @todo move all known paths/names/... to class constants
*
* @todo add to php include dir a custom dir for our own pake tasks
*
* @bug at least on win, after using svn to checkout a project, the script does
*      not have enough rights to remove the checkout dir...
*/

// First off, test if user is running directly this script
// (we allow both direct invocation via "php pakefile.php" and invocation via "php pake.php")
if ( !function_exists( 'pake_desc' ) )
{
    // the folder where this script installs the pake tool is pake/src
    if ( file_exists( 'pake/src/bin/pake.php' ) )
    {
        include( 'pake/src/bin/pake.php' );

        // try to force ezc autoloading (including pake.php will have set include path from env var PHP_CLASSPATH)
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
        $src = 'http://svn.projects.ez.no/ezextensionbuilder/stable/pake/ezextensionbuilder_pakedir.zip';
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

        echo
            "Succesfully downloaded sources\n" .
            "  Next steps: copy pake/options-sample.yaml to pake/options.yaml, edit it\n" .
            "  then run again this script.\n".
            "  Use the environment var PHP_CLASSPATH for proper class autoloading of eg. Zeta Components";
        exit( 0 );

    }
}

pake_desc( 'Shows help message' );
pake_task( 'default' );

pake_desc( 'Shows the properties for this build file' );
pake_task( 'show-properties' );

pake_desc( 'Prepares the extension to be built' );
pake_task( 'init' );

pake_desc( 'Builds the extension' );
pake_task( 'build', 'init' );

pake_desc( 'Removes the entire build directory' );
pake_task( 'clean' );

pake_desc( 'Removes the build/ and the dist/ directory' );
pake_task( 'clean-all' );

pake_desc( 'Removes the generated tarball' );
pake_task( 'dist-clean' );

pake_desc( 'Updates ezinfo.php with correct version numbers' );
pake_task( 'update-ezinfo' );

pake_desc( 'Update license headers in source code files' );
pake_task( 'update-license-headers' );

pake_desc( 'Updates extra files with correct version numbers and licensing info' );
pake_task( 'update-extra-files' );

pake_desc( 'Generates the document of the extension, if created in RST' );
pake_task( 'generate-documentation' );

//pake_desc( 'Checks PHP code coding standard, requires PHPCodeSniffer' );
//pake_task( 'coding-standards-check' );

pake_desc( 'Generates an MD5 file with all md5 sums of source code files' );
pake_task( 'generate-md5sums' );

pake_desc( 'Checks if a schema.sql / cleandata.sql is available for supported databases' );
pake_task( 'check-sql-files' );

pake_desc( 'Checks for LICENSE and README files' );
pake_task( 'check-gnu-files' );

/*
pake_desc( 'Generates an XML definition for eZ Publish extension package types' );
pake_task( 'generate-ezpackage-xml-definition' );

pake_desc( 'Updates version numbers in package.xml' );
pake_task( 'update-package-xml' );

pake_desc( 'Build dependent extensions' );
pake_task( 'build-dependencies' );

pake_desc( 'Creates tarballs for ezpackages.' );
pake_task( 'create-package-tarballs' );
*/

pake_desc( 'Converts an existing ant properties file in its corresponding yaml version' );
pake_task( 'convert-configuration' );

// This file could be included twice, avoid redefining functions and classes

if ( !function_exists( 'run_default' ) )
{

// ***

function run_default()
{
    pake_echo ( 'Please run : pake --tasks to learn more about available tasks' );
}

function run_show_properties()
{
    $opts = eZExtBuilder::getOpts();
    pake_echo ( 'Build dir: ' . $opts['build']['dir'] );
    pake_echo ( 'Extension name: ' . $opts['extension']['name'] );
}

/// @todo add a dependency on a check-updates task that updates script itself
function run_init()
{
    $opts = eZExtBuilder::getOpts();
    pake_mkdirs( $opts['build']['dir'] );

    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
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
    else
    {
        throw new pakeException( "Missing source repo option: either svn:url or git:url" );
    }

    // remove files

    // known files/dirs not to be packed / md5'ed
    /// @todo !important shall we make this configurable?
    $files = array( 'ant', 'build.xml', 'pake', 'pakefile.php', '.svn', '.git' );
    // files from user configuration
    $files = array_merge( $files, eZExtBuilder::loadFileListFromFile( 'pake/files.to.exclude.txt' ) );

    /**
     Uses a regular expression to search and replace the correct string
     Within the file, please note there is a limit of 25 sets to indent 3rd party
     lib version numbers, if you use more than 25 spaces the version number will
     not be updated correctly
    */
    $files = pakeFinder::type( 'any' )->name( $files )->in( $opts['build']['dir'] );
    foreach ( $files as $file )
    {
        pake_replace_regexp( $files, $opts['build']['dir'], array(
            '/^([\s]{1,25}\047Version\047[\s]+=>[\s]+\047)(.*)(\047,)$/m' => '$1'.$opts['version']['alias'].$opts['releasenr']['separator'].$opts['version']['release'].'$3' ) );
    }
}

function run_build()
{
    /// @todo shall we pass via some pakeApp call?
    run_update_ezinfo();
    run_update_license_headers();
    run_update_extra_files();
    run_generate_documentation();
    run_generate_md5sums();
    run_check_sql_files();
    run_check_gnu_files();
    //run_eznetwork_certify();
    run_update_package_xml();
    run_generate_ezpackage_xml_definition();
    run_create_package_tarballs();
}

function run_clean()
{
    $opts = eZExtBuilder::getOpts();
    pake_remove_dir( $opts['build']['dir'] );
}

function run_clean_all()
{
    /// @todo shall we pass via some pakeApp call?
    run_clean();
    run_dist_clean();
}

function run_dist_clean()
{
    $opts = eZExtBuilder::getOpts();
    pake_remove_dir( $opts['dist']['dir'] );
}

/// @todo make sure pake_replace_regexp is merged upstream or devlivered by us
function run_update_ezinfo()
{
    $opts = eZExtBuilder::getOpts();
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    /// @todo shall we limit this to 1 level deep?
    $files = pakeFinder::type( 'file' )->name( 'ezinfo.php' )->in( $destdir );

    /*
    * Uses a regular expression to search and replace the correct string
    * Within the file, please note there is a limit of 25 sets to indent 3rd party
    * lib version numbers, if you use more than 25 spaces the version number will
    * not be updated correctly
    */
    pake_replace_regexp( $files, $destdir, array(
        '/^([\s]{1,25}\x27Version\x27[\s]+=>[\s]+\x27)(.*)(\x27,\r?\n)/m' => '${1}' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] . '$3' ) );
}

/**
* @todo use more tolerant comment tags (eg multiline comments)
* @todo parse tpl files too?
* @todo use other strings than these, since it's gonna be community extensions?
*/
function run_update_license_headers()
{
    $opts = eZExtBuilder::getOpts();
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    $files = pakeFinder::type( 'file' )->name( array( '*.php', '*.css', '*.js' ) )->in( $destdir );
    pake_replace_regexp( $files, $destdir, array(
        '#// SOFTWARE RELEASE: (.*)#m' => '// SOFTWARE RELEASE: ' . $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'] ) );
    pake_replace_regexp( $files, $destdir, array(
        '/Copyright \(C\) 1999-[\d]{4} eZ Systems AS/m' => 'Copyright (C) 1999-' . strftime( '%Y' ). ' eZ Systems AS' ) );
}

function run_update_extra_files()
{
    $opts = eZExtBuilder::getOpts();
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    $extrafiles = eZExtBuilder::loadFileListFromFile( 'pake/files.to.parse.txt' );
    $files = pakeFinder::type( 'file' )->name( $extrafiles )->in( $destdir );
    pake_replace_tokens( $files, $destdir, '[', ']', array(
        'EXTENSION_VERSION' => $opts['version']['alias'] . $opts['releasenr']['separator'] . $opts['version']['release'],
        'EXTENSION_PUBLISH_VERSION' => $opts['ezp']['version']['major'] . $opts['ezp']['version']['minor'] . $opts['ezp']['version']['release'],
        'EXTENSION_LICENSE' => $opts['version']['license'] ) );
}

/**
* @todo allow config file to specify doc dir
* @todo parse any doxygen file found, too
*/
function run_generate_documentation()
{
    $opts = eZExtBuilder::getOpts();
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
    * We remove them as well as original .rst files
    */
    pake_remove( pakeFinder::type( 'file' )->name( 'Makefile' )->in( $destdir ), '' );

}

function run_generate_md5sums()
{
    $opts = eZExtBuilder::getOpts();
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
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
function run_check_sql_files()
{
    $opts = eZExtBuilder::getOpts();
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];

    $schemafiles = array( 'share' => 'db_schema.dba', 'sql/mysql' => 'schema.sql', 'sql/oracle' => 'schema.sql', 'sql/postgres' => 'schema.sql' );
    $count = 0;
    foreach( $schemafiles as $dir => $file )
    {
        $files = pakeFinder::type( 'file' )->name( $file )->maxdepth( 1 )->in( $destdir . "/$dir" );
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

    $datafiles = array( 'share' => 'db_data.dba', 'sql/mysql' => 'cleandata.sql', 'sql/oracle' => 'cleandata.sql', 'sql/postgres' => 'cleandata.sql' );
    $count = 0;
    foreach( $datafiles as $dir => $file )
    {
        $files = pakeFinder::type( 'file' )->name( $file )->maxdepth( 1 )->in( $destdir . "/$dir" );
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

function run_check_gnu_files()
{
    $opts = eZExtBuilder::getOpts();
    $destdir = $opts['build']['dir'] . '/' . $opts['extension']['name'];
    $files = pakeFinder::type( 'file' )->name( array( 'README', 'LICENSE' ) )->maxdepth( 1 )->in( $destdir );
    if ( count( $files ) != 2 )
    {
        throw new pakeException( "README and/or INSTALL files missing. Please fix" );
    }
}


function run_convert_configuration()
{
    $extname = dirname(__FILE__);
    while ( !is_file( "ant/$extname.properties" ) )
    {
        $extname = pake_input( 'What is the name of the current extension?' );
        if ( !is_file( "ant/$extname.properties" ) )
        {
            pake_echo( "File ant/$extname.properties not found" );
        }
    }

    eZExtBuilder::covertPropertyFileToYamlFile(
        "ant/$extname.properties",
        'pake/options.yaml',
        array( $extname => '' ),
        "extension:\n    name: $extname\n\n" );

    foreach( array( 'files.to.parse.txt', 'files.to.exclude.txt' ) as $file )
    {
        $src = "ant/$file";
        $dst = "pake/$file";
        if ( file_exists( $src ) )
        {
            $ok = !file_exists( $dst ) || ( pake_input( "Destionation file $dst exists. Overwrite? [y/n]", 'n' ) == 'y' );
            $ok && pake_copy( $src, $dst, array( 'override' => true ) );
        }
    }
}

// ***

class eZExtBuilder
{
    static $options = null;

    static function getOpts()
    {
        if ( !is_array( self::$options ) )
        {
            self::loadConfiguration();
        }
        return self::$options;
    }

    /// @bug this only works as long as all defaults are 2 leles deep
    static function loadConfiguration ( $infile='pake/options.yaml' )
    {
        $mandatory_opts = array( 'extension' => array( 'name' ), 'version' => array( 'major', 'minor', 'release' ) );
        $default_opts = array(
            'build' => array( 'dir' => 'build' ),
            'dist' => array( 'dir' => 'dist' ),
            'create' => array( 'tarball' => false ),
            'version' => array( 'license' => 'GNU General Public License v2.0' ),
            'releasenr' => array( 'separator' => '-' ) );
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
        if ( !isset( $options['version']['alias'] ) )
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
        self::$options = $options;
        return true;
    }

    /// @todo move to a separate class to slim down base class
    static function covertPropertyFileToYamlFile( $infile, $outfile='pake/options.yaml', $transform = array(), $prepend='' )
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
                            else
                            {
                                $path[$i] = $dst;
                            }
                        }
                    }
                }
                $value = $line[1];
                $token = array_pop( $path );
                if ( $path != $current )
                {
                    // elements index can have holes here, cannot trust them => reorder
                    foreach( array_values(  $path ) as $j => $element )
                    {
                        $line = '';
                        for ( $i = 0; $i < $j; $i++ )
                        {
                            $line .= '    ';
                        }
                        $line .= $element . ':';
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
        $ok && file_put_contents( $outfile, $prepend . implode( $out, "\n" ) );
    }

    /**
    * Reads a list of files from a txt file
    * . one file per line
    * . comment lines start with #
    * . whitespace stripped at beginning/end of line
    */
    static function loadFileListFromFile( $file )
    {
        if ( !file_exists( $file ) )
        {
            return array();
        }
        $files = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        foreach ( $files as $i => $file )
        {
            $file = trim( $file );
            if ( $file == '' || $file[0] == '#' )
            {
                unset( $files[$i] );
            }
        }
        return array_values( $files );
    }
}

}

?>
