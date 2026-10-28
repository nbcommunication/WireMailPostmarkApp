# WireMail Postmark API
Extends WireMail to use the Postmark API for sending emails.

# Installation
1. Download the [zip file](https://github.com/nbcommunication/WireMailPostmarkApp/archive/master.zip) at Github or clone the repo into your `site/modules` directory.
2. If you downloaded the zip file, extract it in your `site/modules` directory.
3. In your admin, go to Modules > Refresh, then Modules > New, then click on the Install button for this module.

# Requirements
- ProcessWire >= 3.0.123
- PHP >= 8.1
- [postmark-php](https://github.com/ActiveCampaign/postmark-php) ^7.0 (bundled with this module via Composer)

# API
Prior to using this module, you must set up a server in your [Postmark account](https://account.postmarkapp.com/servers) and create an API Token. You should also set up a [Sender Signature](https://account.postmarkapp.com/signature_domains). Add the API Token and Sender Signature to the module configuration.

## Usage
Usage is similar to the basic WireMail implementation, although a few extra options are available. Please refer to the [WireMail documentation](https://processwire.com/api/ref/wire-mail/) for full instructions on using WireMail, and to the examples below.

## Extra Methods
The following are extra methods implemented by this module:

### Chainable
The following methods can be used in a chained statement:

**setSenderSignature(**_string_ **$senderSignature)** - Set a different sender signature than the default.
- Must use a registered and confirmed Sender Signature.
- To include a name, use the format "Full Name \<sender@domain.com\>" for the address.

**cc(**_string|array|null_ **$email)** - Set a "cc" email address.
- When batch sending or using a template, cc recipients are only added to the first message, so they receive one copy.
- Please refer to [WireMail::to()](https://processwire.com/api/ref/wire-mail/to/) for more information on how to use this method.

**bcc(**_string|array|null_ **$email)** - Set a "bcc" email address.
- When batch sending or using a template, bcc recipients are only added to the first message, so they receive one copy.
- Please refer to [WireMail::to()](https://processwire.com/api/ref/wire-mail/to/) for more information on how to use this method.

**attachInlineImage(**_string_ **$file**, _string_ **$filename)** - Add an inline image for referencing in HTML.
- Reference using "cid:" e.g. `<img src='cid:filename.ext'>`

**setTag(**_string_ **$tag)** - Set the email tag.

**setTrackOpens(**_bool_ **$trackOpens)** - Override "Track opens" module setting on a per-email basis.
- Disabled automatically for 'Forgot Password' emails from ProcessWire

**setTrackLinks(**_bool_ **$trackLinks)** - Override "Track links" module setting on a per-email basis.
- Disabled automatically for 'Forgot Password' emails from ProcessWire

**setMetaData(**_string|array_ **$key**, _string_ **$value** = ''**)** - Add custom metadata to the email.

**setMessageStream(**_string_ **$messageStream)** - Set the message stream.

**setSendBatch(**_bool_ **$sendBatch)** - Set the batch mode.
- This is off by default, meaning that a single email is sent with each recipient seeing the other recipients
- If this is on, any email addresses set by `cc()` and `bcc()` are only added to the first message
- Postmark has a limit on 50 email addresses per message and 500 messages per batch request. This module will split the recipients into batches if necessary and will also split up batches of messages too.

**setRecipientVariables(**_array_ **$variables**, _string_ **$email** = ''**)** - Set the recipient variables
- `$variables` should be an array of data keyed by the recipient email address, or specific for a recipient specified by `$email`.
- Variables are only used when either `$sendBatch` or a template is being used.
- With `$sendBatch`, `{{variable}}` placeholders in the subject, body and HTML body are replaced for each recipient.

**setTemplate(**_string_ **$template**, _array_ **$variables** = [], _bool_ **$inlineCss** = `true`**)** - Set the template alias or id
- You can set template variables by passing an array to `$variables`.
- You can toggle the `$inlineCss` setting. [More information](https://postmarkapp.com/developer/api/templates-api#email-with-template)

**setTemplateVariables(**_array_ **$variables)** - Set the template variables.
- These are variables shared by each recipient's message

### Other

**getClient()** - Return the Postmark client.
- For more details please see the documentation for [postmark-php](https://github.com/ActiveCampaign/postmark-php/wiki/Getting-Started).

**getResponse(**_int_ **$index** = `null`**)** - Return the last send() response
- Returns a `Postmark\Models\PostmarkResponse` object.
- Pass an `$index` if you want to get a specific response from a batch send.

**getResponses()** - Return the last batch send() responses
- Returns an array of `Postmark\Models\PostmarkResponse` objects.

**send()** - Send the email.
- Returns a positive number (indicating number of emails sent) or 0 on failure.

## Examples

### Basic Example
Send an email. The From address is the Sender Signature set in the module configuration (or by `setSenderSignature()`); `from()` is not used.

```php
$postmark = $mail->new();
$sent = $postmark->to('user@domain.com')
	->subject('Message Subject')
	->body('Message Body')
	->send();
```

### Advanced Example
Send an email using all supported WireMail methods and extra methods implemented by WireMailPostmarkApp:
```php
$postmark = $mail->new();

// WireMail methods
$postmark->to([
		'user@domain.com' => 'A User',
		'user2@domain.com' => 'Another User',
	])
	->replyTo('reply@company.com', 'Company Name')
	->subject('Message Subject')
	->bodyHTML(
		'<p>Message Body with variables: {{name}} = {{toName}} <{{toEmail}}> ({{key3}})</p>' .
		'<img src="cid:filename-inline.jpg">'
	) // A text version will be automatically created
	->header('key1', 'value1')
	->headers(['key2' => 'value2'])
	->attachment('/path/to/file.ext', 'filename.ext');

// WireMailPostmarkApp methods
$postmark->setSenderSignature('Alternate <another@company.com>') // Use a different Sender Signature
	->cc('cc@domain.com')
	->bcc(['bcc@domain.com', 'bcc2@domain.com'])
	->attachInlineImage('/path/to/file-inline.jpg', 'filename-inline.jpg') // Add inline image
	->setTag('tag1') // Set the tag
	->setTrackOpens(false) // Disable tracking opens
	->setTrackLinks(false)  // Disable tracking clicks
	->setMetaData('key1', 'value1') // Custom metadata
	->setMetaData(['key2' => 'value2']) // Custom metadata as array
	->setMessageStream('outbound') // The stream to use
	->setSendBatch(false) // A single email will be sent, both 'to' recipients shown
	->setRecipientVariables([
		'user@domain.com' => [
			'name' => 'user',
		],
		'user2@domain.com' => [
			'name' => 'user2',
		]
	]) // variables for each of the 'to' addresses
	->setTemplate('template1') // The template to use
	->setTemplateVariables(['key3' => 'value3']); // Set template variables


// A template is used, so each recipient is sent their own message and 2 is returned if successful
$numSent = $postmark->send();

echo 'The email was ' . ($numSent ? '' : 'not ') . 'sent.';
```

### Sending in Batch Mode
```php
// If using batch mode, the recipient variable 'toName' is inferred from the `to` addresses, e.g.
$postmark = $mail->new();
$postmark->to([
		'user@domain.com' => 'A User',
		'user2@domain.com' => 'Another User',
	])
	->setSendBatch(true)
	->subject('Message Subject')
	->bodyHTML('<p>Dear {{toName}},</p>')
	->send();

// to =
// A User <user@domain.com>
// Another User <user2@domain.com>
//
// recipientVariables =
// {
//		"user@domain.com": {
//			"toName": "A User",
//			"toEmail": "user@domain.com"
//		},
//		"user2@domain.com": {
//			"toName": "Another User",
//			"toEmail": "user2@domain.com"
//		}
// }
//
// bodyHTML[user@domain.com] =
// <p>Dear A User,</p>
// bodyHTML[user2@domain.com] =
// <p>Dear Another User,</p>

// You can also use `setRecipientVariables()` to extend/override the inferred `recipientVariables` e.g.
$postmark = $mail->new();
$postmark->to([
		'user@domain.com' => 'A User',
		'user2@domain.com' => 'Another User',
	])
	->setRecipientVariables([
		'user@domain.com' => [
			'title' => 'A User (title)',
		],
		'user2@domain.com' => [
			'toName' => 'Another User (changed name)',
			'title' => 'Another User (title)',
		],
	])
	->setSendBatch(true)
	->subject('Message Subject')
	->bodyHTML('<p>Dear {{toName}},</p><p>Title: {{title}}!</p>')
	->send();

// to =
// A User <user@domain.com>
// Another User <user2@domain.com>
//
// recipientVariables =
// {
//		"user@domain.com": {
//			"toName": "A User",
//			"toEmail": "user@domain.com",
//			"title": "A User (title)"
//		},
//		"user@domain.com": {
//			"toName": "Another User (changed name)",
//			"toEmail": "user2@domain.com",
//			"title": "Another User (title)"
//		}
// }
//
// bodyHTML[user@domain.com] =
// <p>Dear A User,</p><p>Title: A User (title)!</p>
// bodyHTML[user2@domain.com] =
// <p>Dear Another User (changed name),</p><p>Title: Another User (title)!</p>
```

### Sending with a template
How you set up your templates and layouts in Postmark is up to you, and this will determine which variables you pass to Postmark.
This module provides some defaults however. Alongside `toName` and `toEmail`, if a `body` or `bodyHTML` is set, these variables are also passed when using a template. The module will also attempt to replace any tags in these values with template/recipient variables. Hopefully the example below will demonstrate this:

```php
$postmark = $mail->new();
$postmark->to('user@example.com, user2@example.com')
	->setTemplate('template1')
	->setTemplateVariables([
		'siteUrl' => $pages->get(1)->httpUrl, // https://www.example.com/
		'test' => 123,
	])
	->setRecipientVariables([
		'user@example.com' => [
			'name' => 'User',
			'toName' => 'User',
		],
		'user2@example.com' => [
			'name' => 'User 2',
			'test' => 456,
		],
	])
	->bodyHTML(
		'<p>Dear {{name}}</p>' .
		'<p>This email was sent to {{toName}} <{{toEmail}}> from {{siteUrl}}.</p>' .
		'<p>{{test}}</p>'
	)
	->send();
```

The template subject:
```mustachio
Message from {{name}} on {{siteUrl}}
```

The HTML template (template1):
```mustachio
<table>
	<tr>
		<td>{{{bodyHTML}}}</td>
	</tr>
</table>
```
*Note the three curly braces being used - this prevents the value from being entity encoded (e.g. allows you to use HTML).*

The Text template (template1)
```mustachio
{{body}}
```

The two HTML emails sent:
```html
<!-- To: user@example.com -->
<!-- Subject: Message from User on https://www.example.com/ -->
<table>
	<tr>
		<td>
			<p>Dear User</p>
			<p>This email was sent to User <user@example.com> from https://www.example.com/.</p>
			<p>123</p>
		</td>
	</tr>
</table>

<!-- To: user2@example.com -->
<!-- Subject: Message from User 2 on https://www.example.com/ -->
<table>
	<tr>
		<td>
			<p>Dear User 2</p>
			<p>This email was sent to  <user2@example.com> from https://www.example.com/.</p>
			<p>456</p>
		</td>
	</tr>
</table>
```

### Using postmark-php for extended integration
```php
$postmarkClient = $modules->get('WireMailPostmarkApp')->getClient();
$postmarkClient->getOpenStatistics();
```

## Setting WireMailPostmarkApp as default

If WireMailPostmarkApp is the only WireMail module you have installed, then you can skip this step. However, if you have multiple WireMail modules installed, and you want WireMailPostmarkApp to be the default one used by ProcessWire, then you should add the following to your /site/config.php file:
```php
$config->wireMail('module', 'WireMailPostmarkApp');
```

## Changelog

### 0.1.0
- Fixed: BCC recipients could be ignored in batch and template sends because the message array used the key `BCc` instead of Postmark's `Bcc`. Single sends were not affected.
- Changed: per-recipient BCC overrides in template sends are now read from the `Bcc` recipient variable (previously `BCc`).
- Fixed: exception handling in `init()` and `send()` caught an unqualified `Exception`, which resolved to the non-existent `ProcessWire\Exception`, so non-Postmark exceptions were not caught and escaped from `send()`. It now catches `\Throwable`, so these are logged instead.
- Added: responses without a `MessageID` are now logged with Postmark's error message and code. Recipient email addresses are logged (not names) and obfuscated, e.g. `us****me@domain.com`. Email addresses in logged exception messages from `send()` are obfuscated too.
- Added: with `setSendBatch(true)`, `{{variable}}` placeholders in the subject are now replaced for each recipient, as they are in the body.
- Fixed: the "Track links?" module setting was ignored because the toggle value (`1`/`0`) wasn't mapped to a Postmark option, so link tracking was always `None` unless set in code.
- Fixed: batch and template sends returned the full recipient count if any response from the instance had ever succeeded. `send()` now returns the number of recipients whose messages Postmark accepted, and responses are cleared at the start of each send.
- Fixed: in batch and template sends, cc/bcc recipients received a copy of every message. They are now only added to the first message.
- Docs: removed `from()` from the README examples, as the From address is always the Sender Signature.
- Changed: now requires PHP >= 8.1. The bundled dependencies already required it, so the module could not load on PHP 7.4/8.0.
- Updated: bundled dependencies (Guzzle 7.16.1, guzzlehttp/psr7 2.13.2, guzzlehttp/promises 2.6.0). `composer.lock` was out of date with `vendor/` and has been regenerated; `composer audit` reports no advisories.
- Fixed: PHP 8.5 deprecation (`finfo_close()`) and a warning when `ProcessForgotPassword` is not installed.
- Changed: upgraded postmark-php from 4.0.5 to 7.0.0. **Breaking:** `getResponse()` and `getResponses()` now return `Postmark\Models\PostmarkResponse` objects instead of `DynamicResponseModel`. Use the properties `MessageID`, `To`, `Message`, `ErrorCode`, `SubmittedAt` exactly as written; array access (`$response['MessageID']`) and case-insensitive names are no longer supported. Code using `getClient()` directly should be checked against the postmark-php 7 API.
