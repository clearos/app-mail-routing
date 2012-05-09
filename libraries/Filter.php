<?php

/**
 * Mail filter class based on Kolab.
 *
 * @category   Apps
 * @package    SMTP
 * @subpackage Libraries
 * @author     Kolab http://www.kolab.org
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2012 ClearFoundation
 * @copyright  See Kolab AUTHORS
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 2 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/smtp/
 */

///////////////////////////////////////////////////////////////////////////////
//
//  This program is free software; you can redistribute it and/or modify
//  it under the terms of the GNU General Public License as published by
//  the Free Software Foundation; either version 2 of the License, or
//  (at your option) any later version.
//
//  This program is distributed in the hope that it will be useful,
//  but WITHOUT ANY WARRANTY; without even the implied warranty of
//  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//  GNU General Public License for more details.
//
//  You should have received a copy of the GNU General Public License
//  along with this program; if not, write to the Free Software
//  Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
//
///////////////////////////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('smtp');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

/* Load the required PEAR libraries */
require_once 'PEAR.php';

/* Load the Filter libraries */
require_once 'Transport.php';

/* Some output constants */
define('OUT_STDOUT', 128);
define('OUT_LOG', 256);

/* Failure constants from postfix src/global/sys_exits.h */
define('EX_USAGE', 64);       /* command line usage error */
define('EX_DATAERR', 65);     /* data format error */
define('EX_NOINPUT', 66);     /* cannot open input */
define('EX_NOUSER', 67);      /* user unknown */
define('EX_NOHOST', 68);      /* host name unknown */
define('EX_UNAVAILABLE', 69); /* service unavailable */
define('EX_SOFTWARE', 70);    /* internal software error */
define('EX_OSERR', 71);       /* system resource error */
define('EX_OSFILE', 72);      /* critical OS file missing */
define('EX_CANTCREAT', 73);   /* can't create user output file */
define('EX_IOERR', 74);       /* input/output error */
define('EX_TEMPFAIL', 75);    /* temporary failure */
define('EX_PROTOCOL', 76);    /* remote error in protocol */
define('EX_NOPERM', 77);      /* permission denied */
define('EX_CONFIG', 78);      /* local configuration error */

class Filter 
{
    var $_transport;

    var $_startts;

    var $_id = '';

    var $_debug;

    var $_tmpdir;
    
    var $_tmpfile;
    var $_tmpfh;

    var $_sender;
    var $_recipients;
    var $_client_address;
    var $_fqhostname;
    var $_sasl_username;

    function Filter($transport = 'StdOut', $debug = false)
    {
        global $conf;

        /* Always display all possible problems */
        ini_set('error_reporting', E_ALL);
        ini_set('track_errors', '1');

        /* Setup error logging */
        if (isset($conf['filter']['error_log'])) {
            ini_set('log_errors', '1');
            ini_set('error_log', $conf['filter']['error_log']);
        }

        /* Print PHP messages to StdOut if we are debugging */
        if ($debug) {
            ini_set('display_errors', '1');
        }

        $this->_transport = $transport;
        $this->_debug = $debug;

        $this->_startts = $this->_microtime_float();
	    $this->_tmpdir = sys_get_temp_dir();

        /* Set a custom PHP error handler to catch any coding errors */
        set_error_handler(array($this, '_fatal'));
    }

    function parse($inh = STDIN)
    {
        $result = $this->_start();
        if ($result instanceof PEAR_Error) {
            $this->_handle($result);
        }

        $result = $this->_parse($inh);
        if ($result instanceof PEAR_Error) {
            $this->_handle($result);
        }

        clearos_log("mailfilter", sprintf(_("successfully completed (sender=%s, recipients=%s, client_address=%s, id=%s)"), 
                                  $this->_sender, 
                                  join(', ',$this->_recipients), 
                                  $this->_client_address, $this->_id));
    }
    
    function _start()
    {
        /* Setup the temporary storage */
        $result = $this->_initTmp();
        if ($result instanceof PEAR_Error) {
            return $result;
        }
        
        /* Parse our arguments */
        $result = $this->_parseArgs();
        if ($result instanceof PEAR_Error) {
            return $result;
        }

        clearos_log("mailfilter", sprintf(_("starting up (sender=%s, recipients=%s, client_address=%s)"), 
                                  $this->_sender, 
                                  join(', ',$this->_recipients), 
                                  $this->_client_address));
    }
    
    function _stop()
    {
        return $this->_microtime_float() - $this->_startts;
    }
    
