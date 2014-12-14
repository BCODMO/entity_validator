<?php

class ObjectValidateBase implements ObjectValidateInterface {

  /**
   * @var Array
   * The schema information.
   */
  protected $schema;

  /**
   * @var Array
   * The plugin information.
   */
  protected $plugin;

  /**
   * @var Array
   * List of errors collected in the validation process.
   */
  protected $errors;

  /**
   * @return Array
   */
  public function getPlugin() {
    return $this->plugin;
  }

  /**
   * @param Array $plugin
   */
  public function setPlugin($plugin) {
    $this->plugin = $plugin;
  }

  /**
   * @param Array $schema
   *   The schema information.
   *
   * @return $this
   */
  public function setSchema($schema) {
    $this->schema = $schema;
    return $this;
  }

  /**
   * @return Array
   *   Return the schema information.
   */
  public function getSchema() {
    return $this->schema;
  }

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin) {
    $this->setPlugin($plugin);

    if (empty($this->plugin['schema'])) {
      throw new \EntityValidatorException('Missing schema name.');
    }

    $schema = drupal_get_schema($plugin['schema'], TRUE);
    if (!$schema || !db_table_exists($plugin['schema'])) {
      throw new \EntityValidatorException(format_string('A schema or a definition for @name was not found.', array('@name' => $plugin['schema'])));
    }

    $this->setSchema($schema);
  }

  /**
   * {@inheritdoc}
   */
  public function publicFieldsInfo() {
    $schema = $this->getSchema();
    $fields = array();

    foreach ($schema['fields'] as $field => $info) {
      $fields[$field] = array(
        'type' => $this->getRealType($info['type']),
        'required' => $info['not null'],
        'property' => $field,
      );

      if (!empty($info['size'])) {
        $fields[$field]['size'] = $info['size'];
      }
    }

    return $fields;
  }

  /**
   * Map the type defined in the hook_schema to php data type.
   *
   * @param $type
   *   The type defined in the hook_schema. i.e: serial, int.
   */
  public function getRealType($type) {
    $types = array(
      'blob' => 'unknown',
      'char' => 'unknown',
      'float' => 'float',
      'int' => 'int',
      'numeric' => 'int',
      'serial' => 'int',
      'text' => 'string',
      'varchar' => 'string',
    );

    return $types[$type];
  }

  /**
   * {@inheritdoc}
   */
  public function getPublicFields() {
    $public_fields = $this->publicFieldsInfo();

    foreach ($public_fields as $property => &$public_field) {
      // Adding type validation.
      $public_field['validators'][] = array($this, 'validateType');

      if ($public_field['required']) {
        $public_field['validators'][] = array($this, 'isNotEmpty');
      }
    }

    return $public_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($object, $silent = FALSE) {
    // Clear any previous error messages.
    $this->clearErrors();

    if (!$public_fields = $this->getPublicFields()) {
      return TRUE;
    }

    // Collect the properties callbacks.
    foreach ($public_fields as $public_field) {
      $property = $public_field['property'];

      foreach ($public_field['validators'] as $validator) {
        $value = $object->{$property};

        if ($validator) {
          // Property has value.
          call_user_func($validator, $property, $value);
        }
      }
    }

    if (!$errors = $this->getErrors()) {
      return TRUE;
    }

    if ($silent) {
      // Don't throw an error, just indicate validation failed.
      return FALSE;
    }

    $params = array('@errors' => $errors);
    throw new \EntityValidatorException(format_string('The validation process failed: @errors', $params));
  }

  /**
   * {@inheritdoc}
   */
  public function setError($property, $message, $params = array()) {
    $params['@property'] = $property;
    $this->errors[$property][] = array('message' => $message, 'params' => $params);
  }

  /**
   * {@inheritdoc}
   */
  public function getErrors($squash = TRUE) {
    if (!$squash) {
      return $this->errors;
    }

    $return = array();
    foreach ($this->errors as $errors) {
      foreach ($errors as $error) {
        $error += array('params' => array());
        $return[] = format_string($error['message'], $error['params']);
      }
    }

    return implode("\n\r", $return);
  }

  /**
   * {@inheritdoc}
   */
  public function clearErrors() {
    $this->errors = array();
  }

  /**
   * Verify the field value.
   *
   * @param string $property
   *   The property name.
   * @param mixed $value
   *   The value of the property.
   */
  public function validateType($property, $value) {
    $fields = $this->getPublicFields();

    if (($type = $fields[$property]['type']) == 'unknown') {
      // The field type is unknonw. Don't validate the value.
      return;
    }

    if (!function_exists('is_' . $type)) {
      return;
    }

    if (!call_user_func('is_' . $type, $value)) {
      $params = array(
        '@value' => $value,
        '@property' => $property,
      );
      $this->setError($property, 'The @value is not matching the @property type.', $params);
    }
  }

  /**
   * Verify the field is not empty.
   *
   * @param string $property
   *   The property name.
   * @param mixed $value
   *   The value of the property.
   */
  protected function isNotEmpty($property, $value) {
    if (empty($value)) {
      $params = array('@property' => $property);
      $this->setError($property, 'The field @property cannot be empty.', $params);
    }
  }
}
