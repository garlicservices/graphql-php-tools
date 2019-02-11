<?php

namespace Ola\GraphQL\Tools;

use GraphQL\Type\Schema;
use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\GraphQL;

class MergeSchemas
{

    private $schemas = [];
    private $onTypeConflict;
    private $resolvers;

    /**
     * @param $schemas
     * @param $resolvers
     * @param null $onTypeConflict
     * @return \GraphQL\Type\Schema|Schema
     * @throws SchemaError
     * @throws \ErrorException
     */
    public static function mergeSchemas($schemas, $resolvers, $onTypeConflict = null)
    {
        $merger = new self($schemas, $resolvers, $onTypeConflict);

        return $merger->merge($schemas, $resolvers, $onTypeConflict);
    }

    /**
     * MergeSchemas constructor.
     * @param $schemas
     * @param $resolvers
     * @param $onTypeConflict
     * @throws \Exception
     */
    public function __construct($schemas, $resolvers, $onTypeConflict)
    {
        if (!is_array($schemas)) {
            throw new \Exception(
                "Input schemas must be array of \"GraphQL\\Type\\Schema\" or string schema definitions"
            );
        }

        $this->schemas = $schemas;
        $this->onTypeConflict = $onTypeConflict;
        $this->resolvers = $resolvers;
    }

    /**
     * @param $schemas
     * @param $resolvers
     * @param null $onTypeConflict
     * @return \GraphQL\Type\Schema|Schema
     * @throws SchemaError
     * @throws \ErrorException
     */
    public function merge($schemas, $resolvers, $onTypeConflict = null)
    {
        if (!is_callable($onTypeConflict)) {
            $onTypeConflict = function ($left, $right) {
                return $left;
            };
        }

        $queryFields = [];
        $mutationFields = [];

        $typeRegistry = new TypeRegistry();
        $mergeInfo = new MergeInfo($typeRegistry);

        $actualSchemas = [];
        $extensions = [];
        $fullResolvers = [];

        foreach ($schemas as $key => $schema) {
            if ($schema instanceof Schema) {
                $actualSchemas[$key] = $schema;
            } else {
                if (is_string($schema)) {
                    $parsedSchemaDocument = Parser::parse($schema);
                    try {
                        $actualSchema = BuildSchema::buildAST($parsedSchemaDocument);
                        $actualSchemas[$key] = $actualSchema;
                    } catch (\Exception $e) {
                        // Could not create a schema from parsed string, will use extensions
                    }
                    $parsedSchemaDocument = ExecutableSchema::extractExtensionDefinitions($parsedSchemaDocument);
                    if (count($parsedSchemaDocument->definitions)) {
                        $extensions[] = $parsedSchemaDocument;
                    }
                }
            }
        }

        foreach ($actualSchemas as $key => $schema) {
            $typeRegistry->addSchema($schema, $key);
            $queryType = $schema->getQueryType();
            $mutationType = $schema->getMutationType();
            foreach ($schema->getTypeMap() as $typeName => $type) {
                if (Type::getNamedType($type) && substr(
                        Type::getNamedType($type)->name,
                        0,
                        2
                    ) !== '__' && $type !== $queryType && $type !== $mutationType) {
                    $newType = null;
                    if (Type::isCompositeType($type) || $type instanceof InputObjectType) {
                        $newType = $this->recreateCompositeType($schema, $type, $typeRegistry);
                    } else {
                        $newType = Type::getNamedType($type);
                    }


                    $typeRegistry->addType($newType->name, $newType, $onTypeConflict);
                }
            };
        }

        // This is not a bug/oversight, we iterate twice cause we want to first
        // resolve all types and then force the type thunks
        foreach ($actualSchemas as $key => $schema) {
            $queryType = $schema->getQueryType();
            $mutationType = $schema->getMutationType();

            foreach ($queryType->getFields() as $name => $val) {
                if (!isset($fullResolvers['Query'])) {
                    $fullResolvers['Query'] = [];
                }
                $fullResolvers['Query'][$key][$name] = $this->createDelegatingResolver($mergeInfo, 'query', $name);
            }

            $queryFields = array_merge(
                $queryFields,
                [
                    $key => new ObjectType(
                        [
                            'name' => $key.'Queries',
                            'fields' => $this->fieldMapToFieldConfigMap($queryType->getFields(), $typeRegistry),
                        ]
                    ),
                ]
            );

            if ($mutationType) {
                if (!isset($fullResolvers['Mutation'])) {
                    $fullResolvers['Mutation'] = [];
                }
                foreach ($mutationType->getFields() as $name => $val) {
                    $fullResolvers['Mutation'][$key][$name] = $this->createDelegatingResolver(
                        $mergeInfo,
                        'mutation',
                        $name
                    );
                }

                $mutationFields = array_merge(
                    $mutationFields,
                    [
                        $key => new ObjectType(
                            [
                                'name' => $key.'Mutations',
                                'fields' => $this->fieldMapToFieldConfigMap($mutationType->getFields(), $typeRegistry),
                            ]
                        ),
                    ]
                );
            }
        }

        $passedResolvers = [];
        if (is_callable($resolvers)) {
            $passedResolvers = call_user_func($resolvers, $mergeInfo);
        }

        if (count($passedResolvers)) {
            foreach ($passedResolvers as $typeName => $type) {
                if ($type instanceof ScalarType) {
                    break;
                }

                foreach ($type as $fieldName => $field) {
                    if (isset($field['fragment'])) {
                        $typeRegistry->addFragment($typeName, $fieldName, $field['fragment']);
                    }
                };
            };
        }

        $fullResolvers = $this->mergeDeep($fullResolvers, $passedResolvers);

        $query = new ObjectType(
            [
                'name' => 'Query',
                'fields' => $queryFields,
            ]
        );

        $mutation = null;
        if (!empty($mutationFields)) {
            $mutation = new ObjectType(
                [
                    'name' => 'Mutation',
                    'fields' => $mutationFields,
                ]
            );
        }

        $mergedSchema = new Schema(
            [
                'query' => $query,
                'mutation' => $mutation,
                'types' => $typeRegistry->getAllTypes(),
            ]
        );

        foreach ($extensions as $key => $extension) {
            $mergedSchema = ExtendSchema::extend($mergedSchema, $extension);
        };

        ExecutableSchema::addResolveFunctionsToSchema($mergedSchema, $fullResolvers);

        return $mergedSchema;
    }

