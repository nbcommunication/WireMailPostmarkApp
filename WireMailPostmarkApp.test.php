<?php namespace ProcessWire;

/**
 * WireTest for WireMailPostmarkApp
 *
 * Exercises the module's public fluent-builder API (cc/bcc, attachments, template
 * selection, recipient variables, tracking overrides, metadata, etc.) plus a few
 * pure-function internal helpers via reflection.
 *
 * Deliberately NOT covered: ___send() and any live batch/template sending, since those
 * require valid Postmark API credentials and network access. Treat that as a manual/
 * integration test rather than an automated one - see API.md.
 *
 */
class WireTest_WireMailPostmarkApp extends WireTest {

	/**
	 * Only run if the module is actually installed
	 *
	 * @return bool
	 *
	 */
	public function allow() {
		return $this->wire()->modules->isInstalled('WireMailPostmarkApp');
	}

	/**
	 * Get a fresh WireMailPostmarkApp instance
	 *
	 * The module is non-singular, so modules->get() constructs (and inits) a new
	 * instance every time, keeping each test section isolated from the others.
	 *
	 * @return WireMailPostmarkApp
	 *
	 */
	protected function newMail() {
		return $this->wire()->modules->get('WireMailPostmarkApp');
	}

	/**
	 * Get/set a protected or private property via reflection
	 *
	 * @param object $obj
	 * @param string $name
	 * @return mixed
	 *
	 */
	protected function getProtected($obj, $name) {
		$rp = new \ReflectionProperty($obj, $name);
		$rp->setAccessible(true);
		return $rp->getValue($obj);
	}

	/**
	 * Invoke a protected or private method via reflection
	 *
	 * @param object $obj
	 * @param string $name
	 * @param array $args
	 * @return mixed
	 *
	 */
	protected function callProtected($obj, $name, array $args = []) {
		$rm = new \ReflectionMethod($obj, $name);
		$rm->setAccessible(true);
		return $rm->invokeArgs($obj, $args);
	}

	public function execute() {
		$this->testClient();
		$this->testCcBcc();
		$this->testAttachInlineImage();
		$this->testTemplate();
		$this->testRecipientVariables();
		$this->testMetaData();
		$this->testTrackingAndOptions();
		$this->testResponsesEmptyByDefault();
		$this->testGetEmailsHelper();
		$this->testPopulateVariablesHelper();
		$this->testMimeType();
	}

	protected function testClient() {
		$mail = $this->newMail();
		$this->check(
			'getClient() returns a PostmarkClient instance',
			true,
			$mail->getClient() instanceof \Postmark\PostmarkClient
		);
	}

	protected function testCcBcc() {

		// Single email
		$mail = $this->newMail();
		$mail->cc('a@example.com');
		$this->check('cc() single email stored', ['a@example.com' => 'a@example.com'], $mail->get('cc'));

		// Name as 2nd argument
		$mail = $this->newMail();
		$mail->cc('a@example.com', 'Alice');
		$ccName = $mail->get('ccName');
		$this->check('cc() name argument stored', 'Alice', $ccName['a@example.com']);

		// 'Name <email>' format
		$mail = $this->newMail();
		$mail->cc('Bob <b@example.com>');
		$ccName = $mail->get('ccName');
		$this->check('cc() "Name <email>" format parses name', 'Bob', $ccName['b@example.com']);

		// CSV string, multiple addresses
		$mail = $this->newMail();
		$mail->cc('a@example.com,b@example.com');
		$this->check('cc() CSV string adds 2 addresses', 2, count($mail->get('cc')));

		// Associative array (email => name)
		$mail = $this->newMail();
		$mail->bcc(['a@example.com' => 'Alice', 'b@example.com' => 'Bob']);
		$bccName = $mail->get('bccName');
		$this->check('bcc() associative array name for a@example.com', 'Alice', $bccName['a@example.com']);
		$this->check('bcc() associative array name for b@example.com', 'Bob', $bccName['b@example.com']);

		// Appending across calls rather than overwriting
		$mail = $this->newMail();
		$mail->cc('a@example.com');
		$mail->cc('b@example.com');
		$this->check('cc() appends across calls rather than overwriting', 2, count($mail->get('cc')));

		// Clearing with null
		$mail->cc(null);
		$this->check('cc(null) clears all cc addresses', 0, count($mail->get('cc')));

		// bcc mirrors cc behaviour
		$mail = $this->newMail();
		$mail->bcc('c@example.com');
		$this->check('bcc() single email stored', ['c@example.com' => 'c@example.com'], $mail->get('bcc'));
	}

