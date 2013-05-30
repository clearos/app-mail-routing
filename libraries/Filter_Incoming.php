<?php

/**
 * Mail pre-delivery filter.
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

// Point Clark Networks -- start

define( 'PCN_FAIL', 1 );
define( 'PCN_USER_EXISTS', 2 );
define( 'PCN_NO_SUCH_USER', 3 );

function verify_recipient($recipient)
{
    global $conf;

    $ldap = ldap_connect($conf['filter']['ldap_uri']);

    if (!ldap_bind($ldap, $conf['filter']['bind_dn'], $conf['filter']['bind_pw'])) {
        clearos_log("mailfilter", sprintf(_("Unable to contact LDAP server: %s"), ldap_error($ldap)));
        return PCN_FAIL;
    }

    // Strip out special Dspam addresses spam-recipient and notspam-recipient
    $recipient = preg_replace("/^(notspam|spam)-/", "", $recipient);
    $user = preg_replace("/@.*/", "", $recipient);

    $filter = "(&(objectClass=pcnMailAccount)(uid=$user)(pcnMailFlag=TRUE))";
    $result = ldap_search($ldap, $conf['filter']['base_dn'], $filter, array("dn", "mail"));

    if (!$result) {
        clearos_log("mailfilter", sprintf(_("Unable to perform LDAP search: %s"), ldap_error($ldap)));
        return PCN_FAIL;
    }

    $entries = ldap_get_entries($ldap, $result);
    if ($entries['count'] == 0) {
        return PCN_NO_SUCH_USER;
    } else {
        return PCN_USER_EXISTS;
    }
}

// Point Clark Networks -- end

class Filter_Incoming extends \Filter
{
    var $_add_headers;

    function __construct($transport = 'LMTP', $debug = false)
    {
        \Filter::Filter($transport, $debug);
    }

    function _parse($inh = STDIN)
    {
        $add_headers = array();
        $headers_done = false;

        while (!feof($inh)) {
            $buffer = fgets($inh, 8192);
            $line = rtrim( $buffer, "\r\n");
            if ($line == '') {
                /* Done with headers */
                $headers_done = true;
            } else if (preg_match('/^Message-ID: (.*)/i', $line, $regs)) {
                $this->_id = $regs[1];
            }
            if (@fwrite($this->_tmpfh, $buffer) === false) {
                $msg = $php_errormsg;
                return \PEAR::raiseError(sprintf(_("Error: Could not write to %s: %s"),
                                                $this->_tmpfile, $msg),
                                        OUT_LOG | EX_TEMPFAIL);
            }
        }

        if (@fclose($this->_tmpfh) === false) {
            $msg = $php_errormsg;
            return \PEAR::raiseError(sprintf(_("Error: Failed closing %s: %s"),
                                            $this->_tmpfile, $msg),
                                    OUT_LOG | EX_TEMPFAIL);
        }

        if (file_exists('/var/clearos/mail_archive/enabled')) {
            $msg_filename = '/var/clearos/mail_archive/messages/' . preg_replace(array('/^</', '/>$/'), array('', ''), $this->_id);
            if (!file_exists($msg_filename))
                copy($this->_tmpfile, $msg_filename);
            // We'll use this custom header to populate delivered-to address in database metadata
            $delivered_to = implode(',', $this->_recipients);
            exec("/bin/sed -i -e 's/^\(Message-Id.*\)$/X-Clear-Delivered-To-" . rand(0, 1000) . ": $delivered_to \\n\\1/i' $msg_filename");
        }

        /* Check if we still have recipients */
        if (empty($this->_recipients)) {
            clearos_log("mailfilter", "No recipients left.");
            return;
        } else {
            $result = $this->deliver();
            if ($result instanceof \PEAR_Error) {
                return $result;
            }
        }
        
        clearos_log("mailfilter", "filter successfully completed.");
    }

    function deliver()
    {
        global $conf;

        /* Route mail to where it needs to go.  */

        $host = "localhost";
        $port = 2003;

        /*
        $pcnverify = verify_recipient($this->_recipients[0]);

        if ($pcnverify == PCN_USER_EXISTS) {
            if (isset($conf['spam_quarantine_mailbox']) && !empty($conf['spam_quarantine_mailbox'])) {
                clearos_log("mailfilter", "Directing message to spam quarantine.");
                $this->_recipients[0] = $conf['spam_quarantine_mailbox'];
                $port = 2003;
            } else {
                clearos_log("mailfilter", "Directing message to mail delivery.");
                $port = 2003;
            }
        } else if ($pcnverify == PCN_NO_SUCH_USER) {
            if (isset($conf['catch_all_mailbox']) && !empty($conf['catch_all_mailbox'])) {
                clearos_log("mailfilter", "Redirecting message to catch-all mailbox");
                $port = 2003;
                $this->_recipients[0] = $conf['catch_all_mailbox'];
            } else {
                clearos_log("mailfilter", sprintf(_("Bouncing message to %s"), $this->_recipients[0]));
                return PEAR::raiseError(sprintf(_("Fatal: 550- Mailbox does not exist")), OUT_LOG | EX_NOUSER);
            }
        } else {
            clearos_log("mailfilter", "Directing unverified message to mail delivery");
            $port = 2003;
        }
        */

        // Point Clark Networks -- end

        $transport = $this->_getTransport($host, $port);

        $tmpf = @fopen($this->_tmpfile, 'r');
        if (!$tmpf) {
            $msg = $php_errormsg;
            return \PEAR::raiseError(sprintf(_("Error: Could not open %s for writing: %s"),
                                            $this->_tmpfile, $msg),
                                    OUT_LOG | EX_TEMPFAIL);
        }

        $result = $transport->start($this->_sender, $this->_recipients);
        if ($result instanceof \PEAR_Error) {
            return $this->_rewriteCode($result);
        }
        
        $headers_done = false;
        while (!feof($tmpf) && !$headers_done) {
            $buffer = fgets($tmpf, 8192);
            if (!$headers_done && rtrim($buffer, "\r\n") == '') {
                $headers_done = true;
                if (! is_array($this->_add_headers))
                    $this->_add_headers = array($this->_add_headers);
                foreach ($this->_add_headers as $h) {
                    $result = $transport->data("$h\r\n");
                    if ($result instanceof \PEAR_Error) {
                        return $this->_rewriteCode($result);
                    }
                }
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
            if ($len > 0) {
                while ($buffer{$len-1} == "\r" && $len < 8192 + 100) {
                    $buffer .= fread($tmpf, 1);
                    $len++;
                }
            }
            $result = $transport->data($buffer);
            if ($result instanceof \PEAR_Error) {
                return $this->_rewriteCode($result);
            }
        }
        return $transport->end();
    }
}
