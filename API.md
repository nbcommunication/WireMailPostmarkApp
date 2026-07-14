# WireMailPostmarkApp API Reference

This document is a reference for agents/developers writing code that uses the
WireMailPostmarkApp module. It describes the module's public API only - for
installation and configuration instructions see README.md.

## What it is

WireMailPostmarkApp extends ProcessWire's `WireMail` class to send email via
the [Postmark](https://postmarkapp.com) API, using the official
`postmarkapp/postmark-php` client library (bundled in `vendor/`).

Because it extends `WireMail`, all standard `WireMail` methods (`to()`,
`from()`, `fromName()`, `subject()`, `body()`, `bodyHTML()`, `attachment()`,
`header()`, `send()`, etc) work as normal. This document focuses on the
methods this module adds or overrides.

## Getting an instance

Use the standard ProcessWire mail API - do not instantiate the class directly.

```php
$mail = wireMail(); // Returns a WireMailPostmarkApp instance if module is installed/configured
```

To be explicit or to check the type before calling module-specific methods:

```php
$mail = wireMail();
if($mail instanceof WireMailPostmarkApp) {
	$mail->setTag('welcome-email');
}
```

## Configuration

These are set via the module's config screen (Modules > WireMailPostmarkApp)
and read as properties on the module:

- `serverToken` (string, required) - Postmark Server API token.
- `senderSignature` (string, required) - Default From address/signature.
  Must be a verified Sender Signature or Domain in Postmark. May include a
  name, e.g. `"Jane Doe <jane@example.com>"`.
- `trackOpens` (bool) - Default open tracking setting.
- `trackLinks` (bool) - Default link tracking setting (maps to Postmark's
  `None`/`HtmlAndText`/`HtmlOnly`/`TextOnly` options; boolean true maps to
  `HtmlAndText`).

## Methods

### cc($email = null, $name = null)

Add one or more CC recipients. Accumulates across calls; pass `null` to
clear all previously set CC addresses.

```php
$mail->cc('someone@example.com');
$mail->cc(['a@example.com' => 'A Person', 'b@example.com' => 'B Person']);
$mail->cc(null); // clear
```

### bcc($email = null, $name = null)

Same signature and behaviour as `cc()`, but for BCC recipients.

### attachInlineImage($file, $filename = null)

Attach an image to be referenced inline in HTML body content via
`cid:filename`. If `$filename` is omitted, it is derived from `$file`'s
basename.

```php
$mail->attachInlineImage('/path/to/logo.png', 'logo.png');
$mail->bodyHTML('<img src="cid:logo.png">');
```

### setTag($tag)

Sets Postmark's message `Tag`, used for categorizing/filtering messages in
the Postmark UI and via webhooks. Accepts a single string.

### setTrackOpens(bool $trackOpens)

Overrides the module's configured open-tracking setting for this send only.

### setTrackLinks($trackLinks)

Overrides the module's configured link-tracking setting for this send only.
Accepts a bool (mapped to `None`/`HtmlAndText`) or one of the literal
Postmark strings: `None`, `HtmlAndText`, `HtmlOnly`, `TextOnly`.

### setMetaData($key, $value = '')

Adds custom metadata key/value pairs to the message (Postmark's `Metadata`
field, returned in webhooks). Accepts either a `$key => $value` pair, or an
associative array as `$key` to merge multiple values at once.

```php
$mail->setMetaData('orderId', '12345');
$mail->setMetaData(['orderId' => '12345', 'customerId' => '678']);
```

### setMessageStream($messageStream)

Sets the Postmark Message Stream ID to send through (e.g. `outbound`,
`broadcasts`, or a custom stream).

### setSendBatch($sendBatch)

Forces (or disables) Postmark batch sending (`sendEmailBatch`) even when the
recipient count is below the automatic batching threshold (see Batching
below). Accepts a bool.

### setRecipientVariables(array $variables, $email = '')

Registers per-recipient template/placeholder variables.

- If `$email` is provided, `$variables` is treated as the variable set for
  that single email address.
- If `$email` is omitted, `$variables` should be an associative array keyed
  by email address, each value being that recipient's variable set.

Repeated calls for the same email merge with previously set variables.

```php
// Single recipient
$mail->setRecipientVariables(['firstName' => 'Jane'], 'jane@example.com');

// Multiple recipients at once
$mail->setRecipientVariables([
	'jane@example.com' => ['firstName' => 'Jane'],
	'john@example.com' => ['firstName' => 'John'],
]);
```

Variables are available in the plain-text/HTML body via `{{variableName}}`
placeholders (see Recipient Variables below), and are also passed as
`TemplateModel` values when using `setTemplate()`.

### setTemplate($template, $variables = [], $inlineCss = true)

Switches the send to use a Postmark Template rather than raw `body()` /
`bodyHTML()` content.

- `$template` - Postmark Template ID (int) or Alias (string).
- `$variables` - optional array of global template variables (applied to
  every recipient, merged with per-recipient variables from
  `setRecipientVariables()`). May be omitted; if a bool is passed here
  instead, it is treated as `$inlineCss` and `$variables` defaults to `[]`.
- `$inlineCss` - whether Postmark should inline CSS in the template's HTML
  (Postmark's `InlineCss` option). Default `true`.

```php
$mail->setTemplate('welcome-email', ['companyName' => 'Acme Ltd']);
```

Note: any `body()` / `bodyHTML()` content set is still used as the source for
`{{variable}}` placeholder replacement of the message body/HTML body content
sent alongside the template's own data via `TemplateModel`, i.e. `body`/
`bodyHTML` are made available to the template as `TemplateModel` keys
`body`/`bodyHTML`.

### setTemplateVariables(array $variables)

Adds/merges additional global template variables without calling
`setTemplate()` again. Useful for building up variables incrementally.

### setSenderSignature($senderSignature)

Overrides the configured `senderSignature` for this send only. Must be a
verified Sender Signature/Domain in Postmark. May include a name in the
format `"Full Name <sender@domain.com>"`.

### getClient()

Returns the underlying `Postmark\PostmarkClient` instance, for advanced use
cases not covered by this module's own methods (e.g. calling other Postmark
API endpoints directly).

```php
$client = $mail->getClient();
```

### getResponse($index = null)

Returns the Postmark API response (`Postmark\Models\DynamicResponseModel`)
from the most recent single-recipient `send()`. If `$index` (int) is given,
returns that specific response from a prior batch send instead.

### getResponses()

Returns the array of all responses from the most recent batch send (i.e.
when the recipient count exceeded the auto-batch threshold, `setSendBatch()`
was used, or a template send occurred).

## send()

Standard `WireMail::send()`. Returns an `int`:

- Single send: `1` on success (a `MessageID` was returned), `0` on failure.
- Batch/template send: the number of recipients (`0` if no responses were
  successful).

Exceptions from the Postmark client (`PostmarkException` and generic
`\Exception`) are caught internally and written to the module's log rather
than being thrown - check the return value and/or `$modules->get('WireMailPostmarkApp')`'s
log (Setup > Logs > wire-mail-postmark-app) rather than wrapping `send()` in
a try/catch.

## Batching behaviour

- Postmark's API accepts a maximum of 500 messages per batch call
  (`self::batchLimit`). If more than 500 messages need to be sent in one
  `send()` call, they are automatically split into multiple batch API calls.
- If more than 50 `to` recipients are set (`self::toLimit`) and no template
  is in use, sending automatically switches to batch mode, splitting
  recipients into groups of 50 per outgoing message (each still delivered as
  its own message, recipients don't see each other).
- Call `setSendBatch(true)` to force per-recipient batch sending (one
  message per recipient, each with its own recipient-variable
  substitutions) even below the 50-recipient threshold - required if you
  want per-recipient `{{variable}}` substitution without a template.
- Any `setTemplate()` call always uses `sendEmailBatchWithTemplate()`,
  regardless of recipient count.

## Recipient variable placeholders

When not using a Postmark Template, `{{variableName}}` placeholders in the
plain-text `body()` and HTML `bodyHTML()` content are replaced per-recipient
using values from `setRecipientVariables()`. This only takes effect when
batch sending is active (recipient count > 50, or `setSendBatch(true)` was
called) - for a small number of recipients with per-recipient content, call
`setSendBatch(true)` explicitly.