	protected function testAttachInlineImage() {

		$mail = $this->newMail();
		$mail->attachInlineImage('/path/to/logo.png');
		$inline = $this->getProtected($mail, 'inline');
		$this->check('attachInlineImage() derives filename from path when not given', true, isset($inline['logo.png']));
		$this->check('attachInlineImage() maps derived filename to file path', '/path/to/logo.png', $inline['logo.png']);

		$mail->attachInlineImage('/other/path/photo.jpg', 'custom-name.jpg');
		$inline = $this->getProtected($mail, 'inline');
		$this->check('attachInlineImage() uses explicit filename when given', true, isset($inline['custom-name.jpg']));

		$result = $mail->attachInlineImage('/a/b.png');
		$this->check('attachInlineImage() is chainable', true, $result === $mail);
	}

	protected function testTemplate() {

		// Normal form: template + variables array
		$mail = $this->newMail();
		$mail->setTemplate('welcome-email', ['name' => 'Test User']);
		$this->check('setTemplate() stores template alias', 'welcome-email', $this->getProtected($mail, 'template'));
		$this->check('setTemplate() sets template variables', ['name' => 'Test User'], $this->getProtected($mail, 'templateVariables'));
		$this->check('setTemplate() defaults inlineCss to true', true, $this->getProtected($mail, 'inlineCss'));

		// Argument-juggling: bool as 2nd arg shifts to $inlineCss, variables stay empty
		$mail = $this->newMail();
		$mail->setTemplate('welcome-email', false);
		$this->check('setTemplate() bool shorthand sets inlineCss=false', false, $this->getProtected($mail, 'inlineCss'));
		$this->check('setTemplate() bool shorthand leaves templateVariables empty', [], $this->getProtected($mail, 'templateVariables'));

		// Numeric template id cast to int (TemplateId vs TemplateAlias distinction in ___send())
		$mail = $this->newMail();
		$mail->setTemplate('12345');
		$this->check('setTemplate() numeric string is cast to int', true, is_int($this->getProtected($mail, 'template')));

		// Chainable
		$mail = $this->newMail();
		$result = $mail->setTemplate('x');
		$this->check('setTemplate() is chainable', true, $result === $mail);
	}

	protected function testRecipientVariables() {

		// Single-email form: setRecipientVariables($vars, $email)
		$mail = $this->newMail();
		$mail->setRecipientVariables(['name' => 'Alice'], 'a@example.com');
		$vars = $this->getProtected($mail, 'recipientVariables');
		$this->check('setRecipientVariables() single-email form nests under email', 'Alice', $vars['a@example.com']['name']);

		// Multi-email form: setRecipientVariables([email => vars, ...])
		$mail = $this->newMail();
		$mail->setRecipientVariables([
			'a@example.com' => ['name' => 'Alice'],
			'b@example.com' => ['name' => 'Bob'],
		]);
		$vars = $this->getProtected($mail, 'recipientVariables');
		$this->check('setRecipientVariables() multi-email form sets both emails', true,
			isset($vars['a@example.com']) && isset($vars['b@example.com'])
		);

		// Merging across calls rather than overwriting
		$mail->setRecipientVariables(['a@example.com' => ['age' => 30]]);
		$vars = $this->getProtected($mail, 'recipientVariables');
		$this->check('setRecipientVariables() merges rather than overwrites existing email vars', true,
			isset($vars['a@example.com']['name']) && isset($vars['a@example.com']['age'])
		);
	}