    function _parseArgs()
    {
        $args = $_SERVER['argv'];
        $opts = array( 's', 'r', 'c', 'h', 'u' );

        // clearos_log("mailfilter", sprintf(_("Arguments: %s"), print_r($args, true)));

        $options = array();
        for ($i = 0; $i < count($args); ++$i) {
            $arg = $args[$i];
            if (!empty($arg) && $arg[0] == '-' && isset($arg[1])) {
                if (in_array($arg[1], $opts)) {
                    $val = array();
                    $i++;
                    while($i < count($args) && !empty($args[$i]) && 
                          $args[$i][0] != '-') {
                        $val[] = $args[$i];
                        $i++;
                    }
                    $i--;
                    if (array_key_exists($arg[1], $options) &&
                        is_array($options[$arg[1]])) {
                        $options[$arg[1]] = array_merge(
                            (array)$options[$arg[1]],
                            (array)$val
                        );
                    } else if (count($val) == 1) {
                        $options[$arg[1]] = $val[0];
                    } else {
                        $options[$arg[1]] = $val;
                    }
                }
            }
        }

        if (!array_key_exists('r', $options) ||
            !array_key_exists('s', $options)) {
            return PEAR::raiseError(sprintf(_("Usage is %s -s sender@domain -r recipient@domain"),
                                             $args[0]),
                                     OUT_STDOUT | EX_USAGE);
        }

        if (empty($options['s'])) {
            $sender = '';
        } else if (is_array($options['s'])) {
            $sender = $options['s'][0];
        } else {
            $sender = $options['s'];
        }

        $this->_sender = strtolower($sender);

        $recipients = $options['r'];

        /* make sure recipients is an array */
        if (!is_array($recipients)) {
            $recipients = array($recipients);
        }

        /* make recipients lowercase */
        for ($i = 0; $i < count($recipients); $i++) {
            $recipients[$i] = strtolower($recipients[$i]);
        }
        $this->_recipients = $recipients;

        if (isset($options['c']) && !empty($options['c'])) {
            $this->_client_address = $options['c'];
        }
        
        if (isset($options['h']) && !empty($options['h'])) {
            $this->_fqhostname = strtolower($options['h']);
        }
        
        if (isset($options['u']) && !empty($options['u'])) {
            $this->_sasl_username = strtolower($options['u']);
        }
    }
    
    function _initTmp()
    {
        /* Temp file for storing the message */
        $this->_tmpfile = @tempnam($this->_tmpdir, 'mailfilter' . preg_replace('/.*\\//', '', get_class($this)) . '.');
        $this->_tmpfh = @fopen($this->_tmpfile, "w");
        if( !$this->_tmpfh ) {
            $msg = $php_errormsg;
            return PEAR::raiseError(sprintf(_("Error: Could not open %s for writing: %s"),
                                            $this->_tmpfile, $msg),
                                    OUT_LOG | EX_IOERR);
        }

        register_shutdown_function(array($this, '_cleanupTmp'));
    }
    
    function _cleanupTmp() {
        if (@file_exists($this->_tmpfile)) {
            @unlink($this->_tmpfile);
        }
    }

    function _microtime_float() 
    {
        list($usec, $sec) = explode(" ", microtime());
        return (float) $usec + (float) $sec;
    }

    function &_getTransport($host, $port)
    {
        $class = 'Transport_' . $this->_transport;
        if (class_exists($class)) {
            $transport = new $class($host, $port);
            return $transport;
        }
        return PEAR::raiseError(sprintf(_("No such class \"%s\""), $class),
                                OUT_LOG | EX_CONFIG);
    }

    function _rewriteCode($result) 
    {
        if ($result->getCode() < 500) {
            $code = EX_TEMPFAIL;
        } else {
            $code = EX_UNAVAILABLE;
        }
        $append = sprintf(_(", original code %s"), $result->getCode());
        $result->message = $result->getMessage() . $append;
        $result->code = OUT_LOG | OUT_STDOUT | $code;
        return $result;
    }

    function _fatal($errno, $errmsg, $filename, $linenum, $vars)
    {
        /* Ignore strict errors for now since even PEAR will raise
         * strict notices 
         */
        if ($errno == E_STRICT) {
            return false;
        }

        $fatal = array(E_ERROR,
                       E_PARSE,
                       E_CORE_ERROR,
                       E_COMPILE_ERROR,
                       E_USER_ERROR);

        if (in_array($errno, $fatal)) {
            $code = OUT_STDOUT | OUT_LOG | EX_UNAVAILABLE;
            $msg = 'CRITICAL: You hit a fatal bug in the mail filter: ' . $errmsg;
        } else {
            $code = 0;
            $msg = 'PHP Error: ' . $errmsg;
        }

        $error = new \PEAR_Error($msg, $code);
        $this->_handle($error);

        return false;
    }

    function _log($result)
    {
        if (!empty($this->_id)) {
            $id = ' <ID: ' . $this->_id . '>';
        } else {
            $id = '';
        }
            
        $msg = $result->getMessage() . $id;

        /* Log all errors */
        $file = __FILE__;
        $line = __LINE__;
        
        $frames = $result->getBacktrace();
        if (count($frames) > 1) {
            $frame = $frames[1];
        } else if (count($frames) == 1) {
            $frame = $frames[0];
        }
        if (isset($frame['file'])) {
            $file = $frame['file'];
        }
        if (isset($frame['line'])) {
            $line = $frame['line'];
        }

        if (!$this->_debug) {
            clearos_log("mailfilter", "$msg, $file, $line");
        } else {
            $msg .= ' (Line ' . $frame['line'] . ' in ' . basename($frame['file']) . ")\n";
            clearos_log("mailfilter", $msg);
        }
    }
    
    function _handle($result)
    {
        $msg = $result->getMessage();
        $code = $result->getCode();

        if ($code & OUT_STDOUT) {
            fwrite(STDOUT, $msg);
        }

        if ($code & OUT_LOG || empty($code)) {
            $this->_log($result);
        }
        
        // FIXME: Add a userinfo handler in case there were multiple
        // combined errors

        /* If we have an error code we want to return it to the
         * calling application and exit here
         */
        if ($code) {
            /* Return the first seven bits as error code to postfix */
            exit($code & 127);
        }
    }
}
