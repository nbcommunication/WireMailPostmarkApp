<?php namespace ProcessWire;

/**
 * WireMail Postmark API
 *
 * #pw-summary Extends WireMail to use the Postmark API for sending emails
 * #pw-body =
 * More information can be found here: https://github.com/ActiveCampaign/postmark-php/wiki
 * #pw-body
 *
 * #pw-var $postmark
 *
 * @copyright 2025 NB Communication Ltd
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 *
 * @property string $serverToken
 * @property string $senderSignature
 * @property bool $trackOpens
 * @property bool|string $trackLinks
 *
 */

require_once 'vendor/autoload.php';

use \Postmark\PostmarkClient;
use \Postmark\Models\PostmarkException;
use \Postmark\Models\PostmarkAttachment;

class WireMailPostmarkApp extends WireMail implements Module {

	/**
	 * getModuleInfo is a module required by all modules to tell ProcessWire about them
	 *
	 * @return array
	 *
	 */
	public static function getModuleInfo() {
		return [
			'title' => 'WireMail Postmark API',
			'version' => 003,
			'summary' => 'Extends WireMail to use the Postmark API for sending emails.',
			'author' => 'nbcommunication',
			'href' => 'https://github.com/nbcommunication/WireMailPostmarkApp',
			'singular' => false,
			'autoload' => false,
			'icon' => 'envelope',
			'requires' => 'ProcessWire>=3.0.123,PHP>=7.4',
		];
	}

	const batchLimit = 500;
	const optionsTrackLinks = ['None', 'HtmlAndText', 'HtmlOnly', 'TextOnly'];
	const toLimit = 50;

	/**
	 * The Postmark client
	 *
	 * @var PostmarkClient
	 *
	 */
	protected $client;

	/**
	 * An array of inline images
	 *
	 * @var array
	 *
	 */
	protected $inline = [];

	/**
	 * Should inlineCss be used?
	 *
	 * @var bool
	 *
	 */
	protected $inlineCss = true;

	/**
	 * The message stream
	 *
	 * @var string
	 *
	 */
	protected $messageStream = null;

	/**
	 * Custom metadata for the email to be sent
	 *
	 * @var array
	 *
	 */
	protected $metaData = [];

	/**
	 * An key=value array of recipient variable replacements keyed by email
	 *
	 * @var array
	 *
	 */
	protected $recipientVariables = [];

	/**
	 * The last response
	 *
	 * @var Postmark\Models\DynamicResponseModel
	 *
	 */
	protected $response = null;

	/**
	 * The last responses
	 *
	 * @var array
	 *
	 */
	protected $responses = [];

	/**
	 * Send batch emails?
	 *
	 * @var bool
	 *
	 */
	protected $sendBatch = false;

	/**
	 * The template alias or id
	 *
	 * @var string|int
	 *
	 */
	protected $template = null;

	/**
	 * An key=value array of template variables
	 *
	 * @var array
	 *
	 */
	protected $templateVariables = [];

	/**
	 * The message tag
	 *
	 * @var string
	 *
	 */
	protected $tag = null;

	/**
	 * Initialize the module
	 *
	 */
	public function init() {

		try {
			$this->client = new PostmarkClient($this->serverToken);
			$this->cc();
			$this->bcc();
		} catch(PostmarkException $e) {
			$this->log($e->getMessage());
		} catch(Exception $e) {
			$this->log($e->getMessage());
		}
	}

	/**
	 * Set a different sender signature than the default
	 *
	 * Must have a registered and confirmed Sender Signature.
	 * To include a name, use the format "Full Name <sender@domain.com>" for the address.
	 *
	 * @param string $senderSignature
	 * @return WireMail $this
	 *
	 */
	public function setSenderSignature($senderSignature) {
		$this->senderSignature = $senderSignature;
		return $this;
	}

	/**
	 * Set the email CC address
	 *
	 * Each added email addresses appends to any addresses already supplied, unless
	 * you specify NULL as the email address, in which case it clears them all.
	 *
	 * @param string|array|null $email Specify any ONE of the following:
	 * - Single email address or 'User Name <user@example.com>' string.
	 * - CSV string of #1.
	 * - Non-associative array of #1.
	 * - Associative array of (email => name)
	 * - NULL (default value, to clear out any previously set values)
	 * @param string $name Optionally provide a TO name, applicable
	 *	only when specifying #1 (single email) for the first argument.
	 * @return WireMail $this
	 * @throws WireException If any provided emails were invalid
	 * @see WireMailgun::setEmail()
	 *
	 */
	public function cc($email = null, $name = null) {
		return $this->setEmail('cc', $email, $name);
	}

	/**
	 * Set the email BCC address
	 *
	 * Each added email addresses appends to any addresses already supplied, unless
	 * you specify NULL as the email address, in which case it clears them all.
	 *
	 * @param string|array|null $email Specify any ONE of the following:
	 * - Single email address or 'User Name <user@example.com>' string.
	 * - CSV string of #1.
	 * - Non-associative array of #1.
	 * - Associative array of (email => name)
	 * - NULL (default value, to clear out any previously set values)
	 * @param string $name Optionally provide a TO name, applicable
	 *	only when specifying #1 (single email) for the first argument.
	 * @return WireMail $this
	 * @throws WireException If any provided emails were invalid
	 * @see WireMailgun::setEmail()
	 *
	 */
	public function bcc($email = null, $name = null) {
		return $this->setEmail('bcc', $email, $name);
	}

	/**
	 * Add an inline image for referencing in HTML
	 *
	 * @param string $file
	 * @param string $filename
	 * @return WireMail $this
	 *
	 */
	public function attachInlineImage($file, $filename = null) {
		if(is_null($filename)) {
			$f = explode('/', $file);
			$filename = $f[count($f) - 1];
		}
		$this->inline[$filename] = $file;
		return $this;
	}

	/**
	 * Set the email tag
	 *
	 * @param string $tag
	 * @return WireMail $this
	 *
	 */
	public function setTag($tag) {
		$this->tag = (string) $tag;
		return $this;
	}

	/**
	 * Override 'Track Opens' module setting on a per-email basis
	 *
	 * @param bool $trackOpens
	 * @return WireMail $this
	 *
	 */
	public function setTrackOpens(bool $trackOpens) {
		$this->trackOpens = $trackOpens;
		return $this;
	}

	/**
	 * Override 'Track Links' module setting on a per-email basis
	 *
	 * @param string|bool $trackLinks
	 * @return WireMail $this
	 *
	 */
	public function setTrackLinks($trackLinks) {
		$this->trackLinks = $trackLinks;
		return $this;
	}

	/**
	 * Add custom metadata to the email
	 *
	 * @param string|array $key
	 * @param string $value
	 * @return WireMail $this
	 *
	 */
	public function setMetaData($key, $value = '') {
		if(is_array($key)) {
			$this->metaData = array_merge($this->metaData, $key);
		} else {
			$this->metaData[$key] = $value;
		}
		return $this;
	}

