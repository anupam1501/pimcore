<?php
/**
 * @var \Pimcore\Templating\PhpEngine $this
 * @var \Pimcore\Templating\PhpEngine $view
 * @var \Pimcore\Templating\GlobalVariables $app
 */
?>

<section class="area-text-accordion">

    <?php
        $id = "accordion-" . uniqid();
    ?>
    <div class="panel-group" id="<?= $id ?>">



        <?php
        $block = $this->block("accordion");
        ?>

        <p class="text-small">
            <code>
                <?= $block->getType() ?> -
                <?= $block->getRealName() ?> -
                <?= $block->getName() ?>
            </code>
        </p>

        <div style="padding: 15px; border: 2px solid limegreen;">

        <?php
        while($block->loop()) { ?>
            <?php
                $entryId = $id . "-" . $this->block("accordion")->getCurrent();
            ?>
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 class="panel-title">
                        <a data-toggle="collapse" data-parent="#<?= $id ?>" href="#<?= $entryId ?>">
                            <?= $this->input("headline") ?>
                        </a>
                    </h4>
                </div>
                <div id="<?= $this->editmode ? "" : $entryId ?>" class="panel-collapse collapse <?= ($this->editmode || $this->block("accordion")->getCurrent() == 0) ? "in" : "" ?>">
                    <div class="panel-body">
                        <?= $this->wysiwyg("text") ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        </div>
    </div>


</section>

