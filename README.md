emailParser
===========

PHP class that parses raw email with support of attachments.


## Usage
```
include_once 'emailParser.class.php';
$parser = new emailParser(file_get_contents('email.txt'));

$parser->getHTMLBody();
$parser->getPlainBody();
$parser->getSubject();
$parser->getAttachments();
$parser->getTo();
$parser->getHeader('From');
```
