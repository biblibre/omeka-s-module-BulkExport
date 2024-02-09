<?php declare(strict_types=1);

namespace BulkExport\Writer;

use BulkExport\Form\Writer\TextWriterConfigForm;
use BulkExport\Traits\OpenDocumentTextTemplateTrait;
use PhpOffice\PhpWord;

class OpenDocumentTextWriter extends AbstractFieldsWriter
{
    use OpenDocumentTextTemplateTrait;

    protected $label = 'OpenDocument Text'; // @translate
    protected $extension = 'odt';
    protected $mediaType = 'application/vnd.oasis.opendocument.text';
    protected $configFormClass = TextWriterConfigForm::class;
    protected $paramsFormClass = TextWriterConfigForm::class;

    public function isValid(): bool
    {
        if (!extension_loaded('zip') || !extension_loaded('xml')) {
            $this->lastErrorMessage = sprintf(
                'To process export of "%s", the php extensions "zip" and "xml" are required.', // @translate
                $this->getLabel()
            );
            return false;
        }
        return parent::isValid();
    }

    protected function initializeOutput()
    {
        $this->initializeOpenDocumentText();
        return $this;
    }

    protected function finalizeOutput()
    {
        $objWriter = PhpWord\IOFactory::createWriter($this->openDocument, 'ODText');
        $objWriter->save($this->filepath);
        return $this;
    }
}
