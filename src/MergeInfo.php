<?php

namespace Ola\GraphQL\Tools;

use GraphQL\Type\Introspection;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Visitor;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\UnionType;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\GraphQL;

class MergeInfo
{
    /**
     * @var TypeRegistry
     */
    private $typeRegistry;

    /**
     * MergeInfo constructor.
     * @param $typeRegistry
     */
    public function __construct($typeRegistry)
    {
        $this->typeRegistry = $typeRegistry;
    }

    /**
     * @param mixed $operation
     * @param mixed $fieldName
     * @param mixed $args
     * @param mixed $context
     * @param ResolveInfo $info
     * @return array
     * @throws \ErrorException
     */
    public function delegate($operation, $fieldName, $args, $context, ResolveInfo $info)
    {
        $schema = $this->typeRegistry->getSchemaByField($operation, $fieldName);

        if (!$schema) {
            throw new \ErrorException("Cannot find subschema for root field \"$operation -> \"$fieldName\"");
        }
        $fragmentReplacements = $this->typeRegistry->fragmentReplacements;

        return $this->delegateToSchema($schema, $fragmentReplacements, $operation, $fieldName, $args, $context, $info);
    }

    /**
     * @param mixed $schema
     * @param mixed $fragmentReplacements
     * @param $operation
     * @param $fieldName
     * @param $args
     * @param $context
     * @param ResolveInfo $info
     * @return array
     * @throws \ErrorException
     */
    private function delegateToSchema(
        $schema,
        $fragmentReplacements,
        $operation,
        $fieldName,
        $args,
        $context,
        ResolveInfo $info
    ) { //Promise
        if ($operation == 'mutation') {
            $type = $schema->getMutationType();
        } else {
            $type = $schema->getQueryType();
        }

        if ($type) {
            /**
             * @var \GraphQL\Language\AST\DocumentNode
             */
            $graphqlDoc = $this->createDocument(
                $schema,
                $fragmentReplacements,
                $type,
                $fieldName,
                $operation,
                $info->fieldNodes,
                $info->fragments,
                $info->operation->variableDefinitions
            );
            $operationDefinition = Utils::find(
                $graphqlDoc->definitions,
                function ($definition) {
                    return $definition->kind == NodeKind::OPERATION_DEFINITION;
                }
            );
            $variableValues = null;
            if (!empty($operationDefinition) && !empty($operationDefinition->variableDefinitions)) {
                $variableValues = Utils::map(
                    $operationDefinition->variableDefinitions,
                    function ($definition) use ($args, $info) {
                        $key = $definition->variable->name->value;
                        $actualKey = $key;
                        if (Utils::startsWith($actualKey, '_')) {
                            $actualKey = substr($actualKey, 1);
                        }
                        $value = $args[$actualKey] || $args[$key] || $info->variableValues[$key];

                        return [$key, $value];
                    }
                );
            }

            $result = GraphQL::executeQuery($schema, $graphqlDoc, $info->rootValue, $context, $variableValues);

            if (!empty($result->errors)) {
                $errorMessages = Utils::map(
                    $result->errors,
                    function ($error) {
                        return $error->message;
                    }
                );
                $errorMessage = implode('\n', $errorMessages);
                throw new \ErrorException($errorMessage);
            } else {
                return $result->data[$fieldName];
            }
        }

        throw new \ErrorException('Could not forward to merged schema');
    }

