<?php


?>

<div class="well well-sm" style="margin-bottom:0 !important;">
    <p>
        <?php echo $view['translator']->trans('mautic.plugin.easy.webhook_info'); ?>
    </p>
</div>

<script>
    function toggleSafeToSend(value) {
    if (value === '1') {
        document.getElementById('integration_details_featureSettings_result_0').checked = true;
        document.getElementById('integration_details_featureSettings_result_1').checked = false;

        document.getElementById('integration_details_featureSettings_safe_to_send_0').checked = false;
        document.getElementById('integration_details_featureSettings_safe_to_send_1').checked = true;

        updateButtonClasses();
    }
}

function toggleResult(value) {
    if (value === '1') {
        // Set the checked states of the radio buttons
        document.getElementById('integration_details_featureSettings_safe_to_send_0').checked = true;
        document.getElementById('integration_details_featureSettings_safe_to_send_1').checked = false;

        document.getElementById('integration_details_featureSettings_result_0').checked = false;
        document.getElementById('integration_details_featureSettings_result_1').checked = true;      

        // Update the classes for the labels
        updateButtonClasses();

    }
}

function updateButtonClasses() {
    updateButtonClass('integration_details_featureSettings_safe_to_send_0', "no");
    updateButtonClass('integration_details_featureSettings_safe_to_send_1', "yes");
    updateButtonClass('integration_details_featureSettings_result_0', "no");
    updateButtonClass('integration_details_featureSettings_result_1', "yes");
}

function updateButtonClass(elementId, type_button) {
    let element = document.getElementById(elementId);
    let label = element.closest('label');

    if (type_button==="no"){
        if (element.checked) {
            label.classList.add('btn-danger');
            label.classList.add('active');
            label.classList.remove('btn-default');
        } else {
            label.classList.remove('btn-danger');
            label.classList.remove('active');
            label.classList.add('btn-default');
        }
    }
    if (type_button==="yes"){
        if (element.checked) {
            label.classList.add('btn-success');
            label.classList.add('active');
            label.classList.remove('btn-default');
        } else {
            label.classList.remove('btn-success');
            label.classList.remove('active');
            label.classList.add('btn-default');
        }
    }
}





</script>