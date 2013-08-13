<?php

/**
 * @author CaTz
 * @link https://github.com/CaTzil/emailParser
 */

class emailParser
{

    /**
     *
     * @var Boolean
     */
    private $isImapExtensionAvailable = false;

    /**
     *
     * @var String
     */
    private $emailRawContent;

    /**
     *
     * @var Associative Array
     */
    protected $headers;

    /**
     *
     * @var String of the whole headers part
     */
    protected $rawHeaders;
    /**
     *
     * @var String of all the mail without the headers
     */
    protected $rawBodyParts;
    /**
     * 
     * @var String of the email initial boundry 
     */
    protected $boundry;

    /**
     * 
     * @var String charset of the email from the headers
     */
    protected $charset;

    /**
     * 
     * @var Associative Array with text and html part of the email
     */
    protected $bodies;

    /**
     * 
     * @var Associative Array with attachments of the email
     */
    protected $files;


    public function __construct($emailRawContent)
    {
        $this->emailRawContent = $emailRawContent;

        $this->extractHeadersAndRawBody();

        $this->parseHeaders();
		
        $this->parseContent();


        if (function_exists('imap_open'))
        {
            $this->isImapExtensionAvailable = true;
        }
    }

    protected function extractHeadersAndRawBody()
    {
        $splitted_content = preg_split("/(\r?\n\r?\n)/", $this->emailRawContent);
        $this->rawHeaders = $splitted_content[0];
        $this->rawBodyParts = implode("\n\n", array_slice($splitted_content, 1));
    }

    protected function parseHeaders()
    {
        $this->boundry = null;
        $this->charset = 'utf-8';

        $lines = preg_split("/\r?\n|\r/", $this->rawHeaders);
        $currentHeader = '';

        foreach ($lines as $line)
        {
            if (preg_match('/([^:]+): ?(.*)$/', $line, $matches))
            {
                $newHeader = strtolower(trim($matches[1]));
                $value = trim(stripslashes($matches[2]));
                if(!isset($this->headers[$newHeader]))
                	$this->headers[$newHeader] = ($currentHeader == $newHeader) ? $this->headers[$newHeader] . $value : $value;
               	else
               		$this->headers[$newHeader] .= ' '.(($currentHeader == $newHeader) ? $this->headers[$newHeader] . $value : $value);
               		
                $currentHeader = $newHeader;
            }
            else
                if ($currentHeader)
                { //concate to the current
                    $this->headers[$currentHeader] .= ' ' . trim($line);
                }
        }
        
        //check and extract the boundry if exists
        if (isset($this->headers["content-type"]))
        {
            $this->boundry = $this->getBoundry($this->headers["content-type"]);
        }

        //check and extract the charset if exists
        if (isset($this->headers["content-type"]) && preg_match("/charset=(.*)/i", $this->headers["content-type"], $matches))
        {
            $this->charset = strtolower(trim($matches[1], '"\' '));
        }
    }

    protected function parseContent()
    {
        // Not modern mail
        if ($this->boundry == null)
        {
            $body = $this->rawBodyParts;

            $body = $this->decodeContent($body, $this->headers['content-transfer-encoding'], $this->charset);

            $this->bodies['plain'] = strip_tags($body);
            $this->bodies['html'] = $body;
        }
        else
        {
            $this->parseByBoundry($this->rawBodyParts, $this->boundry);
        }
    }


    protected function parseByBoundry($body, $boundry)
    {
        $count = substr_count($body, $boundry);

        //seperate the body by the boundry
        $parts = array();
        for ($i = 0, $start = 0; $i < $count - 1; $i++)
        {
            $start = strpos($body, $boundry, $start) + strlen($boundry);
            $end = strpos($body, $boundry, $start);
            $parts[] = substr($body, $start, $end - $start);
        }

        if (is_array($parts) && count($parts))
        {
            foreach ($parts as $part)
            {
                if ($innerBoundry = $this->getBoundry($part))
                {

                    $this->parseByBoundry(substr($part, strpos($part, $innerBoundry)), $innerBoundry);
                }
                else
                {
                    //this part dosnet have boundry, so it pure data!

                    $this->parseBody($part);
                    $this->parseAttachment($part);
                } 
            }
        }
    }

    protected function parseBody($data)
    {
        if (preg_match("/content\-type: ?text\/(html|plain); ?charset=(.*)/i", $data, $matches))
        {
            $type = strtolower($matches[1]);
            $charset = trim(strtolower(stripslashes($matches[2])), "\"'\r\n ");

            $encoding = $this->getEncoding($data);
            if (!isset($this->bodies[$type]))
            {
                $this->bodies[$type] = $this->decodeContent(substr($data, strpos($data, "\n\n")), $encoding, $charset);
            }
        }
    }