    private function createDocument(
        $schema,
        $fragmentReplacements,
        $type,
        $rootFieldName,
        $operation,
        $selections,
        $fragments,
        $variableDefinitions = []
    ) {
        $rootField = $type->getFields()[$rootFieldName];
        $newVariables = [];
        $rootSelectionSet = new SelectionSetNode(
            [
                'kind' => NodeKind::SELECTION_SET,
                'selections' => Utils::map(
                    $selections,
                    function ($selection) use ($newVariables, $rootFieldName, $rootField) {
                        if ($selection->kind == NodeKind::FIELD) {
                            list($newSelection, $variables) = $this->processRootField(
                                $selection,
                                $rootFieldName,
                                $rootField
                            );
                            $newVariables = array_merge($newVariables, $variables);

                            return $newSelection;
                        } else {
                            return $selection;
                        }
                    }
                ),
            ]
        );

        $newVariableDefinitions = Utils::map(
            $newVariables,
            function ($arg, $variable) use ($rootField) {
                $argDef = Utils::find(
                    $rootField->args,
                    function ($rootArg) use ($arg) {
                        return $rootArg->name == $arg;
                    }
                );
                if (!$argDef) {
                    throw new \ErrorException('Unexpected missing arg');
                }
                $typeName = $this->typeToAst($argDef->type);

                return new VariableNode(
                    [
                        'kind' => NodeKind::VARIABLE_DEFINITION,
                        'variable' => new VariableNode(
                            [
                                'kind' => NodeKind::VARIABLE,
                                'name' => new NameNode(
                                    [
                                        'kind' => NodeKind::NAME,
                                        'value' => $variable,
                                    ]
                                ),
                            ]
                        ),
                        'type' => $typeName,
                    ]
                );
            }
        );

        list($selectionSet, $processedFragments, $usedVariables) = $this->filterSelectionSetDeep(
            $schema,
            $fragmentReplacements,
            $type,
            $rootSelectionSet,
            $fragments
        );

        if (!empty($variableDefinitions)) {
            $variableDefinitions = array_filter(
                $variableDefinitions,
                function ($variableDefinition) use ($usedVariables) {
                    return in_array($usedVariables, $variableDefinition->variable->name->value);

                }
            );
        }

        $operationDefinition = new OperationDefinitionNode(
            [
                'kind' => NodeKind::OPERATION_DEFINITION,
                'operation' => $operation,
                'variableDefinitions' => !empty($variableDefinitions) ? array_merge(
                    $variableDefinitions,
                    $newVariableDefinitions
                ) : $newVariableDefinitions,
                'selectionSet' => $selectionSet,
            ]
        );

        $newDoc = new DocumentNode(
            [
                'kind' => NodeKind::DOCUMENT,
                'definitions' => !empty($processedFragments) ? [
                    $operationDefinition,
                    $processedFragments,
                ] : [$operationDefinition],
            ]
        );

        return $newDoc;
    }

    /**
     * @param $schema
     * @param $fragmentReplacements - typeName: string, fieldName: string
     * @param $type
     * @param $selectionSet
     * @param $fragments
     * @return mixed : selectionSet, fragments, usedVariables
     * @throws \ErrorException
     */
    private function filterSelectionSetDeep($schema, $fragmentReplacements, $type, $selectionSet, $fragments)
    {
        $validFragments = [];
        foreach ($fragments as $fragmentName => $fragment) {
            $typeName = $fragment->typeCondition->name->value;
            $innerType = $schema->getType($typeName);
            if ($innerType) {
                $validFragments[] = $fragment->name->value;
            }
        };
        list($newSelectionSet, $remainingFragments, $usedVariables) = $this->filterSelectionSet(
            $schema,
            $fragmentReplacements,
            $type,
            $selectionSet,
            $validFragments
        );

        $newFragments = [];
        // (XXX): So this will break if we have a fragment that only has link fields
        $haveRemainingFragments = count($remainingFragments);
        while ($haveRemainingFragments) {
            $name = array_pop($remainingFragments);
            if ($newFragments[$name]) {
                continue;
            } else {
                $nextFragment = $fragments[$name];
                if (!$name) {
                    throw new \ErrorException("Could not find fragment \"$name\"");
                }
                $typeName = $nextFragment->typeCondition->name->value;
                $innerType = $schema->getType($typeName);
                if (!$innerType) {
                    continue;
                }
                list($fragmentSelectionSet, $fragmentUsedFragments, $fragmentUsedVariables) = $this->filterSelectionSet(
                    $schema,
                    $fragmentReplacements,
                    $innerType,
                    $nextFragment->selectionSet,
                    $validFragments
                );
                $remainingFragments = array_merge($remainingFragments, $fragmentUsedFragments);
                $usedVariables = array_merge($usedVariables, $fragmentUsedVariables);
                $newFragments[$name] = [
                    'kind' => NodeKind::FRAGMENT_DEFINITION,
                    'name' => new NameNode(
                        [
                            'kind' => NodeKind::NAME,
                            'value' => $name,
                        ]
                    ),
                    'typeCondition' => $nextFragment->typeCondition,
                    'selectionSet' => $fragmentSelectionSet,
                ];
            }
            $haveRemainingFragments = count($remainingFragments);
        }
        $newFragmentValues = Utils::map(
            $newFragments,
            function ($name) use ($newFragments) {
                return new FragmentDefinitionNode($newFragments[$name]);
            }
        );

        return [
            $newSelectionSet,
            $newFragmentValues,
            $usedVariables,
        ];
    }

