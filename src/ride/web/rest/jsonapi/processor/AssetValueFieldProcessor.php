<?php

namespace ride\web\rest\jsonapi\processor;

use ride\library\orm\definition\field\ModelField;
use ride\library\orm\entry\Entry;
use ride\library\orm\model\Model;
use ride\library\StringHelper;

/**
 * Field processor of the ORM JsonAPI for the Asset value field
 */
class AssetValueFieldProcessor extends FileFieldProcessor {

    /**
     * Processes the incoming value before setting it to the entry
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @param mixed $value Incoming value
     * @return mixed Value to set on the entry
     */
    public function processInputValue(Model $model, ModelField $field, Entry $entry, $value) {
        if ($value === null || $model->getName() != 'Asset' || $field->getName() != 'value' || $this->isUrl($value)) {
            // no value or not a asset file value
            return $value;
        }

        return $this->handleValue($model, $field, $entry, $value);
    }

    /**
     * Gets the current field value of the entry
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @return mixed Current field value of the entry
     */
    protected function getCurrentValue(Model $model, ModelField $field, Entry $entry) {
        $value = parent::getCurrentValue($model, $field, $entry);
        if ($this->isUrl($value)) {
            // don't create DataURI from URL, pretend we're empty
            return null;
        }

        return $value;
    }

    /**
     * Checks if the provided value is a URL
     * @param string $value Value to check
     * @return boolean
     */
    private function isUrl($value) {
        return StringHelper::startsWith($value, array('http://', 'https://'));
    }

}
