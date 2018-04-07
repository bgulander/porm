<?php

declare(strict_types=1);

namespace PORM\SQL;

use PORM\Drivers\IPlatform;
use PORM\Cache;
use PORM\EventDispatcher;
use PORM\Exceptions\InvalidQueryException;
use PORM\Metadata\Registry;
use PORM\SQL\AST\IVisitor;


class Translator {

    private $parser;

    private $walker;

    private $metadataRegistry;

    private $platform;

    private $eventDispatcher;

    private $cache;

    /** @var IVisitor[] */
    private $visitors = null;


    public function __construct(
        AST\Parser $parser,
        Registry $metadataRegistry,
        IPlatform $platform,
        EventDispatcher $eventDispatcher,
        ?Cache\IStorage $cache = null
    ) {
        $this->parser = $parser;
        $this->walker = new AST\Walker();
        $this->metadataRegistry = $metadataRegistry;
        $this->platform = $platform;
        $this->eventDispatcher = $eventDispatcher;
        $this->cache = $cache;
    }


    public function translate(string $query) : Query {
        if ($this->cache) {
            return $this->cache->get(
                $this->getQueryCacheKey($query),
                function() use ($query) {
                    return $this->doTranslate($query);
                }
            );
        } else {
            return $this->doTranslate($query);
        }
    }

    public function compile(AST\Node\Query $query) : Query {
        $this->eventDispatcher->dispatch(self::class . '::beforeCompile', $query);

        foreach ($this->getASTVisitors() as $visitors) {
            $this->walker->apply($query, ... $visitors);
        }

        $this->eventDispatcher->dispatch(self::class . '::compile', $query);

        switch (get_class($query)) {
            case AST\Node\SelectQuery::class: /** @var AST\Node\SelectQuery $query */
                return $this->platform->formatSelectQuery($query);
            case AST\Node\InsertQuery::class: /** @var AST\Node\InsertQuery $query */
                return $this->platform->formatInsertQuery($query);
            case AST\Node\UpdateQuery::class: /** @var AST\Node\UpdateQuery $query */
                return $this->platform->formatUpdateQuery($query);
            case AST\Node\DeleteQuery::class: /** @var AST\Node\DeleteQuery $query */
                return $this->platform->formatDeleteQuery($query);
            default:
                throw new InvalidQueryException(":-(");
        }
    }


    private function doTranslate(string $query) : Query {
        $ast = $this->parser->parseQuery($query);
        return $this->compile($ast);
    }

    private function getQueryCacheKey(string $query) : string {
        return sha1($query) . strlen($query);
    }

    private function getASTVisitors() : array {
        if ($this->visitors === null) {
            $this->visitors[] = [
                new AST\Visitor\EntityResolverVisitor($this->metadataRegistry),
                new AST\Visitor\SubqueryMappingVisitor(),
                new AST\Visitor\JoinConditionResolverVisitor($this->metadataRegistry),
                new AST\Visitor\IdentifierResolverVisitor(),
                new AST\Visitor\ResultMappingVisitor(),
            ];

            $this->visitors[] = [
                new AST\Visitor\ParameterResolverVisitor(),
            ];
        }

        return $this->visitors;
    }


    public static function serialize(Query $query) : string {
        return Cache\Helpers::serializeInstance($query, [
            'sql' => 'Compiled SQL',
            'parameterMap' => 'Parameter map',
            'resultMap' => 'Result map',
            'parameterIndex' => 'Parameter index',
        ]);
    }

}
