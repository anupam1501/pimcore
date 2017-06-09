<?php
/**
 * @var \Pimcore\Templating\PhpEngine $this
 * @var \Pimcore\Templating\PhpEngine $view
 * @var \Pimcore\Templating\GlobalVariables $app
 */

$block = $this->block('AB-B')
?>

<p class="text-small">
<code>
    <?= $block->getType() ?> -
    <?= $block->getRealName() ?> -
    <?= $block->getName() ?>
</code>
</p>


<div style="padding: 15px; border: 2px solid blue;">

<?php while ($block->loop()): ?>

    <?php
    $areablock = $this->areablock('AB-B-AB');
    ?>

    <p class="text-small">
        <code>
            <?= $areablock->getType() ?> -
            <?= $areablock->getRealName() ?> -
            <?= $areablock->getName() ?>
        </code>
    </p>

    <div style="padding: 15px; border: 2px solid orange;">

    <?= $areablock ?>

    </div>

<?php endwhile; ?>

</div>
