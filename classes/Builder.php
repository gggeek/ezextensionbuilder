<?php
/**
 * Core functionality
 *
 * @author    G. Giunta
 * @copyright (C) G. Giunta 2013
 * @license   code licensed under the GNU GPL 2.0: see README file
 */

namespace eZExtBuilder;

use pakeException;
use pakeFinder;
use pakeYaml;

class Builder
{

    static $options = null;
    static $defaultExt = null;
    protected static $options_dir = 'pake';
    const VERSION = '0.5.0-dev';
    const MIN_PAKE_VERSION = '1.7.4';

    static function getBuildDir( $opts )
    {
        $dir = $opts['build']['dir'];
        if ( $opts['create']['ezpackage'] || $opts['create']['pearpackage'] )
        {
            $dir .= '/ezextension';
        }
        return $dir;
    }

    static function getReportDir( $opts )
    {
        return $opts['report']['dir'];
    }

    static function getOptionsDir()
    {
        return self::$options_dir;
    }

    /**
     * Tries to find out the vendor dir of composer - should work both when ezextbuilder is main project and when it is
     * a dependency. Returns FALSE if not found
     *
     * @param string $vendorPrefix
     * @return string
     */
    static function getVendorDir( $vendorPrefix = 'vendor' )
    {
        if( is_dir( __DIR__ . '/../../../composer' ) && is_file( __DIR__ . '/../../../autoload.php' ) )
        {
            return realpath( __DIR__ . '/../../..' );
        }
        if( is_dir( __DIR__ . "/../$vendorPrefix/composer" ) && is_file( __DIR__ . "/../$vendorPrefix/autoload.php" ) )
        {
            return realpath( __DIR__ . "/../$vendorPrefix" );
        }
        return false;
    }

    static function getResourceDir()
    {
        return __DIR__ . '/..';
    }

    /**
     * Searches for a default extension name (i.e. when there is only 1 config file in the config dir), saves it internally
     * and returns it
     *
     * @return string
     * @throws pakeException
     */
    static function getDefaultExtName()
    {
        if ( self::$defaultExt != null )
        {
            return self::$defaultExt;
        }
        $optsDir = self::getOptionsDir();
        /// @bug corner case: what if file options-.yaml is there?
        $files = pakeFinder::type( 'file' )->name( 'options-*.yaml' )->not_name( 'options-sample.yaml' )->
            not_name( 'options-user.yaml' )->maxdepth( 0 )->in( $optsDir );
        if ( count( $files ) == 1 )
        {
            self::$defaultExt = substr( basename( $files[0] ), 8, -5 );
            pake_echo ( 'Found extension: ' . self::$defaultExt );
            return self::$defaultExt;
        }
        else if ( count( $files ) == 0 )
        {
            throw new pakeException( "Missing configuration file $optsDir/options-[extname].yaml, cannot continue" );
        }
        else
        {
            throw new pakeException( "Multiple configuration files $optsDir/options-*.yaml found, need to specify an extension name to continue\n(run ezextbuilder list-extensions for a list of available extensions)" );
        }
    }

    /**
     * Returns the list of extensions for which we have a config file available
     * @return array
     */
    static function getAvailableExtNames()
    {
        $files = pakeFinder::type( 'file' )->name( 'options-*.yaml' )->not_name( 'options-sample.yaml' )->not_name( 'options-user.yaml' )->maxdepth( 0 )->in( self::getOptionsDir() );
        foreach ( $files as $i => $file )
        {
            $files[$i] = substr( basename( $file ), 8, -5 );
        }
        return $files;
    }

    static function setConfigDir( $cliopts = array() )
    {
        if ( isset( $cliopts['config-dir'] ) )
        {
            if( !is_dir( $cliopts['config-dir'] ) )
            {
                throw new PakeOption( "Could not find configuration-file directory {$cliopts['config-dir']}" );
            }
            self::$options_dir = $cliopts['config-dir'];
        }
    }

