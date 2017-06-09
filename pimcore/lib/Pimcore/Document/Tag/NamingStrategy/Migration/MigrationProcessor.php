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

class MigrationProcessor
{
    private $typeMap = [];
    private $nameMap = [];
    private $blockNames = [];
    private $blockInfos;
    private $blockTypes = ['block', 'areablock'];
    private $parents = [];

    public function add(string $name, string $type)
    {
        $this->typeMap[$type][] = $name;
        $this->nameMap[$name]   = $type;

        if ($this->isBlock($type)) {
            $this->blockNames[] = $name;
        }

        sort($this->typeMap[$type]);
        ksort($this->nameMap);
        sort($this->blockNames);
    }

    public function findParentCandidates()
    {
        $parentCandidates = [];
        foreach ($this->blockNames as $blockName) {
            $pattern = '/^(?<realName>.+)' . self::escapeRegexString($blockName) . '(?<indexes>[\d_]*)$/';

            foreach ($this->blockNames as $matchingBlockName) {
                if ($blockName === $matchingBlockName) {
                    continue;
                }

                if (preg_match($pattern, $matchingBlockName, $match)) {
                    $parentCandidates[$matchingBlockName][] = $blockName;
                }
            }
        }

        $this->resolveParents($parentCandidates);
        $this->buildBlockInfos();
    }

    private function resolveParents(array $parentCandidates)
    {
        $changed = true;

        $parents = [];
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

        $this->parents = $parents;
    }

    private function buildBlockInfos()
    {
        $hierarchies = [];

        foreach ($this->blockNames as $blockName) {
            $hierarchy = [];

            $currentBlockName = $blockName;
            while (isset($this->parents[$currentBlockName])) {
                $currentBlockName = $this->parents[$currentBlockName];
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

        $blockInfos = [];
        foreach ($hierarchies as $blockName => $parentNames) {
            $parent = null;
            if (count($parentNames) > 0) {
                $lastParentName = (array_reverse($parentNames))[0];
                if (!isset($blockInfos[$lastParentName])) {
                    throw new \LogicException(sprintf('Block info for parent "%s" was not found', $lastParentName));
                }

                $parent = $blockInfos[$lastParentName];
            }

            $blockInfos[$blockName] = new BlockInfo($blockName, $parent);
        }

        $this->blockInfos = $blockInfos;

        /**
         * @var string $blockName
         * @var BlockInfo $blockInfo
         */
        foreach ($blockInfos as $blockName => $blockInfo) {
            $parents = [];
            foreach ($blockInfo->getParents() as $parent) {
                $parents[] = $parent->getRealName();
            }

            dump([
                'parents'  => $parents,
                'name'     => $blockInfo->getName(),
                'realName' => $blockInfo->getRealName(),
                'index'    => $blockInfo->getIndex(),
                'level'    => $blockInfo->getLevel(),
            ]);
        }

        /*
        $id = 'AB-BAB3';
        $id = 'accordionAB_AB-BAB3_AB-B-ABAB_AB-BAB33_13_1_2';

        dump($this->blockInfo[$id]->getName());
        dump($this->blockInfo[$id]->getRealName());
        dump($this->blockInfo[$id]->getIndex());

        dump($this->blockInfo[$id]);
        */
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
