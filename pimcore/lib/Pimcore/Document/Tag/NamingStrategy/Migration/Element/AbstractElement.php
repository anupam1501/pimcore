<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Document\Tag\NamingStrategy\Migration\Element;

use Pimcore\Document\Tag\NamingStrategy\Migration\MigrationProcessor;

abstract class AbstractElement
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $realName;

    /**
     * @var int|null
     */
    protected $index;

    /**
     * @var Block|null
     */
    protected $parent;

    /**
     * @var Block[]
     */
    protected $parents;

    /**
     * @var bool
     */
    private $processed = false;

    public function __construct(string $name, Block $parent = null)
    {
        $this->name   = $name;
        $this->parent = $parent;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRealName(): string
    {
        $this->process();

        return $this->realName;
    }

    /**
     * @return int|null
     */
    public function getIndex()
    {
        $this->process();

        return $this->index;
    }

    public function getParent()
    {
        return $this->parent;
    }

    /**
     * @return Block[]
     */
    public function getParents(): array
    {
        if (null !== $this->parents) {
            return $this->parents;
        }

        $parents = [];
        $parent  = $this->parent;

        while (null !== $parent) {
            $parents[] = $parent;
            $parent    = $parent->getParent();
        }

        $this->parents = array_reverse($parents);

        return $this->parents;
    }

    public function getLevel(): int
    {
        return count($this->getParents());
    }

    private function process()
    {
        if ($this->processed) {
            return;
        }

        // no parent (root level): we have no index and the realName is the
        // same as the full name
        if (null === $this->parent) {
            $this->index     = null;
            $this->realName  = $this->name;
            $this->processed = true;

            return;
        }

        $this->processHierarchy();
        $this->processed = true;
    }

    protected function processHierarchy()
    {
        // find parent names and build pattern to match against
        // e.g.:
        //
        // input:       accordionAB_AB-BAB3_AB-B-ABAB_AB-BAB33_13_1_2
        // realName:    accordion
        // indexes:     3_1_2
        //
        // the string between the real name and the index suffix is built
        // from parent block names

        $parentIndexes    = [];
        $namePatternParts = [];

        foreach ($this->getParents() as $parent) {
            $namePatternParts[] = $parent->getName();

            if (null !== $parentIndex = $parent->getIndex()) {
                $parentIndexes[] = $parent->getIndex();
            }
        }

        $namePatternParts = array_reverse($namePatternParts);

        $namePattern = implode('_', array_reverse($namePatternParts));
        $pattern     = '/^(?<realName>.+)' . MigrationProcessor::escapeRegexString($namePattern) . '(?<indexes>[\d_]*)$/';

        // TODO fail if preg_match_all returns more than 1 result?
        if (!preg_match($pattern, $this->name, $matches)) {
            throw new \LogicException(sprintf(
                'Failed to match "%s" against pattern "%s"',
                $this->name, $pattern
            ));
        }

        $this->realName = (string)$matches['realName'];

        // get index from index suffix and check if remaining indexes match parent indexes
        if (empty($matches['indexes'])) {
            $this->index = null;
        } else {
            $indexes = explode('_', $matches['indexes']);
            $indexes = array_map(function ($index) {
                return (int)$index;
            }, $indexes);

            $this->index = array_pop($indexes);

            // check if remaining indexes match with parent indexes
            // e.g. indexes resulted in 3_2_1 -> our index is 1 and we expect
            // parent indexes to be [3, 2]
            if ($indexes !== $parentIndexes) {
                throw new \LogicException(sprintf(
                    'Parent indexes do not match index hierarchy for block "%s". Indexes: %s, Parent: %s',
                    $this->name,
                    json_encode($indexes),
                    json_encode($parentIndexes)
                ));
            }
        }
    }
}