    /**
     * Loads, caches and returns the config options for a given extension
     * @return array
     */
    static function getOpts( $extname='', $cliopts = array() )
    {
        self::setConfigDir( $cliopts );

        if ( $extname == '' )
        {
            $extname = self::getDefaultExtName();
        }

        /// @bug we cache the options gotten from disk, but what if this function is invoked multiple times with different cli options?
        if ( !isset( self::$options[$extname] ) || !is_array( self::$options[$extname] ) )
        {
            // custom config file
            if ( isset( $cliopts['config-file'] ) )
            {
                $cfgfile = $cliopts['config-file'];
            }
            else
            {
                $cfgfile = self::getOptionsDir() . "/options-$extname.yaml";
            }

            // user-local config file
            if ( isset( $cliopts['user-config-file'] ) )
            {
                $usercfgfile = $cliopts['user-config-file'];
                if ( !is_file( $cliopts['user-config-file'] ) )
                {
                    throw new PakeOption( "Could not find user-configuration-file {$cliopts['user-config-file']}" );
                }
            }
            else
            {
                $usercfgfile = self::getOptionsDir() . "/options-user.yaml";
            }

            // command-line config options
            foreach( $cliopts as $opt => $val )
            {
                if ( substr( $opt, 0, 7 ) == 'option.')
                {
                    unset( $cliopts[$opt] );

                    // transform dotted notation in array structure
                    $work = array_reverse( explode( '.', substr( $opt, 7 ) ) );
                    $built = array( array_shift( $work ) => $val );
                    foreach( $work as $key )
                    {
                        $built = array( $key=> $built );
                    }
                    self::recursivemerge( $cliopts, $built );
                }
            }

            self::loadConfiguration( $cfgfile, $extname, $usercfgfile, $cliopts );
        }

        return self::$options[$extname];
    }

