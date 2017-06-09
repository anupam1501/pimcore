<?php
/**
 * @var \Pimcore\Templating\PhpEngine $this
 * @var \Pimcore\Templating\PhpEngine $view
 * @var \Pimcore\Templating\GlobalVariables $app
 */

$this->extend('layout.html.php');

?>

<?php
$areablock = $this->areablock('AB');
?>

<p class="text-small">
    <code>
        <?= $areablock->getType() ?> -
        <?= $areablock->getRealName() ?> -
        <?= $areablock->getName() ?>
    </code>
</p>

<div style="padding: 15px; border: 2px solid red;">
    <?= $areablock ?>
</div>
