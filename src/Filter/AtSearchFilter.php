<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\Model\Example;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;
use ApiPlatform\Api\IdentifiersExtractorInterface as LegacyIdentifiersExtractorInterface;
use ApiPlatform\Api\IriConverterInterface as LegacyIriConverterInterface;
use ApiPlatform\Doctrine\Common\Filter\SearchFilterInterface;
use ApiPlatform\Doctrine\Common\Filter\SearchFilterTrait;
use ApiPlatform\Doctrine\Orm\Util\QueryBuilderHelper;
use ApiPlatform\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\IdentifiersExtractorInterface;
use ApiPlatform\Metadata\IriConverterInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

final class AtSearchFilter extends AbstractFilter implements SearchFilterInterface
{
    use SearchFilterTrait;

    public const DOCTRINE_INTEGER_TYPE = Types::INTEGER;

    public function __construct(
        ManagerRegistry $managerRegistry,
        IriConverterInterface|LegacyIriConverterInterface $iriConverter,
        PropertyAccessorInterface $propertyAccessor = null,
        LoggerInterface $logger = null,
        array $properties = null,
        IdentifiersExtractorInterface|LegacyIdentifiersExtractorInterface $identifiersExtractor = null,
        NameConverterInterface $nameConverter = null,
        protected array $swaggerDescription = []
    ) {
        parent::__construct($managerRegistry, $logger, $properties, $nameConverter);

        $this->iriConverter = $iriConverter;
        $this->identifiersExtractor = $identifiersExtractor;
        $this->propertyAccessor = $propertyAccessor ?: PropertyAccess::createPropertyAccessor();
    }

    protected function getIriConverter(): IriConverterInterface|LegacyIriConverterInterface
    {
        return $this->iriConverter;
    }

    protected function getPropertyAccessor(): PropertyAccessorInterface
    {
        return $this->propertyAccessor;
    }

