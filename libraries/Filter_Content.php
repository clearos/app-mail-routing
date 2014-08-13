<?php

/**
 * Mail filter class based on Kolab.
 *
 * @category   apps
 * @package    mail-routing
 * @subpackage libraries
 * @author     Kolab http://www.kolab.org
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2012 ClearFoundation
 * @copyright  See Kolab AUTHORS 
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 2 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/mail_routing/
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
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\mail_routing;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

/* Load the basic filter definition */
require_once 'Filter.php';

define('RM_STATE_READING_HEADER', 1 );
define('RM_STATE_READING_FROM',   2 );
define('RM_STATE_READING_SUBJECT',3 );
define('RM_STATE_READING_SENDER', 4 );
define('RM_STATE_READING_BODY',   5 );

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Mail filter class based on Kolab.
 *
 * @category   apps
 * @package    mail-routing
 * @subpackage libraries
 * @author     Kolab http://www.kolab.org
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2012 ClearFoundation
 * @copyright  See Kolab AUTHORS 
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 2 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/mail_routing/
 */

class Filter_Content extends \Filter
{
    function __construct($transport = 'SMTP', $debug = false)
    {
        \Filter::Filter($transport, $debug);
    }
    
    function _parse($inh = STDIN)
    {
        $from = false;
        $subject = false;
        $rewrittenfrom = false;
        $state = RM_STATE_READING_HEADER;

        while (!feof($inh) && $state != RM_STATE_READING_BODY) {

            $buffer = fgets($inh, 8192);
            $line = rtrim($buffer, "\r\n");

            if ($line == '') {
                /* Done with headers */
                $state = RM_STATE_READING_BODY;
            } else {
                if ($line[0] != ' ' && $line[0] != "\t") {
                    $state = RM_STATE_READING_HEADER;
                }
                switch( $state ) {
                case RM_STATE_READING_HEADER:
                    if (preg_match('/^Sender: (.*)/i', $line, $regs)) {
                        $from = $regs[1];
                        $state = RM_STATE_READING_SENDER;
                    } else if (!$from && preg_match('/^From: (.*)/i', $line, $regs)) {
                        $from = $regs[1];
                        $state = RM_STATE_READING_FROM;
                    } else if (preg_match('/^Subject: (.*)/i', $line, $regs)) {
                        $subject = $regs[1];
                        $state = RM_STATE_READING_SUBJECT;
                    } else if (preg_match('/^Message-ID: (.*)/i', $line, $regs)) {
                        $this->_id = $regs[1];
                    }
                    break;
                case RM_STATE_READING_FROM:
                    $from .= $line;
                    break;
                case RM_STATE_READING_SENDER:
                    $from .= $line;
                    break;
                case RM_STATE_READING_SUBJECT:
                    $subject .= $line;
                    break;
                }
            }
            if (@fwrite($this->_tmpfh, $buffer) === false) {
                $msg = $php_errormsg;
                return \PEAR::raiseError(sprintf(_("Error: Could not write to %s: %s"),
                                                $this->_tmpfile, $msg),
                                        OUT_LOG | EX_IOERR);
            }
        }
        while (!feof($inh)) {
            $buffer = fread($inh, 8192);
            if (@fwrite($this->_tmpfh, $buffer) === false) {
                $msg = $php_errormsg;
                return \PEAR::raiseError(sprintf(_("Error: Could not write to %s: %s"),
                                                $this->_tmpfile, $msg),
                                        OUT_LOG | EX_IOERR);
            }
        }

        if (@fclose($this->_tmpfh) === false) {
            $msg = $php_errormsg;
            return \PEAR::raiseError(sprintf(_("Error: Failed closing %s: %s"),
                                            $this->_tmpfile, $msg),
                                    OUT_LOG | EX_IOERR);
        }

        if (file_exists('/var/clearos/mail_archive/enabled')) {
            $msg_filename = '/var/clearos/mail_archive/messages/' . date('Ymd_Hi', time()) . '_' . rand(0, 10000);
            if (!file_exists($msg_filename))
                copy($this->_tmpfile, $msg_filename);
        }

        $result = $this->deliver($rewrittenfrom);
        if ($result instanceof \PEAR_Error) {
            return $result;
        }
    }

