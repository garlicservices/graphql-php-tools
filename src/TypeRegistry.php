<?php

namespace Ola\GraphQL\Tools;

use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use GraphQL\GraphQL;

use GraphQL\Language\Parser;
use GraphQL\Language\AST\NodeKind;

class TypeRegistry
{
    public $fragmentReplacements;
    private $types;
    private $schemaByField; //query | mutation

    /**
     * TypeRegistry constructor.
     */
    public function __construct()
    {
        $this->types = [];
        $this->schemaByField = [
            'query' => [],
            'mutation' => [],
        ];
        $this->fragmentReplacements = [];
    }

    /**
     * @param $operation
     * @param $fieldName
     * @return mixed
     */
    public function getSchemaByField($operation, $fieldName)
    {
        return $this->schemaByField[$operation][$fieldName];
    }

    /**
     * @return array
     */
    public function getAllTypes()
    {
        return array_values($this->types);
    }

    /**
     * @param $name
     * @return mixed
     * @throws \ErrorException
     */
    public function getType($name)
    {
        if (!$this->types[$name]) {
            throw new \ErrorException("No such type: \"$name\"");
        }

        return $this->types[$name];
    }

    /**
     * @param $type
     * @return mixed
     * @throws \ErrorException
     */
    public function resolveType($type)
    {
        if ($type instanceof ListOfType) {
            return Type::listOf($this->resolveType($type->getWrappedType(true)));
        } else {
            if ($type instanceof NonNull) {
                return Type::nonNull($this->resolveType($type->getWrappedType(true)));
            } else {
                if (Type::getNamedType($type)) {
                    return $this->getType(Type::getNamedType($type)->name);
                } else {
                    return $type;
                }
            }
        }
    }

    /**
     * @param $schema
     * @param null $service
     */
    public function addSchema($schema, $service = null)
    {
        $query = $schema->getQueryType();
        if ($query) {
            $fieldNames = array_keys($query->getFields());
            foreach ($fieldNames as $fieldName) {
                $this->schemaByField['query'][$service][$fieldName] = $schema;
            }
        }
        $mutation = $schema->getMutationType();
        if ($mutation) {
            $fieldNames = array_keys($mutation->getFields());
            foreach ($fieldNames as $fieldName) {
                $this->schemaByField['mutation'][$service][$fieldName] = $schema;
            }
        }
    }

    /**
     * @param $name
     * @param $type
     * @param null $onTypeConflict
     */
    public function addType($name, $type, $onTypeConflict = null)
    {
        if (isset($this->types[$name])) {
            if (is_callable($onTypeConflict)) {
                $type = call_user_func($onTypeConflict, $this->types[$name], $type);
            } else {
                throw new Error("Type name conflict: \"$name\"");
            }
        }
        $this->types[$name] = $type;
    }

    /**
     * @param $typeName
     * @param $fieldName
     * @param $fragment
     * @throws \ErrorException
     */
    public function addFragment($typeName, $fieldName, $fragment)
    {
        if (!$this->fragmentReplacements[$typeName]) {
            $this->fragmentReplacements[$typeName] = [];
        }
        $this->fragmentReplacements[$typeName][$fieldName] = $this->parseFragmentToInlineFragment($fragment);
    }

    /**
     * @param $definitions
     * @return array
     * @throws \ErrorException
     */
    private function parseFragmentToInlineFragment($definitions)
    {
        $document = Parser::parse($definitions);
        foreach ($document->definitions as $key => $definition) {
            if ($definition->kind == NodeKind::FRAGMENT_DEFINITION) {
                return [
                    'kind' => NodeKind::INLINE_FRAGMENT,
                    'typeCondition' => $definition->typeCondition,
                    'selectionSet' => $definition->selectionSet,
                ];
            }
        }
        throw new \ErrorException("Could not parse fragment");
    }
}