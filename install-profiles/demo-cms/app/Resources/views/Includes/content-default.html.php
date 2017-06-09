<?php
/**
 * @var \Pimcore\Templating\PhpEngine $this
 * @var \Pimcore\Templating\PhpEngine $view
 * @var \Pimcore\Templating\GlobalVariables $app
 */
?>

<?= $this->template('Includes/content-headline.html.php'); ?>
<?= $this->areablock('content'); ?>

<h3>CONTENT 1</h3>
<?= $this->areablock('content1'); ?>