    protected function parseAttachment($data)
    {
        if (preg_match("/Content\-Disposition: ?attachment;/i", $data, $matches))
        {
            $filename = null;
            if (preg_match("/filename= ?(.*)/i", $data, $matches))
            {
                $filename = trim(stripslashes(strtolower($matches[1])), "'\" \r\n");
                $filename = iconv_mime_decode($filename, 0, 'utf-8');
            }

            $content_type = null;
            if (preg_match("%Content\-Type: ?([a-z]+/[a-z]+)%i", $data, $matches))
            {
                $content_type = strtolower($matches[1]);
            }
            
            $file["filename"] = $filename;
            $file["content_type"] = $content_type;
			
			$encoding = $this->getEncoding($data);

            $file["data"] = $this->decodeContent(substr($data, strpos($data, "\n\n")), $encoding, 'utf-8', true);

            $this->files[] = $file;
        }
    }


    protected function getBoundry($content)
    {
        $boundry = '';
        if (preg_match("/boundary=(.*)/i", $content, $matches))
        {
            $boundry = '--' . trim(stripslashes($matches[1]), '"\' ');
        }

        return $boundry;
    }

    protected function getEncoding($content)
    {
        if (preg_match("/Content\-Transfer\-Encoding: ?(.*)/i", $content, $matches))
        {
            $encoding = trim(strtolower($matches[1]), "'\"\r\n ");
        }
        else
            if (isset($this->headers['content-transfer-encoding']))
            {
                $encoding = $this->headers['content-transfer-encoding'];
            }
            else
                $encoding = 'base64';

        return $encoding;
    }


    protected function decodeContent($content, $encodedWith, $charset, $isBinary = false)
    {
        $content = preg_replace('/((\r?\n)*)$/', '', $content);

        if ($encodedWith == 'base64')
        {
            $content = base64_decode($content);
        }
        elseif ($encodedWith == 'quoted-printable')
        {
            $content = quoted_printable_decode($content);
        }

        if ($charset !== 'utf-8')
        {
            // FORMAT=FLOWED, despite being popular in emails, it is not
            // supported by iconv
            $charset = str_replace("FORMAT=FLOWED", "", $charset);

            $content = iconv($charset, 'utf-8//TRANSLIT', $content);

            if ($content === false)
            { // iconv returns FALSE on failure
                $content = utf8_encode($content);
            }
        }
        if (!$isBinary)
            $content = stripslashes($content);

        return $content;
    }

    /**
     *
     * @return string (in UTF-8 format)
     * @throws Exception if a subject header is not found
     */
    public function getSubject()
    {
        if (!isset($this->headers['subject']))
        {
            throw new Exception("Couldn't find the subject of the email");
        }

        $ret = '';

        if ($this->isImapExtensionAvailable)
        {
            foreach (imap_mime_header_decode($this->headers['subject']) as $h)
            { // subject can span into several lines
                $charset = ($h->charset == 'default') ? 'US-ASCII' : $h->charset;
                $ret .= iconv($charset, "UTF-8//TRANSLIT", $h->text);
            }
        }
        else
        {
            $ret = iconv_mime_decode($this->headers['subject'], 0, 'UTF-8');
        }

        return $ret;
    }

    /**
     *
     * @return array
     */
    public function getCc()
    {
        if (!isset($this->headers['cc']))
        {
            return array();
        }

        return explode(',', $this->headers['cc']);
    }

    /**
     *
     * @return array
     * @throws Exception if a to header is not found or if there are no recipient
     */
    public function getTo()
    {
        if ((!isset($this->headers['to'])) || (!count($this->headers['to'])))
        {
            throw new Exception("Couldn't find the recipients of the email");
        }
        return explode(',', $this->headers['to']);
    }

    /**
     * return string - UTF8 encoded
     */
    public function getBody($returnType = 'plain')
    {
        return (isset($this->bodies[$returnType])) ? $this->bodies[$returnType] : "";
    }

    /**
     * @return string - UTF8 encoded
     *
     */
    public function getPlainBody()
    {
        return $this->getBody();
    }

    /**
     * return string - UTF8 encoded
     */
    public function getHTMLBody()
    {
        return $this->getBody('html');
    }

    /**
     * N.B.: if the header doesn't exist an empty string is returned
     *
     * @param string $headerName - the header we want to retrieve
     * @return string - the value of the header
     */
    public function getHeader($headerName)
    {
        $headerName = strtolower($headerName);

        if (isset($this->headers[$headerName]))
        {
            return $this->headers[$headerName];
        }
        return '';
    }

    /**
     * 
     * @return Associative Array - array of all the attachments, each entry is assoc array with file info.
     */
    public function getAttachments($type='')
    {
    	if($type)
    	{
    		foreach($this->files as $file)
    		{
    			//check the file type
    			if(strpos($file["content_type"], $type)!==false || strpos($file["file_name"], '.'.$type))
    			return $file;
    		}
    	}

		return $this->files;
    }
}

?>