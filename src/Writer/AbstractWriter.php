<?php declare(strict_types=1);

namespace BulkExport\Writer;

use BulkExport\Interfaces\Configurable;
use BulkExport\Interfaces\Parametrizable;
use BulkExport\Traits\ConfigurableTrait;
use BulkExport\Traits\ParametrizableTrait;
use BulkExport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Form;
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\AbstractRepresentation;
use Omeka\Job\AbstractJob as Job;

abstract class AbstractWriter implements WriterInterface, Configurable, Parametrizable
{
    use ConfigurableTrait;
    use ParametrizableTrait;
    use ServiceLocatorAwareTrait;

    /**
     * @var string
     */
    protected $label;

    /**
     * @var string
     */
    protected $extension;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Job
     */
    protected $job;

    /**
     * @var string
     */
    protected $mediaType;

    /**
     * @var string
     */
    protected $configFormClass;

    /**
     * @var string
     */
    protected $paramsFormClass;

    /**
     * var array
     */
    protected $configKeys = [];

    /**
     * var array
     */
    protected $paramsKeys = [];

    /**
     * @var string|null
     */
    protected $lastErrorMessage;

    /**
     * @var int
     */
    protected $totalEntries;

    /**
     * @var string
     */
    protected $filepath;

    /**
     * Writer constructor.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->setServiceLocator($services);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getExtension(): ?string
    {
        return $this->extension;
    }

    public function isValid(): bool
    {
        $this->lastErrorMessage = null;
        return true;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLogger(Logger $logger): WriterInterface
    {
        $this->logger = $logger;
        return $this;
    }

    public function setJob(Job $job): WriterInterface
    {
        $this->job = $job;
        return $this;
    }

    public function getConfigFormClass()
    {
        return $this->configFormClass;
    }

    public function handleConfigForm(Form $form)
    {
        $values = $form->getData();
        $config = array_intersect_key($values, array_flip($this->configKeys));
        $this->setConfig($config);
        return $this;
    }

    public function getParamsFormClass()
    {
        return $this->paramsFormClass;
    }

    public function handleParamsForm(Form $form)
    {
        $this->lastErrorMessage = null;
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));
        $this->setParams($params);
        return $this;
    }

    abstract public function process(): WriterInterface;

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir($dirPath)
    {
        if (!file_exists($dirPath)) {
            $config = $this->getServiceLocator()->get('Config');
            $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            if (!is_writeable($basePath)) {
                $this->logger->err(sprintf(
                    'The destination folder "%s" is not writeable.',
                    $basePath
                ));
                return null;
            }
            @mkdir($dirPath, 0755, true);
        } elseif (!is_dir($dirPath) || !is_writeable($dirPath)) {
            $this->logger->err(sprintf(
                'The destination folder "%s" is not writeable.',
                $basePath . '/' . $dirPath
            ));
            return null;
        }
        return $dirPath;
    }

    protected function prepareTempFile()
    {
        // TODO Use Omeka factory for temp files.
        $config = $this->getServiceLocator()->get('Config');
        $tempDir = $config['temp_dir'] ?: sys_get_temp_dir();
        $this->filepath = @tempnam($tempDir, 'omk_bke_');
        return $this;
    }

    protected function getOutputFilepath(): string
    {
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $destinationDir = $basePath . '/bulk_export';

        $exporterLabel = $this->getParam('exporter_label', '');
        $base = $this->slugify($exporterLabel);
        $base = $base ? preg_replace('/_+/', '_', $base) . '-' : '';
        $date = $this->getParam('export_started', new \DateTime())->format('Ymd-His');
        $extension = $this->getExtension();

        // Avoid issue on very big base.
        $outputFilepath = null;
        $i = 0;
        do {
            $filename = sprintf('%s%s%s.%s', $base, $date, $i ? '-' . $i : '', $extension);
            $outputFilepath = $destinationDir . '/' . $filename;
        } while (++$i && file_exists($outputFilepath));

        return $outputFilepath;
    }

    /**
     * Transform the given string into a valid filename
     *
     * @see \Omeka\Api\Adapter\SiteSlugTrait::slugify()
     */
    protected function slugify(string $input): string
    {
        if (extension_loaded('intl')) {
            $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
            $slug = $transliterator->transliterate($input);
        } elseif (extension_loaded('iconv')) {
            $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input);
        } else {
            $slug = $input;
        }
        $slug = mb_strtolower($slug, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9-]+/u', '_', $slug);
        $slug = preg_replace('/-{2,}/', '_', $slug);
        $slug = preg_replace('/-*$/', '', $slug);
        return $slug;
    }

    protected function saveFile()
    {
        $outputFilepath = $this->getOutputFilepath();
        $filename = basename($outputFilepath);

        try {
            $result = copy($this->filepath, $outputFilepath);
            @unlink($this->filepath);
        } catch (\Exception $e) {
            throw new \Omeka\Job\Exception\RuntimeException(sprintf(
                'Export error when saving "%1$s" (temp file: "%2$s"): %3$s',
                $filename,
                $this->filepath,
                $e
            ));
        }

        if (!$result) {
            throw new \Omeka\Job\Exception\RuntimeException(sprintf(
                'Export error when saving "%1$s" (temp file: "%2$s")',
                $filename,
                $this->filepath,
            ));
        }

        $params = $this->getParams();
        $params['filename'] = $filename;
        $this->setParams($params);
        return $this;
    }

    /**
     * @todo Factorize with \BulkExport\Traits\ResourceFieldsTrait::mapResourceTypeToEntity()
     * @param string $jsonResourceType
     * @return string|null
     */
    protected function mapResourceTypeToEntity($jsonResourceType)
    {
        $mapping = [
            // Core.
            'o:User' => \Omeka\Entity\User::class,
            'o:Vocabulary' => \Omeka\Entity\Vocabulary::class,
            'o:ResourceClass' => \Omeka\Entity\ResourceClass::class,
            'o:ResourceTemplate' => \Omeka\Entity\ResourceTemplate::class,
            'o:Property' => \Omeka\Entity\Property::class,
            'o:Item' => \Omeka\Entity\Item::class,
            'o:Media' => \Omeka\Entity\Media::class,
            'o:ItemSet' => \Omeka\Entity\ItemSet::class,
            'o:Module' => \Omeka\Entity\Module::class,
            'o:Site' => \Omeka\Entity\Site::class,
            'o:SitePage' => \Omeka\Entity\SitePage::class,
            'o:Job' => \Omeka\Entity\Job::class,
            'o:Resource' => \Omeka\Entity\Resource::class,
            'o:Asset' => \Omeka\Entity\Asset::class,
            'o:ApiResource' => null,
            // Modules.
            'oa:Annotation' => \Annotate\Entity\Annotation::class,
        ];
        return $mapping[$jsonResourceType] ?? null;
    }

    protected function mapResourceTypeToApiResource($jsonResourceType)
    {
        $mapping = [
            // Core.
            'o:User' => 'users',
            'o:Vocabulary' => 'vocabularies',
            'o:ResourceClass' => 'resource_classes',
            'o:ResourceTemplate' => 'resource_templates',
            'o:Property' => 'properties',
            'o:Item' => 'items',
            'o:Media' => 'media',
            'o:ItemSet' => 'item_sets',
            'o:Module' => 'modules',
            'o:Site' => 'sites',
            'o:SitePage' => 'site_pages',
            'o:Job' => 'jobs',
            'o:Resource' => 'resources',
            'o:Asset' => 'assets',
            'o:ApiResource' => 'api_resources',
            // Modules.
            'oa:Annotation' => 'annotations',
        ];
        return $mapping[$jsonResourceType] ?? null;
    }

    protected function mapResourceTypeToText($jsonResourceType)
    {
        $mapping = [
            // Core.
            'o:User' => 'users',
            'o:Vocabulary' => 'vocabularies',
            'o:ResourceClass' => 'resource classes',
            'o:ResourceTemplate' => 'resource templates',
            'o:Property' => 'properties',
            'o:Item' => 'items',
            'o:Media' => 'media',
            'o:ItemSet' => 'item sets',
            'o:Module' => 'modules',
            'o:Site' => 'sites',
            'o:SitePage' => 'site pages',
            'o:Job' => 'jobs',
            'o:Resource' => 'resources',
            'o:Asset' => 'assets',
            'o:ApiResource' => 'api resources',
            // Modules.
            'oa:Annotation' => 'annotations',
        ];
        return $mapping[$jsonResourceType] ?? null;
    }

    protected function mapResourceTypeToTable($jsonResourceType)
    {
        $mapping = [
            // Core.
            'o:User' => 'user',
            'o:Vocabulary' => 'vocabulary',
            'o:ResourceClass' => 'resource_class',
            'o:ResourceTemplate' => 'resource_template',
            'o:Property' => 'property',
            'o:Item' => 'item',
            'o:Media' => 'media',
            'o:ItemSet' => 'item_set',
            'o:Module' => 'module',
            'o:Site' => 'site',
            'o:SitePage' => 'site_page',
            'o:Job' => 'job',
            'o:Resource' => 'resource',
            'o:Asset' => 'asset',
            'o:ApiResource' => 'api_resource',
            // Modules.
            'oa:Annotation' => 'annotation',
        ];
        return $mapping[$jsonResourceType] ?? null;
    }

    protected function mapRepresentationToResourceType(AbstractRepresentation $representation)
    {
        $class = get_class($representation);
        $mapping = [
            // Core.
            \Omeka\Api\Representation\UserRepresentation::class => 'users',
            \Omeka\Api\Representation\VocabularyRepresentation::class => 'vocabularies',
            \Omeka\Api\Representation\ResourceClassRepresentation::class => 'resource_classes',
            \Omeka\Api\Representation\ResourceTemplateRepresentation::class => 'resource_templates',
            \Omeka\Api\Representation\PropertyRepresentation::class => 'properties',
            \Omeka\Api\Representation\ItemRepresentation::class => 'items',
            \Omeka\Api\Representation\MediaRepresentation::class => 'media',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
            \Omeka\Api\Representation\ModuleRepresentation::class => 'modules',
            \Omeka\Api\Representation\SiteRepresentation::class => 'sites',
            \Omeka\Api\Representation\SitePageRepresentation::class => 'site_pages',
            \Omeka\Api\Representation\JobRepresentation::class => 'jobs',
            \Omeka\Api\Representation\ResourceReference::class => 'resources',
            \Omeka\Api\Representation\AssetRepresentation::class => 'assets',
            \Omeka\Api\Representation\ApiResourceRepresentation::class => 'api_resources',
            // Modules.
            \Annotate\Api\Representation\AnnotationRepresentation::class => 'annotations',
        ];
        return $mapping[$class] ?? null;
    }

    protected function mapRepresentationToResourceTypeText(AbstractRepresentation $representation)
    {
        $class = get_class($representation);
        $mapping = [
            // Core.
            \Omeka\Api\Representation\UserRepresentation::class => 'User',
            \Omeka\Api\Representation\VocabularyRepresentation::class => 'Vocabulary',
            \Omeka\Api\Representation\ResourceClassRepresentation::class => 'Resource class',
            \Omeka\Api\Representation\ResourceTemplateRepresentation::class => 'Resource template',
            \Omeka\Api\Representation\PropertyRepresentation::class => 'Property',
            \Omeka\Api\Representation\ItemRepresentation::class => 'Item',
            \Omeka\Api\Representation\MediaRepresentation::class => 'Media',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'Item set',
            \Omeka\Api\Representation\ModuleRepresentation::class => 'Module',
            \Omeka\Api\Representation\SiteRepresentation::class => 'Site',
            \Omeka\Api\Representation\SitePageRepresentation::class => 'Site page',
            \Omeka\Api\Representation\JobRepresentation::class => 'Job',
            \Omeka\Api\Representation\ResourceReference::class => 'Resource',
            \Omeka\Api\Representation\AssetRepresentation::class => 'Asset',
            \Omeka\Api\Representation\ApiResourceRepresentation::class => 'Api resource',
            // Modules.
            \Annotate\Api\Representation\AnnotationRepresentation::class => 'Annotation',
        ];
        return $mapping[$class] ?? null;
    }
}
