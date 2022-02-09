emailParser
===========

PHP class that parses raw email with support of attachments.


## Usage
```php
include_once 'emailParser.class.php';
$parser = new emailParser(file_get_contents('raw_email_data.txt'));

$parser->getHTMLBody();
$parser->getPlainBody();
$parser->getSubject();
$parser->getAttachments();
$parser->getAttachments('pdf');
$parser->getTo();
$parser->getHeader('From');
```
