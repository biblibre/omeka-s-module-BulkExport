<?php declare(strict_types=1);

namespace BulkExport\Writer;

use BulkExport\Form\Writer\SpreadsheetWriterConfigForm;
use OpenSpout\Common\Type;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

class OpenDocumentSpreadsheetWriter extends AbstractSpreadsheetWriter
{
    protected $label = 'OpenDocument Spreadsheet'; // @translate
    protected $extension = 'ods';
    protected $mediaType = 'application/vnd.oasis.opendocument.spreadsheet';
    protected $configFormClass = SpreadsheetWriterConfigForm::class;
    protected $paramsFormClass = SpreadsheetWriterConfigForm::class;

    protected $configKeys = [
        'separator',
        'format_fields',
        'format_generic',
        'format_resource',
        'format_resource_property',
        'format_uri',
        'language',
        'resource_types',
        'metadata',
        'metadata_exclude',
    ];

    protected $paramsKeys = [
        'separator',
        'format_fields',
        'format_generic',
        'format_resource',
        'format_resource_property',
        'format_uri',
        'language',
        'resource_types',
        'metadata',
        'metadata_exclude',
        'query',
    ];

    protected $spreadsheetType = Type::ODS;

    public function isValid(): bool
    {
        if (!extension_loaded('zip') || !extension_loaded('xml')) {
            $this->lastErrorMessage = sprintf(
                'To process export of "%s", the php extensions "zip" and "xml" are required.',
                $this->getLabel()
            );
            return false;
        }

        $config = $this->getServiceLocator()->get('Config');
        $tempDir = $config['temp_dir'] ?: sys_get_temp_dir();
        $tempDir = $this->checkDestinationDir($tempDir);
        if (!$tempDir) {
            $this->lastErrorMessage = sprintf(
                'The temporary folder "%s" does not exist or is not writeable.',
                $tempDir
            );
            return false;
        }

        return parent::isValid();
    }

    protected function initializeOutput()
    {
        $config = $this->getServiceLocator()->get('Config');
        $tempDir = $config['temp_dir'] ?: sys_get_temp_dir();
        $this->spreadsheetWriter = WriterEntityFactory::createODSWriter();
        $this->spreadsheetWriter
            ->setTempFolder($tempDir)
            ->openToFile($this->filepath);
        return $this;
    }
}