    /**
     * @param $schema
     * @param $fragmentReplacements
     * @param $type
     * @param $selectionSet
     * @param $validFragments
     * @return array
     */
    private function filterSelectionSet($schema, $fragmentReplacements, $type, $selectionSet, $validFragments)
    {
        $usedFragments = [];
        $usedVariables = [];
        $typeStack = [$type];
        $filteredSelectionSet = new SelectionSetNode(
            Visitor::visit(
                $selectionSet,
                [
                    NodeKind::FIELD => [
                        'enter' => function ($node) use ($typeStack, $fragmentReplacements) {
                            $parentType = $this->resolveType($typeStack[count($typeStack) - 1]);
                            if ($parentType instanceof NonNull || $parentType instanceof ListOfType) {
                                $parentType = $parentType->getWrappedType(true);//if error stdObject look for ->ofType
                            }
                            if ($parentType instanceof ObjectType || $parentType instanceof InterfaceType) {
                                $fields = $parentType->getFields();
                                $field = ($node->name->value == '__typename') ? Introspection::TypeNameMetaFieldDef(
                                ) : $fields[$node->name->value];
                                if (!$field) {
                                    if (!empty($fragmentReplacements[$parentType->name])) {
                                        $fragment = $fragmentReplacements[$parentType->name][$node->name->value];
                                    }
                                    if ($fragment) {
                                        return $fragment;
                                    }
                                } else {
                                    return null;
                                }
                            } else {
                                $typeStack[] = $field->type;
                            }
                        },
                        'leave' => function () use ($typeStack) {
                            array_pop($typeStack);
                        },
                    ],
                    NodeKind::SELECTION_SET => function ($node) use ($typeStack) {
                        $parentType = $this->resolveType($typeStack[count($typeStack) - 1]);
                        if ($parentType instanceof InterfaceType || $parentType instanceof UnionType) {
                            $node->selections[] = new FieldNode(
                                [
                                    'kind' => NodeKind::FIELD,
                                    'name' => new NameNode(
                                        [
                                            'kind' => NodeKind::NAME,
                                            'value' => '__typename',
                                        ]
                                    ),
                                ]
                            );

                            return $node;
                        }
                    },
                    NodeKind::FRAGMENT_SPREAD => function ($node) use ($validFragments, $usedFragments) {
                        if (in_array($validFragments, $node->name->value)) {
                            $usedFragments[] = $node->name->value;
                        } else {
                            return null;
                        }
                    },
                    NodeKind::INLINE_FRAGMENT => [
                        'enter' => function ($node) use ($typeStack, $schema) {
                            if ($node->typeCondition) {
                                $innerType = $schema->getType($node->typeCondition->name->value);
                                if ($innerType) {
                                    $typeStack[] = $innerType;
                                } else {
                                    return null;
                                }
                            }
                        },
                        'leave' => function ($node) use ($typeStack, $schema) {
                            if ($node->typeCondition) {
                                $innerType = $schema->getType($node->typeCondition->name->value);
                                if ($innerType) {
                                    array_pop($typeStack);
                                } else {
                                    return null;
                                }
                            }
                        },
                    ],
                    NodeKind::VARIABLE => function ($node) use ($usedVariables) {
                        $usedVariables[] = $node->name->value;
                    },
                ]
            )
        );

        return [$filteredSelectionSet, $usedFragments, $usedVariables];
    }

