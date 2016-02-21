<?php

namespace ride\web\rest\jsonapi\processor;

use ride\library\orm\definition\field\ModelField;
use ride\library\orm\entry\Entry;
use ride\library\orm\model\Model;

/**
 * Field processor for the ORM JsonAPI
 */
interface FieldProcessor {

    /**
     * Processes the incoming value before setting it to the entry
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @param mixed $value Incoming value
     * @return mixed Value to set on the entry
     */
    public function processInputValue(Model $model, ModelField $field, Entry $entry, $value);

}
