<?php
namespace BulkImport\Form\Processor;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use BulkImport\Form\EntriesByBatchTrait;
use Omeka\Form\Element\PropertySelect;
use Omeka\Form\Element\ResourceClassSelect;
use Omeka\Form\Element\ResourceSelect;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

abstract class AbstractResourceProcessorConfigForm extends Form
{
    use ServiceLocatorAwareTrait;
    use EntriesByBatchTrait;

    public function init()
    {
        $this->baseFieldset();
        $this->addFieldsets();
        $this->addEntriesByBatch();

        $this->baseInputFilter();
        $this->addInputFilter();
        $this->addEntriesByBatchInputFilter();
    }

    protected function baseFieldset()
    {
        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this->add([
            'name' => 'o:resource_template',
            'type' => ResourceSelect::class,
            'options' => [
                'label' => 'Resource template', // @translate
                'empty_option' => '',
                'resource_value_options' => [
                    'resource' => 'resource_templates',
                    'query' => [],
                    'option_text_callback' => function ($resourceTemplate) {
                        return $resourceTemplate->label();
                    },
                ],
            ],
            'attributes' => [
                'id' => 'o-resource-template',
                'class' => 'chosen-select',
                'data-placeholder' => 'Select a template…', // @translate
                'data-api-base-url' => $urlHelper('api/default', ['resource' => 'resource_templates']),
            ],
        ]);

        $this->add([
            'name' => 'o:resource_class',
            'type' => ResourceClassSelect::class,
            'options' => [
                'label' => 'Resource class', // @translate
                'empty_option' => '',
                'term_as_value' => true,
            ],
            'attributes' => [
                'id' => 'resource-class-select',
                'class' => 'chosen-select',
                'data-placeholder' => 'Select a class…', // @translate
            ],
        ]);

        $this->add([
            'name' => 'o:owner',
            'type' => ResourceSelect::class,
            'options' => [
                'label' => 'Owner', // @translate
                'prepend_value_options' => [
                    'current' => 'Current user' // @translate
                ],
                'resource_value_options' => [
                    'resource' => 'users',
                    'query' => ['sort_by' => 'name', 'sort_dir' => 'ASC'],
                    'option_text_callback' => function ($user) {
                        return sprintf('%s (%s)', $user->name(), $user->email());
                    },
                ],
            ],
            'attributes' => [
                'id' => 'select-owner',
                'value' => 'current',
                'class' => 'chosen-select',
                'data-placeholder' => 'Select a user', // @translate
                'data-api-base-url' => $urlHelper('api/default', ['resource' => 'users'], ['query' => ['sort_by' => 'email', 'sort_dir' => 'ASC']]),
            ],
        ]);

        $this->add([
            'name' => 'o:is_public',
            'type' => Element\Radio::class,
            'options' => [
                'label' => 'Visibility', // @translate
                'value_options' => [
                    'true' => 'Public', // @translate
                    'false' => 'Private', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'o-is-public',
            ],
        ]);

        $this->add([
            'name' => 'identifier_name',
            'type' => PropertySelect::class,
            'options' => [
                'label' => 'Identifier name', // @translate
                'info' => 'Allows to identify existing resources, for example to attach a media to an existing item or to update a resource. It is always recommended to set one ore more unique identifiers to all resources, with a prefix.', // @translate
                'empty_option' => '', // @translate
                'prepend_value_options' => [
                    'o:id' => 'Internal id', // @translate
                ],
                'term_as_value' => true,
            ],
            'attributes' => [
                'id' => 'identifier_name',
                'multiple' => true,
                'required' =>false,
                'value' => [
                    'o:id',
                    'dcterms:identifier',
                ],
                'class' => 'chosen-select',
                'data-placeholder' => 'Select an identifier name…', // @translate
            ],
        ]);
    }

    protected function addFieldsets()
    {
    }

    protected function addMapping()
    {
        /** @var \BulkImport\Interfaces\Processor $processor */
        $processor = $this->getOption('processor');
        /** @var \BulkImport\Interfaces\Reader $reader */
        $reader = $processor->getReader();

        $services = $this->getServiceLocator();
        $automapFields = $services->get('ViewHelperManager')->get('automapFields');

        $this->add([
            'name' => 'mapping',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Mapping', // @translate
            ],
        ]);

        $fieldset = $this->get('mapping');

        // Add all columns from file as inputs.
        $availableFields = $reader->getAvailableFields();
        $fields = $automapFields($availableFields);
        foreach ($availableFields as $index => $name) {
            $fieldset->add([
                'name' => $name,
                'type' => PropertySelect::class,
                'options' => [
                    'label' => $name,
                    'term_as_value' => true,
                    'prepend_value_options' => $this->prependMappingOptions(),
                ],
                'attributes' => [
                    'value' => isset($fields[$index]) ? $fields[$index] : null,
                    'required' => false,
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select one or more targets…', // @translate
                ],
            ]);
        }
    }

    protected function prependMappingOptions()
    {
        return [
            'metadata' => [
                'label' => 'Resource metadata', // @translate
                'options' => [
                    'o:resource_template' => 'Resource template', // @translate
                    'o:resource_class' => 'Resource class', // @translate
                    'o:owner' => 'Owner', // @translate
                    'o:is_public' => 'Visibility public/private', // @translate
                ],
            ],
        ];
    }

    protected function baseInputFilter()
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'o:resource_template',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'o:resource_class',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'o:owner',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'o:is_public',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'identifier_name',
            'required' => false,
        ]);
    }

    protected function addInputFilter()
    {
    }

    protected function addMappingFilter()
    {
        $inputFilter = $this->getInputFilter()->get('mapping');
        // Change required to false.
        foreach ($inputFilter->getInputs() as $input) {
            $input->setRequired(false);
        }
    }
}