	/**
	 * Set the message stream
	 *
	 * @param string $messageStream
	 * @return WireMail $this
	 *
	 */
	public function setMessageStream($messageStream) {
		$this->messageStream = (string) $messageStream;
		return $this;
	}

	/**
	 * Set the batch mode
	 *
	 * @param string $sendBatch
	 * @return WireMail $this
	 *
	 */
	public function setSendBatch($sendBatch) {
		$this->sendBatch = (bool) $sendBatch;
		return $this;
	}

	/**
	 * Set the recipient variables
	 *
	 * @param array $variables
	 * @param string $email
	 * @return WireMail $this
	 *
	 */
	public function setRecipientVariables(array $variables, $email = '') {
		$recipientVariables = $this->recipientVariables;
		if($email) {
			$variables = [$email => $variables];
		}
		foreach($variables as $email => $vars) {
			$email = $this->wire()->sanitizer->email($email);
			if($email) {
				if(!isset($recipientVariables[$email])) {
					$recipientVariables[$email] = [];
				}
				$recipientVariables[$email] = array_merge($recipientVariables[$email], $vars);
			}
		}
		$this->recipientVariables = $recipientVariables;
		return $this;
	}

	/**
	 * Set the template alias or id
	 *
	 * @param string|int $template
	 * @param array $variables
	 * @param bool $inlineCss
	 * @return WireMail $this
	 *
	 */
	public function setTemplate($template, $variables = [], $inlineCss = true) {

		if(!is_array($variables)) {
			$inlineCss = $variables;
			$variables = [];
		}

		$this->template = is_numeric($template) ? (int) $template : (string) $template;
		if(count($variables)) $this->setTemplateVariables($variables);
		$this->inlineCss = (bool) $inlineCss;

		return $this;
	}

	/**
	 * Set the template variables
	 *
	 * @param array $variables
	 * @return WireMail $this
	 *
	 */
	public function setTemplateVariables(array $variables) {
		$this->templateVariables = array_merge($this->templateVariables, $variables);
		return $this;
	}

	/**
	 * Return the Postmark client
	 *
	 * @return PostmarkClient
	 *
	 */
	public function getClient() {
		return $this->client;
	}

	/**
	 * Return the last send() response
	 *
	 * @param int $index
	 * @return Postmark\Models\DynamicResponseModel
	 *
	 */
	public function getResponse($index = null) {
		return is_int($index) ?
			$this->responses[$index] :
			($this->response ?:
				($this->responses[0] ?? null)
			);
	}

	/**
	 * Return the last batch send() responses
	 *
	 * @return array
	 *
	 */
	public function getResponses() {
		return $this->responses;
	}

	/**
	 * Send the email
	 *
	 * Call this method only after you have specified at least the `subject`, `to` and `body`.
	 *
	 * @return int Returns a positive number (indicating number of emails sent) or 0 on failure.
	 * @throws WireException
	 * @see WireMail::send()
	 *
	 */
	public function ___send() {

		$sent = 0;

		try {

			// When sending a password reset email, prevent Open/Click Tracking
			if($this->mail['subject'] == $this->wire()->modules->get('ProcessForgotPassword')->emailSubject) {
				$this->setTrackOpens(false);
				$this->setTrackLinks(false);
			}

			$textBody = $this->mail['body'];
			if(empty($textBody) && !empty($this->mail['bodyHTML'])) {
				$textBody = $this->htmlToText($this->mail['bodyHTML']);
			}

			$replyTo = null;
			if(!empty($this->mail['replyTo'])) {
				$replyTo = $this->mail['replyTo'];
				if(!empty($this->mail['replyToName'])) {
					$replyTo = "{$this->mail['replyToName']} <$replyTo>";
				}
				if(isset($this->mail['header']['Reply-To'])) {
					unset($this->mail['header']['Reply-To']);
				}
			}

			// Add Attachments
			$attachments = [];
			$hasAttachments = isset($this->mail['attachments']) && count($this->mail['attachments']);
			$hasInline = count($this->inline);
			if($hasAttachments || $hasInline) {
				// Attachments
				if($hasAttachments) {
					$i = 0;
					foreach($this->mail['attachments'] as $filename => $file) {
						$mime = $this->getMimeType($file);
						if($mime) {
							$attachments[] = PostmarkAttachment::fromRawData(
								$this->wire()->files->fileGetContents($file),
								$filename,
								$mime
							);
						}
					}
				}
				// Inline images
				if($hasInline) {
					$i = 0;
					foreach($this->inline as $filename => $file) {
						$mime = $this->getMimeType($file);
						if($mime) {
							$attachments[] = PostmarkAttachment::fromFile(
								$file,
								$filename,
								$mime,
								"cid:$filename"
							);
						}
					}
				}
			}

			$trackLinks = is_bool($this->trackLinks) ?
				self::optionsTrackLinks[(int) $this->trackLinks] :
				$this->trackLinks;

			$message = [
				'From' => $this->senderSignature,
				'Subject' => $this->mail['subject'],
				'HtmlBody' => $this->mail['bodyHTML'],
				'TextBody' => $textBody,
				'Tag' => $this->tag,
				'TrackOpens' => (bool) $this->trackOpens,
				'ReplyTo' => $replyTo,
				'Cc' => $this->getEmails($this->mail['ccName']),
				'BCc' => $this->getEmails($this->mail['bccName']),
				'Headers' => count($this->mail['header']) ? $this->mail['header'] : null,
				'Attachments' => count($attachments) ? $attachments : null,
				'TrackLinks' => in_array($trackLinks, self::optionsTrackLinks) ? $trackLinks : 'None',
				'Metadata' => count($this->metaData) ? $this->metaData : null,
				'MessageStream' => $this->messageStream,
			];

			$to = $this->mail['toName'];
			$c = count($to);
			$sendBatch = $c > self::toLimit || $this->sendBatch;

			if($sendBatch || $this->template) {

				// variables
				$recipients = [];
				foreach($to as $email => $name) {

					if(is_array($name)) {
						foreach(['name', 'toName'] as $key) {
							if(isset($name[$key])) {
								$name = $name[$key];
								break;
							}
						}
						if(is_array($name)) $name = '';
					}

					$variables = $this->recipientVariables[$email] ?? [];
					if(!isset($variables['toEmail'])) {
						$variables['toEmail'] = $email;
					}
					if(!isset($variables['toName'])) {
						$variables['toName'] = $name;
					}

					$sendTo = empty($variables['toName']) ? $email : "{$variables['toName']} <{$email}>";

					$recipients[$sendTo] = $variables;
				}

				$messages = [];
				if($this->template) {

					foreach($recipients as $sendTo => $variables) {

						$variables = array_merge($this->templateVariables, $variables);
						$variables['body'] = $this->populateVariables($message['TextBody'], $variables);
						$variables['bodyHTML'] = $this->populateVariables($message['HtmlBody'], $variables);

						$messages[] = [
							'From' => $message['From'],
							'To' => $sendTo,
							'Template' . (is_int($this->template) ? 'Id' : 'Alias') => $this->template,
							'TemplateModel' => $variables,
							'InlineCss' => $this->inlineCss,
							'Tag' => $message['Tag'],
							'TrackOpens' => $message['TrackOpens'],
							'ReplyTo' => $message['ReplyTo'],
							'Cc' => $variables['Cc'] ?? $message['Cc'],
							'BCc' => $variables['BCc'] ?? $message['BCc'],
							'Headers' => $message['Headers'],
							'Attachments' => $message['Attachments'],
							'TrackLinks' => $message['TrackLinks'],
							'Metadata' => $message['Metadata'],
							'MessageStream' => $message['MessageStream'],
						];
					}

				} else if($this->sendBatch) {

					foreach($recipients as $sendTo => $variables) {
						$messages[] = array_merge($message, [
							'To' => $sendTo,
							'HtmlBody' => $this->populateVariables($message['HtmlBody'], $variables),
							'TextBody' => $this->populateVariables($message['TextBody'], $variables),
						]);
					}

				} else {

					// Split sending into batches as the 'to' limit has been reached
					$offset = 0;
					$limit = self::toLimit;
					do {
						$messages[] = array_merge(
							$message,
							[
								'To' => $this->getEmails(array_slice($to, $offset, $limit)),
							]
						);
						$offset = $offset + $limit;
					} while($offset < $c);
				}

				$cm = count($messages);
				$offset = 0;
				$limit = self::batchLimit;
				do {

					$batchMessages = array_slice($messages, $offset, $limit);
					$responses = $this->template ?
						$this->client->sendEmailBatchWithTemplate($batchMessages) :
						$this->client->sendEmailBatch($batchMessages);

					foreach($responses as $response) {
						if($response->MessageID) {
							$this->responses[] = $response;
						}
					}

					$offset = $offset + $limit;

				} while($offset < $cm);

				$sent = count($this->responses) ? $c : 0;

			} else {

				$this->response = $this->client->sendEmail(
					$message['From'],
					$this->getEmails($to),
					$message['Subject'],
					$message['HtmlBody'],
					$message['TextBody'],
					$message['Tag'],
					$message['TrackOpens'],
					$message['ReplyTo'],
					$message['Cc'],
					$message['BCc'],
					$message['Headers'],
					$message['Attachments'],
					$message['TrackLinks'],
					$message['Metadata'],
					$message['MessageStream'],
				);

				$sent = $this->response->MessageID ? 1 : 0;
			}

		} catch(PostmarkException $e) {
			$this->log($e->getMessage());
		} catch(Exception $e) {
			$this->log($e->getMessage());
		}

		return $sent;
	}