	protected function testMetaData() {

		$mail = $this->newMail();
		$mail->setMetaData('key1', 'value1');
		$meta = $this->getProtected($mail, 'metaData');
		$this->check('setMetaData() key/value form sets metaData', 'value1', $meta['key1']);

		$mail->setMetaData(['key2' => 'value2']);
		$meta = $this->getProtected($mail, 'metaData');
		$this->check('setMetaData() array form merges additional keys', 'value2', $meta['key2']);
		$this->check('setMetaData() array form retains earlier keys', 'value1', $meta['key1']);
	}

	protected function testTrackingAndOptions() {

		$mail = $this->newMail();

		$result = $mail->setTrackOpens(true);
		$this->check('setTrackOpens() is chainable', true, $result === $mail);
		$this->check('setTrackOpens() sets trackOpens property', true, $mail->trackOpens);

		$mail->setTrackOpens(false);
		$this->check('setTrackOpens(false) clears trackOpens property', false, $mail->trackOpens);

		$mail->setTrackLinks('HtmlOnly');
		$this->check('setTrackLinks() sets trackLinks property', 'HtmlOnly', $mail->trackLinks);

		$mail->setSenderSignature('sender@example.com');
		$this->check('setSenderSignature() sets senderSignature property', 'sender@example.com', $mail->senderSignature);

		$mail->setTag('newsletter');
		$this->check('setTag() sets internal tag', 'newsletter', $this->getProtected($mail, 'tag'));

		$mail->setMessageStream('outbound');
		$this->check('setMessageStream() sets internal messageStream', 'outbound', $this->getProtected($mail, 'messageStream'));

		$mail->setSendBatch(true);
		$this->check('setSendBatch(true) sets internal sendBatch', true, $this->getProtected($mail, 'sendBatch'));

		$mail->setSendBatch(0);
		$this->check('setSendBatch() casts value to bool', false, $this->getProtected($mail, 'sendBatch'));
	}

	protected function testResponsesEmptyByDefault() {
		$mail = $this->newMail();
		$this->check('getResponse() returns null before any send', null, $mail->getResponse());
		$this->check('getResponses() returns empty array before any send', [], $mail->getResponses());
	}

	protected function testGetEmailsHelper() {
		$mail = $this->newMail();
		$result = $this->callProtected($mail, 'getEmails', [
			['a@example.com' => '', 'b@example.com' => 'Bob'],
		]);
		$this->check(
			'getEmails() builds CSV, including name where present',
			'a@example.com,Bob <b@example.com>',
			$result
		);
	}

	protected function testPopulateVariablesHelper() {
		$mail = $this->newMail();

		$result = $this->callProtected($mail, 'populateVariables', [
			'Hello {{name}}, welcome to {{site}}.',
			['name' => 'Alice', 'site' => 'Example'],
		]);
		$this->check('populateVariables() replaces {{placeholders}}', 'Hello Alice, welcome to Example.', $result);

		// Non-scalar (array) values should be skipped, not cause an error
		$result = $this->callProtected($mail, 'populateVariables', [
			'Hi {{name}}',
			['name' => 'Bob', 'extra' => ['x' => 'y']],
		]);
		$this->check('populateVariables() skips non-scalar variable values without error', 'Hi Bob', $result);
	}

	protected function testMimeType() {

		$mail = $this->newMail();
		$tmp = rtrim(sys_get_temp_dir(), '/') . '/wire_test_postmark_' . uniqid() . '.png';

		// Minimal 1x1 transparent PNG
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
		file_put_contents($tmp, $png);

		try {
			$mime = $this->callProtected($mail, 'getMimeType', [$tmp]);
			$this->check('getMimeType() detects PNG image mime type', 'image/png', $mime, '*=');
		} finally {
			if(is_file($tmp)) @unlink($tmp);
		}
	}
}