    /**
     * @param $target
     * @param $source
     * @return mixed
     */
    private function mergeDeep($target, $source)
    {
        $output = $target;
        if (is_array($target) && is_array($source)) {
            foreach ($source as $key => $src) {
                if (is_array($src)) {
                    if (empty($target[$key])) {
                        $output[$key] = $src;
                    } else {
                        $output[$key] = $this->mergeDeep($target[$key], $source[$key]);
                    }
                } else {
                    $output[$key] = $src;
                }
            };
        }

        return $output;
    }

    /**
     * @param $mergeInfo
     * @param $operation
     * @param $fieldName
     * @return \Closure
     */
    private function createDelegatingResolver($mergeInfo, $operation, $fieldName)
    {
        return function ($root, $args, $context, $info) use ($mergeInfo, $operation, $fieldName) {
            return $mergeInfo->delegate($operation, $fieldName, $args, $context, $info);
        };
    }

    /**
     * @param $schema
     * @param $type
     * @param $registry
     * @return InputObjectType|InterfaceType|ObjectType|UnionType
     * @throws \ErrorException
     */
    private function recreateCompositeType($schema, $type, $registry)
    {
        if ($type instanceof ObjectType) {
            $fields = $type->getFields();
            $interfaces = $type->getInterfaces();

            return new ObjectType(
                [
                    'name' => $type->name,
                    'description' => $type->description,
                    'isTypeOf' => $type->isTypeOf ?? '',
                    'fields' => function () use ($fields, $registry) {
                        return $this->fieldMapToFieldConfigMap($fields, $registry);
                    },
                    'interfaces' => function () use ($interfaces, $registry) {
                        return Utils::map(
                            $interfaces,
                            function ($iface) use ($registry) {
                                return $registry->resolveType($iface);
                            }
                        );
                    },
                ]
            );
        } elseif ($type instanceof InterfaceType) {
            $fields = $type->getFields();

            return new InterfaceType(
                [
                    'name' => $type->name,
                    'description' => $type->description,
                    'fields' => function () use ($fields, $registry) {
                        return $this->fieldMapToFieldConfigMap($fields, $registry);
                    },
                    'resolveType' => function ($parent, $context, $info) {
                        return $this->resolveFromParentTypename($parent, $info->schema);
                    },
                ]
            );
        } elseif ($type instanceof UnionType) {
            return new UnionType(
                [
                    'name' => $type->name,
                    'description' => $type->description,
                    'types' => function () use ($type, $registry) {
                        return Utils::map(
                            $type->getTypes(),
                            function ($unionMember) use ($registry) {
                                return $registry->resolveType($unionMember);
                            }
                        );
                    },
                    'resolveType' => function ($parent, $context, $info) {
                        return $this->resolveFromParentTypename($parent, $info->schema);
                    },
                ]
            );
        } elseif ($type instanceof InputObjectType) {
            return new InputObjectType(
                [
                    'name' => $type->name,
                    'description' => $type->description,
                    'fields' => function () use ($type, $registry) {
                        return $this->inputFieldMapToFieldConfigMap($type->getFields(), $registry);
                    },
                ]
            );
        } else {
            throw new \ErrorException("Invalid type \"$type\"");
        }
    }

