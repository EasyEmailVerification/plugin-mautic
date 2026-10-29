<?php

$attr = $form->vars['attr'];
?>
    <div class="alert alert-info"><?php echo $view['translator']->trans('mautic.plugin.easy.submit'); ?></div>
    <div style="margin-top: 10px">
        <ul class="list-group" style="max-height: 400px;overflow-y: auto">
            <?php
            echo '<li class="list-group-item">'.$lookupItem.'</li>';
            ?>
        </ul>
    </div>
<?php
echo $view['form']->form($form, ['attr' => $attr]);
