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

namespace Pimcore\Document\Tag\NamingStrategy\Migration;

use Pimcore\Document\Tag\NamingStrategy\Migration\Element\AbstractElement;
use Pimcore\Document\Tag\NamingStrategy\Migration\Element\Block;
use Pimcore\Document\Tag\NamingStrategy\Migration\Element\Editable;

class MigrationProcessor
{
    /**
     * Map of elements by name => type
     *
     * @var array
     */
    private $map = [];

    /**
     * @var Block[]
     */
    private $blocks = [];

    /**
     * @var Editable[]
     */
    private $editables = [];

    /**
     * @var AbstractElement[]
     */
    private $elements = [];

    /**
     * @var bool
     */
    private $processed = false;

    /**
     * @var array
     */
    private $blockTypes = ['block', 'areablock'];

    /**
     * Add an element mapping
     *
     * @param string $name
     * @param string $type
     */
    public function add(string $name, string $type)
    {
        $this->map[$name] = $type;
        ksort($this->map);
    }

    public function getElement(string $name): AbstractElement
    {
        $this->process();

        if (!isset($this->elements[$name])) {
            throw new \InvalidArgumentException(sprintf('Element with name "%s" does not exist', $name));
        }

        return $this->elements[$name];
    }

    private function process()
    {
        if ($this->processed) {
            return;
        }

        $blockNames            = $this->getBlockNames();
        $blockParentCandidates = $this->findBlockParentCandidates($blockNames);
        $blockParents          = $this->resolveBlockParents($blockParentCandidates);

        $this->blocks    = $this->buildBlocks($blockNames, $blockParents);
        $this->editables = $this->buildEditables();
        $this->elements  = array_merge($this->blocks, $this->editables);

        $this->processed = true;
    }

    private function buildEditables(): array
    {
        $blocks = $this->getBlocksSortedByLevel();

        $editables = [];
        foreach ($this->map as $name => $type) {
            if ($this->isBlock($type)) {
                continue;
            }

            $editables[$name] = $this->buildEditable($name, $blocks);
        }

        return $editables;
    }

    private function buildEditable(string $name, array $blocks): Editable
    {
        $parent = null;
        foreach ($blocks as $block) {
            $matchString = $block->getEditableMatchString();
            $pattern     = '/^(?<realName>.+)' . self::escapeRegexString($matchString) . '(?<indexes>[\d_]*)$/';

            if (preg_match($pattern, $name, $matches)) {
                $parent = $block;
                break;
            }
        }

        return new Editable($name, $parent);
    }

    private function buildBlocks(array $blockNames, array $blockParents): array
    {
        $hierarchies = [];
        foreach ($blockNames as $blockName) {
            $hierarchy = [];

            $currentBlockName = $blockName;
            while (isset($blockParents[$currentBlockName])) {
                $currentBlockName = $blockParents[$currentBlockName];
                $hierarchy[] = $currentBlockName;
            }

            $hierarchies[$blockName] = array_reverse($hierarchy);
        }

        uasort($hierarchies, function ($a, $b) {
            if (count($a) === count($b)) {
                return 0;
            }

            return count($a) < count($b) ? -1 : 1;
        });

        $blocks = [];
        foreach ($hierarchies as $blockName => $parentNames) {
            $parent = null;
            if (count($parentNames) > 0) {
                $lastParentName = (array_reverse($parentNames))[0];
                if (!isset($blocks[$lastParentName])) {
                    throw new \LogicException(sprintf('Block info for parent "%s" was not found', $lastParentName));
                }

                $parent = $blocks[$lastParentName];
            }

            $blocks[$blockName] = new Block($blockName, $parent);
        }

        return $blocks;
    }

    /**
     * Tries to find a list of blocks which could be a block's parent. Example:
     *
     *      name:     AB-B-ABAB_AB-BAB33_1
     *      parents:  [
     *          AB,
     *          AB-B
     *      ]
     *
     * We need to catch the AB-B parent, not its ancestor AB, so we first try to find
     * all candidates, then resolve in resolveBlockParents until only one candidate
     * is left in the list. As soon as we know AB is AB-B's parent, we can safely
     * remove AB from the list of candidates for AB-B-ABAB_AB-BAB33_1
     *
     * @param array $blockNames
     *
     * @return array
     */
    private function findBlockParentCandidates(array $blockNames): array
    {
        $parentCandidates = [];
        foreach ($blockNames as $blockName) {
            $pattern = '/^(?<realName>.+)' . self::escapeRegexString($blockName) . '(?<indexes>[\d_]*)$/';

            foreach ($blockNames as $matchingBlockName) {
                if ($blockName === $matchingBlockName) {
                    continue;
                }

                if (preg_match($pattern, $matchingBlockName, $match)) {
                    $parentCandidates[$matchingBlockName][] = $blockName;
                }
            }
        }

        return $parentCandidates;
    }

    /**
     * @param array $parentCandidates
     *
     * @return array
     */
    private function resolveBlockParents(array $parentCandidates): array
    {
        $changed = true;
        $parents = [];

        // iterate list until we narrowed down the list of candidates to 1 for
        // every block
        while ($changed) {
            $changed = false;

            foreach ($parentCandidates as $name => $candidates) {
                if (count($candidates) === 0) {
                    throw new \LogicException('Expected at least one parent candidate');
                }

                if (count($candidates) === 1) {
                    if (!isset($parents[$name])) {
                        $parents[$name] = $candidates[0];
                        $changed = true;
                    }
                } else {
                    $indexesToRemove = [];
                    foreach ($candidates as $candidate) {
                        if (isset($parents[$candidate])) {
                            // check if the parent of the candidate is in our candidates list
                            // if found (array_keys has a result), remove the parent from our candidates list
                            $parent = $parents[$candidate];
                            $indexesToRemove = array_merge($indexesToRemove, array_keys($candidates, $parent));
                        }
                    }

                    // remove all parent candidates we found
                    if (count($indexesToRemove) > 0) {
                        $changed = true;

                        foreach ($indexesToRemove as $indexToRemove) {
                            unset($candidates[$indexToRemove]);
                        }

                        $parentCandidates[$name] = array_values($candidates);
                    }
                }
            }
        }

        return $parents;
    }

    private function getBlockNames()
    {
        $blockNames = [];
        foreach ($this->map as $name => $type) {
            if ($this->isBlock($type)) {
                $blockNames[] = $name;
            }
        }

        return $blockNames;
    }

    /**
     * Get blocks sorted by deepest level first
     *
     * @return Block[]
     */
    private function getBlocksSortedByLevel(): array
    {
        $blocks = $this->blocks;
        uasort($blocks, function(Block $a, Block $b) {
            if ($a->getLevel() === $b->getLevel()) {
                return 0;
            }

            return $a->getLevel() < $b->getLevel() ? 1 : -1;
        });

        return $blocks;
    }

    public function debugElement(AbstractElement $element)
    {
        $parents = [];
        foreach ($element->getParents() as $parent) {
            $parents[] = $parent->getRealName();
        }

        dump([
            'parents'  => $parents,
            'name'     => $element->getName(),
            'realName' => $element->getRealName(),
            'index'    => $element->getIndex(),
            'level'    => $element->getLevel(),
        ]);
    }

    private function isBlock(string $type): bool
    {
        return in_array($type, $this->blockTypes);
    }

    public static function escapeRegexString(string $string): string
    {
        $string = str_replace('.', '\\.', $string);
        $string = str_replace('-', '\\-', $string);

        return $string;
    }
}
