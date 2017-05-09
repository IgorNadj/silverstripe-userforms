<?php

/**
 * @package userforms
 */
class UserForm extends Form
{

	/**
	 * @param Controller $controller
	 * @param string $name
	 */
    public function __construct(Controller $controller, $name = 'Form')
    {
		$this->controller = $controller;
		$this->setRedirectToFormOnValidationError(true);

		parent::__construct(
			$controller,
			$name,
			new FieldList(),
			new FieldList()
		);

		$this->setFields($fields = $this->getFormFields());
		$fields->setForm($this);
		$this->setActions($actions = $this->getFormActions());
		$actions->setForm($this);

		$requiredFields = $this->getRequiredFields();
//		$this->requiredFieldsByRules($requiredFields, $this->request->postVars());
		$this->setValidator($requiredFields);

        // This needs to be re-evaluated since fields have been assigned
        $this->setupFormErrors();

		// Number each page
		$stepNumber = 1;
		foreach($this->getSteps() as $step) {
			$step->setStepNumber($stepNumber++);
		}

		if($controller->DisableCsrfSecurityToken) {
			$this->disableSecurityToken();
		}

		$data = Session::get("FormInfo.{$this->FormName()}.data");

		if(is_array($data)) {
			$this->loadDataFrom($data);
		}



		$this->extend('updateForm');

//		$err = Session::get_all();
//		die('watd<pre> '."FormInfo.{$this->FormName()}.errors".print_r($err,true));

//		die('validator <pre>'.print_r($this->getValidator(),true));
//
//		die('y');
	}

    public function setupFormErrors()
    {
        // Suppress setupFormErrors if fields haven't been bootstrapped
        if ($this->fields && $this->fields->exists()) {
            return parent::setupFormErrors();
        }

        return $this;
    }

	/**
	 * Used for partial caching in the template.
	 *
	 * @return string
	 */
    public function getLastEdited()
    {
		return $this->controller->LastEdited;
	}

	/**
	 * @return bool
	 */
    public function getDisplayErrorMessagesAtTop()
    {
		return (bool)$this->controller->DisplayErrorMessagesAtTop;
	}

	/**
	 * Return the fieldlist, filtered to only contain steps
	 *
	 * @return ArrayList
	 */
    public function getSteps()
    {
		return $this->Fields()->filterByCallback(function($field) {
			return $field instanceof UserFormsStepField;
		});
	}

	/**
	 * Get the form fields for the form on this page. Can modify this FieldSet
	 * by using {@link updateFormFields()} on an {@link Extension} subclass which
	 * is applied to this controller.
	 *
	 * This will be a list of top level composite steps
	 *
	 * @return FieldList
	 */
    public function getFormFields()
    {
		$fields = new UserFormsFieldList();
		$target = $fields;
		foreach ($this->controller->Fields() as $field) {
			$target = $target->processNext($field);
		}
		$fields->clearEmptySteps();
		$this->extend('updateFormFields', $fields);
        $fields->setForm($this);
		return $fields;
	}

	/**
	 * Generate the form actions for the UserDefinedForm. You
	 * can manipulate these by using {@link updateFormActions()} on
	 * a decorator.
	 *
	 * @todo Make form actions editable via their own field editor.
	 *
	 * @return FieldList
	 */
    public function getFormActions()
    {
		$submitText = ($this->controller->SubmitButtonText) ? $this->controller->SubmitButtonText : _t('UserDefinedForm.SUBMITBUTTON', 'Submit');
		$clearText = ($this->controller->ClearButtonText) ? $this->controller->ClearButtonText : _t('UserDefinedForm.CLEARBUTTON', 'Clear');

		$actions = new FieldList(
			new FormAction("process", $submitText)
		);

		if($this->controller->ShowClearButton) {
			$actions->push(new ResetFormAction("clearForm", $clearText));
		}

		$this->extend('updateFormActions', $actions);
        $actions->setForm($this);
		return $actions;
	}