    /**
     * @param $type
     * @return mixed
     */
    private function resolveType($type)
    {
        $lastType = $type;
        while ($lastType instanceof NonNull || $lastType instanceof ListOfType) {
            $lastType = $lastType->getWrappedType(true);//if error stdObject look for ->ofType
        }

        return $lastType;
    }

    /**
     * @param $type
     * @return ListTypeNode|NamedTypeNode|NonNullTypeNode
     * @throws \ErrorException
     */
    private function typeToAst($type)
    {
        if ($type instanceof NonNull) {
            $innerType = $this->typeToAst($type->getWrappedType(true));//if error stdObject look for ->ofType
            if ($innerType->kind == NodeKind::LIST_TYPE || $innerType->kind == NodeKind::NAMED_TYPE) {
                return new NonNullTypeNode(
                    [
                        'kind' => NodeKind::NON_NULL_TYPE,
                        'type' => $innerType,
                    ]
                );
            } else {
                throw new \ErrorException('Incorrent inner non-null type');
            }
        } else {
            if ($type instanceof ListOfType) {
                return new ListTypeNode(
                    [
                        'kind' => NodeKind::LIST_TYPE,
                        'type' => $this->typeToAst($type->getWrappedType(true)),//if error stdObject look for ->ofType
                    ]
                );
            } else {
                return new NamedTypeNode (
                    [
                        'kind' => NodeKind::NAMED_TYPE,
                        'name' => new NameNode(
                            [
                                'kind' => NodeKind::NAME,
                                'value' => $type->toString(),
                            ]
                        ),
                    ]
                );
            }
        }
    }

    /**
     * @param $selection
     * @param $rootFieldName
     * @param $rootField
     * @return array FieldNode selection and variables array
     */
    private function processRootField($selection, $rootFieldName, $rootField)
    {
        $existingArguments = $selection->arguments ?: [];
        $existingArgumentNames = Utils::map(
            $existingArguments,
            function ($arg) {
                return $arg->name->value;
            }
        );
        $missingArgumentNames = array_diff(
            Utils::map(
                $rootField->args,
                function ($arg) {
                    return $arg->name;
                }
            ),
            $existingArgumentNames
        );
        $variables = [];
        $missingArguments = Utils::map(
            $missingArgumentNames,
            function ($name) use ($variables) {
                $variableName = "_".$name;
                $variables[] = [
                    'arg' => $name,
                    'variable' => $variableName,
                ];

                return new ArgumentNode(
                    [
                        'kind' => NodeKind::ARGUMENT,
                        'name' => new NameNode(
                            [
                                'kind' => NodeKind::NAME,
                                'value' => $name,
                            ]
                        ),
                        'value' => new VariableNode(
                            [
                                'kind' => NodeKind::VARIABLE,
                                'name' => new NameNode(
                                    [
                                        'kind' => NodeKind::NAME,
                                        'value' => $variableName,
                                    ]
                                ),
                            ]
                        ),
                    ]
                );
            }
        );

        $arguments = $existingArguments->merge($missingArguments);

        return [
            new FieldNode(
                [
                    'kind' => NodeKind::FIELD,
                    'alias' => null,
                    'arguments' => $arguments,
                    'selectionSet' => $selection->selectionSet,
                    'name' => new NameNode(
                        [
                            'kind' => NodeKind::NAME,
                            'value' => $rootFieldName,
                        ]
                    ),
                ]
            ),
            $variables,
        ];
    }
}