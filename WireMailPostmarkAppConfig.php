<?php namespace ProcessWire;

/**
 * WireMail Postmark Configuration
 *
 */

class WireMailPostmarkAppConfig extends ModuleConfig {

	/**
	 * Returns default values for module variables
	 *
	 * @return array
	 *
	 */
	public function getDefaults() {
		return [
			'trackOpens' => 1,
			'trackLinks' => 1,
		];
	}

	/**
	 * Returns inputs for module configuration
	 *
	 * @return InputfieldWrapper
	 *
	 */
	public function getInputfields() {

		$inputfields = parent::getInputfields();

		$inputfields->add([
			'type' => 'text',
			'name' => 'serverToken',
			'label' => $this->_('Server API token'),
			'required' => true,
			'columnWidth' => 50,
			'icon' => 'key',
		]);

		$inputfields->add([
			'type' => 'text',
			'name' => 'senderSignature',
			'label' => $this->_('Sender Signature'),
			'required' => true,
			'columnWidth' => 50,
			'icon' => 'pencil-square-o',
		]);

		$inputfields->add([
			'type' => 'toggle',
			'name' => 'trackOpens',
			'label' => $this->_('Track opens?'),
			'columnWidth' => 50,
			'icon' => 'eye',
		]);

		$inputfields->add([
			'type' => 'toggle',
			'name' => 'trackLinks',
			'label' => $this->_('Track links?'),
			'columnWidth' => 50,
			'icon' => 'mouse-pointer',
		]);

		return $inputfields;
	}
}