	/**
	 * Get the required form fields for this form.
	 *
	 * @return RequiredFields
	 */
    public function getRequiredFields()
    {
		// Generate required field validator
		$requiredNames = $this
            ->getController()
			->Fields()
			->filter('Required', true)
			->column('Name');
		$required = new RequiredFields($requiredNames);
		$this->extend('updateRequiredFields', $required);
        $required->setForm($this);
		return $required;
	}

	/**
	 * Method will checks all required fields within post data, and check did it
	 * need to remove field from required fields if field rules (dependencies) are negative.
	 *
	 * @param RequiredFields $requiredFields Set required fields
	 * @param array          $post $_POST data
	 *
	 * @return bool | returns false when no post array are given and true when scripts end.
	 */
	public function requiredFieldsByRules(RequiredFields &$requiredFields, array $post = []) {
		if(count($post) <= 0) return false;

		foreach($requiredFields->getRequired() as $name) {
			if(!isset($post[$name]) || empty($post[$name])) {
				// field is required, but is not set or empty
				// check the rules expression, maybe this field is hidden
				if(($fields = $this->Fields()->filter('Name', $name)) && $fields->exists()) {
					/** @var EditableFormField $field */
					$field = $fields->first();
					$remove = false;

					foreach($field->CustomRules() as $rule) {
						$rule = $rule->toMap();
						$remove = (string) $rule['Display'] === 'Show' ? false : true;
						$expectedValue = $rule['Value'];
						$conditionFieldName = $rule['ConditionField'];
						$postConditionFieldValue = isset($post[$conditionFieldName]) ? $post[$conditionFieldName] : '';
						$conditionField = !empty($conditionFieldName) ? $this->Fields()->filter('Name', $conditionFieldName) : null;

						if(!is_null($conditionField) && $conditionField->exists()) {
							/** @var EditableFormField $conditionField */
							$conditionField = $conditionField->first();
						}

						// todo: improve with radio, checkbox, and checkbox group inputs
						switch($rule['ConditionOption']) {
							case 'IsNotBlank':

								if(empty($postConditionFieldValue)) {
									$remove = !$remove;
								}

								break;
							case 'IsBlank':

								if(!empty($postConditionFieldValue)) {
									$remove = !$remove;
								}

								break;

							case 'HasValue':

								if($postConditionFieldValue != $expectedValue) {
									$remove = !$remove;
								}

								break;

							case 'ValueLessThan':

								if((float) $postConditionFieldValue >= (float) $expectedValue) {
									$remove = !$remove;
								}

								break;

							case 'ValueLessThanEqual':

								if((float) $postConditionFieldValue > (float) $expectedValue) {
									$remove = !$remove;
								}

								break;

							case 'ValueGreaterThan':

								if((float) $postConditionFieldValue <= (float) $expectedValue) {
									$remove = !$remove;
								}

								break;

							case 'ValueGreaterThanEqual':

								if((float) $postConditionFieldValue < (float) $expectedValue) {
									$remove = !$remove;
								}

								break;

							default: // ==HasNotValue

								if($postConditionFieldValue == $expectedValue) {
									$remove = !$remove;
								}

								break;
						}
					}

					if($remove) {
						// remove field from required fields
						$requiredFields->removeRequiredField($name);
					}
				}
			}
		}

		return true;
	}

	/**
	 * Override some we can add UserForm specific attributes to the form.
	 *
	 * @return array
	 */
    public function getAttributes()
    {
		$attrs = parent::getAttributes();

		$attrs['class'] = $attrs['class'] . ' userform';
		$attrs['data-livevalidation'] = (bool)$this->controller->EnableLiveValidation;
		$attrs['data-toperrors'] = (bool)$this->controller->DisplayErrorMessagesAtTop;
		$attrs['data-hidefieldlabels'] = (bool)$this->controller->HideFieldLabels;

		return $attrs;
	}

    /**
     * @return string
     */
    public function getButtonText() {
        return $this->config()->get('button_text');
    }

}
