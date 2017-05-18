<?php namespace JanVince\SmallContactForm\Components;

use Cms\Classes\ComponentBase;
use JanVince\SmallContactForm\Models\Settings;
use JanVince\SmallContactForm\Models\Message;

use Validator;
use Illuminate\Support\MessageBag;
use Redirect;
use Request;
use Input;
use Session;
use Flash;
use Form;

class SmallContactForm extends ComponentBase
{

	private $validationRules;
	private $validationMessages;

	private $postData = [];
	private $post;

	private $errorAutofocus;


    public function componentDetails()
    {
        return [
            'name'        => 'janvince.smallcontactform::lang.controller.contact_form.name',
            'description' => 'janvince.smallcontactform::lang.controller.contact_form.description'
        ];
    }

	public function onRun(){

		// Inject CSS assets if required
		if(Settings::get('add_assets') && Settings::get('add_css_assets')){
			$this->addCss('/modules/system/assets/css/framework.extras.css');
			$this->addCss('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css');
		}

		// Inject JS assets if required
		if(Settings::get('add_assets') && Settings::get('add_js_assets')){
			$this->addJs('https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js');
			$this->addJs('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js');
			$this->addJs('/modules/system/assets/js/framework.js');
			$this->addJs('/modules/system/assets/js/framework.extras.js');
		}

	}

	/**
	 * Form handler
	 */
	public function onFormSend(){

		/**
		 * Validation
		 */
		$this->setFieldsValidationRules();

		$this->post = Input::all();

		// Antispam validation if allowed
		if( Settings::get('add_antispam') ) {
			$this->validationRules['_protect'] = 'size:0';

			if( !empty($this->post['_form_created']) ) {

				$delay = ( Settings::get('antispam_delay') ? intval(Settings::get('antispam_delay')) : intval(e(trans('janvince.smallcontactform::lang.settings.antispam.antispam_delay_placeholder'))) );

				$formCreatedTime = strtr(Input::get('_form_created'), 'jihgfedcba', '0123456789');

				$this->post['_form_created'] = intval($formCreatedTime + $delay);

				$this->validationRules['_form_created'] = 'numeric|max:' . time();

			}

		}

		// Validate
		$validator = Validator::make($this->post, $this->validationRules, $this->validationMessages);
		$validator->valid();
		$this->validationMessages = $validator->messages();
		$this->setPostData($validator->messages());

		if($validator->invalid()){

			$errors = [];

			// Form main error msg
			$errors[] = ( Settings::get('form_error_msg') ? Settings::get('form_error_msg') : e(trans('janvince.smallcontactform::lang.settings.form.error_msg_placeholder')));

			// validation error msg for Antispam field
			if( empty($this->postData['_protect']['error']) && !empty($this->postData['_form_created']['error']) ) {
				$errors[] = ( Settings::get('antispam_delay_error_msg') ? Settings::get('antispam_delay_error_msg') : e(trans('janvince.smallcontactform::lang.settings.antispam.antispam_delay_error_msg_placeholder')));
			}

			Flash::error(implode(PHP_EOL, $errors));

		} else {

			Flash::success(
				( Settings::get('form_success_msg') ? Settings::get('form_success_msg') : e(trans('janvince.smallcontactform::lang.settings.form.success_msg_placeholder')) )
			);

			Session::flash('flashSuccess', true);

			$message = new Message;

			// Store data in DB
			$message->storeFormData($this->postData);

			// Send auto reply
			$message->sendAutoreplyEmail($this->postData);

			// Send notification
			$message->sendNotificationEmail($this->postData);

			// Redirect to prevent repeated sending of form
			// Clear data after success AJAX send
			if(!Request::ajax()){
				return Redirect::refresh();
			} else {
				$this->post = [];
				$this->postData = [];
			}

		}

	}

	/**
	 * Get plugin settings
	 * Twig access: contactForm.fields
	 * @return array
	 */
	public function fields(){

		return Settings::get('form_fields', []);

	}

