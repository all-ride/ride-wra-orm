<?php

namespace ride\web\rest\jsonapi\processor;

use ride\library\http\HttpFactory;
use ride\library\orm\definition\field\ModelField;
use ride\library\orm\entry\format\EntryFormatter;
use ride\library\orm\entry\Entry;
use ride\library\orm\model\Model;
use ride\library\system\file\browser\FileBrowser;
use ride\library\system\file\File;
use ride\library\StringHelper;

use ride\service\MimeService;

/**
 * File field processor for the ORM JsonAPI
 */
class FileFieldProcessor implements FieldProcessor {

    /**
     * Instance of the HTTP factory
     * @var \ride\library\http\HttpFactory
     */
    protected $httpFactory;

    /**
     * Instance of the MIME service
     * @var \ride\service\MimeService
     */
    protected $mimeService;

    /**
     * Instance of the file browser
     * @var \ride\library\system\file\browser\FileBrowser
     */
    protected $fileBrowser;

    /**
     * Default upload directory
     * @var \ride\library\system\file\File
     */
    protected $uploadDirectory;

    /**
     * Constructs a new file field processor
     * @param \ride\library\http\HttpFactory $httpFactory
     * @param \ride\service\MimeService $mimeService
     * @param \ride\library\system\file\browser\FileBrowser $fileBrowser
     * @param \ride\library\system\file\File $uploadDirectory
     */
    public function __construct(HttpFactory $httpFactory, MimeService $mimeService, FileBrowser $fileBrowser, File $uploadDirectory) {
        $this->httpFactory = $httpFactory;
        $this->mimeService = $mimeService;
        $this->fileBrowser = $fileBrowser;
        $this->uploadDirectory = $uploadDirectory;
    }

    /**
     * Processes the incoming value before setting it to the entry
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @param mixed $value Incoming value
     * @return mixed Value to set on the entry
     */
    public function processInputValue(Model $model, ModelField $field, Entry $entry, $value) {
        if ($value === null || ($field->getType() !== 'file' && $field->getType() !== 'image')) {
            // no value or not a file type
            return $value;
        }

        return $this->handleValue($model, $field, $entry, $value);
    }

    /**
     * Handles the value for potential upload
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @param string $value DataURI of the file
     * @return string Relative path of the uploaded file
     */
    protected function handleValue(Model $model, ModelField $field, Entry $entry, $value) {
        // get current value
        $currentValue = $this->getCurrentValue($model, $field, $entry);
        if ($currentValue) {
            $currentDataUri = $this->getDataUriFromPath($currentValue);
        } else {
            $currentDataUri = null;
        }

        // compare current value with the incoming value
        if ($currentDataUri == $value) {
            return $currentValue;
        }

        return $this->handleUpload($model, $field, $entry, $value);
    }

    /**
     * Handles the upload for the provided value
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @param string $value DataURI of the file
     * @return string Relative path of the uploaded file
     */
    protected function handleUpload(Model $model, ModelField $field, Entry $entry, $value) {
        // new value, create file from incoming DataURI
        $applicationDirectory = $this->fileBrowser->getApplicationDirectory();
        $publicDirectory = $this->fileBrowser->getPublicDirectory();

        $absoluteApplicationDirectory = $applicationDirectory->getAbsolutePath();
        $absolutePublicDirectory = $publicDirectory->getAbsolutePath();

        $uploadDirectory = $field->getOption('upload.path');
        if ($uploadDirectory) {
            $uploadDirectory = str_replace('%application%', $absoluteApplicationDirectory, $uploadDirectory);
            $uploadDirectory = str_replace('%public%', $absolutePublicDirectory, $uploadDirectory);
            $uploadDirectory = $this->fileBrowser->getFileSystem()->getFile($uploadDirectory);
            $uploadDirectory->create();
        } else {
            $uploadDirectory = $this->uploadDirectory;
        }

        $name = $this->getFileName($model, $field, $entry);
        $dataUri = $this->httpFactory->createDataUriFromString($value);
        $extension = $this->mimeService->getExtensionForMediaType($dataUri->getMimeType());

        $uploadFile = $uploadDirectory->getChild($name . '.' . $extension);
        $uploadFile = $uploadFile->getCopyFile();
        $uploadFile->write($dataUri->getData());

        // return relative path of new file
        $path = $uploadFile->getAbsolutePath();
        $path = str_replace($absoluteApplicationDirectory . '/', '', $path);
        $path = str_replace($absolutePublicDirectory . '/', '', $path);

        return $path;
    }

    /**
     * Gets the file name for a newly uploaded file
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @return string Name for the file without path and extension
     */
    protected function getFileName(Model $model, ModelField $field, Entry $entry) {
        $entryFormatter = $model->getOrmManager()->getEntryFormatter();
        $format = $model->getMeta()->getFormat(EntryFormatter::FORMAT_TITLE);

        $name = $entryFormatter->formatEntry($entry, $format);
        if ($name) {
            $name = StringHelper::safeString($name);
        } else {
            $name = StringHelper::generate();
        }

        return $name;
    }

    /**
     * Gets a DataURI of the provided file
     * @param string $path Path of the file
     * @return string|null DataURI of the file
     */
    protected function getDataUriFromPath($path) {
        $file = $this->fileBrowser->getFile($path);
        if (!$file) {
            $file = $this->fileBrowser->getPublicFile($path);
            if (!$file) {
                return null;
            }
        }

        $dataUri = $this->httpFactory->createDataUriFromFile($file);

        return $dataUri->encode();
    }

    /**
     * Gets the current field value of the entry
     * @param \ride\library\orm\model\Model $model
     * @param \ride\library\orm\definition\field\ModelField $field
     * @param \ride\library\orm\entry\Entry $entry
     * @return mixed Current field value of the entry
     */
    protected function getCurrentValue(Model $model, ModelField $field, Entry $entry) {
        return $model->getReflectionHelper()->getProperty($entry, $field->getName());
    }

}
