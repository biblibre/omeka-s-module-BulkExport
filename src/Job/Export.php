<?php
namespace BulkExport\Job;

use BulkExport\Interfaces\Configurable;
use BulkExport\Interfaces\Parametrizable;
use BulkExport\Processor\Manager as ProcessorManager;
use BulkExport\Writer\Manager as WriterManager;
use Log\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;
use Zend\Log\Logger;

class Export extends AbstractJob
{
    protected $export;

    /**
     * @var \Zend\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    public function perform()
    {
        ini_set('auto_detect_line_endings', true);

        $logger = $this->getLogger();
        $export = $this->getExport();
        $this->api()->update('bulk_exports', $export->id(), ['o:job' => $this->job], [], ['isPartial' => true]);
        $writer = $this->getWriter();
        $processor = $this->getProcessor();
        $processor->setWriter($writer);
        $processor->setLogger($logger);

        $logger->log(Logger::NOTICE, 'Export started'); // @translate

        $processor->process();

        $logger->log(Logger::NOTICE, 'Export completed'); // @translate
    }

    /**
     * Get the logger for the bulk process (the Omeka one, with reference id).
     *
     * @return \Zend\Log\Logger
     */
    protected function getLogger()
    {
        if ($this->logger) {
            return $this->logger;
        }
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');
        $referenceIdProcessor = new \Zend\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/export/' . $this->getExport()->id());
        $this->logger->addProcessor($referenceIdProcessor);
        return $this->logger;
    }

    /**
     * @return \Omeka\Api\Manager
     */
    protected function api()
    {
        if (!$this->api) {
            $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        }
        return $this->api;
    }

    /**
     * @return \BulkExport\Api\Representation\ExportRepresentation|null
     */
    protected function getExport()
    {
        if ($this->export) {
            return $this->export;
        }

        $id = $this->getArg('export_id');
        if ($id) {
            $content = $this->api()->search('bulk_exports', ['id' => $id, 'limit' => 1])->getContent();
            $this->export = is_array($content) && count($content) ? reset($content) : null;
        }

        if (empty($this->export)) {
            // TODO Avoid the useless trace in the log for jobs.
            throw new \Omeka\Job\Exception\InvalidArgumentException('Export record does not exist'); // @translate
        }

        return $this->export;
    }

    protected function getWriter()
    {
        $services = $this->getServiceLocator();
        $export = $this->getExport();
        $exporter = $export->exporter();
        $writerClass = $exporter->writerClass();
        $writerManager = $services->get(WriterManager::class);
        if (!$writerManager->has($writerClass)) {
            throw new \Omeka\Job\Exception\InvalidArgumentException(
                new PsrMessage(
                    'Writer "{writer}" is not available.', // @translate
                    ['writer' => $writerClass]
                )
            );
        }
        $writer = $writerManager->get($writerClass);
        $writer->setServiceLocator($services);
        if ($writer instanceof Configurable && $writer instanceof Parametrizable) {
            $writer->setConfig($exporter->writerConfig());
            $writer->setParams($export->writerParams());
        }
        return $writer;
    }

    protected function getProcessor()
    {
        $services = $this->getServiceLocator();
        $export = $this->getExport();
        $exporter = $export->exporter();
        $processorClass = $exporter->processorClass();
        $processorManager = $services->get(ProcessorManager::class);
        if (!$processorManager->has($processorClass)) {
            throw new \Omeka\Job\Exception\InvalidArgumentException(
                new PsrMessage(
                    'Processor "{processor}" is not available.', // @translate
                    ['processor' => $processorClass]
                )
            );
        }
        $processor = $processorManager->get($processorClass);
        $processor->setServiceLocator($services);
        if ($processor instanceof Configurable && $processor instanceof Parametrizable) {
            $processor->setConfig($exporter->processorConfig());
            $processor->setParams($export->processorParams());
        }
        return $processor;
    }
}