	/**
	 * Get form attributes
	 */
	public function getFormAttributes(){

		$attributes = [];

		$attributes['class'] = Settings::get('form_css_class');
		$attributes['request'] = $this->alias . '::onFormSend';
		$attributes['method'] = 'POST';

		if( Settings::get('form_allow_ajax', 0) ) {

			$attributes['data-request'] = $this->alias . '::onFormSend';
			$attributes['data-request-validate'] = NULL;
			$attributes['data-request-update'] = "'". $this->alias ."::scf-message':'#scf-message','". $this->alias ."::scf-form':'#scf-form'";

		}

		if( Settings::get('form_send_confirm_msg') and Settings::get('form_allow_confirm_msg') ) {

			$attributes['data-request-confirm'] = Settings::get('form_send_confirm_msg');

		}

		return $attributes;

	}

	/**
	 * Generate field HTML code
	 * @return string
	 */
	public function getFieldHtmlCode(array $fieldSettings){

		if(empty($fieldSettings['name']) && empty($fieldSettings['type'])){
			return NULL;
		}

		$fieldType = Settings::getFieldTypes($fieldSettings['type']);
		$fieldRequired = $this->isFieldRequired($fieldSettings);

		$output = [];

		$wrapperCss = ( $fieldSettings['wrapper_css'] ? $fieldSettings['wrapper_css'] : e(trans('janvince.smallcontactform::lang.settings.form_fields.wrapper_css_placeholder')) );

		// Add wrapper error class if there are any
		if(!empty($this->postData[$fieldSettings['name']]['error'])){
			$wrapperCss .= ' has-error';
		}

		$output[] = '<div class="' . $wrapperCss . '">';

			// Label
			if(!empty($fieldSettings['label'])){
				$output[] = '<label class="control-label ' . ( $fieldRequired ? 'required' : '' ) . '" for="' . $fieldSettings['name'] . '">' . $fieldSettings['label'] . '</label>';
			}

			// Add help-block if there are errors
			if(!empty($this->postData[$fieldSettings['name']]['error'])){
				$output[] = '<small class="help-block">' . $this->postData[$fieldSettings['name']]['error'] . "</small>";
			}

			// Field attributes
			$attributes = [
				'id' => $fieldSettings['name'],
				'name' => $fieldSettings['name'],
				'class' => ($fieldSettings['field_css'] ? $fieldSettings['field_css'] : e(trans('janvince.smallcontactform::lang.settings.form_fields.field_css_placeholder')) ),
				'value' => (!empty($this->postData[$fieldSettings['name']]['value']) && empty($fieldType['html_close']) ? $this->postData[$fieldSettings['name']]['value'] : '' ),
			];

			// Autofocus only when no error
			if(!empty($fieldSettings['autofocus']) && !Flash::error()){
				$attributes['autofocus'] = NULL;
			}

			// Add custom attributes from field settings
			if(!empty($fieldType['attributes'])){
				$attributes = array_merge($attributes, $fieldType['attributes']);
			}

			// Add error class if there are any and autofocus field
			if(!empty($this->postData[$fieldSettings['name']]['error'])){
				$attributes['class'] = $attributes['class'] . ' error';

				if(empty($this->errorAutofocus)){
					$attributes['autofocus'] = NULL;
					$this->errorAutofocus = true;
				}

			}

			if($fieldRequired){
				$attributes['required'] = NULL;
			}


			$output[] = '<' . $fieldType['html_open'] . ' ' . $this->formatAttributes($attributes) . '>';

			// For pair tags insert value between
			if(!empty($this->postData[$fieldSettings['name']]['value']) && !empty($fieldType['html_close'])){
				$output[] = $this->postData[$fieldSettings['name']]['value'];
			}

			if(!empty($fieldType['html_close'])){
				$output[] = '</' . $fieldType['html_close'] . '>';
			}

		$output[] = "</div>";

		return(implode('', $output));

	}

