<?php
/*  
 *  COPYRIGHT
 *  ---------
 *
 *  See Kolab AUTHORS file @ http://www.kolab.org
 *  ClearFoundation 2012
 *
 *
 *  LICENSE
 *  -------
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 *  $Revision: 1.2 $
 */

class Transport 
{
    var $host;
    var $port;
    var $transport;
    var $got_newline;

    function Transport($host = '127.0.0.1', $port = 2003)
    {
        $this->host = $host;
        $this->port = $port;
        $this->transport = false;
    }

    function &createTransport() { 
        return PEAR::raiseError(_("Abstract method Transport::createTransport() called!"));
    }

    function start($sender, $recips)
    {
        $transport = $this->createTransport();
        if ($transport instanceof PEAR_Error) {
            return $transport;
        }
        $this->transport = $transport;
        
        $myclass = get_class($this->transport);
        $this->got_newline = true;

        $result = $this->transport->connect();
        if ($result instanceof PEAR_Error) {
            return $result;
        }
        
        $result = $this->transport->mailFrom($sender);
        if ($result instanceof PEAR_Error) {
            $resp = $this->transport->getResponse();
            return PEAR::raiseError(sprintf(_("Failed to set sender: %s, code=%s"),
                                            $resp[1], $resp[0]), $resp[0]);
        }
    
        if (!is_array($recips)) {
            $recips = array($recips);
        }

        $reciperrors = array();
        foreach ($recips as $recip) {
            $result = $this->transport->rcptTo($recip);
            if ($result instanceof PEAR_Error) {
                $resp = $this->transport->getResponse();
                $reciperrors[] = PEAR::raiseError(sprintf(_("Failed to set recipient: %s, code=%s"),
                                                          $resp[1], $resp[0]), $resp[0]);
            }
        }

        if (count($reciperrors) == count($recips)) {
            /* OK, all failed, just give up */
            if (count($reciperrors) == 1) {
                /* Only one failure, just return that */
                return $reciperrors[0];
            }
            /* Multiple errors */
            return $this->createErrorObject($reciperrors,
                                            _("Delivery to all recipients failed!"));
        }

        $result = $this->transport->_put('DATA');
        if ($result instanceof PEAR_Error) {
            return $result;
        }

        $result = $this->transport->_parseResponse(354);
        if ($result instanceof PEAR_Error) {
            return $result;
        }
        
        if (!empty($reciperrors)) {
            return $this->createErrorObject($reciperrors,
                                            _("Delivery to some recipients failed!"));
        }
        return true;
    }

    // Encapsulate multiple errors in one
    function createErrorObject($reciperrors, $msg = null)
    {
        /* Return the lowest errorcode to not bounce more
         * than we have to
         */
        if ($msg == null) {
            $msg = 'Delivery to recipients failed.';
        }
        
        $code = 1000;

        foreach ($reciperrors as $err) {
            if ($err->code < $code) {
                $code = $err->code;
            }
        }
        return new PEAR_Error($msg, $code, null, null, $reciperrors);  
    }

    /* Modified implementation from Net_SMTP that supports
     * dotstuffing even when getting the mail line-by line */
    function quotedataline(&$data)
    {
        /*
         * Change Unix (\n) and Mac (\r) linefeeds into Internet-standard CRLF
         * (\r\n) linefeeds.
         */
        $data = preg_replace(array('/(?<!\r)\n/','/\r(?!\n)/'), "\r\n", $data);

        /*
         * Because a single leading period (.) signifies an end to the data,
         * legitimate leading periods need to be "doubled" (e.g. '..').
         */
        if ($this->got_newline && !empty($data) && $data[0] == '.') {
            $data = '.'.$data;
        }
        
        $data = str_replace("\n.", "\n..", $data);
        $len = strlen($data);
        if ($len > 0) {
            $this->got_newline = ( $data[$len-1] == "\n" );
        }
    }

    function data($data) {
        $this->quotedataline($data);
        $result = $this->transport->_send($data);
        if ($result instanceof PEAR_Error) {
            return $result;
        }
        return true;
    }

    function end() 
    {
        if ($this->got_newline) {          
            $dot = ".\r\n";
        } else {
            $dot = "\r\n.\r\n";
        }
        
        $result = $this->transport->_send($dot);
        if ($result instanceof PEAR_Error) {
            return $result;
        }
        $result = $this->transport->_parseResponse(250);
        if ($result instanceof PEAR_Error) {
            return $result;
        }
        $this->transport->disconnect();
        $this->transport = false;
        return true;
    }
}

class Transport_LMTP extends Transport 
{
    function Transport_LMTP($host = '127.0.0.1', $port = 2003)
    {
        $this->Transport($host,$port);
    }

    function &createTransport() 
    {
        require_once 'Net/LMTP.php';
        $transport = new Net_LMTP($this->host, $this->port);
        return $transport;
    }

}

class Transport_SMTP extends Transport
{

    function Transport_SMTP($host = '127.0.0.1', $port = 25)
    {
        $this->Transport($host,$port);
    }

    function &createTransport()
    {
        require_once 'Net/SMTP.php';
        $transport = new Net_SMTP($this->host, $this->port);
        return $transport;
    }
}

class StdOutWrapper
{
    function connect()
    {
        return true;
    }

    function disconnect()
    {
        return true;
    }

    function mailFrom($sender)
    {
        return fwrite(STDOUT, sprintf(_("Mail from sender: %s\n"), $sender));
    }

    function rcptTo($recipient)
    {
        return fwrite(STDOUT, sprintf(_("Mail to recipient: %s\n"), $recipient));
    }

    function _put($cmd)
    {
        return true;
    }

    function _parseResponse($code)
    {
        return true;
    }

    function _send($data)
    {
        return fwrite(STDOUT, $data);
    }
}

class DropWrapper extends StdOutWrapper
{
    function mailFrom($sender)
    {
        return true;
    }

    function rcptTo($recipient)
    {
        return true;
    }

    function _send($data)
    {
        return true;
    }
}

class Transport_StdOut extends Transport
{

    function Transport_SMTP($host = 'irrelevant', $port = 0)
    {
    }

    function &createTransport()
    {
        $transport = new StdOutWrapper();
        return $transport;
    }
}

class Transport_Drop extends Transport
{

    function Transport_SMTP($host = 'irrelevant', $port = 0)
    {
    }

    function &createTransport()
    {
        $transport = new DropWrapper();
        return $transport;
    }
}

?>