    /// @bug this only works as long as all defaults are 2 levels deep
    static protected function loadConfiguration ( $infile='', $extname='', $useroptsfile='', $overrideoptions=array() )
    {
        if ( $infile == '' )
        {
            $infile = self::getOptionsDir() . '/options' . ( $extname != '' ? "-$extname" : '' ) . '.yaml';
        }
        $mandatory_opts = array( /*'extension' => array( 'name' ),*/ 'version' => array( 'major', 'minor', 'release' ) );
        $default_opts = array(
            'build' => array( 'dir' => 'build' ),
            'dist' => array( 'dir' => 'dist' ),
            'report' => array( 'dir' => 'dist/report' ),
            'create' => array( 'tarball' => false, 'zip' => false, 'filelist_md5' => true, 'doxygen_doc' => false, 'ezpackage' => false, 'pearpackage' => false ),
            'version' => array( 'license' => 'GNU General Public License v2.0' ),
            'releasenr' => array( 'separator' => '-' ),
            'files' => array( 'to_parse' => array(), 'to_exclude' => array(), 'gnu_dir' => '', 'sql_files' => array( 'db_schema' => 'schema.sql', 'db_data' => 'cleandata.sql' ) ),
            'dependencies' => array( 'extensions' => array() ) );

        // load main config file
        /// @todo !important: test if !file_exists give a nicer warning than what we get from loadFile()
        $options = pakeYaml::loadFile( $infile );

        // merge data from local config file
        if ( $useroptsfile != '' && file_exists( $useroptsfile ) )
        {
            $useroptions = pakeYaml::loadFile( $useroptsfile );
            //var_dump( $useroptions );
            self::recursivemerge( $options, $useroptions );
        }

        // merge options from cli
        if ( count( $overrideoptions ) )
        {
            //var_dump( $overrideoptions );
            self::recursivemerge( $options, $overrideoptions );
        }

        // check if anything mandatory is missing
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

        // hardcoded overrides
        if ( !isset( $options['extension']['name'] ) || $options['extension']['name'] == '' )
        {
            $options['extension']['name'] = $extname;
        }
        if ( !isset( $options['version']['alias'] ) || $options['version']['alias'] == '' )
        {
            $options['version']['alias'] = $options['version']['major'] . '.' . $options['version']['minor'];
        }

        // merge default values
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
    static function convertPropertyFileToYamlFile( $infile, $outfile='', $transform = array(), $prepend='' )
    {
        if ( $outfile == '' )
        {
            $outfile = self::getOptionsDir() . '/options.yaml';
        }
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
     * Creates an archive out of a directory.
     *
     * Uses command-lne tar as Zeta Cmponents do no compress well, and pake
     * relies on phar which is buggy/unstable on old php versions
     *
     * @param boolean $no_top_dir when set, $sourcedir directory is not packaged as top-level dir in archive
     * @todo for tar formats, fix the extra "." dir packaged
     */
    static function archiveDir( $sourcedir, $archivefile, $no_top_dir=false )
    {
        // please tar cmd on win - OH MY!

        $archivefile = str_replace( '\\', '/', $archivefile );
        $sourcedir = str_replace( '\\', '/', realpath( $sourcedir ) );

        if( $no_top_dir )
        {
            $srcdir = '.';
            $workdir = $sourcedir;
        }
        else
        {
            $srcdir = basename( $sourcedir );
            $workdir = dirname( $sourcedir );
        }
        $archivedir = dirname( $archivefile );
        $extra = '';

        $tar = self::getTool( 'tar' );

        if ( substr( $archivefile, -7 ) == '.tar.gz' || substr( $archivefile, -4 ) == '.tgz' )
        {
            $cmd = "$tar -z -cvf";
            $extra = "-C " . escapeshellarg( $workdir );
            $workdir = $archivedir;
            $archivefile = basename( $archivefile );
        }
        else if ( substr( $archivefile, -8 ) == '.tar.bz2' )
        {
            $cmd = "$tar -j -cvf";
            $extra = "-C " . escapeshellarg( $workdir );
            $workdir = $archivedir;
            $archivefile = basename( $archivefile );
        }
        else if ( substr( $archivefile, -4 ) == '.tar' )
        {
            $cmd = "$tar -cvf";
            $extra = "-C " . escapeshellarg( $workdir );
            $workdir = $archivedir;
            $archivefile = basename( $archivefile );
        }
        else if ( substr( $archivefile, -4 ) == '.zip' )
        {
            $zip = self::getTool( 'zip' );
            $cmd = "$zip -9 -r";
        }
        else
        {
            throw new pakeException( "Can not determine archive type from filename: $archivefile" );
        }

        pake_sh( self::getCdCmd( $workdir ) . " && $cmd $archivefile $extra $srcdir" );

        pake_echo_action( 'file+', $archivefile );
    }

    /**
     * Find a cli executable, looking first for configured binaries, then in $PATH and/or composer bin dir.
     * Takes proper care of adding windows suffixes to tool name when needed
     *
     * @param string $tool e.g. "git"
     * @param array $opts
     * @param bool $composerBinary when true, look in vendor/bin before $PATH
     * @return string
     */
    public static function getTool( $tool, $opts=false, $composerBinary=false )
    {
        // dirty workaround
        if ( $opts == false )
        {
            $opts = self::$options[self::$defaultExt];
        }
        if ( isset( $opts['tools'][$tool] ) && is_string( $opts['tools'][$tool] ) && $opts['tools'][$tool] != '' )
        {
            return escapeshellarg( $opts['tools'][$tool] );
        }
        else if ( isset( $opts['tools'][$tool] ) && is_array( $opts['tools'][$tool] )
            && isset( $opts['tools'][$tool]['binary'] ) && $opts['tools'][$tool]['binary'] != '' )
        {
            return escapeshellarg( $opts['tools'][$tool]['binary'] );
        }
        else
        {
            if ( $composerBinary )
            {
                $vendorDir = self::getVendorDir();
                if ( file_exists( $vendorDir . "/bin/$tool" ) )
                {
                    $file = realpath( $vendorDir . "/bin/$tool" );
                    if ( strtoupper( substr( PHP_OS, 0, 3) ) === 'WIN' )
                    {
                        $file .= '.bat';
                    }
                    return escapeshellarg( $file );
                }
            }
            return escapeshellarg( pake_which( $tool ) );
        }
    }

    /**
     * Make "cd" work for all cases, even on win
     */
    static function getCdCmd( $dir )
    {
        if ( $dir[1] == ':' )
        {
            return 'cd /D ' . escapeshellarg( $dir );
        }
        return 'cd ' . escapeshellarg( $dir );
    }

    static function recursivemerge( &$a, $b )
    {
        //$a will be result. $a will be edited. It's to avoid a lot of copying in recursion
        foreach( $b as $child => $value )
        {
            if( isset( $a[$child] ) )
            {
                if( is_array( $a[$child] ) && is_array( $value ) )
                {
                    //merge if they are both arrays
                    self::recursivemerge( $a[$child], $value );
                }
                else
                {
                    // replace otherwise
                    $a[$child] = $value;
                }
            }
            else
            {
                $a[$child] = $value; //add if not exists
            }
        }
    }

    // *** helper functions ***

    /**
     * Mimics ant pattern matching.
     *
     * @see http://ant.apache.org/manual/dirtasks.html#patterns
     * @todo in pake 1.6.3 and later this functionality is supported natively. To be removed
     * @todo more complete testing
     * @bug looking for " d i r / * * / " will return subdirs but not dir itself
     */
    static function pake_antpattern( $files, $rootdir )
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
                            $found = self::pake_antpattern( array( $newfile ), $newdir );
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