    /**
     * {@inheritdoc}
     */
    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        Operation $operation = null,
        array $context = []
    ): void {
        if (
            null === $value
            || !$this->isPropertyEnabled($property, $resourceClass)
            || !$this->isPropertyMapped($property, $resourceClass, true)
        ) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $field = $property;

        $values = $this->normalizeValues((array) $value, $property);
        if (null === $values) {
            return;
        }

        $associations = [];
        if ($this->isPropertyNested($property, $resourceClass)) {
            [$alias, $field, $associations] = $this->addJoinsForNestedProperty(
                $property,
                $alias,
                $queryBuilder,
                $queryNameGenerator,
                $resourceClass,
                Join::INNER_JOIN
            );
        }

        $caseSensitive = true;
        $strategy = $this->properties[$property] ?? self::STRATEGY_EXACT;

        // prefixing the strategy with i makes it case insensitive
        if (str_starts_with($strategy, 'i')) {
            $strategy = substr($strategy, 1);
            $caseSensitive = false;
        }

        $metadata = $this->getNestedMetadata($resourceClass, $associations);

        if ($metadata->hasField($field)) {
            if ('id' === $field) {
                $values = array_map($this->getIdFromValue(...), $values);
                // todo: handle composite IDs
            }

            if (!$this->hasValidValues($values, $this->getDoctrineFieldType($property, $resourceClass))) {
                $this->logger->notice('Invalid filter ignored', [
                    'exception' => new InvalidArgumentException(
                        sprintf(
                            'Values for field "%s" are not valid according to the doctrine type.',
                            $field
                        )
                    ),
                ]);

                return;
            }

            $this->addWhereByStrategy(
                $strategy,
                $queryBuilder,
                $queryNameGenerator,
                $alias,
                $field,
                $values,
                $caseSensitive
            );

            return;
        }

        // metadata doesn't have the field, nor an association on the field
        if (!$metadata->hasAssociation($field)) {
            return;
        }

        // association, let's fetch the entity (or reference to it) if we can so we can make sure we get its orm id
        $associationResourceClass = $metadata->getAssociationTargetClass($field);
        $associationMetadata = $this->getClassMetadata($associationResourceClass);
        $associationFieldIdentifier = $associationMetadata->getIdentifierFieldNames()[0];
        $doctrineTypeField = $this->getDoctrineFieldType($associationFieldIdentifier, $associationResourceClass);

        $values = array_map(function ($value) use ($associationFieldIdentifier, $doctrineTypeField) {
            if (is_numeric($value)) {
                return $value;
            }
            try {
                $item = $this->getIriConverter()->getResourceFromIri($value, ['fetch_data' => false]);

                return $this->propertyAccessor->getValue($item, $associationFieldIdentifier);
            } catch (InvalidArgumentException) {
                /*
                 * Can we do better? This is not the ApiResource the call was made on,
                 * so we don't get any kind of api metadata for it without (a lot of?) work elsewhere...
                 * Let's just pretend it's always the ORM id for now.
                 */
                if (!$this->hasValidValues([$value], $doctrineTypeField)) {
                    $this->logger->notice('Invalid filter ignored', [
                        'exception' => new InvalidArgumentException(
                            sprintf(
                                'Values for field "%s" are not valid according to the doctrine type.',
                                $associationFieldIdentifier
                            )
                        ),
                    ]);

                    return null;
                }

                return $value;
            }
        }, $values);

        $expected = \count($values);
        $values = array_filter($values, static fn ($value) => null !== $value);
        if ($expected > \count($values)) {
            /*
             * Shouldn't this actually fail harder?
             */
            $this->logger->notice('Invalid filter ignored', [
                'exception' => new InvalidArgumentException(
                    sprintf(
                        'Values for field "%s" are not valid according to the doctrine type.',
                        $field
                    )
                ),
            ]);

            return;
        }

        $associationAlias = $alias;
        $associationField = $field;
        if (
            $metadata->isCollectionValuedAssociation($associationField)
            || $metadata->isAssociationInverseSide($field)
        ) {
            $associationAlias = QueryBuilderHelper::addJoinOnce(
                $queryBuilder,
                $queryNameGenerator,
                $alias,
                $associationField
            );
            $associationField = $associationFieldIdentifier;
        }

        $this->addWhereByStrategy(
            $strategy,
            $queryBuilder,
            $queryNameGenerator,
            $associationAlias,
            $associationField,
            $values,
            $caseSensitive
        );
    }

    /**
     * Adds where clause according to the strategy.
     *
     * @throws InvalidArgumentException If strategy does not exist
     */
    protected function addWhereByStrategy(
        string $strategy,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $alias,
        string $field,
        mixed $values,
        bool $caseSensitive
    ): void {
        if (!\is_array($values)) {
            $values = [$values];
        }

        $wrapCase = $this->createWrapCase($caseSensitive);
        $valueParameter = ':' . $queryNameGenerator->generateParameterName($field);
        $aliasedField = sprintf('%s.%s', $alias, $field);

        if (!$strategy || self::STRATEGY_EXACT === $strategy) {
            if (1 === \count($values)) {
                $queryBuilder
                    ->andWhere($queryBuilder->expr()->eq($wrapCase($aliasedField), $wrapCase($valueParameter)))
                    ->setParameter($valueParameter, $values[0]);

                return;
            }

            $queryBuilder
                ->andWhere($queryBuilder->expr()->in($wrapCase($aliasedField), $valueParameter))
                ->setParameter($valueParameter, $caseSensitive ? $values : array_map('strtolower', $values));

            return;
        }

        $ors = [];
        $parameters = [];
        foreach ($values as $key => $value) {
            $keyValueParameter = sprintf('%s_%s', $valueParameter, $key);
            $parameters[] = [$caseSensitive ? $value : strtolower($value), $keyValueParameter];

            $ors[] = match ($strategy) {
                self::STRATEGY_PARTIAL => $queryBuilder->expr()->like(
                    $wrapCase($aliasedField),
                    $wrapCase((string) $queryBuilder->expr()->concat("'%'", $keyValueParameter, "'%'"))
                ),
                self::STRATEGY_START => $queryBuilder->expr()->like(
                    $wrapCase($aliasedField),
                    $wrapCase((string) $queryBuilder->expr()->concat($keyValueParameter, "'%'"))
                ),
                self::STRATEGY_END => $queryBuilder->expr()->like(
                    $wrapCase($aliasedField),
                    $wrapCase((string) $queryBuilder->expr()->concat("'%'", $keyValueParameter))
                ),
                self::STRATEGY_WORD_START => $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->like(
                        $wrapCase($aliasedField),
                        $wrapCase((string) $queryBuilder->expr()->concat($keyValueParameter, "'%'"))
                    ),
                    $queryBuilder->expr()->like(
                        $wrapCase($aliasedField),
                        $wrapCase((string) $queryBuilder->expr()->concat("'% '", $keyValueParameter, "'%'"))
                    )
                ),
                default => throw new InvalidArgumentException(sprintf('strategy %s does not exist.', $strategy)),
            };
        }

        $queryBuilder->andWhere($queryBuilder->expr()->orX(...$ors));
        foreach ($parameters as $parameter) {
            $queryBuilder->setParameter($parameter[1], $parameter[0]);
        }
    }

    /**
     * Creates a function that will wrap a Doctrine expression according to the
     * specified case sensitivity.
     *
     * For example, "o.name" will get wrapped into "LOWER(o.name)" when $caseSensitive
     * is false.
     */
    protected function createWrapCase(bool $caseSensitive): \Closure
    {
        return static function (string $expr) use ($caseSensitive): string {
            if ($caseSensitive) {
                return $expr;
            }

            return sprintf('LOWER(%s)', $expr);
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function getType(string $doctrineType): string
    {
        return match ($doctrineType) {
            Types::ARRAY => 'array',
            Types::BIGINT, Types::INTEGER, Types::SMALLINT => 'int',
            Types::BOOLEAN => 'bool',
            Types::DATE_MUTABLE, Types::TIME_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE,
            Types::DATE_IMMUTABLE, Types::TIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_IMMUTABLE
                => \DateTimeInterface::class,
            Types::FLOAT => 'float',
            default => 'string',
        };
    }


    // This function is only used to hook in documentation generators (supported by Swagger and Hydra)
    public function getDescription(string $resourceClass): array
    {
        $description = [];
        foreach ($this->properties as $property => $strategy) {
            $description[$this->swaggerDescription['name']] = [
                'property' => $property,
                'description' => $this->swaggerDescription['description'],
                'type' => $this->swaggerDescription['type'] ?? Type::BUILTIN_TYPE_STRING,
                'required' => $this->swaggerDescription['required'] ?? false,
            ];
            if (isset($this->swaggerDescription['openapi'])) {
                if (
                    isset($this->swaggerDescription['openapi']['examples'])
                    && is_array($this->swaggerDescription['openapi']['examples'])
                    && count($this->swaggerDescription['openapi']['examples']) > 0
                ) {
                    $examples = [];
                    foreach ($this->swaggerDescription['openapi']['examples'] as $example) {
                        $examples[] = new Example(
                            $example['summary'] ?? null,
                            $example['description'] ?? null,
                            $example['value'] ?? null
                        );
                    }
                    if (count($examples) > 0) {
                        $description[$this->swaggerDescription['name']]['openapi']['examples'] = $examples;
                    }
                }

                if (isset($this->swaggerDescription['openapi']['example'])) {
                    $description[$this->swaggerDescription['name']]['openapi']['example'] =
                        $this->swaggerDescription['openapi']['example'];
                }
            }
        }

        return $description;
    }
}