	/**
	 * Get emails as CSV string from given $mail variable
	 *
	 * @param array $emails
	 * @return string
	 *
	 */
	protected function getEmails(array $emails) {
		$items = [];
		foreach($emails as $email => $name) {
			if(is_array($name)) {
				foreach(['name', 'toName'] as $key) {
					if(isset($name[$key])) {
						$name = $name[$key];
						break;
					}
				}
				if(is_array($name)) $name = '';
			}
			$items[] = empty($name) ? $email : "{$name} <{$email}>";
		}
		return implode(',', $items);
	}

	/**
	 * Populate recipient variables
	 *
	 * @param string $str
	 * @param array $variables
	 * @return string
	 *
	 */
	protected function populateVariables($str, $variables) {
		foreach($variables as $key => $value) {
			if(!is_array($value)) {
				$str = str_replace('{{' . $key . '}}', $value, $str);
			}
		}
		return $str;
	}

	/**
	 * Set the email CC/BCC address
	 *
	 * Each added email addresses appends to any addresses already supplied, unless
	 * you specify NULL as the email address, in which case it clears them all.
	 *
	 * @param string $type The type of email to set, cc or bcc
	 * @param string|array|null $email Specify any ONE of the following:
	 * - Single email address or 'User Name <user@example.com>' string.
	 * - CSV string of #1.
	 * - Non-associative array of #1.
	 * - Associative array of (email => name)
	 * - NULL (default value, to clear out any previously set values)
	 * @param string $name Optionally provide a TO name, applicable
	 *	only when specifying #1 (single email) for the first argument.
	 * @return WireMail $this
	 * @throws WireException If any provided emails were invalid
	 *
	 */
	protected function setEmail($type, $email = null, $name = null) {

		if(is_null($email)) {
			// Clear existing values
			$this->mail[$type] = [];
			$this->mail["{$type}Name"] = [];
			return $this;
		}

		if(empty($email)) return $this;

		$emails = is_array($email) ? $email : explode(',', $email);
		foreach($emails as $key => $value) {

			$typeName = '';
			if(is_string($key)) {
				// Associative array
				// Email provided as $key, and $typeName as value
				$typeEmail = $key;
				$typeName = $value;
			} else if(strpos($value, '<') !== false && strpos($value, '>') !== false) {
				// Email provided as: 'User Name <user@example.com>'
				list($typeEmail, $typeName) = $this->extractEmailAndName($value);
			} else {
				// Just an email address, possibly with name as a function argument
				$typeEmail = $value;
			}

			if(empty($typeName)) $typeName = $name; // Use function argument if not overwritten
			$typeEmail = $this->sanitizeEmail($typeEmail);
			$this->mail[$type][$typeEmail] = $typeEmail;
			$this->mail["{$type}Name"][$typeEmail] = $this->sanitizeHeader($typeName);
		}

		return $this;
	}

