<?php
namespace BulkImport\Api\Adapter;

use BulkImport\Entity\Log;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class LogAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'bulk_logs';
    }

    public function getRepresentationClass()
    {
        return \BulkImport\Api\Representation\LogRepresentation::class;
    }

    public function getEntityClass()
    {
        return Log::class;
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        foreach ($data as $key => $value) {
            $method = 'set' . Inflector::camelize($key);
            if (!method_exists($entity, $method)) {
                continue;
            }
            $entity->$method($value);
        }
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['severity'])) {
            $qb->andWhere(
                $qb->expr()->lte(
                    $this->getEntityClass() . '.severity',
                    $this->createNamedParameter($qb, $query['severity'])
                )
            );
        }

        if (isset($query['import'])) {
            $qb->andWhere(
                $qb->expr()->eq(
                    $this->getEntityClass() . '.import',
                    $this->createNamedParameter($qb, $query['import'])
                )
            );
        }
    }
}
