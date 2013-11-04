<?php
/**
 * Implement a read-write-lock system, so that tasks can be executed without stepping on each other toes
 * (see http://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock)
 *
 * We avoid usage of php flock() call, as when on nfs it becomes unreliable.
 *
 * Known limitations:
 * - very simple algorithm - does not prevent writer starvation
 * - acquire() is all but atomic; we should do some stress testing to see how we fare
 * - killing processes and bad coding will leave lock files on disk. This is why we have a cleanup() function
 *
 * @author    G. Giunta
 * @copyright (C) G. Giunta 2013
 * @license   code licensed under the GNU GPL 2.0: see README file
 */

namespace eZExtBuilder;

class SharedLock
{

    static $cleanedUp = false;

    /**
     * Returns true if the given task for the given extension has not already been locked
     * If no task name is passed, the lock will block any task for this extension (iff the 2nd task checks for locks, of course).
     *
     * @param string $token
     * @param int $mode LOCK_SH (reader) or LOCK_EX (writer)
     * @param array $opts
     * @param bool $autoCleanup when true, on first lock acquired we remove any stale locks found
     * @return bool
     */
    static public function acquire( $token, $mode, $opts=array(), $autoCleanup=true /*, $task=''*/ )
    {
        // just in case (is_file results might be cached!)...
        clearstatcache();

        if ( $autoCleanup && !self::$cleanedUp )
        {
            self::cleanup( $opts );
            self::$cleanedUp = true;
        }

        $lockDir = self::lockDir( $opts );
        $wLockFile = "$lockDir/{$token}_W.lock";

        if ( file_exists( $wLockFile ) )
        {
            return false;
        }

        if ( $mode == LOCK_EX && count( glob( $lockDir . "/{$token}_R/*.lock" ) ) )
        {
            return false;
        }

        if ( $mode == LOCK_EX )
        {
            pake_mkdirs( $lockDir );
            if ( !file_put_contents( $wLockFile, getmypid() /*. ' ' . $task*/, LOCK_EX ) )
            {
                pake_echo_error( "Could not create W lock file '$wLockFile'" );
                return false;
            }
            return true;
        }

        // assume a read lock
        $rLockFile = "$lockDir/{$token}_R/" . getmypid() . ".lock";
        pake_mkdirs( "$lockDir/{$token}_R/" );
        if ( !file_put_contents( $rLockFile, getmypid() /*. ' ' . $task*/ ) )
        {
            // log some error?
            pake_echo_error( "Could not create R lock file '$wLockFile'" );
            return false;
        }
        return true;
    }

    /**
     * Releases the token. Does not warn if lock has disappeared before the release.
     * @param string $token
     * @param int $mode
     * @param array $opts
     */
    static function release( $token, $mode, $opts=array() )
    {
        // just in case (is_file results might be cached!)...
        clearstatcache();

        $lockDir = self::lockDir( $opts );
        if ( $mode == LOCK_EX )
        {
            $wLockFile = "$lockDir/{$token}_W.lock";
            if ( is_file(  $wLockFile ) && !unlink( $wLockFile ) )
            {
                // what to do here? we echo an error msg but do not throw an exception
                pake_echo_error( "Could not remove W lock file '$wLockFile'" );
            }
            return;
        }
        // assume a read lock
        $rLockFile = "$lockDir/{$token}_R/" . getmypid() . ".lock";
        if ( is_file(  $rLockFile ) && !unlink( $rLockFile ) )
        {
            // what to do here? we echo an error msg but do not throw an exception
            pake_echo_error( "Could not remove R lock file '$rLockFile'" );
        }
    }

    /**
     * Removes orphaned lock files - by checking their PIDs against running processes
     * @param array $opts
     */
    static public function cleanup( $opts=array() )
    {
        if ( strtoupper( substr( PHP_OS, 0, 3 ) ) == 'WIN' )
        {
            exec( 'tasklist /FO CSV', $runningProcesses, $return_var );
            $runningProcesses = array_map(
                function( $line ){ $cols = explode( ',', $line ); return trim( $cols[1], '"' ); },
                $runningProcesses );
        }
        else
        {
            exec( 'ps -e -o pid', $runningProcesses, $return_var );
        }
        if ( $return_var != 0 )
        {
            pake_echo_error( "Could not get list of processes to remove stale lock files" );
            return;
        }

        $lockDir = self::lockDir( $opts );
        foreach( glob( $lockDir . "/*_W.lock" ) as $writeLock )
        {
            $pid = strstr( file_get_contents( $writeLock ), ' ', true );
            if ( !in_array( $pid, $runningProcesses ) )
            {
                pake_unlink( $writeLock );
            }
        }
        foreach( glob( $lockDir . "/*_R/*.lock" ) as $readLock )
        {
            $pid = strstr( file_get_contents( $readLock ), ' ', true );
            if ( !in_array( $pid, $runningProcesses ) )
            {
                pake_unlink( $readLock );
            }
        }
    }

    static protected function lockDir( $opts=array() )
    {
        $build = Builder::getBuildDir( $opts );
        return $build == '' ? './locks' : "$build/locks";
    }

} 