    function deliver($rewrittenfrom)
    {
        global $conf;

        // Point Clark Networks -- start
        // Try amavis antispam/antivirus on port 10024.  Send to 10025 if ok.
        // Fallback to default 10026 (Postfix) if something goes wrong.

        require_once 'Net/SMTP.php';

        $host = '127.0.0.1';
        $port = 10026;

        set_error_handler('\clearos\apps\mail_routing\ignore_error');
        if ($smtptest = new \Net_SMTP('127.0.0.1', '10024')) {
            if (!(\PEAR::isError($e = $smtptest->connect()))) {
                 $port = 10025;
                 $smtptest->disconnect();
            }
        }
        restore_error_handler();

        // Point Clark Networks -- end

        $transport = $this->_getTransport($host, $port);

        $tmpf = @fopen($this->_tmpfile, 'r');
        if (!$tmpf) {
            $msg = $php_errormsg;
            return \PEAR::raiseError(sprintf(_("Error: Could not open %s for writing: %s"),
                                            $this->_tmpfile, $msg),
                                    OUT_LOG | EX_IOERR);
        }

        $result = $transport->start($this->_sender, $this->_recipients);
        if ($result instanceof \PEAR_Error) {
            return $this->_rewriteCode($result);
        }

        $state = RM_STATE_READING_HEADER;
        while (!feof($tmpf) && $state != RM_STATE_READING_BODY) {
            $buffer = fgets($tmpf, 8192);
            if ($rewrittenfrom) {
                if (preg_match('/^From: (.*)/', $buffer)) {
                    $result = $transport->data($rewrittenfrom);
                    if ($result instanceof \PEAR_Error) {
                        return $this->_rewriteCode($result);
                    }
                    $state = RM_STATE_READING_FROM;
                    continue;
                } else if ($state == RM_STATE_READING_FROM &&
                           ($buffer[0] == ' ' || $buffer[0] == "\t")) {
                    /* Folded From header, ignore */
                    continue;
                }
            }
            if (rtrim($buffer, "\r\n") == '') {
                $state = RM_STATE_READING_BODY;
            } else if ($buffer[0] != ' ' && $buffer[0] != "\t")  {
                $state = RM_STATE_READING_HEADER;
            }
            $result = $transport->data($buffer);
            if ($result instanceof \PEAR_Error) {
                return $this->_rewriteCode($result);
            }
        }
        while (!feof($tmpf)) {
            $buffer = fread($tmpf, 8192);
            $len = strlen($buffer);

            /* We can't tolerate that the buffer breaks the data
             * between \r and \n, so we try to avoid that. The limit
             * of 100 reads is to battle abuse
             */
            while ($buffer{$len-1} == "\r" && $len < 8192 + 100) {
                $buffer .= fread($tmpf,1);
                $len++;
            }
            $result = $transport->data($buffer);
            // TODO: this seem to generate false positives?  LMTP only?
            /*
            if ($result instanceof PEAR_Error) {
                return $this->_rewriteCode($result);
            }
            */
        }
        return $transport->end();
    }
}

/** Returns the format string used to rewrite
    the From header for untrusted messages */
function get_untrusted_subject_insert($sasluser,$sender)
{
    global $conf;

    if ($sasluser) {
        if (isset($conf['filter']['untrusted_subject_insert'])) {
            $fmt = $conf['filter']['untrusted_subject_insert'];
        } else {
            $fmt = _("(UNTRUSTED, sender is <%s>)");
        }
    } else {
        if (isset($conf['filter']['unauthenticated_subject_insert'])) {
            $fmt = $conf['filter']['unauthenticated_subject_insert'];
        } else {
            $fmt = _("(UNTRUSTED, sender <%s> is not authenticated)");
        }
    }
    return sprintf($fmt, $sender);
}

/** Match IP addresses against Networks in CIDR notation. **/ 
function match_ip($network, $ip)
{
    $iplong = ip2long($ip);
    $cidr = explode("/", $network);
    $netiplong = ip2long($cidr[0]);
    if ( count($cidr) == 2 ) {
        $iplong = $iplong & ( 0xffffffff << 32 - $cidr[1] );
        $netiplong = $netiplong & ( 0xffffffff << 32 - $cidr[1] );
    }
    if ($iplong == $netiplong) {
        return true;
    } 
    return false;
}

function ignore_error($one, $two) {};
