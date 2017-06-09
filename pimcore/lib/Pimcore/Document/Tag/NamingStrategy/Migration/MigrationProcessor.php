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

use Pimcore\Document\Tag\NamingStrategy\Migration\Element\AbstractBlock;
use Pimcore\Document\Tag\NamingStrategy\Migration\Element\AbstractElement;
use Pimcore\Document\Tag\NamingStrategy\Migration\Element\Areablock;
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
     * @var array
     */
    private $blockData = [];

    /**
     * @var AbstractBlock[]
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
    private $blockTypes = [
        'block'     => Block::class,
        'areablock' => Areablock::class
    ];

    /**
     * Add an element mapping
     *
     * @param string $name
     * @param string $type
     * @param mixed $data
     */
    public function add(string $name, string $type, $data)
    {
        $this->map[$name] = $type;
        ksort($this->map);

        if ($this->isBlock($type)) {
            $this->addBlockData($name, $data);
        }

        $this->reset();
    }

    /**
     * @param string $name
     * @param mixed $data
     */
    private function addBlockData(string $name, $data)
    {
        if (!empty($data)) {
            $data = unserialize($data);
        } else {
            $data = [];
        }

        $this->blockData[$name] = $data;
    }

    /**
     * @return AbstractElement[]
     */
    public function getElements(): array
    {
        $this->process();

        return $this->elements;
    }

    /**
     * @param string $name
     *
     * @return AbstractElement
     */
    public function getElement(string $name): AbstractElement
    {
        $this->process();

        if (!isset($this->elements[$name])) {
            throw new \InvalidArgumentException(sprintf('Element with name "%s" does not exist', $name));
        }

        return $this->elements[$name];
    }

    private function reset()
    {
        $this->processed = false;
        $this->blocks    = [];
        $this->editables = [];
        $this->elements  = [];
    }

    private function process()
    {
        if ($this->processed) {
            return;
        }

        dump($this->map);

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

    /**
     * @param string $name
     * @param AbstractBlock[] $blocks
     *
     * @return Editable
     */
    private function buildEditable(string $name, array $blocks): Editable
    {
        $parentBlocks = [];
        foreach ($blocks as $block) {
            $matchString = $block->getEditableMatchString();
            $pattern     = '/^(?<realName>.+)' . self::escapeRegexString($matchString) . '(?<indexes>[\d_]*)$/';

            if (preg_match($pattern, $name, $matches)) {
                $parentBlocks[] = $block;
            }
        }

        // no parent blocks -> root element without parent
        if (count($parentBlocks) === 0) {
            return new Editable($name, $this->map[$name]);
        }

        /** @var Editable[] $editables */
        $editables = [];

        foreach ($parentBlocks as $parentBlock) {
            try {
                $editables[] = new Editable($name, $this->map[$name], $parentBlock);
            } catch (\Exception $e) {
                dump('ERROR: ' . $e->getMessage());
            }
        }

        if (count($editables) === 0) {
            throw new \RuntimeException(sprintf(
                'Failed to build an editable for element "%s"',
                $name
            ));
        } elseif (count($editables) > 1) {
            $parentNames = array_map(function (Editable $ed) {
                return $ed->getParent() ? $ed->getParent()->getName() : null;
            }, $editables);

            $nestedStrategy = \Pimcore::getContainer()->get('pimcore.document.tag.naming.strategy.nested');
            $legacyStrategy = \Pimcore::getContainer()->get('pimcore.document.tag.naming.strategy.legacy');

            foreach ($editables as $editable) {
                dump([
                    'editable'   => $editable,
                    'nestedName' => $editable->getNameForStrategy($nestedStrategy),
                    'legacyName' => $editable->getNameForStrategy($legacyStrategy),
                ]);
            }

            throw new \LogicException(sprintf(
                'Ambiguous results. Built %d editables for element "%s". Parents: %s',
                count($editables),
                $name,
                json_encode($parentNames)
            ));
        }

        return $editables[0];
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

            $blockType = $this->map[$blockName];
            if (!isset($this->blockTypes[$blockType])) {
                throw new \InvalidArgumentException(sprintf('Invalid block type "%s"', $blockType));
            }

            $blockClass = $this->blockTypes[$blockType];

            $blocks[$blockName] = new $blockClass($blockName, $this->map[$blockName], $this->blockData[$blockName], $parent);
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
     * Get blocks sorted by deepest level first. If they are on the same level,
     * prefer those which have a number at the end (mitigates errors when
     * having blocks named something like "content" and "content1" simultaneosly
     *
     * @return AbstractBlock[]
     */
    private function getBlocksSortedByLevel(): array
    {
        $compareByTrailingNumber = function (string $a, string $b): int {
            $numberPattern = '/(?<number>\d+)$/';

            $matchesA = (bool)preg_match($numberPattern, $a, $aMatches);
            $matchesB = (bool)preg_match($numberPattern, $b, $bMatches);

            if ($matchesA && !$matchesB) {
                return -1;
            }

            if (!$matchesA && $matchesB) {
                return 1;
            }

            if ($matchesA && $matchesB) {
                $aLen = strlen((string)$aMatches['number']);
                $bLen = strlen((string)$bMatches['number']);

                if ($aLen === $bLen) {
                    return 0;
                }

                return $aLen > $bLen ? -1 : 1;
            }

            return 0;
        };

        $blocks = $this->blocks;
        uasort($blocks, function(AbstractBlock $a, AbstractBlock $b) use ($compareByTrailingNumber) {
            if ($a->getLevel() === $b->getLevel()) {
                return $compareByTrailingNumber($a->getRealName(), $b->getRealName());
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
            'type'     => $element->getType(),
            'name'     => $element->getName(),
            'realName' => $element->getRealName(),
            'index'    => $element->getIndex(),
            'level'    => $element->getLevel(),
        ]);
    }

    private function isBlock(string $type): bool
    {
        return in_array($type, array_keys($this->blockTypes));
    }

    public static function escapeRegexString(string $string): string
    {
        $string = str_replace('.', '\\.', $string);
        $string = str_replace('-', '\\-', $string);

        return $string;
    }
}
