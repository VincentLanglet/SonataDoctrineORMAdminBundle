<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineORMAdminBundle\Datagrid;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

/**
 * This class try to unify the query usage with Doctrine.
 *
 * @final since sonata-project/doctrine-orm-admin-bundle 3.29
 *
 * @method Query\Expr    expr()
 * @method QueryBuilder  setCacheable($cacheable)
 * @method bool          isCacheable()
 * @method QueryBuilder  setCacheRegion($cacheRegion)
 * @method string|null   getCacheRegion()
 * @method int           getLifetime()
 * @method QueryBuilder  setLifetime($lifetime)
 * @method int           getCacheMode()
 * @method QueryBuilder  setCacheMode($cacheMode)
 * @method int           getType()
 * @method EntityManager getEntityManager()
 * @method int           getState()
 * @method string        getDQL()
 * @method Query         getQuery()
 * @method string        getRootAlias()
 * @method array         getRootAliases()
 * @method array         getAllAliases()
 * @method array         getRootEntities()
 * @method QueryBuilder  setParameter($key, $value, $type = null)
 * @method QueryBuilder  setParameters($parameters)
 * @method QueryBuilder  getParameters()
 * @method QueryBuilder  getParameter($key)
 * @method QueryBuilder  add($dqlPartName, $dqlPart, $append = false)
 * @method QueryBuilder  select($select = null)
 * @method QueryBuilder  distinct($flag = true)
 * @method QueryBuilder  addSelect($select = null)
 * @method QueryBuilder  delete($delete = null, $alias = null)
 * @method QueryBuilder  update($update = null, $alias = null)
 * @method QueryBuilder  from($from, $alias, $indexBy = null)
 * @method QueryBuilder  indexBy($alias, $indexBy)
 * @method QueryBuilder  join($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
 * @method QueryBuilder  innerJoin($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
 * @method QueryBuilder  leftJoin($join, $alias, $conditionType = null, $condition = null, $indexBy = null)
 * @method QueryBuilder  set($key, $value)
 * @method QueryBuilder  where($where)
 * @method QueryBuilder  andWhere($where)
 * @method QueryBuilder  orWhere($where)
 * @method QueryBuilder  groupBy($groupBy)
 * @method QueryBuilder  addGroupBy($groupBy)
 * @method QueryBuilder  having($having)
 * @method QueryBuilder  andHaving($having)
 * @method QueryBuilder  orHaving($having)
 * @method QueryBuilder  orderBy($sort, $order = null)
 * @method QueryBuilder  addOrderBy($sort, $order = null)
 * @method QueryBuilder  addCriteria(Criteria $criteria)
 * @method mixed         getDQLPart($queryPartName)
 * @method array         getDQLParts()
 * @method QueryBuilder  resetDQLParts($parts = null)
 * @method QueryBuilder  resetDQLPart($part)
 */
class ProxyQuery implements ProxyQueryInterface
{
    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var string|null
     */
    protected $sortBy;

    /**
     * @var string|null
     */
    protected $sortOrder;

    /**
     * @var int
     */
    protected $uniqueParameterId;

    /**
     * @var string[]
     */
    protected $entityJoinAliases;

    /**
     * For BC reasons, this property is true by default.
     *
     * @var bool
     */
    private $distinct = true;

    /**
     * The map of query hints.
     *
     * @var array<string,mixed>
     */
    private $hints = [];

    /**
     * @param QueryBuilder $queryBuilder
     */
    public function __construct($queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        $this->uniqueParameterId = 0;
        $this->entityJoinAliases = [];
    }

    public function __call($name, $args)
    {
        return \call_user_func_array([$this->queryBuilder, $name], $args);
    }

    public function __get($name)
    {
        return $this->queryBuilder->$name;
    }

    public function __clone()
    {
        $this->queryBuilder = clone $this->queryBuilder;
    }

    /**
     * Optimize queries with a lot of rows.
     * It is not recommended to use "false" with left joins.
     *
     * @param bool $distinct
     *
     * @return self
     */
    final public function setDistinct($distinct)
    {
        if (!\is_bool($distinct)) {
            throw new \InvalidArgumentException('$distinct is not a boolean');
        }

        $this->distinct = $distinct;

        return $this;
    }

    /**
     * @return bool
     */
    final public function isDistinct()
    {
        return $this->distinct;
    }

    public function execute(array $params = [], $hydrationMode = null)
    {
        // always clone the original queryBuilder
        $queryBuilder = clone $this->queryBuilder;

        $rootAlias = current($queryBuilder->getRootAliases());

        // todo : check how doctrine behave, potential SQL injection here ...
        if ($this->getSortBy()) {
            $orderByDQLPart = $queryBuilder->getDQLPart('orderBy');
            $queryBuilder->resetDQLPart('orderBy');

            $sortBy = $this->getSortBy();
            if (false === strpos($sortBy, '.')) { // add the current alias
                $sortBy = $rootAlias.'.'.$sortBy;
            }
            $queryBuilder->addOrderBy($sortBy, $this->getSortOrder());

            foreach ($orderByDQLPart as $orderBy) {
                $queryBuilder->addOrderBy($orderBy);
            }
        }

        $query = $this->getFixedQueryBuilder($queryBuilder)->getQuery();

        foreach ($this->hints as $name => $value) {
            $query->setHint($name, $value);
        }

        return $query->execute($params, $hydrationMode);
    }