    /**
     * @param $fields
     * @param $registry
     * @return array
     * @throws \ErrorException
     */
    private function fieldMapToFieldConfigMap($fields, $registry)
    {
        $result = [];
        foreach ($fields as $name => $field) {
            $result[$name] = $this->fieldToFieldConfig($field, $registry);
        }

        return $result;
    }

    /**
     * @param FieldDefinition $field
     * @param TypeRegistry $registry
     * @return array
     * @throws \ErrorException
     */
    private function fieldToFieldConfig(FieldDefinition $field, TypeRegistry $registry)
    {
        return [
            'type' => $registry->resolveType($field->getType()),
            'args' => $this->argsToFieldConfigArgumentMap($field->args, $registry),
            'description' => $field->description,
            'deprecationReason' => $field->deprecationReason,
        ];
    }

    /**
     * @param $args
     * @param $registry
     * @return array
     */
    private function argsToFieldConfigArgumentMap($args, $registry)
    {
        $result = [];
        foreach ($args as $key => $arg) {
            $result[$arg->name] = $this->argumentToArgumentConfig($arg, $registry);
        }

        return $result;
    }

    /**
     * @param FieldArgument $argument
     * @param TypeRegistry $registry
     * @return array
     * @throws \ErrorException
     */
    private function argumentToArgumentConfig(FieldArgument $argument, TypeRegistry $registry)
    {
        return [
            'name' => $argument->name,
            'type' => $registry->resolveType($argument->getType()),
            'defaultValue' => $argument->defaultValue,
            'description' => $argument->description,
            'astNode' => $argument,
        ];
    }

    /**
     * @param $parent
     * @param $schema
     * @return mixed
     * @throws \ErrorException
     */
    public static function resolveFromParentTypename($parent, $schema)
    {
        $parentTypename = $parent['__typename'];
        if (!$parentTypename) {
            throw new \ErrorException("Did not fetch typename for object, unable to resolve interface.");
        }
        $resolvedType = $schema->getType($parentTypename);

        if (!($resolvedType instanceof ObjectType)) {
            throw new \ErrorException("__typename did not match an object type: ".$parentTypename);
        }

        return $resolvedType;
    }

    /**
     * @param $fields
     * @param $registry
     * @return array
     * @throws \Exception
     */
    private function inputFieldMapToFieldConfigMap($fields, $registry)
    {
        return Utils::mapValues(
            $fields,
            function ($field) use ($registry) {
                return $this->inputFieldToFieldConfig($field, $registry);
            }
        );
    }

    /**
     * @param $field
     * @param $registry
     * @return array
     */
    function inputFieldToFieldConfig($field, $registry)
    {
        return [
            'type' => $registry->resolveType($field->type),
            'description' => $field->description,
        ];
    }
}