	/**
	 * Generate antispam field HTML code
	 * @return string
	 */
	public function getAntispamFieldHtmlCode(){

		if( !Settings::get('add_antispam') ){
			return NULL;
		}

		$output = [];

		$output[] = '<div id="_protect-wrapper" class="form-group ' . (Input::get('_protect') ? 'has-error' : '') . '">';

			$output[] = '<label class="control-label">' . ( Settings::get('antispam_label') ? Settings::get('antispam_label') : e(trans('janvince.smallcontactform::lang.settings.antispam.antispam_label_placeholder'))  ) . '</label>';

			$output[] = '<input type="hidden" name="_form_created" value="' . strtr(time(), '0123456789', 'jihgfedcba') . '">';

			// Add help-block if there are errors
			if(!empty($this->postData['_protect']['error'])){
				$output[] = '<small class="help-block">' . ( Settings::get('antispam_error_msg') ? Settings::get('antispam_error_msg') : e(trans('janvince.smallcontactform::lang.settings.antispam.antispam_error_msg_placeholder'))  ) . "</small>";
			}

			// Field attributes
			$attributes = [
				'id' => '_protect',
				'name' => '_protect',
				'class' => '_protect form-control',
				'value' => 'http://',
			];

			// Add error class if field is not empty
			if( Input::get('_protect') ){
				$attributes['class'] = $attributes['class'] . ' error';

				if(empty($this->errorAutofocus)){
					$attributes['autofocus'] = NULL;
					$this->errorAutofocus = true;
				}

			}

			$output[] = '<input ' . $this->formatAttributes($attributes) . '>';

		$output[] = "</div>";

		$output[] = "
			<script>
				document.getElementById('_protect').setAttribute('value', '');
				document.getElementById('_protect-wrapper').style.display = 'none';
			</script>
		";

		return(implode('', $output));

	}


	/**
	 * Generate antispam field HTML code
	 * @return string
	 */
	public function getSubmitButtonHtmlCode(){

		if( !count($this->fields()) ){
			return e(trans('janvince.smallcontactform::lang.controller.contact_form.no_fields'));
		}

		$output = [];

		$output[] = '<div id="submit-wrapper" class="form-group">';

			$output[] = '<button type="submit" data-attach-loading class="oc-loader ' . ( Settings::get('send_btn_css_class') ? Settings::get('send_btn_css_class') : e(trans('janvince.smallcontactform::lang.settings.buttons.send_btn_css_class_placeholder')) ) . '">';

			$output[] = ( Settings::get('send_btn_text') ? Settings::get('send_btn_text') : e(trans('janvince.smallcontactform::lang.settings.buttons.send_btn_text_placeholder')) );

			$output[] = '</button>';

		$output[] = "</div>";

		return(implode('', $output));

	}


	/**
	 * Generate validation rules and messages
	 */
	private function setFieldsValidationRules(){

		$fieldsDefinition = $this->fields();

		$validationRules = [];
		$validationMessages = [];

		foreach($fieldsDefinition as $field){

			if(!empty($field['validation'])) {
				$rules = [];

				foreach($field['validation'] as $rule) {
					$rules[] = $rule['validation_type'];

					if(!empty($rule['validation_error'])){
						$validationMessages[($field['name'] . '.' . $rule['validation_type'] )] = $rule['validation_error'];
					}
				}
				$validationRules[$field['name']] = implode('|', $rules);
			}

		}

		$this->validationRules = $validationRules;
		$this->validationMessages = $validationMessages;

	}


	/**
	 * Generate post data with errors
	 */
	private function setPostData(MessageBag $validatorMessages){

		foreach( Input::all() as $key => $value){

			$this->postData[$key] = [
				'value' => e(Input::get($key)),
				'error' => $validatorMessages->first($key),
			];

		}

	}

	/**
	 * Format attributes array
	 * @return array
	 */
	private function formatAttributes(array $attributes) {

		$output = [];

		foreach ($attributes as $key => $value) {
			$output[] = $key . '="' . $value . '"';
		}

		return implode(' ', $output);

	}

	/**
	 * Search for required validation type
	 */
	private function isFieldRequired($fieldSettings){

		if(empty($fieldSettings['validation'])){
			return false;
		}

		foreach($fieldSettings['validation'] as $rule) {
			if(!empty($rule['validation_type']) && $rule['validation_type'] == 'required'){
				return true;
			}
		}

		return false;

	}


}