    public function setSortBy($parentAssociationMappings, $fieldMapping)
    {
        $alias = $this->entityJoin($parentAssociationMappings);
        $this->sortBy = $alias.'.'.$fieldMapping['fieldName'];

        return $this;
    }

    public function getSortBy()
    {
        return $this->sortBy;
    }

    public function setSortOrder($sortOrder)
    {
        if (!\in_array(strtoupper($sortOrder), $validSortOrders = ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a valid sort order, valid values are "%s"',
                $sortOrder,
                implode(', ', $validSortOrders)
            ));
        }
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getSortOrder()
    {
        return $this->sortOrder;
    }

    public function getSingleScalarResult()
    {
        $query = $this->queryBuilder->getQuery();

        return $query->getSingleScalarResult();
    }

    /**
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        return $this->queryBuilder;
    }

    public function setFirstResult($firstResult)
    {
        $this->queryBuilder->setFirstResult($firstResult);

        return $this;
    }

    public function getFirstResult()
    {
        return $this->queryBuilder->getFirstResult();
    }

    public function setMaxResults($maxResults)
    {
        $this->queryBuilder->setMaxResults($maxResults);

        return $this;
    }

    public function getMaxResults()
    {
        return $this->queryBuilder->getMaxResults();
    }

    public function getUniqueParameterId()
    {
        return $this->uniqueParameterId++;
    }

    public function entityJoin(array $associationMappings)
    {
        $alias = current($this->queryBuilder->getRootAliases());

        $newAlias = 's';

        $joinedEntities = $this->queryBuilder->getDQLPart('join');

        foreach ($associationMappings as $associationMapping) {
            // Do not add left join to already joined entities with custom query
            foreach ($joinedEntities as $joinExprList) {
                foreach ($joinExprList as $joinExpr) {
                    $newAliasTmp = $joinExpr->getAlias();

                    if (sprintf('%s.%s', $alias, $associationMapping['fieldName']) === $joinExpr->getJoin()) {
                        $this->entityJoinAliases[] = $newAliasTmp;
                        $alias = $newAliasTmp;

                        continue 3;
                    }
                }
            }

            $newAlias .= '_'.$associationMapping['fieldName'];
            if (!\in_array($newAlias, $this->entityJoinAliases, true)) {
                $this->entityJoinAliases[] = $newAlias;
                $this->queryBuilder->leftJoin(sprintf('%s.%s', $alias, $associationMapping['fieldName']), $newAlias);
            }

            $alias = $newAlias;
        }

        return $alias;
    }

    /**
     * Sets a {@see \Doctrine\ORM\Query} hint. If the hint name is not recognized, it is silently ignored.
     *
     * @param string $name  the name of the hint
     * @param mixed  $value the value of the hint
     *
     * @return ProxyQueryInterface
     *
     * @see \Doctrine\ORM\Query::setHint
     * @see \Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER
     */
    final public function setHint($name, $value)
    {
        $this->hints[$name] = $value;

        return $this;
    }

    /**
     * This method alters the query in order to
     *     - add a sort on the identifier fields of the first used entity in the query,
     *       because RDBMS do not guarantee a particular order when no ORDER BY clause
     *       is specified, or when the field used for sorting is not unique.
     *     - add a group by on the identifier fields in order to not display the same
     *       entity twice if entityJoin was used with a one to many relation.
     *
     * @return QueryBuilder
     */
    protected function getFixedQueryBuilder(QueryBuilder $queryBuilder)
    {
        $rootAlias = current($queryBuilder->getRootAliases());

        $identifierFields = $queryBuilder
            ->getEntityManager()
            ->getMetadataFactory()
            ->getMetadataFor(current($queryBuilder->getRootEntities()))
            ->getIdentifierFieldNames();

        $existingOrders = [];
        foreach ($queryBuilder->getDQLPart('orderBy') as $order) {
            foreach ($order->getParts() as $part) {
                $existingOrders[] = trim(str_replace([Criteria::DESC, Criteria::ASC], '', $part));
            }
        }

        $queryBuilder->resetDQLPart('groupBy');

        foreach ($identifierFields as $identifierField) {
            $field = $rootAlias.'.'.$identifierField;

            $queryBuilder->addGroupBy($field);
            if (!\in_array($field, $existingOrders, true)) {
                $queryBuilder->addOrderBy($field, $this->getSortOrder());
            }
        }

        return $queryBuilder;
    }
}