	/**
	 * Get the mime type of a file
	 *
	 * @param string $file
	 * @return string|false
	 *
	 */
	private function getMimeType($file) {

		if(function_exists('mime_content_type')) {

			return mime_content_type($file);

		} else if(function_exists('finfo_open')) {

			$finfo = finfo_open(FILEINFO_MIME);
			$mimeType = finfo_file($finfo, $file);
			finfo_close($finfo);
			return $mimeType;

		} else {

			$mimeTypes = [
				'3dml' => 'text/vnd.in3d.3dml',
				'3g2' => 'video/3gpp2',
				'3gp' => 'video/3gpp',
				'7z' => 'application/x-7z-compressed',
				'aab' => 'application/x-authorware-bin',
				'aac' => 'audio/x-aac',
				'aam' => 'application/x-authorware-map',
				'aas' => 'application/x-authorware-seg',
				'abw' => 'application/x-abiword',
				'ac' => 'application/pkix-attr-cert',
				'acc' => 'application/vnd.americandynamics.acc',
				'ace' => 'application/x-ace-compressed',
				'acu' => 'application/vnd.acucobol',
				'adp' => 'audio/adpcm',
				'aep' => 'application/vnd.audiograph',
				'afp' => 'application/vnd.ibm.modcap',
				'ahead' => 'application/vnd.ahead.space',
				'ai' => 'application/postscript',
				'aif' => 'audio/x-aiff',
				'air' => 'application/vnd.adobe.air-application-installer-package+zip',
				'ait' => 'application/vnd.dvb.ait',
				'ami' => 'application/vnd.amiga.ami',
				'apk' => 'application/vnd.android.package-archive',
				'application' => 'application/x-ms-application',
				'apr' => 'application/vnd.lotus-approach',
				'asf' => 'video/x-ms-asf',
				'aso' => 'application/vnd.accpac.simply.aso',
				'atc' => 'application/vnd.acucorp',
				'atom' => 'application/atom+xml',
				'atomcat' => 'application/atomcat+xml',
				'atomsvc' => 'application/atomsvc+xml',
				'atx' => 'application/vnd.antix.game-component',
				'au' => 'audio/basic',
				'avi' => 'video/x-msvideo',
				'aw' => 'application/applixware',
				'azf' => 'application/vnd.airzip.filesecure.azf',
				'azs' => 'application/vnd.airzip.filesecure.azs',
				'azw' => 'application/vnd.amazon.ebook',
				'bcpio' => 'application/x-bcpio',
				'bdf' => 'application/x-font-bdf',
				'bdm' => 'application/vnd.syncml.dm+wbxml',
				'bed' => 'application/vnd.realvnc.bed',
				'bh2' => 'application/vnd.fujitsu.oasysprs',
				'bin' => 'application/octet-stream',
				'bmi' => 'application/vnd.bmi',
				'bmp' => 'image/bmp',
				'box' => 'application/vnd.previewsystems.box',
				'btif' => 'image/prs.btif',
				'bz' => 'application/x-bzip',
				'bz2' => 'application/x-bzip2',
				'c' => 'text/x-c',
				'c11amc' => 'application/vnd.cluetrust.cartomobile-config',
				'c11amz' => 'application/vnd.cluetrust.cartomobile-config-pkg',
				'c4g' => 'application/vnd.clonk.c4group',
				'cab' => 'application/vnd.ms-cab-compressed',
				'car' => 'application/vnd.curl.car',
				'cat' => 'application/vnd.ms-pki.seccat',
				'ccxml' => 'application/ccxml+xml,',
				'cdbcmsg' => 'application/vnd.contact.cmsg',
				'cdkey' => 'application/vnd.mediastation.cdkey',
				'cdmia' => 'application/cdmi-capability',
				'cdmic' => 'application/cdmi-container',
				'cdmid' => 'application/cdmi-domain',
				'cdmio' => 'application/cdmi-object',
				'cdmiq' => 'application/cdmi-queue',
				'cdx' => 'chemical/x-cdx',
				'cdxml' => 'application/vnd.chemdraw+xml',
				'cdy' => 'application/vnd.cinderella',
				'cer' => 'application/pkix-cert',
				'cgm' => 'image/cgm',
				'chat' => 'application/x-chat',
				'chm' => 'application/vnd.ms-htmlhelp',
				'chrt' => 'application/vnd.kde.kchart',
				'cif' => 'chemical/x-cif',
				'cii' => 'application/vnd.anser-web-certificate-issue-initiation',
				'cil' => 'application/vnd.ms-artgalry',
				'cla' => 'application/vnd.claymore',
				'class' => 'application/java-vm',
				'clkk' => 'application/vnd.crick.clicker.keyboard',
				'clkp' => 'application/vnd.crick.clicker.palette',
				'clkt' => 'application/vnd.crick.clicker.template',
				'clkw' => 'application/vnd.crick.clicker.wordbank',
				'clkx' => 'application/vnd.crick.clicker',
				'clp' => 'application/x-msclip',
				'cmc' => 'application/vnd.cosmocaller',
				'cmdf' => 'chemical/x-cmdf',
				'cml' => 'chemical/x-cml',
				'cmp' => 'application/vnd.yellowriver-custom-menu',
				'cmx' => 'image/x-cmx',
				'cod' => 'application/vnd.rim.cod',
				'cpio' => 'application/x-cpio',
				'cpt' => 'application/mac-compactpro',
				'crd' => 'application/x-mscardfile',
				'crl' => 'application/pkix-crl',
				'cryptonote' => 'application/vnd.rig.cryptonote',
				'csh' => 'application/x-csh',
				'csml' => 'chemical/x-csml',
				'csp' => 'application/vnd.commonspace',
				'css' => 'text/css',
				'csv' => 'text/csv',
				'cu' => 'application/cu-seeme',
				'curl' => 'text/vnd.curl',
				'cww' => 'application/prs.cww',
				'dae' => 'model/vnd.collada+xml',
				'daf' => 'application/vnd.mobius.daf',
				'davmount' => 'application/davmount+xml',
				'dcurl' => 'text/vnd.curl.dcurl',
				'dd2' => 'application/vnd.oma.dd2+xml',
				'ddd' => 'application/vnd.fujixerox.ddd',
				'deb' => 'application/x-debian-package',
				'der' => 'application/x-x509-ca-cert',
				'dfac' => 'application/vnd.dreamfactory',
				'dir' => 'application/x-director',
				'dis' => 'application/vnd.mobius.dis',
				'djvu' => 'image/vnd.djvu',
				'dna' => 'application/vnd.dna',
				'doc' => 'application/msword',
				'docm' => 'application/vnd.ms-word.document.macroenabled.12',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'dotm' => 'application/vnd.ms-word.template.macroenabled.12',
				'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
				'dp' => 'application/vnd.osgi.dp',
				'dpg' => 'application/vnd.dpgraph',
				'dra' => 'audio/vnd.dra',
				'dsc' => 'text/prs.lines.tag',
				'dssc' => 'application/dssc+der',
				'dtb' => 'application/x-dtbook+xml',
				'dtd' => 'application/xml-dtd',
				'dts' => 'audio/vnd.dts',
				'dtshd' => 'audio/vnd.dts.hd',
				'dvi' => 'application/x-dvi',
				'dwf' => 'model/vnd.dwf',
				'dwg' => 'image/vnd.dwg',
				'dxf' => 'image/vnd.dxf',
				'dxp' => 'application/vnd.spotfire.dxp',
				'ecelp4800' => 'audio/vnd.nuera.ecelp4800',
				'ecelp7470' => 'audio/vnd.nuera.ecelp7470',
				'ecelp9600' => 'audio/vnd.nuera.ecelp9600',
				'edm' => 'application/vnd.novadigm.edm',
				'edx' => 'application/vnd.novadigm.edx',
				'efif' => 'application/vnd.picsel',
				'ei6' => 'application/vnd.pg.osasli',
				'eml' => 'message/rfc822',
				'emma' => 'application/emma+xml',
				'eol' => 'audio/vnd.digital-winds',
				'eot' => 'application/vnd.ms-fontobject',
				'epub' => 'application/epub+zip',
				'es' => 'application/ecmascript',
				'es3' => 'application/vnd.eszigno3+xml',
				'esf' => 'application/vnd.epson.esf',
				'etx' => 'text/x-setext',
				'exe' => 'application/x-msdownload',
				'exi' => 'application/exi',
				'ext' => 'application/vnd.novadigm.ext',
				'ez2' => 'application/vnd.ezpix-album',
				'ez3' => 'application/vnd.ezpix-package',
				'f' => 'text/x-fortran',
				'f4v' => 'video/x-f4v',
				'fbs' => 'image/vnd.fastbidsheet',
				'fcs' => 'application/vnd.isac.fcs',
				'fdf' => 'application/vnd.fdf',
				'fe_launch' => 'application/vnd.denovo.fcselayout-link',
				'fg5' => 'application/vnd.fujitsu.oasysgp',
				'fh' => 'image/x-freehand',
				'fig' => 'application/x-xfig',
				'fli' => 'video/x-fli',
				'flo' => 'application/vnd.micrografx.flo',
				'flv' => 'video/x-flv',
				'flw' => 'application/vnd.kde.kivio',
				'flx' => 'text/vnd.fmi.flexstor',
				'fly' => 'text/vnd.fly',
				'fm' => 'application/vnd.framemaker',
				'fnc' => 'application/vnd.frogans.fnc',
				'fpx' => 'image/vnd.fpx',
				'fsc' => 'application/vnd.fsc.weblaunch',
				'fst' => 'image/vnd.fst',
				'ftc' => 'application/vnd.fluxtime.clip',
				'fti' => 'application/vnd.anser-web-funds-transfer-initiation',
				'fvt' => 'video/vnd.fvt',
				'fxp' => 'application/vnd.adobe.fxp',
				'fzs' => 'application/vnd.fuzzysheet',
				'g2w' => 'application/vnd.geoplan',
				'g3' => 'image/g3fax',
				'g3w' => 'application/vnd.geospace',
				'gac' => 'application/vnd.groove-account',
				'gdl' => 'model/vnd.gdl',
				'geo' => 'application/vnd.dynageo',
				'gex' => 'application/vnd.geometry-explorer',
				'ggb' => 'application/vnd.geogebra.file',
				'ggt' => 'application/vnd.geogebra.tool',
				'ghf' => 'application/vnd.groove-help',
				'gif' => 'image/gif',
				'gim' => 'application/vnd.groove-identity-message',
				'gmx' => 'application/vnd.gmx',
				'gnumeric' => 'application/x-gnumeric',
				'gph' => 'application/vnd.flographit',
				'gqf' => 'application/vnd.grafeq',
				'gram' => 'application/srgs',
				'grv' => 'application/vnd.groove-injector',
				'grxml' => 'application/srgs+xml',
				'gsf' => 'application/x-font-ghostscript',
				'gtar' => 'application/x-gtar',
				'gtm' => 'application/vnd.groove-tool-message',
				'gtw' => 'model/vnd.gtw',
				'gv' => 'text/vnd.graphviz',
				'gxt' => 'application/vnd.geonext',
				'h261' => 'video/h261',
				'h263' => 'video/h263',
				'h264' => 'video/h264',
				'hal' => 'application/vnd.hal+xml',
				'hbci' => 'application/vnd.hbci',
				'hdf' => 'application/x-hdf',
				'hlp' => 'application/winhlp',
				'hpgl' => 'application/vnd.hp-hpgl',
				'hpid' => 'application/vnd.hp-hpid',
				'hps' => 'application/vnd.hp-hps',
				'hqx' => 'application/mac-binhex40',
				'htke' => 'application/vnd.kenameaapp',
				'html' => 'text/html',
				'hvd' => 'application/vnd.yamaha.hv-dic',
				'hvp' => 'application/vnd.yamaha.hv-voice',
				'hvs' => 'application/vnd.yamaha.hv-script',
				'i2g' => 'application/vnd.intergeo',
				'icc' => 'application/vnd.iccprofile',
				'ice' => 'x-conference/x-cooltalk',
				'ico' => 'image/x-icon',
				'ics' => 'text/calendar',
				'ief' => 'image/ief',
				'ifm' => 'application/vnd.shana.informed.formdata',
				'igl' => 'application/vnd.igloader',
				'igm' => 'application/vnd.insors.igm',
				'igs' => 'model/iges',
				'igx' => 'application/vnd.micrografx.igx',
				'iif' => 'application/vnd.shana.informed.interchange',
				'imp' => 'application/vnd.accpac.simply.imp',
				'ims' => 'application/vnd.ms-ims',
				'ipfix' => 'application/ipfix',
				'ipk' => 'application/vnd.shana.informed.package',
				'irm' => 'application/vnd.ibm.rights-management',
				'irp' => 'application/vnd.irepository.package+xml',
				'itp' => 'application/vnd.shana.informed.formtemplate',
				'ivp' => 'application/vnd.immervision-ivp',
				'ivu' => 'application/vnd.immervision-ivu',
				'jad' => 'text/vnd.sun.j2me.app-descriptor',
				'jam' => 'application/vnd.jam',
				'jar' => 'application/java-archive',
				'java' => 'text/x-java-source,java',
				'jisp' => 'application/vnd.jisp',
				'jlt' => 'application/vnd.hp-jlyt',
				'jnlp' => 'application/x-java-jnlp-file',
				'joda' => 'application/vnd.joost.joda-archive',
				'jpeg' => 'image/jpeg',
				'jpg' => 'image/jpeg',
				'jpgv' => 'video/jpeg',
				'jpm' => 'video/jpm',
				'js' => 'application/javascript',
				'json' => 'application/json',
				'karbon' => 'application/vnd.kde.karbon',
				'kfo' => 'application/vnd.kde.kformula',
				'kia' => 'application/vnd.kidspiration',
				'kml' => 'application/vnd.google-earth.kml+xml',
				'kmz' => 'application/vnd.google-earth.kmz',
				'kne' => 'application/vnd.kinar',
				'kon' => 'application/vnd.kde.kontour',
				'kpr' => 'application/vnd.kde.kpresenter',
				'ksp' => 'application/vnd.kde.kspread',
				'ktx' => 'image/ktx',
				'ktz' => 'application/vnd.kahootz',
				'kwd' => 'application/vnd.kde.kword',
				'lasxml' => 'application/vnd.las.las+xml',
				'latex' => 'application/x-latex',
				'lbd' => 'application/vnd.llamagraphics.life-balance.desktop',
				'lbe' => 'application/vnd.llamagraphics.life-balance.exchange+xml',
				'les' => 'application/vnd.hhe.lesson-player',
				'link66' => 'application/vnd.route66.link66+xml',
				'lrm' => 'application/vnd.ms-lrm',
				'ltf' => 'application/vnd.frogans.ltf',
				'lvp' => 'audio/vnd.lucent.voice',
				'lwp' => 'application/vnd.lotus-wordpro',
				'm21' => 'application/mp21',
				'm3u' => 'audio/x-mpegurl',
				'm3u8' => 'application/vnd.apple.mpegurl',
				'm4v' => 'video/x-m4v',
				'ma' => 'application/mathematica',
				'mads' => 'application/mads+xml',
				'mag' => 'application/vnd.ecowin.chart',
				'map' => 'application/json',
				'mathml' => 'application/mathml+xml',
				'mbk' => 'application/vnd.mobius.mbk',
				'mbox' => 'application/mbox',
				'mc1' => 'application/vnd.medcalcdata',
				'mcd' => 'application/vnd.mcd',
				'mcurl' => 'text/vnd.curl.mcurl',
				'md' => 'text/x-markdown', // http://bit.ly/1Kc5nUB
				'mdb' => 'application/x-msaccess',
				'mdi' => 'image/vnd.ms-modi',
				'meta4' => 'application/metalink4+xml',
				'mets' => 'application/mets+xml',
				'mfm' => 'application/vnd.mfmp',
				'mgp' => 'application/vnd.osgeo.mapguide.package',
				'mgz' => 'application/vnd.proteus.magazine',
				'mid' => 'audio/midi',
				'mif' => 'application/vnd.mif',
				'mj2' => 'video/mj2',
				'mlp' => 'application/vnd.dolby.mlp',
				'mmd' => 'application/vnd.chipnuts.karaoke-mmd',
				'mmf' => 'application/vnd.smaf',
				'mmr' => 'image/vnd.fujixerox.edmics-mmr',
				'mny' => 'application/x-msmoney',
				'mods' => 'application/mods+xml',
				'movie' => 'video/x-sgi-movie',
				'mp1' => 'audio/mpeg',
				'mp2' => 'audio/mpeg',
				'mp3' => 'audio/mpeg',
				'mp4' => 'video/mp4',
				'mp4a' => 'audio/mp4',
				'mpc' => 'application/vnd.mophun.certificate',
				'mpeg' => 'video/mpeg',
				'mpga' => 'audio/mpeg',
				'mpkg' => 'application/vnd.apple.installer+xml',
				'mpm' => 'application/vnd.blueice.multipass',
				'mpn' => 'application/vnd.mophun.application',
				'mpp' => 'application/vnd.ms-project',
				'mpy' => 'application/vnd.ibm.minipay',
				'mqy' => 'application/vnd.mobius.mqy',
				'mrc' => 'application/marc',
				'mrcx' => 'application/marcxml+xml',
				'mscml' => 'application/mediaservercontrol+xml',
				'mseq' => 'application/vnd.mseq',
				'msf' => 'application/vnd.epson.msf',
				'msh' => 'model/mesh',
				'msl' => 'application/vnd.mobius.msl',
				'msty' => 'application/vnd.muvee.style',
				'mts' => 'model/vnd.mts',
				'mus' => 'application/vnd.musician',
				'musicxml' => 'application/vnd.recordare.musicxml+xml',
				'mvb' => 'application/x-msmediaview',
				'mwf' => 'application/vnd.mfer',
				'mxf' => 'application/mxf',
				'mxl' => 'application/vnd.recordare.musicxml',
				'mxml' => 'application/xv+xml',
				'mxs' => 'application/vnd.triscape.mxs',
				'mxu' => 'video/vnd.mpegurl',
				'n3' => 'text/n3',
				'nbp' => 'application/vnd.wolfram.player',
				'nc' => 'application/x-netcdf',
				'ncx' => 'application/x-dtbncx+xml',
				'n-gage' => 'application/vnd.nokia.n-gage.symbian.install',
				'ngdat' => 'application/vnd.nokia.n-gage.data',
				'nlu' => 'application/vnd.neurolanguage.nlu',
				'nml' => 'application/vnd.enliven',
				'nnd' => 'application/vnd.noblenet-directory',
				'nns' => 'application/vnd.noblenet-sealer',
				'nnw' => 'application/vnd.noblenet-web',
				'npx' => 'image/vnd.net-fpx',
				'nsf' => 'application/vnd.lotus-notes',
				'oa2' => 'application/vnd.fujitsu.oasys2',
				'oa3' => 'application/vnd.fujitsu.oasys3',
				'oas' => 'application/vnd.fujitsu.oasys',
				'obd' => 'application/x-msbinder',
				'oda' => 'application/oda',
				'odb' => 'application/vnd.oasis.opendocument.database',
				'odc' => 'application/vnd.oasis.opendocument.chart',
				'odf' => 'application/vnd.oasis.opendocument.formula',
				'odft' => 'application/vnd.oasis.opendocument.formula-template',
				'odg' => 'application/vnd.oasis.opendocument.graphics',
				'odi' => 'application/vnd.oasis.opendocument.image',
				'odm' => 'application/vnd.oasis.opendocument.text-master',
				'odp' => 'application/vnd.oasis.opendocument.presentation',
				'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
				'odt' => 'application/vnd.oasis.opendocument.text',
				'oga' => 'audio/ogg',
				'ogv' => 'video/ogg',
				'ogx' => 'application/ogg',
				'onetoc' => 'application/onenote',
				'opf' => 'application/oebps-package+xml',
				'org' => 'application/vnd.lotus-organizer',
				'osf' => 'application/vnd.yamaha.openscoreformat',
				'osfpvg' => 'application/vnd.yamaha.openscoreformat.osfpvg+xml',
				'otc' => 'application/vnd.oasis.opendocument.chart-template',
				'otf' => 'application/x-font-otf',
				'otg' => 'application/vnd.oasis.opendocument.graphics-template',
				'oth' => 'application/vnd.oasis.opendocument.text-web',
				'oti' => 'application/vnd.oasis.opendocument.image-template',
				'otp' => 'application/vnd.oasis.opendocument.presentation-template',
				'ots' => 'application/vnd.oasis.opendocument.spreadsheet-template',
				'ott' => 'application/vnd.oasis.opendocument.text-template',
				'oxt' => 'application/vnd.openofficeorg.extension',
				'p' => 'text/x-pascal',
				'p10' => 'application/pkcs10',
				'p12' => 'application/x-pkcs12',
				'p7b' => 'application/x-pkcs7-certificates',
				'p7m' => 'application/pkcs7-mime',
				'p7r' => 'application/x-pkcs7-certreqresp',
				'p7s' => 'application/pkcs7-signature',
				'p8' => 'application/pkcs8',
				'par' => 'text/plain-bas',
				'paw' => 'application/vnd.pawaafile',
				'pbd' => 'application/vnd.powerbuilder6',
				'pbm' => 'image/x-portable-bitmap',
				'pcf' => 'application/x-font-pcf',
				'pcl' => 'application/vnd.hp-pcl',
				'pclxl' => 'application/vnd.hp-pclxl',
				'pcurl' => 'application/vnd.curl.pcurl',
				'pcx' => 'image/x-pcx',
				'pdb' => 'application/vnd.palm',
				'pdf' => 'application/pdf',
				'pfa' => 'application/x-font-type1',
				'pfr' => 'application/font-tdpfr',
				'pgm' => 'image/x-portable-graymap',
				'pgn' => 'application/x-chess-pgn',
				'pgp' => 'application/pgp-signature',
				'pic' => 'image/x-pict',
				'pki' => 'application/pkixcmp',
				'pkipath' => 'application/pkix-pkipath',
				'plb' => 'application/vnd.3gpp.pic-bw-large',
				'plc' => 'application/vnd.mobius.plc',
				'plf' => 'application/vnd.pocketlearn',
				'pls' => 'application/pls+xml',
				'pml' => 'application/vnd.ctc-posml',
				'png' => 'image/png',
				'pnm' => 'image/x-portable-anymap',
				'portpkg' => 'application/vnd.macports.portpkg',
				'potm' => 'application/vnd.ms-powerpoint.template.macroenabled.12',
				'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
				'ppam' => 'application/vnd.ms-powerpoint.addin.macroenabled.12',
				'ppd' => 'application/vnd.cups-ppd',
				'ppm' => 'image/x-portable-pixmap',
				'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroenabled.12',
				'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
				'ppt' => 'application/vnd.ms-powerpoint',
				'pptm' => 'application/vnd.ms-powerpoint.presentation.macroenabled.12',
				'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'prc' => 'application/x-mobipocket-ebook',
				'pre' => 'application/vnd.lotus-freelance',
				'prf' => 'application/pics-rules',
				'psb' => 'application/vnd.3gpp.pic-bw-small',
				'psd' => 'image/vnd.adobe.photoshop',
				'psf' => 'application/x-font-linux-psf',
				'pskcxml' => 'application/pskc+xml',
				'ptid' => 'application/vnd.pvi.ptid1',
				'pub' => 'application/x-mspublisher',
				'pvb' => 'application/vnd.3gpp.pic-bw-var',
				'pwn' => 'application/vnd.3m.post-it-notes',
				'pya' => 'audio/vnd.ms-playready.media.pya',
				'pyv' => 'video/vnd.ms-playready.media.pyv',
				'qam' => 'application/vnd.epson.quickanime',
				'qbo' => 'application/vnd.intu.qbo',
				'qfx' => 'application/vnd.intu.qfx',
				'qps' => 'application/vnd.publishare-delta-tree',
				'qt' => 'video/quicktime',
				'qxd' => 'application/vnd.quark.quarkxpress',
				'ram' => 'audio/x-pn-realaudio',
				'rar' => 'application/x-rar-compressed',
				'ras' => 'image/x-cmu-raster',
				'rcprofile' => 'application/vnd.ipunplugged.rcprofile',
				'rdf' => 'application/rdf+xml',
				'rdz' => 'application/vnd.data-vision.rdz',
				'rep' => 'application/vnd.businessobjects',
				'res' => 'application/x-dtbresource+xml',
				'rgb' => 'image/x-rgb',
				'rif' => 'application/reginfo+xml',
				'rip' => 'audio/vnd.rip',
				'rl' => 'application/resource-lists+xml',
				'rlc' => 'image/vnd.fujixerox.edmics-rlc',
				'rld' => 'application/resource-lists-diff+xml',
				'rm' => 'application/vnd.rn-realmedia',
				'rmp' => 'audio/x-pn-realaudio-plugin',
				'rms' => 'application/vnd.jcp.javame.midlet-rms',
				'rnc' => 'application/relax-ng-compact-syntax',
				'rp9' => 'application/vnd.cloanto.rp9',
				'rpss' => 'application/vnd.nokia.radio-presets',
				'rpst' => 'application/vnd.nokia.radio-preset',
				'rq' => 'application/sparql-query',
				'rs' => 'application/rls-services+xml',
				'rsd' => 'application/rsd+xml',
				'rss' => 'application/rss+xml',
				'rtf' => 'application/rtf',
				'rtx' => 'text/richtext',
				's' => 'text/x-asm',
				'saf' => 'application/vnd.yamaha.smaf-audio',
				'sbml' => 'application/sbml+xml',
				'sc' => 'application/vnd.ibm.secure-container',
				'scd' => 'application/x-msschedule',
				'scm' => 'application/vnd.lotus-screencam',
				'scq' => 'application/scvp-cv-request',
				'scs' => 'application/scvp-cv-response',
				'scurl' => 'text/vnd.curl.scurl',
				'sda' => 'application/vnd.stardivision.draw',
				'sdc' => 'application/vnd.stardivision.calc',
				'sdd' => 'application/vnd.stardivision.impress',
				'sdkm' => 'application/vnd.solent.sdkm+xml',
				'sdp' => 'application/sdp',
				'sdw' => 'application/vnd.stardivision.writer',
				'see' => 'application/vnd.seemail',
				'seed' => 'application/vnd.fdsn.seed',
				'sema' => 'application/vnd.sema',
				'semd' => 'application/vnd.semd',
				'semf' => 'application/vnd.semf',
				'ser' => 'application/java-serialized-object',
				'setpay' => 'application/set-payment-initiation',
				'setreg' => 'application/set-registration-initiation',
				'sfd-hdstx' => 'application/vnd.hydrostatix.sof-data',
				'sfs' => 'application/vnd.spotfire.sfs',
				'sgl' => 'application/vnd.stardivision.writer-global',
				'sgml' => 'text/sgml',
				'sh' => 'application/x-sh',
				'shar' => 'application/x-shar',
				'shf' => 'application/shf+xml',
				'sis' => 'application/vnd.symbian.install',
				'sit' => 'application/x-stuffit',
				'sitx' => 'application/x-stuffitx',
				'skp' => 'application/vnd.koan',
				'sldm' => 'application/vnd.ms-powerpoint.slide.macroenabled.12',
				'sldx' => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
				'slt' => 'application/vnd.epson.salt',
				'sm' => 'application/vnd.stepmania.stepchart',
				'smf' => 'application/vnd.stardivision.math',
				'smi' => 'application/smil+xml',
				'snf' => 'application/x-font-snf',
				'spf' => 'application/vnd.yamaha.smaf-phrase',
				'spl' => 'application/x-futuresplash',
				'spot' => 'text/vnd.in3d.spot',
				'spp' => 'application/scvp-vp-response',
				'spq' => 'application/scvp-vp-request',
				'src' => 'application/x-wais-source',
				'sru' => 'application/sru+xml',
				'srx' => 'application/sparql-results+xml',
				'sse' => 'application/vnd.kodak-descriptor',
				'ssf' => 'application/vnd.epson.ssf',
				'ssml' => 'application/ssml+xml',
				'st' => 'application/vnd.sailingtracker.track',
				'stc' => 'application/vnd.sun.xml.calc.template',
				'std' => 'application/vnd.sun.xml.draw.template',
				'stf' => 'application/vnd.wt.stf',
				'sti' => 'application/vnd.sun.xml.impress.template',
				'stk' => 'application/hyperstudio',
				'stl' => 'application/vnd.ms-pki.stl',
				'str' => 'application/vnd.pg.format',
				'stw' => 'application/vnd.sun.xml.writer.template',
				'sub' => 'image/vnd.dvb.subtitle',
				'sus' => 'application/vnd.sus-calendar',
				'sv4cpio' => 'application/x-sv4cpio',
				'sv4crc' => 'application/x-sv4crc',
				'svc' => 'application/vnd.dvb.service',
				'svd' => 'application/vnd.svd',
				'svg' => 'image/svg+xml',
				'swf' => 'application/x-shockwave-flash',
				'swi' => 'application/vnd.aristanetworks.swi',
				'sxc' => 'application/vnd.sun.xml.calc',
				'sxd' => 'application/vnd.sun.xml.draw',
				'sxg' => 'application/vnd.sun.xml.writer.global',
				'sxi' => 'application/vnd.sun.xml.impress',
				'sxm' => 'application/vnd.sun.xml.math',
				'sxw' => 'application/vnd.sun.xml.writer',
				't' => 'text/troff',
				'tao' => 'application/vnd.tao.intent-module-archive',
				'tar' => 'application/x-tar',
				'tcap' => 'application/vnd.3gpp2.tcap',
				'tcl' => 'application/x-tcl',
				'teacher' => 'application/vnd.smart.teacher',
				'tei' => 'application/tei+xml',
				'tex' => 'application/x-tex',
				'texinfo' => 'application/x-texinfo',
				'tfi' => 'application/thraud+xml',
				'tfm' => 'application/x-tex-tfm',
				'thmx' => 'application/vnd.ms-officetheme',
				'tiff' => 'image/tiff',
				'tmo' => 'application/vnd.tmobile-livetv',
				'torrent' => 'application/x-bittorrent',
				'tpl' => 'application/vnd.groove-tool-template',
				'tpt' => 'application/vnd.trid.tpt',
				'tra' => 'application/vnd.trueapp',
				'trm' => 'application/x-msterminal',
				'tsd' => 'application/timestamped-data',
				'tsv' => 'text/tab-separated-values',
				'ttf' => 'application/x-font-ttf',
				'ttl' => 'text/turtle',
				'twd' => 'application/vnd.simtech-mindmapper',
				'txd' => 'application/vnd.genomatix.tuxedo',
				'txf' => 'application/vnd.mobius.txf',
				'txt' => 'text/plain',
				'ufd' => 'application/vnd.ufdl',
				'umj' => 'application/vnd.umajin',
				'unityweb' => 'application/vnd.unity',
				'uoml' => 'application/vnd.uoml+xml',
				'uri' => 'text/uri-list',
				'ustar' => 'application/x-ustar',
				'utz' => 'application/vnd.uiq.theme',
				'uu' => 'text/x-uuencode',
				'uva' => 'audio/vnd.dece.audio',
				'uvh' => 'video/vnd.dece.hd',
				'uvi' => 'image/vnd.dece.graphic',
				'uvm' => 'video/vnd.dece.mobile',
				'uvp' => 'video/vnd.dece.pd',
				'uvs' => 'video/vnd.dece.sd',
				'uvu' => 'video/vnd.uvvu.mp4',
				'uvv' => 'video/vnd.dece.video',
				'vcd' => 'application/x-cdlink',
				'vcf' => 'text/x-vcard',
				'vcg' => 'application/vnd.groove-vcard',
				'vcs' => 'text/x-vcalendar',
				'vcx' => 'application/vnd.vcx',
				'vis' => 'application/vnd.visionary',
				'viv' => 'video/vnd.vivo',
				'vsd' => 'application/vnd.visio',
				'vsf' => 'application/vnd.vsf',
				'vtu' => 'model/vnd.vtu',
				'vxml' => 'application/voicexml+xml',
				'wad' => 'application/x-doom',
				'wav' => 'audio/x-wav',
				'wax' => 'audio/x-ms-wax',
				'wbmp' => 'image/vnd.wap.wbmp',
				'wbs' => 'application/vnd.criticaltools.wbs+xml',
				'wbxml' => 'application/vnd.wap.wbxml',
				'weba' => 'audio/webm',
				'webm' => 'video/webm',
				'webp' => 'image/webp',
				'wg' => 'application/vnd.pmi.widget',
				'wgt' => 'application/widget',
				'wm' => 'video/x-ms-wm',
				'wma' => 'audio/x-ms-wma',
				'wmd' => 'application/x-ms-wmd',
				'wmf' => 'application/x-msmetafile',
				'wml' => 'text/vnd.wap.wml',
				'wmlc' => 'application/vnd.wap.wmlc',
				'wmls' => 'text/vnd.wap.wmlscript',
				'wmlsc' => 'application/vnd.wap.wmlscriptc',
				'wmv' => 'video/x-ms-wmv',
				'wmx' => 'video/x-ms-wmx',
				'wmz' => 'application/x-ms-wmz',
				'woff' => 'application/x-font-woff',
				'woff2' => 'application/font-woff2',
				'wpd' => 'application/vnd.wordperfect',
				'wpl' => 'application/vnd.ms-wpl',
				'wps' => 'application/vnd.ms-works',
				'wqd' => 'application/vnd.wqd',
				'wri' => 'application/x-mswrite',
				'wrl' => 'model/vrml',
				'wsdl' => 'application/wsdl+xml',
				'wspolicy' => 'application/wspolicy+xml',
				'wtb' => 'application/vnd.webturbo',
				'wvx' => 'video/x-ms-wvx',
				'x3d' => 'application/vnd.hzn-3d-crossword',
				'xap' => 'application/x-silverlight-app',
				'xar' => 'application/vnd.xara',
				'xbap' => 'application/x-ms-xbap',
				'xbd' => 'application/vnd.fujixerox.docuworks.binder',
				'xbm' => 'image/x-xbitmap',
				'xdf' => 'application/xcap-diff+xml',
				'xdm' => 'application/vnd.syncml.dm+xml',
				'xdp' => 'application/vnd.adobe.xdp+xml',
				'xdssc' => 'application/dssc+xml',
				'xdw' => 'application/vnd.fujixerox.docuworks',
				'xenc' => 'application/xenc+xml',
				'xer' => 'application/patch-ops-error+xml',
				'xfdf' => 'application/vnd.adobe.xfdf',
				'xfdl' => 'application/vnd.xfdl',
				'xhtml' => 'application/xhtml+xml',
				'xif' => 'image/vnd.xiff',
				'xlam' => 'application/vnd.ms-excel.addin.macroenabled.12',
				'xls' => 'application/vnd.ms-excel',
				'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroenabled.12',
				'xlsm' => 'application/vnd.ms-excel.sheet.macroenabled.12',
				'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'xltm' => 'application/vnd.ms-excel.template.macroenabled.12',
				'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
				'xml' => 'application/xml',
				'xo' => 'application/vnd.olpc-sugar',
				'xop' => 'application/xop+xml',
				'xpi' => 'application/x-xpinstall',
				'xpm' => 'image/x-xpixmap',
				'xpr' => 'application/vnd.is-xpr',
				'xps' => 'application/vnd.ms-xpsdocument',
				'xpw' => 'application/vnd.intercon.formnet',
				'xslt' => 'application/xslt+xml',
				'xsm' => 'application/vnd.syncml+xml',
				'xspf' => 'application/xspf+xml',
				'xul' => 'application/vnd.mozilla.xul+xml',
				'xwd' => 'image/x-xwindowdump',
				'xyz' => 'chemical/x-xyz',
				'yaml' => 'text/yaml',
				'yang' => 'application/yang',
				'yin' => 'application/yin+xml',
				'zaz' => 'application/vnd.zzazz.deck+xml',
				'zip' => 'application/zip',
				'zir' => 'application/vnd.zul',
				'zmm' => 'application/vnd.handheld-entertainment+xml',
			];

			$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
			if(isset($mimeTypes[$ext])) {
				return $mimeTypes[$ext];
			} else {
				$this->log(sprintf($this->_('Mime type could not be found for %s.'), $file));
				return false;
			}
		}
	}
}
